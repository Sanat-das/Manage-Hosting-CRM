<?php

/**
 * App-level overrides for the `adminlte` translation group.
 *
 * The package registers its language path twice: once as a plain loader path and
 * once as the `adminlte` namespace. The published override at
 * `lang/vendor/adminlte/en/adminlte.php` only merges into the NAMESPACE form,
 * while every view in this app calls the plain form — `__('adminlte.key')` —
 * which reads `lang/{locale}/{group}.php`. That directory did not exist, so any
 * key defined only in the vendor override fell through and Laravel echoed the raw
 * key into the page (e.g. the client dashboard title rendered as
 * "adminlte.client_portal").
 *
 * These are the eight keys that were silently leaking. The other five keys in the
 * vendor override (`customers`, `invoices`, `tickets`, `revenue`, `welcome_back`)
 * already exist in the package file, so they resolve without being repeated here.
 */
return [
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'two_factor_auth' => 'Two-Factor Authentication',
    'two_factor_code' => 'Authentication code',
    'two_factor_recovery_code' => 'Recovery code',
    'recover_with_backup_code' => 'Recover using backup code',
    'verify' => 'Verify',
    'client_portal' => 'Client Portal',
];
