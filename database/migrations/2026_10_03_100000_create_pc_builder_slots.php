<?php

use App\Support\PcBuilderPartsFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The PC Builder's parts, kept in the database instead of the code.
 *
 * The list of slots — Processor, RAM, Monitor and the rest — was an array in
 * ProductService, so the shop could not add a part (Anti Virus, UPS) or change
 * which shelves one draws from without a developer.
 *
 * The thirteen that existed are written in as they were, in the same order,
 * so the builder looks the same on the day this runs. They keep answering to
 * the same shelf names (`default_slugs`, first that exists wins) until someone
 * picks categories for them in the admin; saved and shared builds, and the
 * compatibility engine, key off those names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_builder_slots', function (Blueprint $table) {
            $table->id();
            // What saved builds and the compatibility check call this part.
            // Empty for a built-in part until it is re-pointed, when the name
            // it was answering to is written here and kept.
            $table->string('key', 80)->nullable()->unique();
            // Shown to customers; empty means the category's own name.
            $table->string('name', 80)->nullable();
            $table->string('icon', 40)->default('Box');
            $table->string('group', 20)->default('core');
            $table->boolean('is_required')->default(false);
            $table->string('hint', 160)->nullable();
            $table->unsignedTinyInteger('max_quantity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // The shelves a built-in part looked for before anyone chose any.
            $table->json('default_slugs')->nullable();
            // One of the seven parts the compatibility check reads; those
            // cannot be deleted, only hidden.
            $table->string('compat_role', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('pc_builder_slot_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pc_builder_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['pc_builder_slot_id', 'category_id']);
        });

        /*
         * The list the shop set up, when it was saved with pc-builder:export —
         * so the parts arrive on live exactly as they were arranged locally.
         */
        $file = database_path('data/pc-builder-parts.json');
        // Not in tests: they check the built-in list, not one shop's choices.
        $saved = ! app()->runningUnitTests() && is_file($file)
            ? json_decode((string) file_get_contents($file), true)
            : null;

        if (is_array($saved) && $saved !== []) {
            PcBuilderPartsFile::import($saved);

            return;
        }

        $now = now();
        $slots = [
            ['Processor', ['component-processor', 'cpu'], 'Cpu', 'core', true, 'Sets the socket your motherboard must match', 1, 'cpu'],
            ['CPU Cooler', ['component-cpu-cooler', 'cpu-cooler'], 'Wind', 'core', false, 'Some processors include one', 1, 'cpu-cooler'],
            ['Motherboard', ['component-motherboard', 'motherboard'], 'Server', 'core', true, 'Must match the processor socket and memory type', 1, 'motherboard'],
            ['RAM', ['component-ram-desktop', 'ram'], 'Layers', 'core', true, 'DDR4 and DDR5 are not interchangeable', 4, 'ram'],
            ['Storage', ['component-ssd', 'storage'], 'HardDrive', 'core', true, 'Where Windows and your games live', 4, null],
            ['Graphics Card', ['component-graphics-card', 'graphics-card'], 'Monitor', 'core', false, 'Not needed if the processor has graphics built in', 1, 'graphics-card'],
            ['Power Supply', ['component-power-supply', 'power-supply'], 'Zap', 'core', true, 'Sized against the wattage shown above', 1, 'power-supply'],
            ['Casing', ['component-casing', 'pc-case'], 'Box', 'core', true, 'Must fit the motherboard form factor', 1, 'pc-case'],
            ['Monitor', ['monitor', 'monitors'], 'Tv', 'peripherals', false, null, 2, null],
            ['Keyboard', ['accessories-keyboard', 'keyboards'], 'Keyboard', 'peripherals', false, null, 1, null],
            ['Mouse', ['accessories-mouse', 'mice'], 'Mouse', 'peripherals', false, null, 1, null],
            ['Headphone', ['accessories-headphone', 'headsets'], 'Headphones', 'peripherals', false, null, 1, null],
            ['Router', ['networking-router', 'wifi-routers'], 'Wifi', 'peripherals', false, null, 1, null],
            // New: extras the shop sells alongside a build.
            ['UPS', ['power-ups', 'ups'], 'BatteryCharging', 'peripherals', false, 'Keeps the PC running through a power cut', 1, null],
            ['Anti Virus', ['software-antivirus', 'antivirus'], 'ShieldCheck', 'peripherals', false, null, 1, null],
        ];

        foreach ($slots as $i => [$name, $slugs, $icon, $group, $required, $hint, $max, $role]) {
            DB::table('pc_builder_slots')->insert([
                // Built-in parts show the category's name, as they always did.
                'name' => in_array($name, ['UPS', 'Anti Virus'], true) ? $name : null,
                'icon' => $icon,
                'group' => $group,
                'is_required' => $required,
                'hint' => $hint,
                'max_quantity' => $max,
                'sort_order' => $i + 1,
                'is_active' => true,
                'default_slugs' => json_encode($slugs),
                'compat_role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Storage offered SSDs only; hard disks sit on the shelf next to them.
        $storage = DB::table('pc_builder_slots')->where('sort_order', 5)->value('id');
        $shelves = DB::table('categories')->whereIn('slug', ['component-ssd', 'component-hard-disk-drive'])
            ->where('is_active', true)->orderByRaw("slug = 'component-ssd' desc")->pluck('id');

        if ($storage && $shelves->count() === 2) {
            foreach ($shelves as $position => $categoryId) {
                DB::table('pc_builder_slot_category')->insert([
                    'pc_builder_slot_id' => $storage,
                    'category_id' => $categoryId,
                    'position' => $position,
                ]);
            }
            // It keeps answering to the name saved builds know it by.
            // Named for what it now holds, not after the SSD shelf.
            DB::table('pc_builder_slots')->where('id', $storage)->update(['key' => 'component-ssd', 'name' => 'Storage']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_builder_slot_category');
        Schema::dropIfExists('pc_builder_slots');
    }
};
