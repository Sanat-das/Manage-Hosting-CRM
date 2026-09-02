<?php

namespace App\Providers;

use App\Support\MailSettings;
use Illuminate\Support\ServiceProvider;

/**
 * Makes the admin Email tab's saved SMTP settings the real mail transport.
 */
class MailSettingsServiceProvider extends ServiceProvider
{
    /**
     * Hooked to the mail manager's first resolution rather than boot() on
     * purpose:
     *
     *  - requests that never send mail never touch the settings tables;
     *  - the callback still runs before any config('mail') read, because
     *    MailManager only reads config inside mailer()/resolve(), i.e. after
     *    the instance itself has been built;
     *  - `php artisan migrate` on an empty database boots fine — nothing
     *    resolves the mailer, and MailSettings::apply() swallows failures
     *    anyway.
     *
     * Queue workers are long-running: they resolve the manager once, so a
     * changed SMTP setting only reaches them after `queue:restart`.
     */
    public function register(): void
    {
        $this->app->afterResolving('mail.manager', function () {
            MailSettings::apply();
        });
    }
}
