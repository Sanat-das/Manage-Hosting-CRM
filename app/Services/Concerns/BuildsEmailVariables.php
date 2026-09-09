<?php

namespace App\Services\Concerns;

use App\Models\EmailTemplate;
use App\Support\AppSettings;
use App\Support\Branding;

/**
 * Shared rendering plumbing for the admin-managed email templates.
 *
 * Every customer-facing template (invoice_created, order_confirmation, …) is
 * authored against the same branding/company/currency vocabulary, so the map
 * that resolves those placeholders has to be identical everywhere. It used to
 * live only in InvoiceEmailService, which is why the seeded HTML
 * order_confirmation template rendered raw `{{app_logo_url}}` /
 * `{{company_name}}` text: the order path built its own three-variable map.
 *
 * Consumers add their own domain variables (invoice numbers, order numbers)
 * on top of brandingVariables().
 */
trait BuildsEmailVariables
{
    /**
     * Branding, company, currency and portal-URL variables — everything that
     * is the same for every template regardless of what it is about.
     *
     * @return array<string, string>
     */
    protected function brandingVariables(): array
    {
        $appName = Branding::appName();
        $appUrl = rtrim((string) config('app.url', url('/')), '/');
        $logoUrl = Branding::logoUrl();

        $companyName = $this->setting('company_name', $appName);
        $companyEmail = $this->setting('company_email', (string) config('mail.from.address', ''));
        $companyAddress = $this->setting('company_address', '');
        $companyAddressLine = $companyAddress !== '' ? preg_replace('/\s*\n\s*/', ', ', $companyAddress) : '';

        $currency = $this->setting('currency', 'INR');
        $currencySymbol = $this->setting('catalog_currency_symbol', '₹');

        if ($currencySymbol === '') {
            $currencySymbol = match (strtoupper($currency)) {
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                'INR' => '₹',
                default => $currency.' ',
            };
        }

        return [
            'app_name' => $appName,
            'app_url' => $appUrl,
            'app_logo_url' => $logoUrl,
            'logo_url' => $logoUrl,
            'tagline' => Branding::tagline(),
            'primary_color' => Branding::primaryColor(),
            'footer_text' => Branding::footerText(),

            'company_name' => $companyName,
            'company_email' => $companyEmail,
            'company_phone' => $this->setting('company_phone', ''),
            'company_address' => (string) $companyAddressLine,
            'company_address_raw' => $companyAddress,
            'company_address_html' => $companyAddress !== ''
                ? nl2br(htmlspecialchars($companyAddress, ENT_QUOTES, 'UTF-8'))
                : '',

            'currency' => $currency,
            'currency_code' => $currency,
            'currency_symbol' => $currencySymbol,

            'login_url' => $appUrl.'/login',
            'support_email' => $companyEmail,
            'support_url' => $appUrl.'/support',
            'year' => date('Y'),
            'current_year' => date('Y'),
        ];
    }

    /**
     * Render a template's {{placeholder}} variables.
     *
     * @param  array<string, string>  $vars
     * @return array{0: string, 1: string} [subject, body]
     */
    protected function renderTemplate(EmailTemplate $template, array $vars): array
    {
        $placeholders = array_map(fn (string $key) => '{{'.$key.'}}', array_keys($vars));
        $values = array_values($vars);

        return [
            str_replace($placeholders, $values, (string) $template->subject),
            str_replace($placeholders, $values, (string) $template->body),
        ];
    }

    protected function isHtml(?string $body): bool
    {
        if ($body === null || $body === '') {
            return false;
        }

        return str_contains($body, '<table')
            || str_contains($body, '<html')
            || str_contains($body, '<div')
            || str_contains($body, '<!doctype');
    }

    protected function toPlainText(string $html): string
    {
        // Keep links readable: <a href="...">text</a> -> text (url)
        $text = preg_replace('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', '$2 ($1)', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Strip the admin-only "Available variables:" footer some stored templates
     * carry — the customer must never see it.
     */
    protected function stripAvailableVariablesFooter(string $content): string
    {
        $content = preg_replace('/\n---\s*Available variables:.*$/is', '', $content) ?? $content;
        $content = preg_replace('/\n--\s*Available variables:.*$/is', '', $content) ?? $content;
        $content = preg_replace('/<!--\s*Available variables:.*?-->/is', '', $content) ?? $content;

        return rtrim($content);
    }

    protected function setting(string $key, string $fallback = ''): string
    {
        try {
            $value = AppSettings::get($key);

            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        } catch (\Throwable) {
            // A missing/broken settings row must never stop an email.
        }

        return $fallback;
    }

    protected function safeRoute(string $name, mixed $param, string $fallback): string
    {
        try {
            return $param === null ? (string) route($name) : (string) route($name, $param);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
