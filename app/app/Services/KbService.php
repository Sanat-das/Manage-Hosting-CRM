<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Knowledge-base domain logic.
 *
 * Categories are backed by the `knowledge_base.category` ENUM column
 * (getting_started/hosting/domains/email/billing/technical) — the local
 * schema has no separate categories table, mirroring the reference where
 * KbModel::getCategories() returns a hardcoded map of the enum values.
 *
 * The reference controller calls getByCategory/getWithCategory/createCategory/
 * deleteCategory/getPopular but the reference model never implemented them
 * (confirmed missing — decisions.md #13), so they are implemented fresh here.
 * create/delete category therefore operate against the fixed enum set:
 * new values outside it are rejected (an ALTER would be required), and a
 * category holding articles cannot be deleted.
 */
class KbService
{
    /** @var array<string, string> enum value => label */
    public const CATEGORIES = [
        'getting_started' => 'Getting Started',
        'hosting' => 'Hosting',
        'domains' => 'Domains',
        'email' => 'Email',
        'billing' => 'Billing',
        'technical' => 'Technical',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /**
     * Category list with published article counts (reference getCategories).
     *
     * @return array<int, array{id: string, name: string, slug: string, article_count: int}>
     */
    public function getCategories(): array
    {
        $categories = [];

        foreach (self::CATEGORIES as $value => $label) {
            $categories[] = [
                'id' => $value,
                'name' => $label,
                'slug' => $value,
                'article_count' => KnowledgeBase::query()
                    ->where('category', $value)
                    ->where('status', self::STATUS_PUBLISHED)
                    ->count(),
            ];
        }

        return $categories;
    }

    /**
     * Published articles in a category, paginated (reference getByCategory).
     */
    public function getByCategory(string $category, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return KnowledgeBase::query()
            ->where('category', $category)
            ->where('status', self::STATUS_PUBLISHED)
            ->orderByDesc('updated_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Single article joined with its category label (reference getWithCategory).
     *
     * @return array<string, mixed>|null
     */
    public function getWithCategory(int $id): ?array
    {
        $article = KnowledgeBase::find($id);

        if ($article === null) {
            return null;
        }

        $data = $article->toArray();
        $data['category_name'] = self::CATEGORIES[$article->category] ?? Str::title(str_replace('_', ' ', $article->category));
        $data['category_slug'] = $article->category;

        return $data;
    }

    /**
     * Register a category. Categories are enum-backed in the local schema, so
     * only the configured values are accepted; anything else would require an
     * ALTER TABLE (rejected — see class docblock).
     *
     * @param  array<string, mixed>  $data  name, slug, description, sort_order
     * @return array{id: string, name: string, slug: string, article_count: int}
     *
     * @throws DomainException when the category is not part of the enum set
     */
    public function createCategory(array $data): array
    {
        $slug = isset($data['slug']) && trim((string) $data['slug']) !== ''
            ? Str::slug((string) $data['slug'])
            : Str::slug((string) ($data['name'] ?? ''));

        if ($slug === '' || ! isset(self::CATEGORIES[$slug])) {
            throw new DomainException(sprintf(
                'Category "%s" cannot be created — the knowledge_base.category column is an ENUM limited to: %s.',
                (string) ($data['name'] ?? $slug),
                implode(', ', array_keys(self::CATEGORIES)),
            ));
        }

        return [
            'id' => $slug,
            'name' => self::CATEGORIES[$slug],
            'slug' => $slug,
            'article_count' => 0,
        ];
    }

    /**
     * Delete a category. Enum values cannot be dropped from the column, but a
     * category holding articles is blocked (delete guard, mirroring the
     * product delete guard decision). An empty category simply has no
     * articles to clean up.
     *
     * @throws DomainException when the category still contains articles
     */
    public function deleteCategory(string $category): bool
    {
        $count = KnowledgeBase::query()->where('category', $category)->count();

        if ($count > 0) {
            throw new DomainException(sprintf(
                'Category "%s" cannot be deleted — %d article(s) still reference it.',
                $category,
                $count,
            ));
        }

        return true;
    }

    /**
     * Most-viewed published articles (reference getPopular).
     */
    public function getPopular(int $limit = 5): Collection
    {
        return KnowledgeBase::query()
            ->where('status', self::STATUS_PUBLISHED)
            ->orderByDesc('views')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Search published articles by title or content (reference search).
     */
    public function search(string $query, int $limit = 10): Collection
    {
        $like = '%'.$query.'%';

        return KnowledgeBase::query()
            ->where('status', self::STATUS_PUBLISHED)
            ->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('content', 'like', $like);
            })
            ->orderByDesc('views')
            ->limit($limit)
            ->get();
    }

    /**
     * Create an article. Slug is derived from the title unless provided, and
     * made unique against existing slugs.
     *
     * @param  array<string, mixed>  $data  category, title, content, status, slug
     */
    public function createArticle(array $data): KnowledgeBase
    {
        $slug = isset($data['slug']) && trim((string) $data['slug']) !== ''
            ? Str::slug((string) $data['slug'])
            : Str::slug((string) $data['title']);

        return KnowledgeBase::create([
            'category' => $data['category'],
            'title' => $data['title'],
            'slug' => $this->uniqueSlug($slug),
            'content' => $data['content'],
            'views' => 0,
            'helpful' => 0,
            'not_helpful' => 0,
            'status' => $data['status'] ?? self::STATUS_DRAFT,
        ]);
    }

    /**
     * Update an article, regenerating the slug when the title changes and no
     * explicit slug is supplied.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateArticle(KnowledgeBase $article, array $data): KnowledgeBase
    {
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $data['slug'] = $this->uniqueSlug(Str::slug((string) $data['slug']), $article->id);
        } elseif (isset($data['title']) && $data['title'] !== $article->title) {
            $data['slug'] = $this->uniqueSlug(Str::slug((string) $data['title']), $article->id);
        }

        $article->update($data);

        return $article;
    }

    public function incrementViews(KnowledgeBase $article): void
    {
        $article->increment('views');
    }

    /**
     * Make a slug unique by appending -2, -3, ... when it collides.
     */
    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = $slug !== '' ? $slug : 'article';
        $candidate = $base;
        $suffix = 2;

        while (KnowledgeBase::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
