<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every admin write used to be declared twice — once under /admin and again
 * under /api/admin — pointing at the same controller method. Each of those
 * methods therefore carried a branch asking whether to answer with JSON or a
 * redirect, and the two declarations could drift apart without anything
 * noticing.
 *
 * The admin UI talks to the backend over axios, so /api/admin/* is the only
 * write surface. These pin that down.
 */
class AdminRouteSurfaceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, RoutingRoute> */
    private function adminRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => str_starts_with($route->uri(), 'admin/')
                || str_starts_with($route->uri(), 'api/admin/')
        ));
    }

    private function writeVerbs(RoutingRoute $route): array
    {
        return array_values(array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']));
    }

    public function test_no_admin_write_is_registered_at_the_site_root(): void
    {
        $offenders = [];

        foreach ($this->adminRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach ($this->writeVerbs($route) as $verb) {
                $offenders[] = "{$verb} /{$route->uri()}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Admin writes belong under /api/admin only: '.implode(', ', $offenders)
        );
    }

    public function test_the_admin_pages_under_the_site_root_are_read_only(): void
    {
        $pages = array_filter(
            $this->adminRoutes(),
            fn (RoutingRoute $r) => ! str_starts_with($r->uri(), 'api/')
        );

        $this->assertNotEmpty($pages, 'The admin screens should still be reachable.');

        foreach ($pages as $route) {
            $this->assertEmpty(
                $this->writeVerbs($route),
                "/{$route->uri()} accepts a write verb."
            );
        }
    }

    /** No controller action should still be deciding its own response shape. */
    public function test_admin_controllers_do_not_branch_on_transport(): void
    {
        $offenders = [];

        foreach (glob(app_path('Http/Controllers/Admin/*.php')) as $file) {
            $source = file_get_contents($file);

            if (str_contains($source, "is('api/*')") || str_contains($source, '->ajax()')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These still choose between JSON and a redirect at runtime: '.implode(', ', $offenders)
        );
    }
}
