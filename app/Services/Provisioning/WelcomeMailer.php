<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Jobs\SendEmail;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\ServiceInstance;
use App\Support\AppSettings;
use App\Support\Branding;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers the welcome email — including the credentials a provisioning module
 * just generated — after a service is provisioned.
 *
 * `products.welcome_email_template_id` has existed since the first product
 * migration and was written by the product forms, but nothing ever read it, so
 * a module could create a cPanel account and the customer would never be told
 * the password. This is the reader.
 *
 * Template resolution: the product's chosen template, falling back to the
 * shipped `service_activated` one. Both must be `active`.
 *
 * The password is deliberately NOT persisted: `SendEmail` writes its body to
 * the `emails` table, so this passes a redacted `logBody` — the customer gets
 * the real credentials, the audit log keeps a readable record with the secret
 * replaced. Same reasoning as ProvisioningDispatcher::redact().
 */
class WelcomeMailer
{
    /** Used when the product names no template of its own. */
    public const FALLBACK_TEMPLATE = 'service_activated';

    /**
     * Placeholders that mean "the template lays out credentials itself". When
     * none are present the block is appended instead, so installs still
     * carrying the pre-credentials `service_activated` body (the seeder does
     * not retro-fit existing rows) deliver the password rather than silently
     * dropping it.
     */
    private const CREDENTIAL_PLACEHOLDERS = ['{{service_credentials}}', '{{service_password}}'];

