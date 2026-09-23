<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\Server;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Infrastructure servers, gated by `hosting.view` — the same permission the
 * server index/show routes use. API credentials and connection secrets are
 * never part of the result payload; the subtitle is the non-secret address
 * plus the server type.
 */
class ServerSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'servers';
    }

    public function label(): string
    {
        return 'Servers';
    }

    public function icon(): string
    {
        return 'bi bi-hdd-rack';
    }

    public function permission(): string
    {
        return 'hosting.view';
    }

    public function showRoute(): string
    {
        return 'admin.servers.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.servers.index';
    }

    protected function baseQuery(): Builder
    {
        return Server::query();
    }

    protected function searchableColumns(): array
    {
        return ['name', 'ip_address', 'server_type'];
    }

    public function toResult(Model $model): array
    {
        /** @var Server $model */
        $subtitle = trim(implode(' · ', array_filter([$model->ip_address, $model->server_type])));

        return $this->resultRow($model, (string) $model->name, $subtitle !== '' ? $subtitle : null);
    }
}
