<?php

namespace App\Http\Controllers\Admin;

use App\Dashboard\DashboardData;
use App\Dashboard\WidgetDefinition;
use App\Dashboard\WidgetRegistry;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Admin panel landing page.
     *
     * Renders the user's saved widget grid (all widgets on first visit) plus
     * the catalogue of widgets still available to add.
     */
    public function index(): View
    {
        $registry = app(WidgetRegistry::class);
        $user = auth()->user();

        $saved = DashboardWidget::where('user_id', $user->id)
            ->orderBy('sort_order')
            ->get()
            ->keyBy('widget_key');

        if ($saved->isEmpty()) {
            $enabledKeys = collect($registry->keys());
        } else {
            $enabledKeys = $saved
                ->filter(fn (DashboardWidget $widget): bool => $widget->enabled)
                ->sortBy('sort_order')
                ->pluck('widget_key');
        }

        $widgets = $enabledKeys
            ->map(fn (string $key): ?WidgetDefinition => $registry->get($key))
            ->filter()
            ->map(fn (WidgetDefinition $definition): array => [
                'key' => $definition->key,
                'title' => $definition->title,
                'icon' => $definition->icon,
                'size' => $definition->size,
                'view' => $definition->view,
                'data' => app(DashboardData::class)->{$definition->provider}(),
            ])
            ->values();

        $available = collect($registry->all())
            ->reject(fn (WidgetDefinition $definition): bool => $enabledKeys->contains($definition->key))
            ->map(fn (WidgetDefinition $definition): array => [
                'key' => $definition->key,
                'title' => $definition->title,
                'icon' => $definition->icon,
                'description' => $definition->description,
            ])
            ->values();

        return view('admin.dashboard.index', compact('widgets', 'available'));
    }

    /**
     * Persist the widget grid snapshot (order + enabled flags) for the user.
     *
     * The frontend collects the visible widgets in DOM order and POSTs them;
     * sort_order indexes must align with that snapshot. Widgets not present in
     * the payload are disabled rather than deleted, so a later snapshot can
     * re-enable them without losing their saved settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'widgets' => ['required', 'array', 'min:1'],
            'widgets.*.key' => ['required', 'string'],
            'widgets.*.enabled' => ['sometimes', 'boolean'],
        ]);

        $registry = app(WidgetRegistry::class);
        $user = $request->user();
        $order = 0;

        foreach ($validated['widgets'] as $entry) {
            if (! $registry->has($entry['key'])) {
                continue;
            }

            DashboardWidget::updateOrCreate(
                ['user_id' => $user->id, 'widget_key' => $entry['key']],
                ['sort_order' => $order++, 'enabled' => $entry['enabled'] ?? true],
            );
        }

        $presentKeys = collect($validated['widgets'])->pluck('key');

        DashboardWidget::where('user_id', $user->id)
            ->whereNotIn('widget_key', $presentKeys)
            ->update(['enabled' => false]);

        return response()->json(['ok' => true]);
    }
}