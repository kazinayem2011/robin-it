<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Services\PcCompatibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class PcBuilderCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    /**
     * @param  array<string, string>  $specs
     */
    private function part(string $slug, string $name, array $specs = []): Product
    {
        $components = Category::firstOrCreate(['slug' => 'components'], ['name' => 'Components', 'is_active' => true]);
        $category = Category::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'parent_id' => $components->id, 'is_active' => true]
        );

        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'price' => 50000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        foreach ($specs as $k => $v) {
            $product->specifications()->create(['name' => $k, 'value' => $v]);
        }

        return $product;
    }

    public function test_check_reports_a_socket_conflict(): void
    {
        $cpu = $this->part('cpu', 'AMD Ryzen 7 7800X3D', ['Socket' => 'AM5', 'TDP' => '120W']);
        $board = $this->part('motherboard', 'MSI MEG Z790 Ace LGA1700', ['Socket' => 'LGA1700']);

        $response = $this->postJson('/api/pc-builder/check', [
            'selection' => ['cpu' => $cpu->id, 'motherboard' => $board->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', PcCompatibilityService::FAIL)
            ->assertJsonPath('data.issues.0.rule', 'cpu_socket')
            ->assertJsonPath('message', 'This build has compatibility conflicts.');

        $this->assertStringContainsString('AM5', $response->json('data.issues.0.message'));
    }

    public function test_check_confirms_a_valid_build(): void
    {
        $cpu = $this->part('cpu', 'AMD Ryzen 7 7800X3D', ['Socket' => 'AM5', 'TDP' => '120W']);
        $board = $this->part('motherboard', 'ASUS X870E Hero ATX', ['Socket' => 'AM5', 'Memory' => '4x DDR5 slots']);
        $ram = $this->part('ram', 'Corsair Vengeance 32GB', ['Speed' => 'DDR5-6000MHz CL30']);

        $this->postJson('/api/pc-builder/check', [
            'selection' => ['cpu' => $cpu->id, 'motherboard' => $board->id, 'ram' => $ram->id],
        ])->assertStatus(200)
            ->assertJsonPath('data.status', PcCompatibilityService::PASS)
            ->assertJsonPath('data.issues', [])
            ->assertJsonPath('message', 'All selected parts are compatible.');
    }

    public function test_check_returns_a_power_estimate(): void
    {
        $cpu = $this->part('cpu', 'Intel Core i9-14900KS', ['Socket' => 'LGA1700', 'TDP' => '150W PBP / 253W MTP']);
        $gpu = $this->part('graphics-card', 'RTX 4090', ['TDP' => '450W']);

        $this->postJson('/api/pc-builder/check', [
            'selection' => ['cpu' => $cpu->id, 'graphics-card' => $gpu->id],
        ])->assertStatus(200)
            ->assertJsonPath('data.power.estimated', 803)
            ->assertJsonPath('data.power.recommended', 1000);
    }

    public function test_check_rejects_an_unknown_product(): void
    {
        $this->postJson('/api/pc-builder/check', [
            'selection' => ['cpu' => 999999],
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_check_requires_a_selection(): void
    {
        $this->postJson('/api/pc-builder/check', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    /**
     * The picker said "Showing N compatible components" while listing everything
     * in the category. Candidates now carry a real per-item verdict.
     */
    public function test_component_listing_annotates_candidates_against_the_build(): void
    {
        $amdCpu = $this->part('cpu', 'AMD Ryzen 7 7800X3D', ['Socket' => 'AM5', 'TDP' => '120W']);
        $intelCpu = $this->part('cpu', 'Intel Core i5-14600K', ['Socket' => 'LGA1700', 'TDP' => '125W']);
        $intelBoard = $this->part('motherboard', 'MSI Z790 Ace', ['Socket' => 'LGA1700']);

        $response = $this->getJson(
            '/api/pc-builder/components/cpu?selection[motherboard]='.$intelBoard->id
        );

        $response->assertStatus(200);

        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame(PcCompatibilityService::FAIL, $byId[$amdCpu->id]['compatibility']['status']);
        $this->assertNotNull($byId[$amdCpu->id]['compatibility']['reason']);
        $this->assertSame(PcCompatibilityService::PASS, $byId[$intelCpu->id]['compatibility']['status']);
    }

    public function test_component_listing_works_with_no_selection(): void
    {
        $this->part('cpu', 'AMD Ryzen 7 7800X3D', ['Socket' => 'AM5']);

        $response = $this->getJson('/api/pc-builder/components/cpu');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.compatibility.status', PcCompatibilityService::PASS);
    }

    public function test_a_part_with_no_socket_spec_is_flagged_unverified_not_compatible(): void
    {
        $mystery = $this->part('cpu', 'Unbranded CPU');
        $board = $this->part('motherboard', 'ASUS X870E', ['Socket' => 'AM5']);

        $response = $this->getJson('/api/pc-builder/components/cpu?selection[motherboard]='.$board->id);

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertSame(
            PcCompatibilityService::UNKNOWN,
            $byId[$mystery->id]['compatibility']['status'],
            'Missing spec data must never be presented as verified compatibility.'
        );
    }
}
