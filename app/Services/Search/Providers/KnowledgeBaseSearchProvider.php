<?php

declare(strict_types=1);

namespace App\Services\Search\Providers;

use App\Models\KnowledgeBase;
use App\Services\KbService;
use App\Services\Search\AbstractSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Knowledge-base articles, matched on title and content.
 *
 * Draft visibility: `kb.view` admits viewers who may read published articles
 * only, so the default base query is published-only. A `kb.edit` holder may
 * manage drafts, so for them the status constraint is dropped. The decision is
 * made from the permission set GlobalSearchService passes into
 * `baseQueryFor()` — `hasPermission()` is never called here.
 *
 * The content body is matched but never rendered: the result subtitle is the
 * category label only, so a result can never leak article text into the
 * typeahead payload.
 */
class KnowledgeBaseSearchProvider extends AbstractSearchProvider
{
    public function key(): string
    {
        return 'kb';
    }

    public function label(): string
    {
        return 'Knowledge Base';
    }

    public function icon(): string
    {
        return 'bi bi-book';
    }

    public function permission(): string
    {
        return 'kb.view';
    }

    public function showRoute(): string
    {
        return 'admin.kb.show';
    }

    public function listRoute(): ?string
    {
        return 'admin.kb.index';
    }

    protected function baseQuery(): Builder
    {
        return KnowledgeBase::query()->where('status', KbService::STATUS_PUBLISHED);
    }

    /**
     * All statuses only for a viewer who may edit KB articles; everyone else
     * stays on the published-only base query.
     *
     * @param  list<string>  $permissionNames
     */
    protected function baseQueryFor(array $permissionNames): Builder
    {
        if (in_array('kb.edit', $permissionNames, true)) {
            return KnowledgeBase::query();
        }

        return $this->baseQuery();
    }

    protected function searchableColumns(): array
    {
        return ['title', 'content'];
    }

    protected function rankColumn(): string
    {
        return 'title';
    }

    public function toResult(Model $model): array
    {
        /** @var KnowledgeBase $model */
        $category = KbService::CATEGORIES[$model->category] ?? (string) $model->category;

        return $this->resultRow($model, (string) $model->title, $category);
    }
}
