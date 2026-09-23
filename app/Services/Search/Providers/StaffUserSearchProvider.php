<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\User;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Panel staff accounts.
 *
 * Client accounts are customers, not staff, so they are filtered out in SQL
 * (`whereNot('role', 'client')`) before the LIMIT — never after the fetch,
 * where they would consume result slots. The `users.role` column is NOT NULL
 * with a `client` default, so a row can never be excluded merely by being NULL.
 *
 * The email is the most identifier-like column, so it decides closeness
 * ranking rather than the default first column (which is also email here, kept
 * explicit so the intent survives a reordering of `searchableColumns()`).
 */
class StaffUserSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'staff';
    }

    public function label(): string
    {
        return 'Staff';
    }

    public function icon(): string
    {
        return 'bi bi-person-badge';
    }

    public function permission(): string
    {
        return 'users.view';
    }

    public function showRoute(): string
    {
        return 'admin.users.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.users.index';
    }

    protected function baseQuery(): Builder
    {
        return User::query()->whereNot('role', 'client');
    }

    protected function searchableColumns(): array
    {
        return ['email', 'first_name', 'last_name'];
    }

    protected function rankColumn(): string
    {
        return 'email';
    }

    public function toResult(Model $model): array
    {
        /** @var User $model */
        return $this->resultRow($model, (string) $model->full_name, $model->email);
    }
}
