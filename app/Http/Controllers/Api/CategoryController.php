<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CategoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CategoryService $categoryService
    ) {}

    /**
     * Get the nested category tree for the Mega Menu.
     */
    public function megaMenu(): JsonResponse
    {
        try {
            $categories = $this->categoryService->getMegaMenuTree();

            return $this->successResponse($categories, 'Mega menu categories fetched successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch mega menu: '.$e->getMessage(), 500);
        }
    }

    /**
     * Get Featured Categories for Homepage Bubble Carousel.
     */
    public function featured(): JsonResponse
    {
        try {
            $categories = $this->categoryService->getFeaturedCategories();

            return $this->successResponse($categories, 'Featured bubble categories fetched successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch featured categories: '.$e->getMessage(), 500);
        }
    }
}
