<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $position = $request->query('position');

        $query = Banner::active();
        if ($position) {
            $query->position($position);
        }

        $banners = $query->get();

        return $this->successResponse($banners, 'Banners fetched successfully.');
    }
}
