<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Services\KbService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin knowledge-base management.
 *
 * Thin wrapper over {@see KbService}. Route contract: routes/admin/support.php
 * (index/create/store/show/edit/update/destroy) gated by the kb.view /
 * kb.create / kb.edit / kb.delete permissions.
 *
 * Categories are enum-backed (see KbService) — the article form picks from
 * {@see KbService::CATEGORIES}, and the index sidebar lists them with
 * published counts (category create/delete live on the Sanctum API, since the
 * web route file exposes no category endpoints).
 */
class KbController extends Controller
{
    private const PER_PAGE = 20;

    /** @var array<string, string> status value => label (migration 120050 enum) */
    public const STATUSES = [
        'draft' => 'Draft',
        'published' => 'Published',
    ];

    public function __construct(private readonly KbService $kb) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $category = $request->query('category');
        $status = $request->query('status');

        $articles = KnowledgeBase::query()
            ->when(in_array($category, array_keys(KbService::CATEGORIES), true), function ($query) use ($category) {
                $query->where('category', $category);
            })
            ->when(in_array($status, array_keys(self::STATUSES), true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('updated_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $categories = $this->kb->getCategories();
        $popular = $this->kb->getPopular(5);
        $statuses = self::STATUSES;

        $stats = [
            'total' => KnowledgeBase::count(),
            'published' => KnowledgeBase::where('status', KbService::STATUS_PUBLISHED)->count(),
            'drafts' => KnowledgeBase::where('status', KbService::STATUS_DRAFT)->count(),
            'categories' => count($categories),
        ];

        return view('admin.kb.index', compact('articles', 'search', 'category', 'status', 'categories', 'popular', 'statuses', 'stats'));
    }

    public function create(): View
    {
        $categories = $this->kb->getCategories();

        return view('admin.kb.create', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        try {
            $article = $this->kb->createArticle($validated);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not create article: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.kb.show', $article)
            ->with('success', "Article \"{$article->title}\" created.");
    }

    public function show(KnowledgeBase $article): View
    {
        $categories = $this->kb->getCategories();
        $popular = $this->kb->getPopular(5);

        return view('admin.kb.show', compact('article', 'categories', 'popular'));
    }

    public function edit(KnowledgeBase $article): View
    {
        $categories = $this->kb->getCategories();

        return view('admin.kb.edit', compact('article', 'categories'));
    }

    public function update(Request $request, KnowledgeBase $article): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        try {
            $this->kb->updateArticle($article, $validated);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['error' => 'Could not update article: '.$e->getMessage()]);
        }

        return redirect()
            ->route('admin.kb.show', $article)
            ->with('success', 'Article updated.');
    }

    public function destroy(KnowledgeBase $article): RedirectResponse
    {
        $article->delete();

        return redirect()
            ->route('admin.kb.index')
            ->with('success', "Article \"{$article->title}\" deleted.");
    }

    /**
     * Article validation rules (migration 120050 enums + service slug rules).
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'category' => ['required', Rule::in(array_keys(KbService::CATEGORIES))],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
        ];
    }
}
