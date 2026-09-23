<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Quote;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Quotes in the global search registry.
 *
 * Matched on `quote_no` and the quote `subject`; gated by `invoices.view` —
 * NOT a quote-specific permission — because `routes/admin/billing.php` gates
 * the quote index/show screens with `permission:invoices.view`. The typeahead
 * row carries the number and the subject only, never the quote totals.
 */
class QuoteSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'quotes';
    }

    public function label(): string
    {
        return 'Quotes';
    }

    public function icon(): string
    {
        return 'bi bi-file-text';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function showRoute(): string
    {
        return 'admin.quotes.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.quotes.index';
    }

    protected function baseQuery(): Builder
    {
        return Quote::query();
    }

    protected function searchableColumns(): array
    {
        return ['quote_no', 'subject'];
    }

    public function toResult(Model $model): array
    {
        /** @var Quote $model */
        return $this->resultRow($model, (string) $model->quote_no, $model->subject);
    }
}
