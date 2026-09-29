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
            // An explicit MailSettings::apply() call wins: it writes the mailer
            // config BEFORE forgetting the resolved manager, so when this hook
            // fires on the fresh resolution the config is already present and
            // must not be overwritten with the default arguments (which would
            // silently reset a caller-supplied timeout). Only supply the stored
            // configuration when nothing has supplied it yet in this process.
            if (config('mail.mailers.'.MailSettings::MAILER) !== null) {
                return;
            }

            MailSettings::apply();
        });
    }
}
