<?php

namespace App\Http\Requests\Admin;

/**
 * Rule fragments shared by the create and edit product forms.
 *
 * They were copied between the two, and had already drifted: the edit form
 * accepted a `preorder_limit` the create form did not.
 */
class ProductRules
{
    /**
     * Selling ahead of a delivery, decided per product. The limit is how far the
     * balance may go below zero: without one a single scripted buyer can commit
     * the shop to any number of units, so it is worth setting even though it is
     * optional.
     *
     * @return array<string, mixed>
     */
    public static function preorder(): array
    {
        return [
            'allow_preorder' => 'nullable|boolean',
            'preorder_limit' => 'nullable|integer|min:1|max:100000',
            'preorder_release_at' => 'nullable|date',
        ];
    }

    /**
     * Options. Stock is deliberately absent from every one of these: editing a
     * product must never move a unit.
     *
     * @return array<string, mixed>
     */
    public static function variants(): array
    {
        return [
            'has_variants' => 'nullable|boolean',
            'variant_attributes' => 'nullable|array',
            'variant_attributes.*' => 'string|max:60',
            'variants' => 'nullable|array',
            'variants.*.id' => 'nullable|integer',
            'variants.*.options' => 'nullable|array',
            'variants.*.name' => 'nullable|string|max:180',
            'variants.*.sku' => 'nullable|string|max:80',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.discount_price' => 'nullable|numeric|min:0',
            'variants.*.image_url' => 'nullable|string|max:2048',
            'variants.*.reorder_level' => 'nullable|integer|min:0|max:100000',
            'variants.*.is_active' => 'nullable|boolean',
            // Only read when switching a single product over to options, where it
            // says how the existing shelf is split. It never adds stock.
            'variants.*.opening_stock' => 'nullable|integer|min:0',
        ];
    }
}
