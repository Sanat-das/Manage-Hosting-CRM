<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Customer;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers, matched on their company and on the linked user account.
 *
 * The company is the display label; the user's email is the subtitle, because
 * that is what a staff member actually recognises a customer by. Both are
 * eager loaded in `baseQuery()` so rendering a page of results costs one
 * query, not one per row.
 */
class CustomerSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers';
    }

    public function icon(): string
    {
        return 'bi bi-people';
    }

    public function permission(): string
    {
        return 'customers.view';
    }

    public function showRoute(): string
    {
        return 'admin.customers.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.customers.index';
    }

    protected function baseQuery(): Builder
    {
        return Customer::query()->with('user');
    }

    protected function searchableColumns(): array
    {
        return ['company'];
    }

    protected function searchableRelations(): array
    {
        return ['user' => ['email', 'first_name', 'last_name']];
    }

    public function toResult(Model $model): array
    {
        /** @var Customer $model */
        return $this->resultRow($model, (string) $model->company, $model->user?->email);
    }
}
