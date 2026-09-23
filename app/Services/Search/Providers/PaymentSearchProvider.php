<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Payment;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Payments in the global search registry.
 *
 * Matched on the gateway `transaction_id`; gated by `payments.view`, the same
 * permission `routes/admin/billing.php` gates the payment index/show screens
 * with. The typeahead row carries the transaction id and the status only —
 * never the paid amount or the payment method's money fields.
 */
class PaymentSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return 'Payments';
    }

    public function icon(): string
    {
        return 'bi bi-credit-card';
    }

    public function permission(): string
    {
        return 'payments.view';
    }

    public function showRoute(): string
    {
        return 'admin.payments.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.payments.index';
    }

    protected function baseQuery(): Builder
    {
        return Payment::query();
    }

    protected function searchableColumns(): array
    {
        return ['transaction_id'];
    }

    public function toResult(Model $model): array
    {
        /** @var Payment $model */
        return $this->resultRow(
            $model,
            (string) $model->transaction_id,
            ucfirst((string) $model->status),
        );
    }
}
