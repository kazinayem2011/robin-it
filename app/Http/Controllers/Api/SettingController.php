<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = SiteSetting::getAllSettings();

        return $this->successResponse($settings, 'Site settings fetched successfully.');
    }
}
