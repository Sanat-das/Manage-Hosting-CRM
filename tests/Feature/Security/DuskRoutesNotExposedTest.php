<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression guard: Laravel Dusk must never publish its routes outside the
 * dedicated `dusk` environment.
 *
 * Dusk ships GET /_dusk/login/{userId}/{guard?} — an unauthenticated "log in as
 * any user id" endpoint behind `web` middleware only. Its own guard is merely
 * `environment() !== 'production'`, which was not enough here: .env.example
 * ships APP_ENV=local and bootstrap/app.php copies it verbatim on first boot,
 * so a real deployment that never set APP_ENV served that route to anyone.
 *
 * The fix is two-part — `dont-discover` in composer.json plus an explicit
 * registration in AppServiceProvider bound to the `dusk` environment, which a
 * misconfigured production box can never satisfy.
 */
class DuskRoutesNotExposedTest extends TestCase
{
    public function test_dusk_login_route_is_not_registered(): void
    {
        $this->assertFalse(
            Route::has('dusk.login'),
            'Dusk published its unauthenticated login route outside the dusk environment.'
        );
    }

    public function test_no_dusk_routes_are_registered_at_all(): void
    {
        $duskRoutes = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), '_dusk')) {
                $duskRoutes[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $duskRoutes, "Dusk routes are exposed:\n".implode("\n", $duskRoutes));
    }

    /**
     * Hitting the route directly must not authenticate anyone. Guards against a
     * future re-registration slipping past the two structural assertions above.
     */
    public function test_guest_hitting_dusk_login_is_not_authenticated(): void
    {
        $this->get('/_dusk/login/1');

        $this->assertFalse(
            auth()->check(),
            'A guest was authenticated via /_dusk/login — unauthenticated takeover.'
        );
    }

    /**
     * The exclusion only holds while composer.json keeps Dusk out of package
     * auto-discovery; re-adding it would silently restore the routes.
     */
    public function test_composer_json_excludes_dusk_from_auto_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $this->assertContains(
            'laravel/dusk',
            $composer['extra']['laravel']['dont-discover'] ?? [],
            'laravel/dusk must stay in extra.laravel.dont-discover.'
        );
    }
}
