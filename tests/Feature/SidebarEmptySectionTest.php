<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Section headers pass `can: admin.panel` for every non-client role, so a narrow
 * role used to see labels like INFRASTRUCTURE with nothing beneath them. These
 * render the real dashboard and assert the invariant from sidebar.blade.php.
 */
class SidebarEmptySectionTest extends TestCase
{
    use RefreshDatabase;

    /** Exact rendered markup of a section header (menu-item.blade.php). */
    private function header(string $label): string
    {
        return '<li class="nav-header" role="heading" aria-level="2">'.$label.'</li>';
    }

    /**
     * Every seeded staff role holds dashboard.view and can reach the panel;
     * `client` is neither seeded nor allowed.
     *
     * @return array<string, array{string}>
     */
    public static function staffRoles(): array
    {
        return [
            'admin' => ['admin'],
            'support' => ['support'],
            'sales' => ['sales'],
            'marketing' => ['marketing'],
            'staff' => ['staff'],
            'editor' => ['editor'],
            'viewer' => ['viewer'],
        ];
    }

    /**
     * Fresh user holding $role via the pivot. `users.role` predates RBAC:
     * editor/viewer use the generic `staff` value and get permissions from the
     * pivot (as SidebarMenuGatingTest does).
     */
    private function actingAsPanelUser(string $role): void
    {
        $column = in_array($role, ['editor', 'viewer'], true) ? 'staff' : $role;

        $user = User::factory()->create(['role' => $column]);
        $user->roles()->sync([Role::where('name', $role)->firstOrFail()->id]);

        $this->actingAs($user);
    }

    /**
     * sidebar.blade.php's pruning loop keeps a header only when a visible
     * nav-item immediately follows it. Asserting that structure (not names)
     * covers every role.
     */
    private function assertNoEmptySectionHeaders(string $html, string $label): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $headers = $xpath->query(
            '//ul[@id="navigation"]/li[contains(concat(" ", normalize-space(@class), " "), " nav-header ") and @role="heading"]'
        );
        $this->assertGreaterThan(0, $headers->length, "[{$label}] expected at least one section header");

        foreach ($headers as $header) {
            $following = $xpath->query('following-sibling::li[1]', $header)->item(0);
            $this->assertNotNull($following, "[{$label}] header ".$header->textContent.' has no following item');
            $classes = preg_split('/\s+/', trim($following->getAttribute('class')));
            $this->assertContains('nav-item', $classes, "[{$label}] header ".$header->textContent.' must be followed by a nav-item');
        }
    }

    /**
     * Panel user whose bespoke role grants only Dashboard and Tickets, so the
     * seeded `staff` pivot the matrix relies on is left untouched.
     */
    private function actingAsNarrowPanelUser(): void
    {
        $role = Role::create([
            'name' => 'sidebar-empty-section-narrow',
            'label' => 'Narrow Sidebar Test',
        ]);
        $role->permissions()->sync(
            Permission::whereIn('name', ['dashboard.view', 'tickets.view'])->pluck('id')
        );

        $user = User::factory()->create(['role' => 'staff']);
        $user->roles()->sync([$role->id]);

        $this->actingAs($user);
    }

    #[DataProvider('staffRoles')]
    public function test_every_staff_role_renders_sidebar_without_empty_section_headers(string $role): void
    {
        $this->actingAsPanelUser($role);

        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertNoEmptySectionHeaders($html, $role);
    }

    public function test_empty_section_header_is_hidden_and_populated_section_stays(): void
    {
        $this->actingAsNarrowPanelUser();

        $response = $this->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee($this->header('INFRASTRUCTURE'), false);
        $response->assertSee($this->header('SUPPORT'), false);
    }

    public function test_full_permission_admin_still_sees_all_section_headers(): void
    {
        $this->actingAsPanelUser('admin');

        $response = $this->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee($this->header('SUPPORT'), false);
        $response->assertSee($this->header('INFRASTRUCTURE'), false);
    }
}
