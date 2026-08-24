<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\PcCompatibilityService;
use App\Services\ProductService;
use Database\Seeders\CaseAndCoolerSeeder;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A build that can actually be completed.
 *
 * Cases and coolers were missing entirely, so no build could be finished and
 * the form-factor and cooler-socket rules never ran — they had nothing to check
 * against. These assert the catalogue can exercise them, and that the parts
 * carry the specs the engine reads rather than silently reporting "unknown".
 */
class BuildableCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(CaseAndCoolerSeeder::class);
    }

    private function compatibility(): PcCompatibilityService
    {
        return app(PcCompatibilityService::class);
    }

    private function product(string $slug): Product
    {
        return Product::with('specifications')->where('slug', $slug)->firstOrFail();
    }

    public function test_every_required_builder_slot_has_something_to_pick(): void
    {
        $slots = collect(app(ProductService::class)->getPcBuilderCategories());

        $empty = $slots->where('required', true)->where('available', 0)->pluck('name');

        $this->assertEmpty(
            $empty,
            'a build cannot be completed; these required slots are empty: '.$empty->implode(', ')
        );
    }

    public function test_cases_carry_the_spec_the_engine_reads(): void
    {
        foreach (['lian-li-lancool-216', 'nzxt-h7-flow', 'cooler-master-nr200p'] as $slug) {
            $this->assertSame(
                [],
                $this->compatibility()->missingSpecsFor($this->product($slug)),
                "{$slug} cannot be compatibility-checked"
            );
        }
    }

    public function test_coolers_carry_the_spec_the_engine_reads(): void
    {
        foreach (['noctua-nh-d15-g2', 'corsair-h150i-elite', 'thermaltake-tough-240'] as $slug) {
            $this->assertSame(
                [],
                $this->compatibility()->missingSpecsFor($this->product($slug)),
                "{$slug} cannot be compatibility-checked"
            );
        }
    }

    /** The rule this catalogue could never exercise before. */
    public function test_an_atx_board_is_refused_by_a_mini_itx_case(): void
    {
        $result = $this->compatibility()->analyse([
            'motherboard' => $this->product('msi-meg-z790-ace-max'),
            'pc-case' => $this->product('cooler-master-nr200p'),
        ]);

        $messages = collect($result['issues'] ?? [])->pluck('message')->implode(' ');

        $this->assertStringContainsString('ATX', $messages);
        $this->assertStringContainsString('NR200P', $messages);
    }

    public function test_an_atx_board_fits_an_atx_case(): void
    {
        $result = $this->compatibility()->analyse([
            'motherboard' => $this->product('msi-meg-z790-ace-max'),
            'pc-case' => $this->product('lian-li-lancool-216'),
        ]);

        $failures = collect($result['issues'] ?? [])->where('status', PcCompatibilityService::FAIL);

        $this->assertCount(0, $failures, 'an ATX board was refused by an ATX case');
    }

    public function test_a_cooler_that_lists_the_socket_is_accepted(): void
    {
        $result = $this->compatibility()->analyse([
            'cpu' => $this->product('intel-core-i9-14900ks'),
            'cpu-cooler' => $this->product('noctua-nh-d15-g2'),
        ]);

        $failures = collect($result['issues'] ?? [])->where('status', PcCompatibilityService::FAIL);

        $this->assertCount(0, $failures);
    }

    /** Seeded stock is explained by the ledger like every other unit. */
    public function test_seeded_stock_is_recorded_in_the_ledger(): void
    {
        $case = $this->product('lian-li-lancool-216');

        $ledger = (int) StockMovement::where('product_id', $case->id)->sum('quantity');

        $this->assertSame($case->stock_quantity, $ledger);
        $this->assertGreaterThan(0, $ledger);
    }

    /** Re-running must not double the stock or duplicate the products. */
    public function test_the_seeder_is_safe_to_run_twice(): void
    {
        $before = Product::where('slug', 'lian-li-lancool-216')->first();

        $this->seed(CaseAndCoolerSeeder::class);

        $after = Product::where('slug', 'lian-li-lancool-216')->get();

        $this->assertCount(1, $after, 'the product was duplicated');
        $this->assertSame($before->stock_quantity, $after->first()->stock_quantity);
    }
}
