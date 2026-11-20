<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Tag;
use App\Services\Inventory\InventoryChangeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TagController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = Tag::withCount('assets');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tags = $query
            ->gridSort([
                'name' => 'name',
                'slug' => 'slug',
                'color' => 'color',
            ])
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('admin.tags.index', compact('tags', 'search'));
    }

    public function create(): View
    {
        return view('admin.tags.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->prepareSlug($request);
        $request->merge(['color' => $request->filled('color') ? $request->input('color') : '#6c757d']);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('tags', 'name')],
            'slug' => ['nullable', 'alpha_dash', 'max:255', Rule::unique('tags', 'slug')],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        Tag::create($validated);

        return redirect()->route('admin.tags.index')->with('success', 'Tag created.');
    }

    public function edit(Tag $tag): View
    {
        return view('admin.tags.edit', compact('tag'));
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $this->prepareSlug($request, $tag);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('tags', 'name')->ignore($tag->id)],
            'slug' => ['nullable', 'alpha_dash', 'max:255', Rule::unique('tags', 'slug')->ignore($tag->id)],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $tag->update($validated);

        return redirect()->route('admin.tags.index')->with('success', 'Tag updated.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $changeLog = app(InventoryChangeLogger::class);

        DB::transaction(function () use ($tag, $changeLog): void {
            $tagId = (int) $tag->id;
            $assetIds = DB::table('taggables')
                ->where('taggable_type', (new Device)->getMorphClass())
                ->where('tag_id', $tagId)
                ->lockForUpdate()
                ->pluck('taggable_id');
            $assets = Device::withTrashed()->whereKey($assetIds)->get(['id']);

            $snapshots = [];
            foreach ($assets as $asset) {
                $snapshots[$asset->id] = array_map('intval', $asset->tags()->pluck('tags.id')->all());
            }

            $allTagIds = collect($snapshots)->flatten()->unique()->values()->all();
            $namesById = $allTagIds === []
                ? []
                : Tag::whereIn('id', $allTagIds)->pluck('name', 'id')->all();

            $tag->delete();

            foreach ($snapshots as $assetId => $before) {
                $asset = Device::withTrashed()->find($assetId);
                if ($asset === null) {
                    continue;
                }

                $after = array_values(array_diff($before, [$tagId]));
                $written = $changeLog->recordTagChange($asset, $before, $after, $namesById);

                if (! $written) {
                    throw new \RuntimeException("Audit log write failed for asset {$assetId}; the tag was not deleted.");
                }
            }
        });

        return redirect()->route('admin.tags.index')->with('success', 'Tag deleted.');
    }

    /**
     * Fill a blank slug from the tag name before validation so the unique and
     * alpha_dash rules apply to the value that is actually stored.
     */
    private function prepareSlug(Request $request, ?Tag $tag = null): void
    {
        $slug = trim((string) $request->input('slug'));

        if ($slug === '') {
            $slug = Str::slug((string) ($request->input('name') ?? $tag?->name ?? ''));
        }

        $request->merge(['slug' => $slug]);
    }
}
