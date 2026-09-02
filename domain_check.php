<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Domain;
use App\Models\Order;

echo "=== ALL ORDERS (id vs order_no vs customer) ===\n";
foreach (Order::orderBy('id')->get() as $o) {
    echo 'id '.$o->id.' | '.$o->order_no.' | customer '.($o->customer_id ?? 'null')."\n";
}

echo "\n=== DOMAINS WITH ORDER_NO RESOLVED ===\n";
foreach (Domain::all() as $d) {
    $order = $d->order;
    echo $d->name.' | order_id '.($d->order_id ?? 'null').' | order_no '.($order->order_no ?? 'NO ORDER')."\n";
}
