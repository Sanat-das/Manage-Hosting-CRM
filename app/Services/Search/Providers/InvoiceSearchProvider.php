<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Invoice;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Invoices in the global search registry.
 *
 * Matched on the unique `invoice_no`; gated by `invoices.view`, the same
 * permission `routes/admin/billing.php` gates the invoice index/show screens
 * with. The typeahead row carries the number and the status label only —
 * never any amount, tax or balance column.
 */
class InvoiceSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'Invoices';
    }

    public function icon(): string
    {
        return 'bi bi-receipt';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function showRoute(): string
    {
        return 'admin.invoices.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.invoices.index';
    }

    protected function baseQuery(): Builder
    {
        return Invoice::query();
    }

    protected function searchableColumns(): array
    {
        return ['invoice_no'];
    }

    public function toResult(Model $model): array
    {
        /** @var Invoice $model */
        return $this->resultRow($model, (string) $model->invoice_no, $model->status_label);
    }
}
