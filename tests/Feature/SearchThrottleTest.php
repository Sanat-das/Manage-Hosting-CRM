<?php

namespace Tests\Feature;

use App\Http\Requests\SearchTypeaheadRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Contract tests for the dedicated `search` rate limiter and the typeahead
 * FormRequest (plan todo 3).
 *
 * The typeahead route itself is owned by a later todo, so the throttle is
 * exercised through a test-defined route carrying the same `throttle:search`
 * middleware the real route will use.
 */
class SearchThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rate-limiter counters live in the cache. Clear them so a re-run or
        // a previous test in the same process cannot leak hits into this one.
        Cache::flush();
        RateLimiter::clear('search');
    }

    /**
     * A panel user holding the given permission names via the `admin` role.
     *
     * @param  array<int, string>  $permissions
     */
    private function panelUserWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole('admin');

        return $user;
    }

    // -----------------------------------------------------------------
    // (a) The `search` limiter is registered and resolvable.
    // -----------------------------------------------------------------

    public function test_search_limiter_is_registered_and_resolvable(): void
    {
        $callback = RateLimiter::limiter('search');

        $this->assertIsCallable($callback, "Rate limiter 'search' is not registered.");

        $limit = $callback(Request::create('/admin/search/typeahead', 'GET'));

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(300, $limit->maxAttempts, 'The search limiter must allow 300 requests per minute.');
        $this->assertSame(60, $limit->decaySeconds, 'The search limiter window must be one minute.');
    }

    public function test_search_limiter_keys_authenticated_requests_by_user_id_and_guests_by_ip(): void
    {
        $callback = RateLimiter::limiter('search');
        $this->assertIsCallable($callback, "Rate limiter 'search' is not registered.");

        $user = User::factory()->create();
        $authenticated = Request::create('/admin/search/typeahead', 'GET');
        $authenticated->setUserResolver(fn () => $user);
        $limit = $callback($authenticated);
        $this->assertSame($user->id, $limit->key, 'An authenticated caller must be keyed by user id.');

        $guest = Request::create('/admin/search/typeahead', 'GET');
        $limit = $callback($guest);
        $this->assertSame($guest->ip(), $limit->key, 'A guest caller must be keyed by IP.');
    }

    // -----------------------------------------------------------------
    // (b) 301 sequential requests by one user: 1st 200, 301st 429.
    // -----------------------------------------------------------------

    public function test_search_throttle_allows_300_requests_and_rejects_the_301st(): void
    {
        Route::middleware('throttle:search')
            ->get('/_test/search-throttle', fn () => response('ok'));

        $user = User::factory()->create();
        $this->actingAs($user);

        $first = $this->get('/_test/search-throttle');
        $this->assertSame(200, $first->getStatusCode(), 'Request #1 must be allowed (200).');

        for ($i = 2; $i <= 300; $i++) {
            $response = $this->get('/_test/search-throttle');
            $this->assertSame(200, $response->getStatusCode(), "Request #{$i} must be allowed (200).");
        }

        $throttled = $this->get('/_test/search-throttle');
        $this->assertSame(429, $throttled->getStatusCode(), 'Request #301 must be throttled (429).');
    }

    // -----------------------------------------------------------------
    // (c) + (d) `q` validation: 101 chars and arrays fail; 1 char passes.
    // -----------------------------------------------------------------

    public function test_q_longer_than_100_chars_fails_validation(): void
    {
        $validator = Validator::make(
            ['q' => str_repeat('a', 101)],
            (new SearchTypeaheadRequest())->rules()
        );

        $this->assertTrue($validator->fails(), 'A 101-character q must fail validation.');
        $this->assertArrayHasKey('q', $validator->errors()->toArray());
    }

    public function test_q_of_exactly_100_chars_passes_validation(): void
    {
        $validator = Validator::make(
            ['q' => str_repeat('a', 100)],
            (new SearchTypeaheadRequest())->rules()
        );

        $this->assertFalse($validator->fails(), 'A 100-character q must pass validation.');
    }

    public function test_one_char_q_passes_validation(): void
    {
        // Short queries are a controller concern (200-empty), never a 422.
        $validator = Validator::make(
            ['q' => 'a'],
            (new SearchTypeaheadRequest())->rules()
        );

        $this->assertFalse($validator->fails(), 'A 1-character q must pass validation.');
    }

    public function test_empty_and_missing_q_pass_validation(): void
    {
        foreach ([['q' => ''], ['q' => null], []] as $payload) {
            $validator = Validator::make($payload, (new SearchTypeaheadRequest())->rules());

            $this->assertFalse(
                $validator->fails(),
                'An empty or missing q must pass validation (nullable): '.json_encode($payload)
            );
        }
    }

    public function test_array_q_fails_validation(): void
    {
        $validator = Validator::make(
            ['q' => ['a']],
            (new SearchTypeaheadRequest())->rules()
        );

        $this->assertTrue($validator->fails(), 'An array q is malformed and must fail validation.');
        $this->assertArrayHasKey('q', $validator->errors()->toArray());
    }

    // -----------------------------------------------------------------
    // authorize(): the `search` permission gates the request.
    // -----------------------------------------------------------------

    public function test_authorize_requires_the_search_permission(): void
    {
        $request = SearchTypeaheadRequest::create('/admin/search/typeahead');

        $this->assertFalse($request->authorize(), 'A guest must not be authorized.');

        $request->setUserResolver(fn () => User::factory()->create());
        $this->assertFalse($request->authorize(), 'A user without the search permission must not be authorized.');

        $request->setUserResolver(fn () => $this->panelUserWith(['search']));
        $this->assertTrue($request->authorize(), 'A user holding the search permission must be authorized.');
    }
}
