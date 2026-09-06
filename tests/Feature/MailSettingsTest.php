<?php

namespace Tests\Feature;

use App\Settings\EmailSettings;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The saved Email tab settings become the real mail transport
 * (App\Support\MailSettings + MailSettingsServiceProvider).
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // AppSettings::$cache is a static that outlives a single test — the
        // legacy `settings` rows read for From address/name would otherwise be
        // whatever an earlier test in this process left behind.
        $prop = new \ReflectionProperty(\App\Support\AppSettings::class, 'cache');
        $prop->setValue(null, null);
    }

    public function test_saved_smtp_host_takes_over_the_env_mailer_when_the_manager_resolves(): void
    {
        config(['mail.default' => 'log']);
        $this->storeSmtp(['smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_username' => 'postmaster', 'smtp_password' => 's3cret']);

        $this->resolveMailManager();

        $this->assertSame(MailSettings::MAILER, config('mail.default'));
        $this->assertSame('smtp', config('mail.mailers.settings_smtp.scheme'));
        $this->assertSame('smtp.example.com', config('mail.mailers.settings_smtp.host'));
        $this->assertSame(587, config('mail.mailers.settings_smtp.port'));
        $this->assertSame('postmaster', config('mail.mailers.settings_smtp.username'));
        $this->assertSame('s3cret', config('mail.mailers.settings_smtp.password'));
    }

    public function test_blank_smtp_host_leaves_the_env_mailer_alone(): void
    {
        config(['mail.default' => 'log']);

        $this->resolveMailManager();

        $this->assertSame('log', config('mail.default'));
        $this->assertNull(MailSettings::apply());
    }

    public function test_ssl_encryption_selects_the_smtps_scheme(): void
    {
        $this->storeSmtp(['smtp_host' => 'smtp.example.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl']);

        $this->assertSame(MailSettings::MAILER, MailSettings::apply());
        $this->assertSame('smtps', config('mail.mailers.settings_smtp.scheme'));
    }

    public function test_tls_on_the_submission_port_stays_on_starttls(): void
    {
        $this->storeSmtp(['smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls']);

        MailSettings::apply();

        $this->assertSame('smtp', config('mail.mailers.settings_smtp.scheme'));
    }

    public function test_saved_from_address_and_name_are_applied_over_env(): void
    {
        config(['mail.from.address' => 'hello@example.com', 'mail.from.name' => 'Example']);
        DB::table('settings')->insert([
            ['setting_key' => 'mail_from_address', 'setting_value' => 'billing@hosting.test'],
            ['setting_key' => 'mail_from_name', 'setting_value' => 'Hosting Billing'],
        ]);

        MailSettings::apply();

        $this->assertSame('billing@hosting.test', config('mail.from.address'));
        $this->assertSame('Hosting Billing', config('mail.from.name'));
    }

    public function test_blank_from_settings_keep_the_env_defaults(): void
    {
        config(['mail.from.address' => 'hello@example.com', 'mail.from.name' => 'Example']);

        MailSettings::apply();

        $this->assertSame('hello@example.com', config('mail.from.address'));
        $this->assertSame('Example', config('mail.from.name'));
    }

    public function test_unreadable_settings_degrade_to_env_instead_of_throwing(): void
    {
        config(['mail.default' => 'log']);

        // Stands in for a missing settings_properties table or a payload that
        // will not hydrate — dropping the table for real leaves RefreshDatabase
        // with a broken transaction and poisons every later test in the process.
        app()->forgetInstance(EmailSettings::class);
        app()->forgetScopedInstances();
        app()->bind(EmailSettings::class, function () {
            throw new \RuntimeException('settings unavailable');
        });

        $this->assertNull(MailSettings::apply());
        $this->assertSame('log', config('mail.default'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function storeSmtp(array $values): void
    {
        $settings = app(EmailSettings::class);
        $settings->fill($values);
        $settings->save();
    }

    /**
     * Rebuild the mail manager so the provider's afterResolving hook fires the
     * way it does on a fresh request.
     */
    private function resolveMailManager(): void
    {
        app()->forgetInstance('mailer');
        app()->forgetInstance('mail.manager');
        app('mail.manager');
    }
}
