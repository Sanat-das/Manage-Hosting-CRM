<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Services\KbService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Sanctum-protected knowledge-base REST API.
 *
 * Mirrors the reference /api/kb endpoints: article index (category + search
 * filters), store/show (show increments the view counter)/update/destroy, plus
 * category management (categories/storeCategory/deleteCategory) and the
 * popular-articles feed. Category create/delete operate against the fixed
 * enum-backed category set via {@see KbService}.
 */
class KbController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly KbService $kb) {}

    public function index(Request $request): JsonResponse
    {
        $category = $request->query('category');
        $search = trim((string) $request->query('search'));

        $articles = KnowledgeBase::query()
            ->where('status', KbService::STATUS_PUBLISHED)
            ->when(in_array($category, array_keys(KbService::CATEGORIES), true), function ($query) use ($category) {
                $query->where('category', $category);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('updated_at')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $articles->map(fn (KnowledgeBase $article) => $this->present($article)),
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page' => $articles->lastPage(),
                'per_page' => $articles->perPage(),
                'total' => $articles->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $article = $this->kb->createArticle($validated);

        return response()->json(['data' => $this->present($article)], 201);
    }

    public function show(KnowledgeBase $article): JsonResponse
    {
        $this->kb->incrementViews($article);

        return response()->json(['data' => $this->present($article->fresh(), true)]);
    }

    public function update(Request $request, KnowledgeBase $article): JsonResponse
    {
        $validated = $request->validate($this->rules(partial: true));

        $this->kb->updateArticle($article, $validated);

        return response()->json(['data' => $this->present($article->fresh())]);
    }

    public function destroy(KnowledgeBase $article): JsonResponse
    {
        $article->delete();

        return response()->json(['message' => 'Article deleted.'], 200);
    }

    public function popular(): JsonResponse
    {
        $articles = $this->kb->getPopular(5);

        return response()->json([
            'data' => $articles->map(fn (KnowledgeBase $article) => $this->present($article)),
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->kb->getCategories()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $category = $this->kb->createCategory($validated);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $category], 201);
    }

    public function deleteCategory(string $category): JsonResponse
    {
        try {
            $this->kb->deleteCategory($category);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Category deleted.'], 200);
    }

    /**
     * Article validation rules (migration 120050 enums + service slug rules).
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'category' => [$required, Rule::in(array_keys(KbService::CATEGORIES))],
            'title' => [$required, 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'content' => [$required, 'string'],
            'status' => [$required, Rule::in([KbService::STATUS_DRAFT, KbService::STATUS_PUBLISHED])],
        ];
    }

    /**
     * API resource shape.
     *
     * @return array<string, mixed>
     */
    private function present(KnowledgeBase $article, bool $detailed = false): array
    {
        $data = [
            'id' => $article->id,
            'category' => $article->category,
            'category_name' => KbService::CATEGORIES[$article->category] ?? Str::title(str_replace('_', ' ', $article->category)),
            'title' => $article->title,
            'slug' => $article->slug,
            'views' => $article->views,
            'helpful' => $article->helpful,
            'not_helpful' => $article->not_helpful,
            'status' => $article->status,
            'updated_at' => $article->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['content'] = $article->content;
            $data['created_at'] = $article->created_at?->toIso8601String();
        }

        return $data;
    }
}
