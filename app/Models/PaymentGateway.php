<?php

namespace App\Models;

use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'driver', 'mode', 'enabled', 'sort_order', 'credentials'])]
class PaymentGateway extends Model
{
    protected $table = 'payment_gateways';

    /**
     * Functional casts. Applied via the $casts property — Eloquent's getCasts()
     * does not resolve #[Cast(...)] class attributes in this Laravel build
     * (see the Invoice.php note).
     */
    protected $casts = [
        'credentials' => 'array',
        'enabled' => 'boolean',
    ];

    public function isOnline(): bool
    {
        return $this->driver()?->isOnline() ?? false;
    }

    public function isConfigured(): bool
    {
        return $this->driver()?->isConfigured() ?? false;
    }

    public function getCredential(string $key, mixed $default = null): mixed
    {
        $credentials = $this->credentials ?? [];

        return $credentials[$key] ?? $default;
    }

    /**
     * Resolve the payment driver through the PaymentGatewayManager. Guarded
     * with class_exists() so callers stay safe while the manager/driver
     * classes are being built (or a gateway's driver is not yet registered).
     */
    private function driver(): ?object
    {
        $managerClass = PaymentGatewayManager::class;

        if (! class_exists($managerClass)) {
            return null;
        }

        return app($managerClass)->driverFor($this);
    }
}
