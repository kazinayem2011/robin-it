<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /**
     * Branding, SEO, shipping and the ticker — the settings a page needs.
     *
     * This returned getAllSettings(), the whole table, on a public route with
     * no authentication: the SMTP host, port, username and the encrypted
     * password were readable by anyone who asked for /api/settings. The Inertia
     * share was fixed to filter these and this second door was left open.
     */
    public function index(): JsonResponse
    {
        $settings = SiteSetting::publicSettings();

        return $this->successResponse($settings, 'Site settings fetched successfully.');
    }
}
