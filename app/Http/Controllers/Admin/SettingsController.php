<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Settings\IntegrationSettings;
use App\Support\AppSettings;
use App\Support\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin settings controller — manages application configuration.
 *
 * Typed settings keys (see AppSettings::TYPED_KEYS) are saved through their
 * spatie typed class, which applies per-key validation. Untyped keys keep
 * writing to the legacy `settings` table.
 */
class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $settings = $this->loadAll();
        $sections = AppSettings::sections();
        $ids = array_column($sections, 'id');
        $tab = $request->query('tab');
        if (! is_string($tab) || ! in_array($tab, $ids, true)) {
            $tab = $ids[0] ?? 'general';
        }
        $activeTab = $tab;

        $lastUpdated = $this->lastUpdatedPerSection();

        return view('admin.settings.index', compact('settings', 'sections', 'activeTab', 'lastUpdated'));
    }

    /**
     * Read latest audit row per section from activity_log (reuse only, no new table).
     *
     * Single query fetches recent settings.updated rows then groups in PHP to avoid
     * JSON query dialect differences (sqlite vs mysql). "all" section updates are
     * treated as fallback for any section without a specific entry.
     *
     * @return array<string, object> section id => activity_log row
     */
    private function lastUpdatedPerSection(): array
    {
        try {
            $rows = \DB::table('activity_log')
                ->where('action', 'settings.updated')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        $allRows = [];
        foreach ($rows as $row) {
            $props = null;
            if (isset($row->properties) && is_string($row->properties)) {
                $decoded = json_decode($row->properties, true);
                if (is_array($decoded)) {
                    $props = $decoded;
                }
            }
            if ($props === null && isset($row->metadata) && is_string($row->metadata)) {
                $decoded = json_decode($row->metadata, true);
                if (is_array($decoded)) {
                    $props = $decoded;
                }
            }
            $section = $props['section'] ?? null;
            if ($section === null && isset($row->description) && is_string($row->description)) {
                if (preg_match('/Settings updated \(([^)]+)\)/', $row->description, $m)) {
                    $section = $m[1];
                }
            }
            if ($section === 'all') {
                $allRows[] = $row;
            } elseif (is_string($section) && $section !== '') {
                if (! isset($map[$section])) {
                    $map[$section] = $row;
                }
            }
        }

        // Fallback: "all" audit applies to any section without specific entry.
        if ($allRows !== []) {
            $latestAll = $allRows[0];
            foreach (AppSettings::sections() as $s) {
                $id = $s['id'];
                if (! isset($map[$id])) {
                    $map[$id] = $latestAll;
                } else {
                    // Keep per-section if newer than all, otherwise all is already newer due to order.
                    // Rows are desc, so if per-section exists it is at least as new as all for that scan.
                }
            }
            // Expose 'all' key as well for blade fallback.
            if (! isset($map['all'])) {
                $map['all'] = $latestAll;
            }
        }

        return $map;
    }

    /**
     * Persist admin settings — typed vs legacy untyped fallback.
     *
     * Typed keys (160 in AppSettings::TYPED_KEYS) delegate validation to their
     * owning Spatie settings class via `Class::rules()[$key]`.
     *
     * Legacy untyped keys (18) — not yet ported to typed classes — use fallback
     * validation `nullable|string|max:1000` and persist to the legacy `settings`
     * table via updateOrInsert. They remain here for backward compatibility:
     *   - registration_enabled, default_currency, default_tax_rate, quote_prefix,
     *     auto_generate_invoice, due_days, gst_enabled, mail_from_address,
     *     mail_from_name, session_timeout, max_login_attempts, lockout_duration,
     *     force_2fa, password_min_length, notify_overdue_invoices,
     *     notify_domain_expiry, notify_new_tickets, domain_expiry_warning_days
     *
     * Do NOT port these to typed without a dedicated migration — the fallback
     * rule is intentionally permissive and must not change typed validation
     * behaviour.
     *
     * CONCURRENCY (PINNED): saves are LAST-WRITE-WINS. There is no version
     * check, no optimistic locking, and no pessimistic locking. Save All is the
     * only submit path in the UI and has the widest blast radius: it persists
     * every posted key, so a stale browser tab silently overwrites any values
     * another admin saved in between. The server-side active_tab scoping filter
     * below remains as defense-in-depth for scoped API/test payloads, but it
     * does NOT eliminate the race — two admins editing the SAME keys still
     * overwrite each other field-for-field. Detection, not prevention: the
     * activity_log audit trail (action=settings.updated, per-section diff)
     * records who changed what, so a lost update can always be traced after
     * the fact. The UI note near Save All tells admins to coordinate.
     */
    public function update(Request $request): RedirectResponse
    {
        $tab = $request->input('active_tab', $request->query('tab'));

        // Normalize missing / filtered payload to empty array so tab-scoped
        // submits (or empty filtered forms) do not trigger 422.
        $payload = $request->input('settings');
        if ($payload === null) {
            $payload = [];
            $request->merge(['settings' => []]);
        }

        if (! is_array($payload)) {
            $payload = [];
            $request->merge(['settings' => []]);
        }

        // --- Branding file uploads (logo/favicon) ---
        // Must run BEFORE typed rules so injected branding_*_path values validate as strings,
        // not files. Supports both top-level inputs (branding_logo) and nested
        // settings[branding_logo] naming — blade uses top-level for clarity.
        $brandingFileRules = [];
        if ($request->hasFile('branding_logo')) {
            $brandingFileRules['branding_logo'] = ['nullable', 'file', 'mimes:svg,png,jpg,jpeg,webp', 'max:2048'];
        }
        if ($request->hasFile('branding_favicon')) {
            $brandingFileRules['branding_favicon'] = ['nullable', 'file', 'mimes:svg,png,jpg,jpeg,webp,ico', 'max:1024'];
        }
        if ($request->hasFile('settings.branding_logo')) {
            $brandingFileRules['settings.branding_logo'] = ['nullable', 'file', 'mimes:svg,png,jpg,jpeg,webp', 'max:2048'];
        }
        if ($request->hasFile('settings.branding_favicon')) {
            $brandingFileRules['settings.branding_favicon'] = ['nullable', 'file', 'mimes:svg,png,jpg,jpeg,webp,ico', 'max:1024'];
        }
        if ($brandingFileRules !== []) {
            try {
                $request->validate($brandingFileRules);
            } catch (ValidationException $e) {
                // Force back to Branding tab so the file error is visible
                $e->redirectTo = route('admin.settings.index', ['tab' => 'branding']);
                throw $e;
            }
            try {
                if ($request->hasFile('branding_logo')) {
                    $payload['branding_logo_path'] = $request->file('branding_logo')->store('branding', 'public');
                } elseif ($request->hasFile('settings.branding_logo')) {
                    $payload['branding_logo_path'] = $request->file('settings.branding_logo')->store('branding', 'public');
                }
                if ($request->hasFile('branding_favicon')) {
                    $payload['branding_favicon_path'] = $request->file('branding_favicon')->store('branding', 'public');
                } elseif ($request->hasFile('settings.branding_favicon')) {
                    $payload['branding_favicon_path'] = $request->file('settings.branding_favicon')->store('branding', 'public');
                }
                $request->merge(['settings' => $payload]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // --- Company phone normalization (ecommerce phone-input parity) ---
        // Phone-input sends _code + _number plus a hidden combined value. Normalize
        // exactly like CustomerController::normalizePhone so company_phone is stored
        // as "+91 9876543210" and the split fields never reach validation/persistence.
        $hasPhoneSplit = isset($payload['company_phone_code']) || isset($payload['company_phone_number']);
        // also support top-level split fields if phone-input was used without settings[] prefix
        if (! $hasPhoneSplit && ($request->has('company_phone_code') || $request->has('company_phone_number') || $request->has('phone_code') || $request->has('phone_number'))) {
            $code = trim((string) ($request->input('company_phone_code', $request->input('phone_code', ''))));
            $number = trim((string) ($request->input('company_phone_number', $request->input('phone_number', ''))));
            if ($code !== '' || $number !== '') {
                if ($code === '' && $number !== '') $code = '+91';
                $payload['company_phone'] = $number !== '' ? trim($code.' '.$number) : $code;
            }
        } elseif ($hasPhoneSplit) {
            $code = trim((string) ($payload['company_phone_code'] ?? ''));
            $number = trim((string) ($payload['company_phone_number'] ?? ''));
            // hidden combined may already be correct (JS synced); prefer split when present
            if ($code !== '' || $number !== '') {
                if ($code === '' && $number !== '') $code = '+91';
                $payload['company_phone'] = $number !== '' ? trim($code.' '.$number) : $code;
            }
            unset($payload['company_phone_code'], $payload['company_phone_number']);
        }
        // legacy top-level phone split for non-settings forms — merge into payload
        if (isset($payload['phone_code']) || isset($payload['phone_number'])) {
            $code = trim((string) ($payload['phone_code'] ?? ''));
            $number = trim((string) ($payload['phone_number'] ?? ''));
            if ($code !== '' || $number !== '') {
                if ($code === '' && $number !== '') $code = '+91';
                $payload['company_phone'] = $number !== '' ? trim($code.' '.$number) : $code;
            }
            unset($payload['phone_code'], $payload['phone_number']);
        }
        if ($hasPhoneSplit || isset($payload['company_phone'])) {
            $request->merge(['settings' => $payload]);
        }

        // --- Company address legacy sync (ecommerce sundered -> single string) ---
        // When sundered fields are submitted, keep the legacy company_address in sync
        // so InvoiceEmailService (which reads company_address) continues to work without
        // code changes. Uses same compile logic as CustomerController::compileLegacyAddress.
        $hasSundered = isset($payload['company_address_line1']) || isset($payload['company_city']) || isset($payload['company_state']) || isset($payload['company_postcode']) || isset($payload['company_country']) || isset($payload['company_address_line2']);
        if ($hasSundered) {
            $legacyParts = array_filter([
                $payload['company_address_line1'] ?? null,
                $payload['company_address_line2'] ?? null,
                $payload['company_city'] ?? null,
                $payload['company_state'] ?? null,
                $payload['company_postcode'] ?? null,
                $payload['company_country'] ?? null,
            ], fn ($v) => $v !== null && trim((string) $v) !== '');
            $compiled = $legacyParts !== [] ? implode(', ', array_map(fn ($v) => trim((string) $v), $legacyParts)) : null;
            // Only overwrite legacy if sundered produced something, or if legacy was blank (allow clearing via sundered empty)
            if ($compiled !== null) {
                $payload['company_address'] = $compiled;
            } elseif (($payload['company_address'] ?? null) === null || trim((string) ($payload['company_address'] ?? '')) === '') {
                // sundered empty and legacy empty -> keep as null so Option A keeps old; do nothing
            }
            $request->merge(['settings' => $payload]);
        }

        $rules = ['settings' => ['present', 'array']];

        foreach ($payload as $key => $value) {
            if (isset(AppSettings::TYPED_KEYS[$key])) {
                $class = AppSettings::TYPED_KEYS[$key];
                $keyRules = $class::rules()[$key] ?? ['nullable', 'string', 'max:1000'];
            } else {
                $keyRules = ['nullable', 'string', 'max:1000'];
            }

            $rules["settings.{$key}"] = $keyRules;
        }

        try {
            $validated = $request->validate($rules);
        } catch (ValidationException $e) {
            // Preserve active tab on validation failure redirect.
            if ($tab) {
                $e->redirectTo = route('admin.settings.index', ['tab' => $tab]);
            }

            throw $e;
        }

        $values = $validated['settings'];

        // Blank encrypted fields were masked on GET (loadAll returns ''). Do not
        // overwrite stored secrets with empty string when form leaves them blank.
        foreach ($this->secretKeys() as $secret) {
            if (array_key_exists($secret, $values) && $values[$secret] === '') {
                unset($values[$secret]);
            }
        }

        // Per-tab scoping: when active_tab is present and not Save All, keep only
        // keys that belong to that tab (single source AppSettings::keyToSection).
        // Currency alias single win — default_currency (disabled, General) and
        // currency (Billing) never collide because disabled is not posted and
        // tab filter keeps them separate.
        if ($tab && ! $request->has('save_all')) {
            $keyToSection = AppSettings::keyToSection();
            $values = array_filter(
                $values,
                fn ($v, $k) => ($keyToSection[$k] ?? null) === $tab,
                ARRAY_FILTER_USE_BOTH
            );
        }

        // Snapshot old values before save for audit diff (typed vs legacy).
        $encryptedForAudit = $this->secretKeys();
        $oldSnapshot = [];
        foreach ($values as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (isset(AppSettings::TYPED_KEYS[$k])) {
                $class = AppSettings::TYPED_KEYS[$k];
                $old = app($class)->{$k};
                $oldSnapshot[$k] = $old === null ? '' : (string) $old;
            } else {
                $oldSnapshot[$k] = (string) (\DB::table('settings')->where('setting_key', $k)->value('setting_value') ?? '');
            }
        }

        $this->saveTyped($values);
        $this->saveUntyped($values);

        // Audit trail — reuse activity_log only (no settings_audits table). Compute diff masked secrets.
        $changes = [];
        foreach ($values as $k => $newRaw) {
            if ($newRaw === null) {
                continue;
            }
            $newStr = (string) $newRaw;
            $oldStr = $oldSnapshot[$k] ?? '';
            if ($newStr === $oldStr) {
                continue;
            }
            if (in_array($k, $encryptedForAudit, true)) {
                $changes[$k] = ['old' => $oldStr !== '' ? '***' : '', 'new' => '***'];
            } else {
                $changes[$k] = ['old' => $oldStr, 'new' => $newStr];
            }
        }
        if ($changes !== []) {
            try {
                $keyToSectionMap = AppSettings::keyToSection();
                $now = now();
                $userId = auth()->id();
                $ip = $request->ip();
                $ua = $request->userAgent();

                // Group changed keys by their owning section so each tab's "Last updated"
                // shows only its own keys, even when Save All is used.
                $bySection = [];
                foreach ($changes as $k => $diff) {
                    $sec = $keyToSectionMap[$k] ?? ($tab ?: 'general');
                    $bySection[$sec][$k] = $diff;
                }

                foreach ($bySection as $sec => $secChanges) {
                    $secKeys = array_keys($secChanges);
                    $properties = [
                        'section' => $sec,
                        'changed_keys' => $secKeys,
                        'changes' => $secChanges,
                    ];
                    \DB::table('activity_log')->insert([
                        'user_id' => $userId,
                        'customer_id' => null,
                        'action' => 'settings.updated',
                        'description' => 'Settings updated (' . $sec . '): changed ' . implode(', ', $secKeys),
                        'metadata' => json_encode($properties),
                        'properties' => json_encode($properties),
                        'event' => 'updated',
                        'subject_type' => 'setting',
                        'subject_id' => null,
                        'ip_address' => $ip,
                        'user_agent' => $ua,
                        'created_at' => $now,
                    ]);
                }
            } catch (\Throwable $e) {
                // Audit must never break settings save.
                report($e);
            }
        }

        if ($request->has('save_all')) {
            $message = 'All settings saved.';
        } elseif ($tab) {
            $label = collect(AppSettings::sections())->firstWhere('id', $tab)['label'] ?? ucfirst((string) $tab);
            $message = $label . ' saved.';
        } else {
            $message = 'Settings updated successfully.';
        }

        return redirect()->route('admin.settings.index', $tab ? ['tab' => $tab] : [])
            ->with('success', $message);
    }

    /**
     * Send a test email so an admin can verify mail delivery from the UI.
     *
     * Sends SYNCHRONOUSLY (not via the SendEmail job) — the whole point is to
     * surface the SMTP handshake result, and a queued job would report
     * "dispatched" long before the transport ever fails. The send is attempted
     * with the STORED settings, so what is tested is what was saved: unsaved
     * edits still sitting in the form are not used. Save the tab first.
     *
     * Transport selection is explicit in the response message so the result is
     * never misread: MailSettings::apply() is re-run here (rather than relying
     * on the copy applied at mail-manager resolution) so a test fired straight
     * after saving the tab uses the credentials just written; when smtp_host is
     * blank there is nothing to test and the app's default mailer from .env is
     * used, which the message says outright.
     *
     * Failures are reported, never thrown: a wrong password must show the SMTP
     * error inline, not a 500. Every attempt lands in the `emails` log with
     * status sent/failed, matching the SendEmail job's own logging.
     */
    public function sendTestEmail(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
        ]);

        $recipient = $validated['test_email'];

        // Short timeout: an unreachable host must not hang the admin's page for
        // the 30s a background send is allowed.
        $mailer = MailSettings::apply(forgetResolvedMailers: true, timeout: 10)
            ?? config('mail.default');
        $via = MailSettings::describe($mailer);

        $appName = AppSettings::get('company_name') ?: config('app.name');
        $subject = 'Test email from '.$appName;
        $body = "This is a test email sent from the {$appName} admin settings page.\n\n"
            ."Sent via: {$via}\n"
            .'Sent at: '.now()->toDateTimeString()."\n\n"
            .'If you received this, outgoing mail is working.';

        $fromAddress = AppSettings::get('mail_from_address') ?: null;
        $fromName = AppSettings::get('mail_from_name') ?: null;

        $log = EmailLog::create([
            'to_email' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'template_name' => 'settings.test',
            'status' => 'queued',
        ]);

        try {
            Mail::mailer($mailer)->raw($body, function ($message) use ($recipient, $subject, $fromAddress, $fromName) {
                $message->to($recipient)->subject($subject);

                if ($fromAddress) {
                    $message->from($fromAddress, $fromName);
                }
            });

            $log->update(['status' => 'sent']);

            return $this->testEmailResponse($request, true, "Test email sent to {$recipient} via {$via}.");
        } catch (\Throwable $e) {
            report($e);
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return $this->testEmailResponse($request, false, "Test email to {$recipient} failed via {$via}: ".$e->getMessage());
        }
    }

    /**
     * Test-email result — JSON for the inline AJAX button, flash redirect for
     * the no-JS form fallback. Failures are 200/ok:false, not an HTTP error:
     * a rejected SMTP login is a valid answer to "does mail work?".
     */
    private function testEmailResponse(Request $request, bool $ok, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message]);
        }

        return redirect()
            ->route('admin.settings.index', ['tab' => 'email'])
            ->with($ok ? 'success' : 'error', $message);
    }

    /**
     * Save typed keys grouped by their settings class.
     *
     * Clear-to-empty semantics — Option A (PINNED):
     * Empty text fields arrive as '' from the browser, are converted to null by
     * Laravel's ConvertEmptyStringsToNull middleware (empty→null), then filtered
     * via array_filter !== null (null→skip) so fill() never overwrites the stored
     * value — the old value is kept (skip→keeps old). To intentionally clear a
     * value, contact an admin (no __CLEAR__ sentinel). Encrypted secrets
     * (smtp_password, IntegrationSettings::casts()) have an additional
     * empty-string guard in update() before saveTyped; blank does not overwrite.
     */
    private function saveTyped(array $values): void
    {
        $byClass = [];

        foreach ($values as $key => $value) {
            if (isset(AppSettings::TYPED_KEYS[$key])) {
                $byClass[AppSettings::TYPED_KEYS[$key]][$key] = $value;
            }
        }

        foreach ($byClass as $class => $classValues) {
            $settings = app($class);

            // CRITICAL bool-coercion fix: PHP weak mode coerces ANY non-empty
            // string — including 'no' and '0' — to true when assigned to a
            // native typed bool property (public bool $cpanel_enabled), which
            // made every Yes/No toggle persist TRUE regardless of selection.
            // Convert incoming values for bool-typed properties to real
            // booleans BEFORE fill(); int/string properties are untouched.
            foreach ($this->boolPropertyNames($class) as $boolKey) {
                if (array_key_exists($boolKey, $classValues)) {
                    $classValues[$boolKey] = $this->toBool($classValues[$boolKey]);
                }
            }

            // Option A (PINNED): empty→null→skip→keeps old. Empty form fields arrive
            // as null (ConvertEmptyStringsToNull) and must never be assigned to
            // non-nullable typed properties; null-skip keeps stored/default value.
            // Do NOT implement __CLEAR__ sentinel — to clear, contact admin.
            // Bool-typed keys never reach this filter as null: they are real
            // booleans after the conversion above (blank bool input → false).
            $settings->fill(array_filter($classValues, fn ($value) => $value !== null));
            $settings->save();
        }
    }

    /**
     * Keys holding a secret: masked on read, and a blank submission keeps the
     * stored value rather than wiping it.
     *
     * Covers every EncryptedCast property on IntegrationSettings plus
     * smtp_password (plain, no casts). Department IMAP passwords live in
     * ticket_departments, not settings.
     *
     * @return list<string>
     */
    private function secretKeys(): array
    {
        return array_values(array_unique([
            ...array_keys(IntegrationSettings::casts()),
            'smtp_password',
        ]));
    }

    /**
     * Names of native bool-typed properties on a settings class.
     *
     * Reflection result is cached per class — the map is fixed at compile time
     * and saveTyped/loadAll call this for every request.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private function boolPropertyNames(string $class): array
    {
        static $cache = [];

        if (! isset($cache[$class])) {
            $cache[$class] = collect((new \ReflectionClass($class))->getProperties())
                ->filter(fn (\ReflectionProperty $property) => $property->getType() instanceof \ReflectionNamedType
                    && $property->getType()->getName() === 'bool')
                ->map(fn (\ReflectionProperty $property) => $property->getName())
                ->values()
                ->all();
        }

        return $cache[$class];
    }

    /**
     * Map an incoming form value onto a real boolean for a bool-typed property.
     *
     * Accepts every shape the Yes/No selects (and the `in:1,0,yes,no,true,false`
     * validation rules) can deliver:
     * 'yes','1','true',true,'on' => true; 'no','0','false',false,'',null => false.
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Save untyped keys to the legacy settings table.
     *
     * Option A (PINNED) applies here too: blank legacy fields arrive as null
     * (ConvertEmptyStringsToNull) and must KEEP the stored value — writing NULL
     * over it would silently wipe the row and, worse, bypass the audit diff
     * loop (which skips nulls), producing an unaudited change. Nulls are
     * skipped before updateOrInsert so they never reach the DB.
     */
    private function saveUntyped(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(AppSettings::TYPED_KEYS[$key])) {
                continue;
            }

            // Blank keeps old value — never persist a NULL overwrite.
            if ($value === null) {
                continue;
            }

            \DB::table('settings')->updateOrInsert(
                ['setting_key' => $key],
                ['setting_value' => $value, 'updated_at' => now()]
            );
        }
    }

    /**
     * Load every setting (legacy rows + typed groups) into one flat map.
     *
     * Corrupted-row resilience: a malformed JSON payload in settings_properties
     * (e.g. literal `null` for the non-nullable string cpanel_host) makes Spatie
     * hydration throw "Cannot assign null to property … of type string" during
     * app($class). Each class is resolved once inside its own try/catch — on
     * failure the exception is reported and that class falls back to its
     * property DEFAULTS so the settings page still renders every field; all
     * other groups are unaffected. Encrypted masking still runs last, after
     * any fallback, so secrets stay masked either way.
     */
    private function loadAll(): array
    {
        $rows = \DB::table('settings')->pluck('setting_value', 'setting_key')->toArray();

        // Group TYPED_KEYS by owning class (key order preserved) so each class
        // is hydrated at most once per request — still 1 query per group.
        $keysByClass = [];
        foreach (AppSettings::TYPED_KEYS as $key => $class) {
            $keysByClass[$class][] = $key;
        }

        foreach ($keysByClass as $class => $keys) {
            try {
                $settings = app($class);

                foreach ($keys as $key) {
                    $rows[$key] = $this->normalizeTypedValue($key, $class, $settings->{$key});
                }
            } catch (\Throwable $e) {
                // A corrupted stored payload must degrade to defaults, never 500 the page.
                report($e);

                $defaults = $this->typedDefaults($class);
                foreach ($keys as $key) {
                    $rows[$key] = $this->normalizeTypedValue($key, $class, $defaults[$key] ?? null);
                }
            }
        }

        // Mask encrypted secrets — never expose plaintext in HTML.
        // Covers EncryptedCast properties dynamically plus smtp_password.
        foreach ($this->secretKeys() as $secret) {
            if (array_key_exists($secret, $rows)) {
                $rows[$secret] = '';
            }
        }

        return $rows;
    }

    /**
     * Normalize a typed property value for rendering: bool-typed keys become
     * the Yes/No select option values ('yes'/'no'), everything else string-
     * casts with null → ''.
     */
    private function normalizeTypedValue(string $key, string $class, mixed $value): string
    {
        // Bool-typed keys render as Yes/No selects whose options are
        // 'yes'/'no'. (string) true would yield '1', which matches no
        // option — the select would show "No" even when the stored value
        // is true. Normalize to the option values instead.
        if (in_array($key, $this->boolPropertyNames($class), true)) {
            return $value ? 'yes' : 'no';
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * Property DEFAULTS of a settings class, filtered to its TYPED_KEYS entries.
     *
     * Fallback when hydration fails on a corrupted settings_properties row —
     * the page renders every field with the class's declared default instead
     * of crashing. Reflection result is cached per class (fixed at compile time).
     *
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function typedDefaults(string $class): array
    {
        static $cache = [];

        if (! isset($cache[$class])) {
            $keysOfClass = array_keys(array_filter(AppSettings::TYPED_KEYS, fn ($c) => $c === $class));
            $cache[$class] = array_intersect_key(
                (new \ReflectionClass($class))->getDefaultProperties(),
                array_flip($keysOfClass)
            );
        }

        return $cache[$class];
    }
}
