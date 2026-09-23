<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\KnowledgeBase;
use App\Models\Product;
use App\Models\Ticket;
use App\Services\Search\GlobalSearchService;
use App\Services\Search\Providers\CatalogProductSearchProvider;
use App\Services\Search\Providers\KnowledgeBaseSearchProvider;
use App\Services\Search\Providers\ProductSearchProvider;
use App\Services\Search\Providers\TicketSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * The support/catalog provider lane: tickets, KB articles, catalog products and
 * products.
 *
 * Every case drives the REAL providers through GlobalSearchService::groups()
 * so the viewer's permission array flows through queryFor()/baseQueryFor() —
 * the KB draft rule in particular is only provable end-to-end, not on a mock.
 */
class SupportCatalogSearchProvidersTest extends TestCase
{
    use CreatesChatUsers {
        chatUser as panelUserWith;
    }
    use RefreshDatabase;

    /** @var list<class-string> */
    private const SUPPORT_PROVIDERS = [
        TicketSearchProvider::class,
        KnowledgeBaseSearchProvider::class,
        CatalogProductSearchProvider::class,
        ProductSearchProvider::class,
    ];

    // --- KB draft visibility ----------------------------------------------

    public function test_a_draft_kb_article_is_invisible_to_a_kb_view_only_viewer(): void
    {
        $this->makeArticle('draft', 'Acme draft runbook', 'Draft body that must never leak');
        $user = $this->panelUserWith('kb.view');

        // The real registry: the permission array is the only reason the KB
        // provider runs, and it must scope itself to published rows.
        $service = new GlobalSearchService;

        $groups = $service->groups($service->permissionNames($user), 'acme', 5);

        $this->assertSame(
            [],
            $groups,
            'A kb.view-only viewer must get no KB group for a draft-only match.',
        );
    }

    public function test_a_draft_kb_article_is_visible_to_a_kb_edit_holder(): void
    {
        $article = $this->makeArticle('draft', 'Acme draft runbook', 'Draft body');
        $user = $this->panelUserWith('kb.view', 'kb.edit');

        $service = new GlobalSearchService;

        $groups = $service->groups($service->permissionNames($user), 'acme', 5);

        $this->assertCount(1, $groups);
        $this->assertSame('kb', $groups[0]['key']);
        $this->assertCount(1, $groups[0]['results']);
        $this->assertSame('Acme draft runbook', $groups[0]['results'][0]['label']);
        $this->assertSame(route('admin.kb.show', $article), $groups[0]['results'][0]['url']);
        $this->assertSame(route('admin.kb.index', ['search' => 'acme']), $groups[0]['list_url']);
    }

    public function test_a_published_kb_article_is_visible_to_a_kb_view_only_viewer(): void
    {
        $article = $this->makeArticle('published', 'Acme published guide', 'Published body');
        $user = $this->panelUserWith('kb.view');

        $service = new GlobalSearchService;

        $groups = $service->groups($service->permissionNames($user), 'acme', 5);

        $this->assertCount(1, $groups, 'Non-vacuity: published articles must still be found.');
        $this->assertSame('kb', $groups[0]['key']);
        $this->assertSame('Knowledge Base', $groups[0]['label']);
        $this->assertSame(
            route('admin.kb.show', $article),
            $groups[0]['results'][0]['url'],
        );
    }

    public function test_the_kb_subtitle_never_contains_the_article_content_body(): void
    {
        $body = 'DISTINCTIVE-BODY-MARKER-9f3a7c1e';
        $this->makeArticle('published', 'Acme backup runbook', $body);
        $user = $this->panelUserWith('kb.view');

        $service = new GlobalSearchService([KnowledgeBaseSearchProvider::class]);

        $groups = $service->groups($service->permissionNames($user), 'acme', 5);

        $this->assertCount(1, $groups);
        $result = $groups[0]['results'][0];

        $this->assertSame('Acme backup runbook', $result['label']);
        // Title/category only: the category label is the subtitle...
        $this->assertSame('Hosting', $result['subtitle']);
        // ...and the body never appears anywhere in the payload.
        $this->assertStringNotContainsString($body, (string) $result['subtitle']);
        $this->assertStringNotContainsString($body, json_encode($groups, JSON_THROW_ON_ERROR));
    }

    // --- tickets -----------------------------------------------------------

    public function test_a_ticket_is_found_by_ticket_no_and_links_to_its_show_route(): void
    {
        $ticket = $this->makeTicket('TKT-2027-00042', 'DNS propagation issue');

        $user = $this->panelUserWith('tickets.view');
        // The real registry, so the config/search.php entry is exercised too.
        $service = new GlobalSearchService;

        $groups = $service->groups($service->permissionNames($user), 'TKT-2027-00042', 5);

        $this->assertCount(1, $groups);
        $this->assertSame('tickets', $groups[0]['key']);
        $this->assertSame('Tickets', $groups[0]['label']);
        $this->assertCount(1, $groups[0]['results']);
        $this->assertSame('TKT-2027-00042', $groups[0]['results'][0]['label']);
        $this->assertSame('DNS propagation issue', $groups[0]['results'][0]['subtitle']);
        $this->assertSame(route('admin.tickets.show', $ticket), $groups[0]['results'][0]['url']);
    }

