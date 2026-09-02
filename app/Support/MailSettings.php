<?php

namespace App\Support;

use App\Settings\EmailSettings;
use Illuminate\Support\Facades\Mail;

/**
 * Applies the admin Email tab's saved mail settings over config('mail').
 *
 * Without this the SMTP fields on Settings > Email were decorative: every real
 * send (App\Jobs\SendEmail -> Mail::raw) used the mailer from .env, so a fully
 * configured SMTP server still delivered to the `log` driver.
 *
 * Wired up by MailSettingsServiceProvider, which defers the call to the first
 * resolution of the mail manager — requests that never send mail pay nothing.
 */
final class MailSettings
{
    /** Name of the on-the-fly mailer built from the Email tab's SMTP fields. */
    public const MAILER = 'settings_smtp';

    /**
     * Push the saved Email settings over config('mail').
     *
     * The stored SMTP server only takes over `mail.default` when smtp_host is
     * non-blank — an empty host means "nothing configured here", and .env must
     * keep winning, otherwise a blank Email tab would break mail entirely.
     * The From address/name are applied independently of the transport: they
     * are just as valid over a .env mailer as over the stored one.
     *
     * @param  bool  $forgetResolvedMailers  drop mailers already built this request, so a
     *                                       send right after a save uses the new credentials
     * @param  int  $timeout  socket timeout in seconds; the UI test button passes a short
     *                        one so an unreachable host cannot hang the page
     * @return string|null the mailer now backing sends, or null when SMTP is unconfigured
     */
    public static function apply(bool $forgetResolvedMailers = false, int $timeout = 30): ?string
    {
        // From address first: it lives in the legacy `settings` rows and stands
        // on its own, so a broken EmailSettings group must not cost us it.
        self::applyFromAddress();

        try {
            $email = app(EmailSettings::class);
            $host = trim($email->smtp_host);
        } catch (\Throwable $e) {
            // Settings unreadable — table missing mid-migration, corrupt payload,
            // no DB at all. Mail config must degrade to .env, never throw: this
            // runs on the way to every send, including queue workers.
            return null;
        }

        if ($host === '') {
            return null;
        }

        $port = $email->smtp_port > 0 ? $email->smtp_port : 587;

        config([
            // Laravel 13 dropped the `encryption` key — the transport is picked
            // from `scheme` (falling back to port 465 => smtps). Implicit TLS
            // ("ssl") must therefore map to smtps; "tls" stays smtp and lets
            // Symfony negotiate STARTTLS.
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'scheme' => (strtolower(trim($email->smtp_encryption)) === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
                'host' => $host,
                'port' => $port,
                'username' => $email->smtp_username !== '' ? $email->smtp_username : null,
                'password' => $email->smtp_password !== '' ? $email->smtp_password : null,
                'timeout' => $timeout,
            ],
            'mail.default' => self::MAILER,
        ]);

        if ($forgetResolvedMailers) {
            Mail::forgetMailers();
        }

        return self::MAILER;
    }

    /**
     * A one-line description of where mail currently goes, for admin-facing
     * result messages — the test button must never leave an admin guessing
     * which transport actually answered.
     */
    public static function describe(?string $mailer): string
    {
        if ($mailer === self::MAILER) {
            $email = app(EmailSettings::class);

            return 'SMTP settings ('.trim($email->smtp_host).':'.$email->smtp_port.')';
        }

        return 'the default "'.$mailer.'" mailer (SMTP Host is blank)';
    }

    /**
     * From address/name come from the legacy untyped `settings` rows that share
     * the Email tab (mail_from_address, mail_from_name). Blank values keep the
     * .env defaults rather than sending From an empty string.
     */
    private static function applyFromAddress(): void
    {
        try {
            $address = trim((string) AppSettings::get('mail_from_address'));
            $name = trim((string) AppSettings::get('mail_from_name'));
        } catch (\Throwable $e) {
            return;
        }

        if ($address !== '') {
            config(['mail.from.address' => $address]);
        }

        if ($name !== '') {
            config(['mail.from.name' => $name]);
        }
    }
}
