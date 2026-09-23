<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\ServiceInstance;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Provisioned service instances.
 *
 * Matched on the service tag (the instance's strongest identifier, and the
 * default ranking column since it is listed first), the panel username and
 * the primary domain. Soft-deleted instances are excluded by the model's
 * global scope, which is the intended behaviour for search.
 */
class ServiceInstanceSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'service-instances';
    }

    public function label(): string
    {
        return 'Service Instances';
    }

    public function icon(): string
    {
        return 'bi bi-hdd-stack';
    }

    public function permission(): string
    {
        return 'service-instances.view';
    }

    public function showRoute(): string
    {
        return 'admin.service-instances.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.service-instances.index';
    }

    protected function baseQuery(): Builder
    {
        return ServiceInstance::query();
    }

    protected function searchableColumns(): array
    {
        return ['service_tag', 'username', 'domain'];
    }

    public function toResult(Model $model): array
    {
        /** @var ServiceInstance $model */
        return $this->resultRow(
            $model,
            (string) $model->service_tag,
            $model->domain ?: $model->username,
        );
    }
}
