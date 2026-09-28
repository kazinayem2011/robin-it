<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\PcBuilderSlot;
use App\Support\PcBuilderPartsFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The parts list travels with the code: set up in one shop, saved to a file,
 * and applied to another by category slug, since ids differ between the two.
 */
class PcBuilderPartsFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_list_saved_in_one_shop_arrives_the_same_in_another(): void
    {
        // "Local": the shop rearranges its parts.
        Category::create(['slug' => 'component-processor', 'name' => 'Processor', 'is_active' => true]);
        $webcams = Category::create(['slug' => 'accessories-webcam', 'name' => 'Webcam', 'is_active' => true]);
        PcBuilderSlot::where('compat_role', 'cpu-cooler')->update(['sort_order' => 0, 'is_required' => true]);
        PcBuilderSlot::where('name', 'UPS')->delete();
        $webcam = PcBuilderSlot::create(['key' => 'webcam', 'name' => 'Webcam', 'icon' => 'Camera', 'group' => 'peripherals', 'sort_order' => 99]);
        $webcam->categories()->attach($webcams->id);

        $saved = PcBuilderPartsFile::export();

        // "Live": the built-in list, and the same shelves under other ids.
        PcBuilderSlot::query()->delete();
        $this->seedBuiltIns();
        $webcams->delete();
        Category::create(['slug' => 'accessories-webcam', 'name' => 'Webcam', 'is_active' => true]);

        PcBuilderPartsFile::import($saved);

        $this->assertSame($saved, PcBuilderPartsFile::export(), 'identical, shelves found by slug');
        $this->assertSame('cpu-cooler', PcBuilderSlot::orderBy('sort_order')->first()->compat_role);
        $this->assertNull(PcBuilderSlot::where('name', 'UPS')->first(), 'removed there too');
    }

    public function test_the_fit_check_parts_are_never_removed_by_an_import(): void
    {
        $saved = collect(PcBuilderPartsFile::export())->whereNull('compat_role')->values()->all();

        PcBuilderPartsFile::import($saved);

        $this->assertSame(7, PcBuilderSlot::whereNotNull('compat_role')->count());
    }

    public function test_it_says_which_categories_the_other_shop_does_not_have(): void
    {
        $result = PcBuilderPartsFile::import([
            ['key' => 'webcam', 'name' => 'Webcam', 'icon' => 'Camera', 'group' => 'peripherals', 'categories' => ['accessories-webcam']],
        ]);

        $this->assertSame(['accessories-webcam'], $result['missing_categories']);
        $this->assertNotNull(PcBuilderSlot::where('key', 'webcam')->first());
    }

    public function test_the_commands_save_and_apply_the_file(): void
    {
        $path = PcBuilderPartsFile::path();
        $had = is_file($path) ? file_get_contents($path) : null;

        try {
            $this->artisan('pc-builder:export')->assertSuccessful();
            $this->assertCount(15, json_decode(file_get_contents($path), true));

            PcBuilderSlot::where('name', 'Anti Virus')->delete();
            $this->artisan('pc-builder:import', ['--force' => true])->assertSuccessful();
            $this->assertNotNull(PcBuilderSlot::where('name', 'Anti Virus')->first(), 'restored from the file');
        } finally {
            // The project's own file is left as it was.
            $had === null ? @unlink($path) : file_put_contents($path, $had);
        }
    }

    /** The migration's built-in list, as a fresh database has it. */
    private function seedBuiltIns(): void
    {
        $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_10_03_100000_create_pc_builder_slots.php', '--force' => true]);
    }
}
