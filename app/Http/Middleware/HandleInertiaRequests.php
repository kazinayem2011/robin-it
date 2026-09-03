<?php

namespace App\Http\Middleware;

use App\Models\Offer;
use App\Models\SiteSetting;
use App\Models\Store;
use App\Models\User;
use App\Support\BrandDetails;
use App\Support\Roles;
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
     * Everything here is a closure. share() runs on every request through the
     * web group — including redirects and downloads, which never render props —
     * and the settings read and the showroom count were being executed on all of
     * them. As closures they are resolved only when a page is actually built.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => [
                // Named fields rather than the whole model. Sharing $request->user()
                // put every column on the User row into the props of every page,
                // so any column added later would be published by default.
                'user' => fn () => $this->userProps($request->user()),
            ],

            // Only the settings that are safe in a browser: this used to share
            // the whole table, SMTP credentials included, with every visitor.
            'site_settings' => fn () => SiteSetting::publicSettings(),

            // Resolved rather than left to the frontend: site_name can be
            // absent from the table entirely, and every page title needs a
            // name to fall back on.
            'brand_name' => fn () => BrandDetails::name(),

            /*
             * Controllers flash a message on nearly every write —
             * back()->with('success', ...) — and none of them were reaching
             * the browser, because this was never shared.
             */
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],

            // The footer advertised "Showrooms & Outlets (15+)" while there
            // were four. A count nobody maintains drifts into a false claim,
            // so it is read from the branches that actually exist.
            'showroom_count' => fn () => Store::where('is_active', true)->count(),

            /*
             * How many campaigns are on, so the header can stop offering them
             * when there are none.
             *
             * The Offers button says "RUNNING NOW" on every page. With nothing
             * running that is a claim the shop cannot keep, and following it
             * lands on an empty page — the dead end this codebase already has
             * a test file named after. Counted here rather than fetched by the
             * header, which would be a second request on every page for the
             * sake of one button.
             */
            'offers_running' => fn () => Offer::current()->count(),
        ];
    }

    /**
     * The signed-in customer, as the frontend needs them.
     *
     * @return array<string, mixed>|null
     */
    private function userProps(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'role' => $user->role,
            'role_label' => Roles::label($user->role),
            // The nav is drawn from these, so a role never sees a link to a
            // section it would be refused from.
            'abilities' => $user->abilities(),
            'email_verified_at' => $user->email_verified_at,
            // Its counterpart, so the account panel can say which of the two
            // contact details has actually been proved rather than assuming.
            'phone_verified_at' => $user->phone_verified_at,
        ];
    }
}
