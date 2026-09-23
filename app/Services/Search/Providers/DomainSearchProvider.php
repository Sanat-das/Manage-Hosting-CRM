<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Domain;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Registered domains, matched on the name alone.
 *
 * The registrar (falling back to the status) is the non-secret descriptor;
 * the auth code and DNS records are never part of the result payload.
 */
class DomainSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'domains';
    }

    public function label(): string
    {
        return 'Domains';
    }

    public function icon(): string
    {
        return 'bi bi-globe';
    }

    public function permission(): string
    {
        return 'domains.view';
    }

    public function showRoute(): string
    {
        return 'admin.domains.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.domains.index';
    }

    protected function baseQuery(): Builder
    {
        return Domain::query();
    }

    protected function searchableColumns(): array
    {
        return ['name'];
    }

    public function toResult(Model $model): array
    {
        /** @var Domain $model */
        return $this->resultRow(
            $model,
            (string) $model->name,
            $model->registrar ?: $model->status,
        );
    }
}
