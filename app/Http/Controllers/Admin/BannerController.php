<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerRequest;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Banners & hero sliders manager.
 */
class BannerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Banners', [
            'banners' => Banner::orderBy('position')->orderBy('sort_order')->get(),
        ]);
    }

    public function store(BannerRequest $request): JsonResponse
    {
        $banner = Banner::create($request->validated());

        return $this->successResponse($banner, 'Banner created successfully.', 201);
    }

    public function update(BannerRequest $request, int $id): JsonResponse
    {
        $banner = Banner::findOrFail($id);
        $banner->update($request->validated());

        return $this->successResponse($banner, 'Banner updated successfully.');
    }

    public function destroy(int $id): JsonResponse
    {
        Banner::findOrFail($id)->delete();

        return $this->successResponse([], 'Banner deleted.');
    }
}