    public function test_a_literal_percent_sign_in_a_ticket_number_matches_only_that_ticket(): void
    {
        $literal = $this->makeTicket('TKT-50%', 'Literal percent ticket');
        $this->makeTicket('TKT-200', 'Ordinary ticket');

        $user = $this->panelUserWith('tickets.view');
        $service = new GlobalSearchService([TicketSearchProvider::class]);

        $groups = $service->groups($service->permissionNames($user), '%', 5);

        // Two tickets exist; an unescaped `%` would dump both. Exactly one row
        // may match, and it is the row containing a literal percent sign.
        $this->assertCount(1, $groups);
        $this->assertCount(1, $groups[0]['results']);
        $this->assertSame('TKT-50%', $groups[0]['results'][0]['label']);
        $this->assertSame(route('admin.tickets.show', $literal), $groups[0]['results'][0]['url']);
    }

    // --- permission leak ---------------------------------------------------

    public function test_a_viewer_without_support_permissions_gets_no_support_or_catalog_groups(): void
    {
        $this->makeTicket('TKT-ACME-1', 'Acme ticket');
        $this->makeArticle('published', 'Acme article', 'Acme body');
        $this->makeCatalogProduct('ACME-SKU-1', 'Acme catalog product');
        $this->makeProduct('Acme product');

        $user = $this->panelUserWith('invoices.view');
        $service = new GlobalSearchService(self::SUPPORT_PROVIDERS);

        $groups = $service->groups($service->permissionNames($user), 'acme', 5);

        $this->assertSame([], $groups, 'No ticket/KB/catalog/product row may leak without its permission.');
    }

    // --- catalog products + products happy path ----------------------------

    public function test_catalog_products_are_found_by_sku_and_products_by_name(): void
    {
        $catalog = $this->makeCatalogProduct('ACME-VPS-01', 'Acme Managed VPS');
        $product = $this->makeProduct('Acme Shared Hosting');

        $user = $this->panelUserWith('catalog-products.view', 'products.view');
        $service = new GlobalSearchService(self::SUPPORT_PROVIDERS);

        $groups = $service->groups($service->permissionNames($user), 'acme', 5);

        $catalogGroup = $this->groupByKey($groups, 'catalog-products');
        $productGroup = $this->groupByKey($groups, 'products');

        $this->assertNotNull($catalogGroup, 'The catalog-products group must be present.');
        $this->assertSame('Catalog Products', $catalogGroup['label']);
        $this->assertSame('Acme Managed VPS', $catalogGroup['results'][0]['label']);
        $this->assertSame('ACME-VPS-01', $catalogGroup['results'][0]['subtitle']);
        $this->assertSame(route('admin.catalog-products.show', $catalog), $catalogGroup['results'][0]['url']);

        $this->assertNotNull($productGroup, 'The products group must be present.');
        $this->assertSame('Products', $productGroup['label']);
        $this->assertSame('Acme Shared Hosting', $productGroup['results'][0]['label']);
        $this->assertSame(route('admin.products.show', $product), $productGroup['results'][0]['url']);

        // SKU-only token: proves the SKU column is genuinely searchable.
        $skuGroups = $service->groups($service->permissionNames($user), 'vps-01', 5);
        $skuCatalogGroup = $this->groupByKey($skuGroups, 'catalog-products');

        $this->assertNotNull($skuCatalogGroup, 'A SKU-only term must find the catalog product.');
        $this->assertSame('Acme Managed VPS', $skuCatalogGroup['results'][0]['label']);
        $this->assertNull($this->groupByKey($skuGroups, 'products'), 'The product name must not match a SKU term.');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>|null
     */
    private function groupByKey(array $groups, string $key): ?array
    {
        foreach ($groups as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    private function makeTicket(string $ticketNo, string $subject): Ticket
    {
        return Ticket::create([
            'ticket_no' => $ticketNo,
            'subject' => $subject,
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
        ]);
    }

    private function makeArticle(string $status, string $title, string $content): KnowledgeBase
    {
        return KnowledgeBase::create([
            'category' => 'hosting',
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'content' => $content,
            'views' => 0,
            'helpful' => 0,
            'not_helpful' => 0,
            'status' => $status,
        ]);
    }

    private function makeCatalogProduct(string $sku, string $name): CatalogProduct
    {
        return CatalogProduct::create([
            'sku' => $sku,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function makeProduct(string $name): Product
    {
        return Product::create([
            'name' => $name,
        ]);
    }
}
