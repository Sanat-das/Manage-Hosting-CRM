<?php

namespace Tests\Browser;

use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Server;
use App\Models\TicketAttachment;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\AppSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Tests\DuskTestCase;

/**
 * Full-application route crawler.
 *
 * Visits EVERY GET route in the application - including routes not linked
 * anywhere in the UI - authenticates as the correct user per area, resolves
 * {param} segments against real records in the database, and asserts that no
 * page returns a client/server error (4xx/5xx).
 *
 * All broken pages are collected and reported together at the end instead of
 * stopping on the first failure.
 */
class FullRouteCrawlerTest extends DuskTestCase
{
    private static bool $schemaMigrated = false;

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $report = [];

    /**
     * The crawler resolves {param} segments against real rows and visits every
     * GET route, so it needs the full canonical data set (users, RBAC, catalog,
     * orders, invoices, hosting, tickets, KB, ...). Migrate + seed once per
     * process; never roll back per test, because Dusk serves the app from a
     * long-lived process holding the sqlite connection open.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schemaMigrated) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            $this->seed(DatabaseSeeder::class);
            self::$schemaMigrated = true;
        }
    }

    public function test_every_get_route_returns_ok(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $admin = User::query()->where('email', 'admin@localhost.com')->firstOrFail();
        $client = User::query()->where('email', 'client1@example.com')->firstOrFail();
        $customer = $client->customer;

        $routes = app('router')->getRoutes()->getRoutes();
        $broken = [];

        foreach ($routes as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if ($reason = $this->skipReason($uri, $customer)) {
                $this->report[] = ['SKIP', $uri, $reason];

                continue;
            }

            $url = $this->resolveUrl($route, $uri, $customer, $admin);
            if ($url === null) {
                $this->report[] = ['SKIP', $uri, 'no test data for params: '.implode(',', $route->parameterNames())];

                continue;
            }

            [$user, $guard] = $this->authFor($uri, $admin, $client);

            try {
                $status = $this->actingAs($user, $guard)->get($url)->getStatusCode();
            } catch (\Throwable $e) {
                $status = 500;
            }

            if ($status >= 400) {
                $broken[] = "$status $url";
                $this->report[] = ['BROKEN', $url, (string) $status];
            } else {
                $this->report[] = ['OK', $url, (string) $status];
            }
        }

        $this->writeReport();

        $this->assertSame(
            [],
            $broken,
            'Broken pages ('.count($broken)."):\n".implode("\n", $broken)
        );
    }

    /**
     * Decide which routes cannot be crawled with the available test data and
     * why. These are recorded but never fail the test.
     */
    protected function skipReason(string $uri, ?Customer $customer): ?string
    {
        return match (true) {
            str_starts_with($uri, '_ignition/') => 'error-page internal route',
            str_starts_with($uri, '_dusk/') => 'Dusk internal route',
            str_starts_with($uri, 'storage/') => 'static file, not a page',
            str_starts_with($uri, 'sanctum/') => 'CSRF cookie endpoint',
            str_starts_with($uri, 'livewire/') => 'Livewire internal route',
            str_starts_with($uri, 'reset-password') => 'requires a valid reset token',
            str_contains($uri, 'two-factor') => '2FA not enabled for the test admin',
            str_ends_with($uri, '/vm-credentials') => 'returns 422 by design when no stored VM credentials exist',
            str_starts_with($uri, 'admin/chat/attachments/') => 'signed URL with per-message access policy, not a crawlable page',
            str_starts_with($uri, 'chat/') && (str_contains($uri, '/messages') || str_contains($uri, '/attachments')) => 'guest-token widget endpoint, not a crawlable page',
            str_contains($uri, '/replies/') && str_ends_with($uri, '/original') => 'seeded replies have no original source message',
            $uri === 'admin/orders/{order}/addons/{orderItem}/edit' && OrderItem::query()->whereNotNull('product_addon_id')->doesntExist() => 'seed data has no addon order item with a parent order',
            $uri === 'admin/servers/{server}/vms' && Server::query()->whereIn('server_type', ['hyperv', 'proxmox'])->doesntExist() => 'seed data has no virtualization server',
            $uri === 'client/tickets/{id}/attachments/{attachment}' && TicketAttachment::query()->doesntExist() => 'seed data has no ticket reply attachments',
            $uri === 'client/hosting/{order}/upgrade' && ! (bool) AppSettings::get('product_enable_upgrades', '1') => 'upgrades are disabled in application settings',
            $uri === 'client/hosting/{order}/upgrade' => $this->eligibleUpgradeOrder($customer) === null
                ? 'client has no active recurring order with an enabled upgrade path in seed data'
                : null,
            default => null,
        };
    }

