<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\PcBuilderSlot;
use App\Models\Product;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PC Builder's parts are the shop's to add, change and order.
 *
 * They were an array in the code, so Anti Virus could not be offered in the
 * builder at all. The seven parts the compatibility check reads can be changed
 * or hidden, never deleted.
 */
class PcBuilderPartsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Roles::OWNER, 'is_active' => true]);
    }

    private function category(string $slug, string $name, int $products = 1, ?Category $parent = null): Category
    {
        $category = Category::create(['slug' => $slug, 'name' => $name, 'is_active' => true, 'parent_id' => $parent?->id]);

        for ($i = 0; $i < $products; $i++) {
            Product::create([
                'category_id' => $category->id, 'name' => "{$name} {$i}", 'slug' => "{$slug}-{$i}",
                'price' => 1000, 'stock_quantity' => 5, 'is_active' => true,
            ]);
        }

        return $category;
    }

    private function builder(): array
    {
        return $this->getJson('/api/pc-builder/categories')->assertOk()->json('data');
    }

    private function part(string $id): ?array
    {
        return collect($this->builder())->firstWhere('id', $id);
    }

    private function save(array $fields, ?int $id = null)
    {
        $payload = $fields + [
            'icon' => 'ShieldCheck', 'group' => 'peripherals', 'is_required' => false,
            'max_quantity' => 1, 'is_active' => true,
        ];

        return $id
            ? $this->actingAs($this->admin)->putJson("/api/admin/pc-builder/parts/{$id}", $payload)
            : $this->actingAs($this->admin)->postJson('/api/admin/pc-builder/parts', $payload);
    }

    // --- What ships ---------------------------------------------------------------

    /** Anti Virus and UPS come ready, drawn from the shop's own shelves. */
    public function test_anti_virus_and_ups_are_offered_when_the_shop_stocks_them(): void
    {
        $this->category('software-antivirus', 'Antivirus', 2);
        $this->category('power-ups', 'UPS', 1);

        $this->assertSame('Anti Virus', $this->part('software-antivirus')['name']);
        $this->assertSame('ShieldCheck', $this->part('software-antivirus')['icon']);
        $this->assertSame('UPS', $this->part('power-ups')['name']);
    }

    public function test_ram_and_storage_allow_more_than_one(): void
    {
        $this->category('component-ram-desktop', 'RAM', 1);
        $this->category('component-processor', 'Processor', 1);

        $this->assertSame(4, $this->part('component-ram-desktop')['max_quantity']);
        $this->assertSame(1, $this->part('component-processor')['max_quantity']);
    }

    // --- Adding a part --------------------------------------------------------------

    public function test_a_new_part_is_offered_with_products_from_its_categories(): void
    {
        $webcams = $this->category('accessories-webcam', 'Webcam', 2);
        $mics = $this->category('accessories-microphone', 'Microphone', 1);

        $this->save(['name' => 'Streaming Kit', 'category_ids' => [$webcams->id, $mics->id], 'icon' => 'Camera'])
            ->assertStatus(201);

        $part = $this->part('streaming-kit');
        $this->assertSame('Streaming Kit', $part['name']);
        $this->assertSame(3, $part['available'], 'products from both categories');

        // The picker opens for the part and lists both shelves.
        $this->assertCount(3, $this->getJson('/api/pc-builder/components/streaming-kit')->assertOk()->json('data'));

        // And the picker page is headed with the part's name.
        $this->get('/pc-builder/choose/streaming-kit')->assertOk()
            ->assertInertia(fn ($page) => $page->where('partName', 'Streaming Kit'));
    }

    public function test_a_part_needs_a_name_and_a_category(): void
    {
        $this->save(['name' => '', 'category_ids' => []])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['name', 'category_ids']]]);
    }

    /* A category's children are included, as for the built-in parts. */
    public function test_products_under_a_chosen_categorys_children_count(): void
    {
        $software = $this->category('software', 'Software', 0);
        $this->category('software-office', 'Office', 2, $software);

        $this->save(['name' => 'Software', 'category_ids' => [$software->id]])->assertStatus(201);

        $this->assertSame(2, $this->part('software')['available']);
    }

    // --- Changing a part ---------------------------------------------------------------

    /*
     * Saved builds and the compatibility check know the processor by the shelf
     * it answered to. Renaming it or pointing it at more shelves must not
     * change that.
     */
    public function test_editing_a_built_in_part_keeps_the_name_saved_builds_use(): void
    {
        $cpus = $this->category('component-processor', 'Processor', 1);
        $apus = $this->category('component-apu', 'APU', 1);
        $slot = PcBuilderSlot::where('compat_role', 'cpu')->first();

        $this->save([
            'name' => 'CPU', 'category_ids' => [$cpus->id, $apus->id], 'icon' => 'Cpu',
            'group' => 'core', 'is_required' => true,
        ], $slot->id)->assertOk();

        $part = $this->part('component-processor');
        $this->assertNotNull($part, 'still answers to component-processor');
        $this->assertSame('CPU', $part['name']);
        $this->assertSame(2, $part['available']);
        $this->assertSame('component-processor', $slot->fresh()->key);
    }

    public function test_a_hidden_part_is_not_offered(): void
    {
        $antivirus = $this->category('software-antivirus', 'Antivirus', 1);
        $slot = PcBuilderSlot::where('name', 'Anti Virus')->first();

        $this->save(['name' => 'Anti Virus', 'category_ids' => [$antivirus->id], 'is_active' => false], $slot->id)
            ->assertOk();

        $this->assertNull($this->part('software-antivirus'));
    }

    public function test_the_order_can_be_changed(): void
    {
        $this->category('component-processor', 'Processor', 1);
        $this->category('component-cpu-cooler', 'Cooler', 1);
        $cooler = PcBuilderSlot::where('compat_role', 'cpu-cooler')->first();

        $this->actingAs($this->admin)->postJson("/api/admin/pc-builder/parts/{$cooler->id}/move", ['direction' => 'up'])
            ->assertOk();

        $this->assertSame(['component-cpu-cooler', 'component-processor'], array_slice(array_column($this->builder(), 'id'), 0, 2));
    }

    // --- Removing one --------------------------------------------------------------------

    public function test_a_compatibility_part_cannot_be_deleted(): void
    {
        $slot = PcBuilderSlot::where('compat_role', 'motherboard')->first();

        $this->actingAs($this->admin)->deleteJson("/api/admin/pc-builder/parts/{$slot->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Hide it instead'));

        $this->assertNotNull($slot->fresh());
    }

    public function test_an_extra_can_be_removed(): void
    {
        $slot = PcBuilderSlot::where('name', 'UPS')->first();

        $this->actingAs($this->admin)->deleteJson("/api/admin/pc-builder/parts/{$slot->id}")->assertOk();

        $this->assertNull($slot->fresh());
    }

    public function test_only_catalogue_staff_can_change_parts(): void
    {
        $support = User::factory()->create(['role' => Roles::SUPPORT, 'is_active' => true]);

        $this->actingAs($support)->postJson('/api/admin/pc-builder/parts', ['name' => 'X'])->assertForbidden();
    }

    public function test_the_admin_page_lists_every_part_with_its_icons(): void
    {
        $props = $this->actingAs($this->admin)->get('/admin/pc-builder')->viewData('page')['props'];

        $this->assertCount(15, $props['parts']);
        $this->assertContains('ShieldCheck', $props['icons']);
        $this->assertTrue(collect($props['parts'])->firstWhere('name', 'Anti Virus') !== null);
    }
}
