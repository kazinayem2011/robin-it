<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Coupons & discounts manager.
 */
class CouponController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Coupons', [
            'coupons' => Coupon::with(['products:id,name', 'categories:id,name'])->latest()->get(),
            // The scope pickers need something to choose from.
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']),
            'scopes' => Coupon::SCOPES,
        ]);
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = Coupon::create($request->couponAttributes());

        $this->syncScope($coupon, $request->validated());

        return $this->successResponse(
            $coupon->load('products:id,name', 'categories:id,name'),
            'Coupon created.',
            201
        );
    }

    public function update(CouponRequest $request, int $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);

        $coupon->update($request->couponAttributes());

        $this->syncScope($coupon, $request->validated());

        return $this->successResponse(
            $coupon->load('products:id,name', 'categories:id,name'),
            'Coupon updated successfully.'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        Coupon::findOrFail($id)->delete();

        return $this->successResponse([], 'Coupon deleted.');
    }

    /**
     * Attach the products or categories a scoped coupon covers.
     *
     * The lists are cleared for the scopes that do not apply, so a coupon
     * switched back to "whole order" cannot keep a stale restriction that would
     * quietly change what it discounts.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncScope(Coupon $coupon, array $validated): void
    {
        $scope = $validated['scope'] ?? $coupon->scope ?? Coupon::SCOPE_ALL;

        $coupon->products()->sync(
            $scope === Coupon::SCOPE_PRODUCTS ? ($validated['product_ids'] ?? []) : []
        );

        $coupon->categories()->sync(
            $scope === Coupon::SCOPE_CATEGORIES ? ($validated['category_ids'] ?? []) : []
        );
    }
}
