<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One part of the PC Builder: Processor, RAM, Anti Virus.
 *
 * See the migration for why this is a table. A part draws its products from
 * the categories chosen for it; a built-in part nobody has re-pointed still
 * looks for its old shelf names (`default_slugs`).
 */
class PcBuilderSlot extends Model
{
    public const GROUP_CORE = 'core';

    public const GROUP_EXTRAS = 'peripherals';

    /**
     * The icons a part can wear. The builder draws the same set.
     */
    public const ICONS = [
        'Cpu', 'Server', 'Layers', 'HardDrive', 'Monitor', 'Zap', 'Box', 'Wind',
        'Tv', 'Keyboard', 'Mouse', 'Headphones', 'Wifi', 'ShieldCheck',
        'BatteryCharging', 'Speaker', 'Camera', 'Gamepad2', 'Cable', 'Printer',
        'Fan', 'Usb', 'MemoryStick', 'Package',
    ];

    protected $fillable = [
        'key', 'name', 'icon', 'group', 'is_required', 'hint', 'max_quantity',
        'sort_order', 'is_active', 'default_slugs', 'compat_role',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'max_quantity' => 'integer',
        'sort_order' => 'integer',
        'default_slugs' => 'array',
    ];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'pc_builder_slot_category')
            ->withPivot('position')
            ->orderBy('pc_builder_slot_category.position');
    }

    /** One of the parts the compatibility check reads: hidden, never deleted. */
    public function checksCompatibility(): bool
    {
        return $this->compat_role !== null;
    }
}
