<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client portal — knowledge base browsing and search.
 */
class KbController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $articles = KnowledgeBase::query()
            ->where('status', 'published')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });
            })
            ->gridSort([
                'title' => 'title',
                'category' => 'category',
                'views' => 'views',
            ])
            ->orderByDesc('views')
            ->paginate(15)
            ->withQueryString();

        $categories = KnowledgeBase::where('status', 'published')
            ->selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category');

        return view('client.kb.index', compact('articles', 'search', 'categories'));
    }

    public function show(string $slug): View
    {
        $article = KnowledgeBase::where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $article->increment('views');

        return view('client.kb.show', compact('article'));
    }
}
