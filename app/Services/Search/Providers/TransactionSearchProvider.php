<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Transaction;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Transactions in the global search registry.
 *
 * Matched on `transaction_id`; gated by `invoices.view` — NOT `payments.view`
 * — because `routes/admin/billing.php` gates the transaction index/show
 * screens with `permission:invoices.view`. The typeahead row carries the
 * transaction id and the status only, never the amount/fee/net amount.
 */
class TransactionSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'transactions';
    }

    public function label(): string
    {
        return 'Transactions';
    }

    public function icon(): string
    {
        return 'bi bi-arrow-left-right';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function showRoute(): string
    {
        return 'admin.transactions.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.transactions.index';
    }

    protected function baseQuery(): Builder
    {
        return Transaction::query();
    }

    protected function searchableColumns(): array
    {
        return ['transaction_id'];
    }

    public function toResult(Model $model): array
    {
        /** @var Transaction $model */
        return $this->resultRow(
            $model,
            (string) $model->transaction_id,
            ucfirst((string) $model->status),
        );
    }
}