    /**
     * @param  array<string, mixed>  $credentials  a module's ProvisioningResult data
     * @return bool true when an email was queued
     */
    public function send(Order $order, ServiceInstance $service, array $credentials = []): bool
    {
        try {
            return $this->deliver($order, $service, $credentials);
        } catch (Throwable $e) {
            // A welcome email must never turn a successful provision into a
            // failed order — the service exists either way.
            Log::error('Welcome email failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function deliver(Order $order, ServiceInstance $service, array $credentials): bool
    {
        if (! AppSettings::bool('hosting_welcome_email_enabled', true)) {
            return false;
        }

        $email = $order->customer?->user?->email;

        if (! $email) {
            Log::info('Welcome email skipped: customer has no linked user email.', ['order_id' => $order->id]);

            return false;
        }

        $template = $this->template($order);

        if ($template === null) {
            Log::info('Welcome email skipped: no active template.', ['order_id' => $order->id]);

            return false;
        }

        $isHtml = $this->isHtml((string) $template->body);
        // WHY: the redacted value MUST be whatever is actually rendered. Guest
        // credentials are what the customer logs in with on a VM; the panel
        // password is a generated secret nobody uses there. deliveredCredentials()
        // prefers guest_username/guest_password so audit redaction stays in sync.
        $password = $this->deliveredCredentials($credentials, $service)['password'];

        $vars = $this->variables($order, $service, $credentials, $isHtml);

        $subject = $this->render((string) $template->subject, $vars);
        $body = $this->render((string) $template->body, $vars);

        // Old templates have no credentials placeholder — append rather than lose it.
        if ($vars['service_credentials'] !== '' && ! $this->mentionsCredentials((string) $template->body)) {
            $body = rtrim($body)."\n\n".$vars['service_credentials']."\n";
        }

        $logBody = $password !== ''
            ? str_replace($password, '[redacted]', $body)
            : $body;

        SendEmail::dispatch(
            $email,
            $subject,
            $isHtml ? $this->toPlainText($body) : $body,
            null,
            [],
            [],
            [],
            $isHtml ? $body : null,
            [],
            $isHtml ? $this->toPlainText($logBody) : $logBody,
        );

        return true;
    }

    /**
     * The product's template, else the shipped fallback. A product pointing at
     * an inactive or deleted template falls back rather than sending nothing.
     */
    private function template(Order $order): ?EmailTemplate
    {
        $id = $order->product?->welcome_email_template_id;

        if ($id !== null) {
            $chosen = EmailTemplate::query()->where('id', $id)->where('status', 'active')->first();

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return EmailTemplate::query()
            ->where('name', self::FALLBACK_TEMPLATE)
            ->where('status', 'active')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    private function variables(Order $order, ServiceInstance $service, array $credentials, bool $isHtml): array
    {
        $appName = Branding::appName();
        $appUrl = rtrim(Branding::baseUrl(), '/');
        if ($appUrl === '') {
            $appUrl = rtrim((string) config('app.url', url('/')), '/');
        }
        $customer = $order->customer;

        // WHY: guest credentials are the ones the customer actually logs in with on
        // a VM. The panel password is a generated secret nobody uses there, so when
        // guest_username/guest_password are present they must be delivered instead.
        // A compute VM without guest credentials has no login to deliver at all —
        // see deliveredCredentials().
        $delivered = $this->deliveredCredentials($credentials, $service);
        $username = $delivered['username'];
        $password = $delivered['password'];
        $ip = (string) ($credentials['ip'] ?? $service->server?->ip_address ?? '');
        $nameservers = $this->flatten($credentials['nameservers'] ?? null);
        $domain = (string) ($service->domain ?? $order->domain_name ?? '');

        $vars = [
            'app_name' => $appName,
            'app_url' => $appUrl,
            'app_logo_url' => str_contains(Branding::logoUrl(), '.local') ? Branding::logoEmailUrl() : Branding::logoUrl(),
            'tagline' => Branding::tagline(),
            'primary_color' => Branding::primaryColor(),
            'footer_text' => Branding::footerText(),

            'company_name' => $this->setting('company_name', $appName),
            'company_email' => $this->setting('company_email', (string) config('mail.from.address', '')),
            'company_phone' => $this->setting('company_phone', ''),
            'company_address' => preg_replace('/\s*\n\s*/', ', ', $this->setting('company_address', '')) ?? '',
            'support_email' => $this->setting('company_email', (string) config('mail.from.address', '')),

            'name' => $customer?->full_name ?? 'there',
            'customer_name' => $customer?->full_name ?? 'there',
            'customer_email' => (string) ($customer?->user?->email ?? ''),
            'customer_id' => $customer ? $customer->display_id : '',

            'product_name' => (string) ($order->product?->name ?? 'Your service'),
            'order_number' => (string) $order->order_number,
            'order_no' => (string) $order->order_number,
            'domain' => $domain,
            'activation_date' => now()->format('M j, Y'),
            'login_url' => $appUrl.'/login',
            'year' => date('Y'),

            // Service credentials
            'service_username' => $username,
            'service_password' => $password,
            'service_ip' => $ip,
            'service_nameservers' => $nameservers,
            // WHY: a compute VM has no control panel. The cPanel URL is
            // meaningless there and would confuse the customer, so hyperv and
            // proxmox omit it entirely — the credentials block then renders
            // Username / Password / Server IP only.
            'control_panel_url' => $this->isComputeVm($service) ? '' : ($domain !== '' ? 'https://'.$domain.'/cpanel' : ''),
        ];

        $vars['service_credentials'] = $this->credentialsBlock($vars, $isHtml);

        return $vars;
    }

    /**
     * Which credentials the customer actually receives.
     *
     * Guest credentials win when the module supplied them (a VM — the guest
     * login is what the customer uses, not the panel username/password), and
     * are delivered exactly as supplied: a guest username must never be paired
     * with the panel password, which exists nowhere in the guest.
     *
     * A compute VM that supplied no guest credentials has no login to deliver —
     * mailing the generated panel password would hand the customer a secret
     * that opens nothing, so both fields stay empty and the credentials block
     * degrades to the service address. Every other module (cPanel, Plesk, …)
     * keeps the panel-credential fallback.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{username: string, password: string}
     */
    private function deliveredCredentials(array $credentials, ServiceInstance $service): array
    {
        $guestUsername = trim((string) ($credentials['guest_username'] ?? ''));
        $guestPassword = trim((string) ($credentials['guest_password'] ?? ''));

        if ($guestUsername !== '' || $guestPassword !== '') {
            return ['username' => $guestUsername, 'password' => $guestPassword];
        }

        if ($this->isComputeVm($service)) {
            return ['username' => '', 'password' => ''];
        }

        $panelUsername = trim((string) ($credentials['username'] ?? ''));

        return [
            'username' => $panelUsername !== '' ? $panelUsername : trim((string) ($service->username ?? '')),
            'password' => trim((string) ($credentials['password'] ?? '')),
        ];
    }

    /**
     * Is this a VM whose login lives inside the guest rather than the panel?
     *
     * Hyper-V and Proxmox VE both build a machine the panel does not manage a
     * login for; the generated panel password is bookkeeping, not a credential.
     */
    private function isComputeVm(ServiceInstance $service): bool
    {
        return in_array(
            strtolower(trim((string) $service->provisioning_method)),
            ['hyperv', 'proxmox'],
            true,
        );
    }

    /**
     * The pre-formatted credentials block behind `{{service_credentials}}`.
     * Empty when the module returned nothing worth showing, so a template
     * carrying the placeholder degrades to a plain activation notice.
     *
     * @param  array<string, string>  $vars
     */
    private function credentialsBlock(array $vars, bool $isHtml): string
    {
        $rows = array_filter([
            'Control panel' => $vars['control_panel_url'],
            'Username' => $vars['service_username'],
            'Password' => $vars['service_password'],
            'Server IP' => $vars['service_ip'],
            'Nameservers' => $vars['service_nameservers'],
        ], static fn (string $value) => $value !== '');

        if ($rows === []) {
            return '';
        }

        // A block with no password (a VM whose login was configured separately)
        // must not tell the customer to change one.
        $hasPassword = ($vars['service_password'] ?? '') !== '';

        if (! $isHtml) {
            $lines = [$hasPassword ? 'Your login details:' : 'Your service details:', ''];

            foreach ($rows as $label => $value) {
                $lines[] = sprintf('  %-14s : %s', $label, $value);
            }

            if ($hasPassword) {
                $lines[] = '';
                $lines[] = 'Please change this password after your first login.';
            }

            return implode("\n", $lines);
        }

        $cells = '';

        foreach ($rows as $label => $value) {
            $cells .= sprintf(
                '<tr><td style="padding:8px 14px;font-size:13px;color:#64748b;">%s</td>'
                .'<td style="padding:8px 14px;font-size:14px;color:#0f172a;font-weight:600;">%s</td></tr>',
                e($label),
                e($value),
            );
        }

        return '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;margin-bottom:8px;">Your service credentials</div>'
            .'<table role="presentation" cellpadding="0" cellspacing="0" '
            .'style="border:1px solid #e2e8f0;border-radius:8px;margin:18px 0;">'
            .$cells
            .'</table>'
            .($hasPassword
                ? '<p style="font-size:13px;color:#64748b;">Please change this password after your first login.</p>'
                : '');
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function render(string $template, array $vars): string
    {
        $placeholders = array_map(static fn (string $key) => '{{'.$key.'}}', array_keys($vars));

        return str_replace($placeholders, array_values($vars), $template);
    }

    private function mentionsCredentials(string $body): bool
    {
        foreach (self::CREDENTIAL_PLACEHOLDERS as $placeholder) {
            if (str_contains($body, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    private function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map('strval', $value)));
        }

        return trim((string) $value);
    }

    private function setting(string $key, string $default): string
    {
        try {
            $value = AppSettings::get($key);

            return is_string($value) && trim($value) !== '' ? trim($value) : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    private function isHtml(string $body): bool
    {
        return str_contains($body, '<table')
            || str_contains($body, '<html')
            || str_contains($body, '<div')
            || stripos($body, '<!doctype') !== false;
    }

    private function toPlainText(string $html): string
    {
        $text = preg_replace('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', '$2 ($1)', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(tr|p|div|h[1-6])>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/td>/i', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
    }
}
