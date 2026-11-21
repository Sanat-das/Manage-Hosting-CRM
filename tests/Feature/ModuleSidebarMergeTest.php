<?php

namespace Tests\Feature;

use App\Providers\ModuleServiceProvider;
use Tests\TestCase;

/**
 * Module links must be inserted ahead of the top-level CLIENT PORTAL header.
 *
 * Appending them used to place a module link inside the client section of the
 * sidebar for an admin. ModuleServiceProvider::mergeSidebarItems() is the
 * exact method boot() calls, so these assertions cover the real merge.
 */
class ModuleSidebarMergeTest extends TestCase
{
    private function provider(): ModuleServiceProvider
    {
        return new ModuleServiceProvider($this->app);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function menu(): array
    {
        return [
            ['text' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'bi bi-speedometer2', 'can' => 'admin.panel'],
            ['header' => 'CLIENT PORTAL', 'role' => 'client'],
            ['text' => 'Portal Dashboard', 'route' => 'client.dashboard', 'icon' => 'bi bi-speedometer2', 'role' => 'client'],
        ];
    }

    /**
     * @return array<int, array{text: string, route: string, icon: string}>
     */
    private function moduleItems(): array
    {
        return [
            ['text' => 'Module Link', 'route' => 'module.index', 'icon' => 'bi bi-puzzle'],
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $menu
     */
    private function positionOf(array $menu, string $key, string $value): int|string|false
    {
        foreach ($menu as $index => $item) {
            if (($item[$key] ?? null) === $value) {
                return $index;
            }
        }

        return false;
    }

    public function test_module_items_are_inserted_before_the_client_portal_header(): void
    {
        $merged = $this->provider()->mergeSidebarItems($this->menu(), $this->moduleItems());

        $modulePosition = $this->positionOf($merged, 'text', 'Module Link');
        $clientPosition = $this->positionOf($merged, 'header', 'CLIENT PORTAL');

        $this->assertNotFalse($modulePosition, 'Module item must be present in the merged menu.');
        $this->assertNotFalse($clientPosition, 'CLIENT PORTAL header must be present in the merged menu.');
        $this->assertLessThan($clientPosition, $modulePosition, 'Module item must precede the CLIENT PORTAL header.');

        $labels = array_map(
            static fn (array $item): string => $item['header'] ?? $item['text'] ?? '',
            $merged
        );
        $this->assertSame(['Dashboard', 'Module Link', 'CLIENT PORTAL', 'Portal Dashboard'], $labels);
    }

    public function test_empty_module_items_leave_the_menu_untouched(): void
    {
        $menu = $this->menu();

        $this->assertSame($menu, $this->provider()->mergeSidebarItems($menu, []));
    }

    public function test_module_items_are_appended_when_the_client_header_is_absent(): void
    {
        $menu = [
            ['text' => 'Dashboard', 'route' => 'admin.dashboard', 'can' => 'admin.panel'],
        ];

        $merged = $this->provider()->mergeSidebarItems($menu, $this->moduleItems());

        $this->assertSame(['Dashboard', 'Module Link'], array_column($merged, 'text'));
    }

    public function test_string_keys_in_the_menu_are_preserved(): void
    {
        $menu = [
            'dashboard' => ['text' => 'Dashboard', 'route' => 'admin.dashboard'],
            'client' => ['header' => 'CLIENT PORTAL', 'role' => 'client'],
        ];

        $merged = $this->provider()->mergeSidebarItems($menu, $this->moduleItems());

        $this->assertArrayHasKey('dashboard', $merged);
        $this->assertArrayHasKey('client', $merged);
        $this->assertSame('Dashboard', $merged['dashboard']['text']);
        $this->assertSame('CLIENT PORTAL', $merged['client']['header']);

        $labels = array_map(
            static fn (array $item): string => $item['header'] ?? $item['text'] ?? '',
            $merged
        );
        $this->assertSame(['Dashboard', 'Module Link', 'CLIENT PORTAL'], array_values($labels));
    }
}
