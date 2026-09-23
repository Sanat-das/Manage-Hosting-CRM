<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\SslCertificate;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SSL certificates.
 *
 * There is no `ssl.*` permission — the certificate index/show routes are
 * gated by `hosting.view` (routes/admin/ssl.php), so this provider uses the
 * same gate rather than inventing a permission nothing grants.
 */
class SslCertificateSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'ssl';
    }

    public function label(): string
    {
        return 'SSL Certificates';
    }

    public function icon(): string
    {
        return 'bi bi-shield-lock';
    }

    public function permission(): string
    {
        return 'hosting.view';
    }

    public function showRoute(): string
    {
        return 'admin.ssl.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.ssl.index';
    }

    protected function baseQuery(): Builder
    {
        return SslCertificate::query();
    }

    protected function searchableColumns(): array
    {
        return ['domain_name', 'provider'];
    }

    public function toResult(Model $model): array
    {
        /** @var SslCertificate $model */
        return $this->resultRow(
            $model,
            (string) $model->domain_name,
            $model->provider ?: $model->certificate_type,
        );
    }
}