    /**
     * Substitute every {param} in the URI with a real value from the database.
     * Returns null when no test data exists for the parameter (recorded as SKIP).
     */
    protected function resolveUrl(Route $route, string $uri, ?Customer $customer, User $admin): ?string
    {
        $special = $this->specialUrl($uri, $customer);
        if ($special !== null) {
            return $special;
        }

        // Signed email-verification URL: id + sha1 hash of the email address.
        if ($uri === 'email/verify/{id}/{hash}') {
            return str_replace(
                ['{id}', '{hash}'],
                [$admin->getKey(), sha1($admin->getEmailForVerification())],
                $uri
            );
        }

        $params = $route->parameterNames();
        if (empty($params)) {
            return $uri;
        }

        $modelMap = $this->actionParamModels($route->getAction('uses'));
        $replacements = [];

        foreach ($params as $param) {
            $value = null;
            $modelClass = $modelMap[$param] ?? null;

            if ($modelClass !== null && class_exists($modelClass)
                && is_subclass_of($modelClass, Model::class)) {
                $record = $this->resolveModelRecord($modelClass, $uri, $customer);
                $value = $record?->getRouteKey();
            }

            if ($value === null) {
                $value = $this->fallbackParam($uri, $param, $customer);
            }

            if ($value === null) {
                return null;
            }

            $replacements['{'.$param.'}'] = $value;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $uri);
    }

    /**
     * Routes that "first row" resolution cannot crawl: nested resources whose
     * child must share the parent id, client records that must be owned by the
     * visitor, and endpoints that require a query string or a non-KB slug.
     * Returns null for every other route so normal resolution takes over.
     */
    protected function specialUrl(string $uri, ?Customer $customer): ?string
    {
        return match ($uri) {
            'admin/orders/{order}/addons/{orderItem}/edit' => $this->orderAddonEditUrl($uri),
            'admin/servers/{server}/vms' => $this->virtualizationServerUrl($uri),
            'client/tickets/{id}/attachments/{attachment}' => $this->clientTicketAttachmentUrl($uri, $customer),
            'client/hosting/{order}/upgrade' => $this->eligibleUpgradeUrl($uri, $customer),
            'admin/chat/search' => $uri.'?q=hosting',
            'admin/chat/search-entities' => $uri.'?q=host',
            'api/ticket-departments/{slug}' => $this->ticketDepartmentSlugUrl($uri),
            default => null,
        };
    }

    /** The addon and its parent order must be the same order (edit() aborts 404 otherwise). */
    protected function orderAddonEditUrl(string $uri): ?string
    {
        $addon = OrderItem::query()->whereNotNull('product_addon_id')->first();
        if ($addon === null) {
            return null;
        }

        return str_replace(
            ['{order}', '{orderItem}'],
            [(string) $addon->order_id, (string) $addon->getRouteKey()],
            $uri
        );
    }

    /** Only virtualization servers answer 200; other types are an intentional 422. */
    protected function virtualizationServerUrl(string $uri): ?string
    {
        $server = Server::query()->whereIn('server_type', ['hyperv', 'proxmox'])->first();

        return $server === null
            ? null
            : str_replace('{server}', (string) $server->getRouteKey(), $uri);
    }

    /** The attachment must belong to a reply on a ticket the customer actually owns, with a real file. */
    protected function clientTicketAttachmentUrl(string $uri, ?Customer $customer): ?string
    {
        if ($customer === null) {
            return null;
        }

        $ticket = $customer->tickets()->first();
        if ($ticket === null) {
            return null;
        }

        $attachment = TicketAttachment::query()
            ->whereHas('reply', fn ($query) => $query->where('ticket_id', $ticket->getKey()))
            ->get()
            ->first(fn (TicketAttachment $item) => Storage::disk($item->disk)->exists($item->path));

        if ($attachment === null) {
            return null;
        }

        return str_replace(
            ['{id}', '{attachment}'],
            [(string) $ticket->getRouteKey(), (string) $attachment->getRouteKey()],
            $uri
        );
    }

    /** Mirrors UpgradeController::eligible(): active recurring service with an enabled path to an active product. */
    protected function eligibleUpgradeOrder(?Customer $customer = null): ?Order
    {
        $orders = $customer !== null ? $customer->orders() : Order::query();

        return $orders
            ->where('status', Order::STATUS_ACTIVE)
            ->whereIn('billing_cycle', array_keys(array_filter(Order::CYCLE_MONTHS, fn (int $months) => $months > 0)))
            ->whereHas('product.upgradeableTo.toProduct', fn ($query) => $query->where('status', 'active'))
            ->first();
    }

