<?php

/*
|--------------------------------------------------------------------------
| App-specific AdminLTE translations (default group)
|--------------------------------------------------------------------------
|
| These keys are used with the default-group syntax `__('adminlte.<key>')`
| in the auth views and client portal. The AdminLTE package registers its own
| lang directory as an extra default-group path (providing `email`, `password`,
| `sign_in`, ...), but it does not contain these app-specific keys — they only
| ever existed in the namespace-scoped file at
| `lang/vendor/adminlte/en/adminlte.php`, which is never consulted for
| `__('adminlte.<key>')` lookups. This file makes them resolvable.
*/

return [
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'two_factor_auth' => 'Two-Factor Authentication',
    'two_factor_code' => 'Authentication code',
    'two_factor_recovery_code' => 'Recovery code',
    'recover_with_backup_code' => 'Recover using backup code',
    'verify' => 'Verify',
    'customers' => 'Customers',
    'invoices' => 'Invoices',
    'tickets' => 'Tickets',
    'revenue' => 'Revenue',
    'client_portal' => 'Client Portal',
    'welcome_back' => 'Welcome back',
];