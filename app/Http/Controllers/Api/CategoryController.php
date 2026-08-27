<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService
    ) {}

    /**
     * Get the nested category tree for the Mega Menu.
     *
     * No try/catch: these used to answer a failure with
     * "Failed to fetch mega menu: " . $e->getMessage() and a 500, on a public
     * unauthenticated route — so a broken query handed its SQL, and a broken
     * include handed its filesystem path, to anyone who asked. The handler in
     * bootstrap/app.php already turns an unexpected throwable into a generic
     * 500 in the standard envelope, which is what should have been happening.
     */
    public function megaMenu(): JsonResponse
    {
        return $this->successResponse(
            $this->categoryService->getMegaMenuTree(),
            'Mega menu categories fetched successfully.'
        );
    }

    /**
     * Get Featured Categories for Homepage Bubble Carousel.
     */
    public function featured(): JsonResponse
    {
        return $this->successResponse(
            $this->categoryService->getFeaturedCategories(),
            'Featured bubble categories fetched successfully.'
        );
    }
}