    protected function eligibleUpgradeUrl(string $uri, ?Customer $customer): ?string
    {
        if ($customer === null || ! (bool) AppSettings::get('product_enable_upgrades', '1')) {
            return null;
        }

        $order = $this->eligibleUpgradeOrder($customer);
        if ($order === null) {
            return null;
        }

        return str_replace('{order}', (string) $order->getRouteKey(), $uri);
    }

    /** The ticket-department {slug} is a department slug, not a knowledge-base slug. */
    protected function ticketDepartmentSlugUrl(string $uri): ?string
    {
        $slug = TicketDepartment::query()->value('slug');

        return $slug === null ? null : str_replace('{slug}', (string) $slug, $uri);
    }

    /**
     * Pick a test record for a route-model-bound parameter, preferring rows
     * the visiting user is actually allowed to see:
     *  - client storefront products must be active + orderable + not admin-only
     *    (StoreController::show aborts 404 otherwise);
     *  - client-portal records (payments, invoices, orders, ...) must belong
     *    to the visiting customer (ownership checks abort 403 otherwise).
     */
    protected function resolveModelRecord(string $modelClass, string $uri, ?Customer $customer): ?Model
    {
        if ($modelClass === Product::class && str_starts_with($uri, 'client/store/')) {
            return $modelClass::query()
                ->where('status', 'active')
                ->where('show_in_order', true)
                ->where('only_admin', false)
                ->first();
        }

        if (str_starts_with($uri, 'client/') && $customer !== null) {
            if (str_contains($uri, 'payments')) {
                // Payments belong to customers via their invoices
                // (payments.customer_id does not exist).
                return $customer->payments()->first();
            }

            $relation = match (true) {
                str_contains($uri, 'invoices') => 'invoices',
                str_contains($uri, 'orders') => 'orders',
                str_contains($uri, 'tickets') => 'tickets',
                default => null,
            };

            if ($relation !== null && method_exists($customer, $relation)) {
                return $customer->{$relation}()->first();
            }
        }

        return $modelClass::query()->first();
    }

    /**
     * Reflect the controller action and map method-parameter names to the
     * type-hinted Eloquent model class (implicit route-model binding).
     *
     * @return array<string, string>
     */
    protected function actionParamModels(mixed $action): array
    {
        static $cache = [];

        if (! is_string($action) || ! str_contains($action, '@')) {
            return [];
        }

        if (isset($cache[$action])) {
            return $cache[$action];
        }

        [$controller, $method] = explode('@', $action);
        if (! class_exists($controller) || ! method_exists($controller, $method)) {
            return $cache[$action] = [];
        }

        $map = [];
        foreach ((new \ReflectionMethod($controller, $method))->getParameters() as $param) {
            $type = $param->getType();
            if ($type !== null && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), Model::class)) {
                $map[$param->getName()] = $type->getName();
            }
        }

        return $cache[$action] = $map;
    }

    /**
     * Resolve params that are not covered by implicit model binding (e.g. KB
     * slugs and client-portal {id} segments scoped to the customer).
     */
    protected function fallbackParam(string $uri, string $param, ?Customer $customer): ?string
    {
        if ($param === 'slug') {
            $kb = KnowledgeBase::query()->first();

            return $kb?->slug;
        }

        if (str_starts_with($uri, 'client/') && $customer !== null) {
            $relation = match (true) {
                str_contains($uri, 'domains') => 'domains',
                str_contains($uri, 'hosting') => 'hostingAccounts',
                str_contains($uri, 'tickets') => 'tickets',
                str_contains($uri, 'invoices') => 'invoices',
                str_contains($uri, 'orders') => 'orders',
                default => null,
            };

            if ($relation !== null) {
                return $customer->{$relation}()->first()?->getRouteKey();
            }
        }

        return null;
    }

    /**
     * Pick the user and auth guard for a URI: client-portal routes go to the
     * client user (ClientMiddleware 403s other roles), API routes use Sanctum.
     *
     * @return array{0: User, 1: string}
     */
    protected function authFor(string $uri, User $admin, User $client): array
    {
        if (str_starts_with($uri, 'api/')) {
            return [$admin, 'sanctum'];
        }

        if ($uri === 'client' || str_starts_with($uri, 'client/')) {
            return [$client, 'web'];
        }

        return [$admin, 'web'];
    }

    protected function writeReport(): void
    {
        $lines = [
            'Route crawl report - '.now()->toDateTimeString(),
            '',
            sprintf('%-7s %-95s %s', 'STATUS', 'URL', 'DETAIL'),
            str_repeat('-', 130),
        ];

        foreach ($this->report as [$category, $url, $detail]) {
            $lines[] = sprintf('%-7s %-95s %s', $category, $url, $detail);
        }

        file_put_contents(
            storage_path('app/route-crawl-report.txt'),
            implode("\n", $lines)."\n"
        );
    }
}
