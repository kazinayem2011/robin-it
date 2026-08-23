<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin UI talks to the backend through axios, whose baseURL is '/api'.
 * Most admin write endpoints were only registered at the site root, so saving
 * settings, banners, blogs, coupons, stores and uploading media all 404'd from
 * the admin screens while working fine when called directly.
 *
 * These assert the /api side exists for everything adminService calls.
 */
class AdminApiRouteCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mirrors resources/js/services/adminService.js and uploadService.js.
     */
    public static function adminEndpointProvider(): array
    {
        return [
            'create category' => ['POST', 'api/admin/categories'],
            'update category' => ['PATCH', 'api/admin/categories/{id}'],
            'delete category' => ['DELETE', 'api/admin/categories/{id}'],
            'create product' => ['POST', 'api/admin/products'],
            'update product' => ['PATCH', 'api/admin/products/{id}'],
            'order status' => ['PATCH', 'api/admin/orders/{id}/status'],
            'create banner' => ['POST', 'api/admin/banners'],
            'update banner' => ['PATCH', 'api/admin/banners/{id}'],
            'delete banner' => ['DELETE', 'api/admin/banners/{id}'],
            'create coupon' => ['POST', 'api/admin/coupons'],
            'delete coupon' => ['DELETE', 'api/admin/coupons/{id}'],
            'create store' => ['POST', 'api/admin/stores'],
            'delete store' => ['DELETE', 'api/admin/stores/{id}'],
            'create blog' => ['POST', 'api/admin/blogs'],
            'update blog' => ['PUT', 'api/admin/blogs/{id}'],
            'delete blog' => ['DELETE', 'api/admin/blogs/{id}'],
            'review status' => ['PATCH', 'api/admin/reviews/{id}/status'],
            'delete review' => ['DELETE', 'api/admin/reviews/{id}'],
            'warranty status' => ['PATCH', 'api/admin/warranty/{id}/status'],
            'save settings' => ['POST', 'api/admin/settings'],
            'test email' => ['POST', 'api/admin/settings/test-email'],
            'upload media' => ['POST', 'api/admin/media'],
            'delete media' => ['DELETE', 'api/admin/media'],
        ];
    }

    #[DataProvider('adminEndpointProvider')]
    public function test_the_endpoint_the_admin_ui_calls_is_registered(string $method, string $uri): void
    {
        $exists = collect(Route::getRoutes()->getRoutes())
            ->contains(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true));

        $this->assertTrue($exists, "{$method} /{$uri} is missing — the admin UI calls it through axios.");
    }

    #[DataProvider('adminEndpointProvider')]
    public function test_the_endpoint_is_behind_the_admin_guard(string $method, string $uri): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        // `web` for the session, `admin` for the role check.
        $this->assertContains('web', $middleware, "{$uri} needs the session to authenticate");
        $this->assertContains('admin', $middleware, "{$uri} must be admin-only");
    }
}
