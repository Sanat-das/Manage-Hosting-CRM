<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\HostingAccount;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Hosting accounts.
 *
 * `host_name` is the account's identifier across the application (admin and
 * client UI, asset links — see the model docblock), so it is both the result
 * label and the ranking column, even though `username` is listed first for
 * matching. The subtitle never carries the legacy panel password.
 */
class HostingAccountSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'hosting';
    }

    public function label(): string
    {
        return 'Hosting Accounts';
    }

    public function icon(): string
    {
        return 'bi bi-hdd-network';
    }

    public function permission(): string
    {
        return 'hosting.view';
    }

    public function showRoute(): string
    {
        return 'admin.hosting.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.hosting.index';
    }

    protected function baseQuery(): Builder
    {
        return HostingAccount::query();
    }

    protected function searchableColumns(): array
    {
        return ['username', 'domain', 'host_name'];
    }

    protected function rankColumn(): string
    {
        return 'host_name';
    }

    public function toResult(Model $model): array
    {
        /** @var HostingAccount $model */
        return $this->resultRow(
            $model,
            (string) $model->host_name,
            $model->domain ?: $model->username,
        );
    }
}
