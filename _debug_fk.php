<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = [
    'invoices', 'invoice_items', 'orders', 'order_items', 'order_status_history',
    'hosting_accounts', 'ip_addresses', 'ip_allocation_history',
    'domains', 'transactions', 'payments', 'activity_logs', 'audit_logs',
];
foreach ($tables as $t) {
    if (Schema::hasTable($t)) {
        echo $t . ': ' . DB::table($t)->count() . PHP_EOL;
    }
}
echo '--- FK constraints on hosting_accounts / orders / invoices ---' . PHP_EOL;
foreach (['hosting_accounts', 'orders', 'invoices'] as $t) {
    $fks = DB::select("SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [$t]);
    foreach ($fks as $fk) {
        echo $t . '.' . $fk->COLUMN_NAME . ' -> ' . $fk->REFERENCED_TABLE_NAME . '.' . $fk->REFERENCED_COLUMN_NAME . ' (' . $fk->CONSTRAINT_NAME . ')' . PHP_EOL;
    }
}
echo '--- tables referencing orders/hosting_accounts/invoices ---' . PHP_EOL;
$refs = DB::select("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IN ('orders','hosting_accounts','invoices','order_items','invoice_items') AND TABLE_NAME NOT IN ('orders','hosting_accounts','invoices','order_items','invoice_items')");
foreach ($refs as $r) {
    echo $r->TABLE_NAME . '.' . $r->COLUMN_NAME . ' -> ' . $r->REFERENCED_TABLE_NAME . PHP_EOL;
}