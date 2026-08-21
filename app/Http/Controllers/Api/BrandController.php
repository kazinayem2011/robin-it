<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;

class BrandController extends Controller
{
    public function index(): JsonResponse
    {
        $brands = Brand::featured()->get(['id', 'name', 'slug', 'logo_path']);

        return $this->successResponse($brands, 'Featured brands fetched successfully.');
    }
}
