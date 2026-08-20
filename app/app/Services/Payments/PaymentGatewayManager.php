<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayDriver;
use App\Models\PaymentGateway;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * PaymentGatewayManager — registry of payment gateways and their drivers.
 *
 * Resolves a gateway's driver through the container, injecting the gateway
 * itself so the driver can answer credential/config questions (isConfigured,
 * mode-based endpoints) without carrying any state of its own.
 */
class PaymentGatewayManager
{
    /**
     * All registered gateways, ordered by display sort order.
     */
    public function all(): Collection
    {
        return PaymentGateway::query()->orderBy('sort_order')->get();
    }

    /**
     * Gateways currently available for checkout (enabled), ordered by sort.
     */
    public function enabled(): Collection
    {
        return PaymentGateway::query()->where('enabled', true)->orderBy('sort_order')->get();
    }

    /**
     * Look up a single gateway by its unique code.
     */
    public function findByCode(string $code): ?PaymentGateway
    {
        return PaymentGateway::query()->where('code', $code)->first();
    }

    /**
     * Resolve the driver instance bound to the given gateway.
     *
     * @throws InvalidArgumentException when the gateway references an unknown
     *                                  driver class or one that is not a driver.
     */
    public function driverFor(PaymentGateway $gateway): PaymentGatewayDriver
    {
        $driverClass = $gateway->driver;

        if (! is_string($driverClass) || ! class_exists($driverClass)) {
            $label = is_string($driverClass) ? $driverClass : get_debug_type($driverClass);

            throw new InvalidArgumentException(sprintf('Unknown payment gateway driver [%s].', $label));
        }

        $driver = app($driverClass, ['gateway' => $gateway]);

        if (! $driver instanceof PaymentGatewayDriver) {
            throw new InvalidArgumentException(sprintf(
                'Payment gateway driver [%s] must implement %s.',
                $driverClass,
                PaymentGatewayDriver::class,
            ));
        }

        return $driver;
    }

    /**
     * Resolve a gateway's driver by code, or null when the gateway is missing.
     */
    public function driver(string $code): ?PaymentGatewayDriver
    {
        $gateway = $this->findByCode($code);

        return $gateway === null ? null : $this->driverFor($gateway);
    }

    /**
     * The codes of every registered gateway.
     *
     * @return list<string>
     */
    public function supportedCodes(): array
    {
        return $this->all()->pluck('code')->all();
    }
}
