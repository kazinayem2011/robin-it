<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use App\Models\Store;
use App\Support\BrandDetails;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Only the settings that are safe in a browser: this used to share
            // the whole table, SMTP credentials included, with every visitor.
            'site_settings' => SiteSetting::publicSettings(),
            // Resolved rather than left to the frontend: site_name can be
            // absent from the table entirely, and every page title needs a
            // name to fall back on.
            'brand_name' => BrandDetails::name(),
            // The footer advertised "Showrooms & Outlets (15+)" while there
            // were four. A count nobody maintains drifts into a false claim,
            // so it is read from the branches that actually exist.
            'showroom_count' => Store::where('is_active', true)->count(),
        ];
    }
}
