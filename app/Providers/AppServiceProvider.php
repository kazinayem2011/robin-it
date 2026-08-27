<?php

namespace App\Providers;

use App\Enums\ApiCode;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use App\Support\MailSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();
        $this->invalidateCatalogueCacheOnWrite();

        // SMTP credentials saved in the admin override the .env defaults.
        MailSettings::apply();
    }

    /**
     * Keep the cached mega menu and featured categories honest.
     *
     * Hung off the models rather than off the admin controllers so a seeder, a
     * tinker session or a future screen cannot leave the navigation showing a
     * category that no longer exists.
     */
    protected function invalidateCatalogueCacheOnWrite(): void
    {
        $flush = static fn () => CategoryService::flush();

        foreach ([Category::class, Product::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }
    }

    /**
     * Laravel 11+ no longer applies `throttle:api` automatically, so every public
     * endpoint here was unlimited — order tracking, promo codes and RMA lookups
     * could all be brute-forced. These limiters restore a sane ceiling.
     */
    protected function configureRateLimiting(): void
    {
        // General browsing: generous, keyed per user or IP.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn () => $this->throttledResponse(
                    'You are browsing a little too quickly. Please wait a moment and try again.'
                ));
        });

        // Endpoints where a wrong guess is cheap and a right guess is valuable:
        // order tracking, promo codes, warranty lookups.
        RateLimiter::for('lookup', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn () => $this->throttledResponse(
                    'Too many attempts. Please wait a minute before trying again.'
                ));
        });

        // Anonymous writes (RMA claims, saved PC builds).
        RateLimiter::for('submissions', function (Request $request) {
            return Limit::perMinute(15)
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn () => $this->throttledResponse(
                    'Too many submissions in a short time. Please wait a minute and try again.'
                ));
        });
    }

    /**
     * Keep 429s in the same envelope as every other API response.
     */
    protected function throttledResponse(string $message)
    {
        return response()->json([
            'error' => true,
            'code' => ApiCode::TOO_MANY_REQUESTS,
            'message' => $message,
            'data' => [],
            'meta' => new \stdClass,
        ], 429);
    }
}
