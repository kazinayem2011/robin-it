<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Services\PcCompatibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The PC Builder advertises an "Instant Compatibility Matrix" but filtered on
 * category alone, so an AM5 processor could be paired with an LGA1700 board and
 * sold as a working build.
 *
 * Spec values here mirror the real catalogue exactly, e.g. the Intel flagship's
 * TDP really is recorded as "150W PBP / 253W MTP".
 */
class PcCompatibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private PcCompatibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PcCompatibilityService::class);
    }

    /**
     * @param  array<string, string>  $specs
     */
    private function part(string $slug, string $name, array $specs = []): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'is_active' => true]
        );

        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'price' => 50000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        foreach ($specs as $key => $value) {
            $product->specifications()->create(['name' => $key, 'value' => $value]);
        }

        return $product->load('specifications');
    }

    private function ryzenAm5(): Product
    {
        return $this->part('cpu', 'AMD Ryzen 7 7800X3D 8-Core AM5 3D V-Cache Gaming Processor', [
            'Socket' => 'AM5', 'TDP' => '120W',
        ]);
    }

    private function intelLga1700(): Product
    {
        return $this->part('cpu', 'Intel Core i9-14900KS 24-Core 6.2GHz Flagship CPU', [
            'Socket' => 'LGA1700', 'TDP' => '150W PBP / 253W MTP',
        ]);
    }

    private function boardAm5(): Product
    {
        return $this->part('motherboard', 'ASUS ROG Crosshair X870E Hero Wi-Fi 7 ATX Motherboard (AM5)', [
            'Socket' => 'AM5', 'Chipset' => 'AMD X870E', 'Memory' => '4x DDR5 slots (Up to 256GB)',
        ]);
    }

    private function boardLga1700(): Product
    {
        return $this->part('motherboard', 'MSI MEG Z790 Ace Max Wi-Fi 6E LGA1700 ATX Motherboard', [
            'Socket' => 'LGA1700', 'Chipset' => 'Intel Z790', 'Memory' => '4x DDR5 slots',
        ]);
    }

    // ------------------------------------------------------------ socket

    public function test_an_am5_cpu_on_an_lga1700_board_is_reported_incompatible(): void
    {
        $result = $this->service->analyse([
            'cpu' => $this->ryzenAm5(),
            'motherboard' => $this->boardLga1700(),
        ]);

        $this->assertSame(PcCompatibilityService::FAIL, $result['status']);

        $issue = collect($result['issues'])->firstWhere('rule', 'cpu_socket');
        $this->assertNotNull($issue);
        $this->assertSame(PcCompatibilityService::FAIL, $issue['status']);
        $this->assertStringContainsString('AM5', $issue['message']);
        $this->assertStringContainsString('LGA1700', $issue['message']);
        $this->assertStringNotContainsString(' a AM5', $issue['message'], 'Article should read "an".');
    }

    public function test_a_matching_socket_pair_passes(): void
    {
        $result = $this->service->analyse([
            'cpu' => $this->ryzenAm5(),
            'motherboard' => $this->boardAm5(),
        ]);

        $this->assertSame(PcCompatibilityService::PASS, $result['status']);
        $this->assertSame([], $result['issues']);
    }

    public function test_an_intel_cpu_on_an_intel_board_passes(): void
    {
        $result = $this->service->analyse([
            'cpu' => $this->intelLga1700(),
            'motherboard' => $this->boardLga1700(),
        ]);

        $this->assertSame(PcCompatibilityService::PASS, $result['status']);
    }

    public static function socketFormatProvider(): array
    {
        return [
            'plain' => ['AM5', 'AM5'],
            'with socket prefix' => ['Socket AM5', 'AM5'],
            'lowercase' => ['am5', 'AM5'],
            'spaced lga' => ['LGA 1700', 'LGA1700'],
            'hyphenated' => ['LGA-1700', 'LGA1700'],
            'padded' => ['  AM5  ', 'AM5'],
        ];
    }

    /**
     * The catalogue is hand-entered, so the same socket appears in several forms.
     */
    #[DataProvider('socketFormatProvider')]
    public function test_socket_values_are_normalised(string $raw, string $expected): void
    {
        $cpu = $this->part('cpu', 'Test CPU', ['Socket' => $raw]);

        $this->assertSame($expected, $this->service->socket($cpu));
    }

    // ------------------------------------------------------------ unknown handling

    public function test_a_missing_socket_reports_unverified_rather_than_compatible(): void
    {
        $result = $this->service->analyse([
            'cpu' => $this->part('cpu', 'Mystery CPU'),   // no Socket spec
            'motherboard' => $this->boardAm5(),
        ]);

        $this->assertSame(PcCompatibilityService::UNKNOWN, $result['status']);

        $issue = collect($result['issues'])->firstWhere('rule', 'cpu_socket');
        $this->assertSame(PcCompatibilityService::UNKNOWN, $issue['status']);
        $this->assertStringContainsString('could not confirm', $issue['message']);
    }

    public function test_one_unverifiable_rule_downgrades_the_whole_build(): void
    {
        $result = $this->service->analyse([
            'cpu' => $this->ryzenAm5(),
            'motherboard' => $this->boardAm5(),               // socket passes
            'cpu-cooler' => $this->part('cpu-cooler', 'Nameless Cooler'), // unverifiable
        ]);

        $this->assertSame(PcCompatibilityService::UNKNOWN, $result['status']);
    }

    public function test_a_hard_conflict_outranks_an_unverifiable_rule(): void
    {
        $result = $this->service->analyse([
            'cpu' => $this->ryzenAm5(),
            'motherboard' => $this->boardLga1700(),            // hard conflict
            'cpu-cooler' => $this->part('cpu-cooler', 'Nameless Cooler'),
        ]);

        $this->assertSame(PcCompatibilityService::FAIL, $result['status']);
    }

    public function test_an_empty_build_is_not_claimed_compatible(): void
    {
        $this->assertSame(PcCompatibilityService::UNKNOWN, $this->service->analyse([])['status']);
    }

    public function test_a_lone_component_raises_no_conflict(): void
    {
        $result = $this->service->analyse(['cpu' => $this->ryzenAm5()]);

        $this->assertSame([], $result['issues'], 'A single part cannot conflict with anything.');
    }

    // ------------------------------------------------------------ memory

    public function test_ddr4_memory_in_a_ddr5_board_is_rejected(): void
    {
        $result = $this->service->analyse([
            'motherboard' => $this->boardAm5(),
            'ram' => $this->part('ram', 'Corsair Vengeance LPX 32GB Kit', [
                'Speed' => 'DDR4-3600MHz CL18',
            ]),
        ]);

        $issue = collect($result['issues'])->firstWhere('rule', 'memory_type');
        $this->assertSame(PcCompatibilityService::FAIL, $issue['status']);
        $this->assertStringContainsString('DDR4', $issue['message']);
        $this->assertStringContainsString('DDR5', $issue['message']);
    }

    public function test_matching_memory_generation_passes(): void
    {
        $result = $this->service->analyse([
            'motherboard' => $this->boardAm5(),
            'ram' => $this->part('ram', 'Corsair Vengeance RGB 32GB DDR5 6000MHz', [
                'Speed' => 'DDR5-6000MHz CL30',
            ]),
        ]);

        $this->assertSame(PcCompatibilityService::PASS, $result['status']);
    }

    // ------------------------------------------------------------ power

    public function test_intel_dual_tdp_notation_uses_the_higher_figure(): void
    {
        // "150W PBP / 253W MTP" — sizing against 150W would under-spec the PSU.
        $this->assertSame(253, $this->service->powerDraw($this->intelLga1700()));
    }

    public function test_an_undersized_psu_is_rejected(): void
    {
        $result = $this->service->analyse([
            'cpu' => $this->intelLga1700(),                              // 253W
            'graphics-card' => $this->part('graphics-card', 'RTX 4090', ['TDP' => '450W']),
            'power-supply' => $this->part('power-supply', 'Corsair CX550 550W', ['Wattage' => '550W']),
        ]);

        $issue = collect($result['issues'])->firstWhere('rule', 'power_supply');
        $this->assertSame(PcCompatibilityService::FAIL, $issue['status']);
        $this->assertStringContainsString('550W', $issue['message']);
    }

    public function test_an_adequate_psu_passes(): void
    {
        $result = $this->service->analyse([
            'cpu' => $this->intelLga1700(),
            'graphics-card' => $this->part('graphics-card', 'RTX 4090', ['TDP' => '450W']),
            'power-supply' => $this->part('power-supply', 'Corsair RM1000e 1000W', ['Wattage' => '1000W']),
        ]);

        $issue = collect($result['checks'])->firstWhere('rule', 'power_supply');
        $this->assertSame(PcCompatibilityService::PASS, $issue['status']);
    }

    public function test_power_estimate_includes_headroom_and_base_draw(): void
    {
        $power = $this->service->powerSummary([
            'cpu' => $this->intelLga1700(),                              // 253
            'graphics-card' => $this->part('graphics-card', 'RTX 4090', ['TDP' => '450W']), // 450
        ]);

        $this->assertSame(803, $power['estimated'], '253 + 450 + 100 base');
        $this->assertSame(1000, $power['recommended'], '803 * 1.2, rounded up to a 50W step');
        $this->assertSame(253, $power['breakdown']['cpu']);
    }

    // ------------------------------------------------------------ cooler & case

    public function test_a_cooler_listing_multiple_sockets_matches_any_of_them(): void
    {
        $cooler = $this->part('cpu-cooler', 'Noctua NH-D15', [
            'Socket' => 'LGA1700 / AM5 / AM4',
        ]);

        $this->assertSame(['LGA1700', 'AM5', 'AM4'], $this->service->supportedSockets($cooler));

        $result = $this->service->analyse(['cpu' => $this->ryzenAm5(), 'cpu-cooler' => $cooler]);
        $check = collect($result['checks'])->firstWhere('rule', 'cooler_socket');
        $this->assertSame(PcCompatibilityService::PASS, $check['status']);
    }

    public function test_a_cooler_without_the_needed_bracket_is_rejected(): void
    {
        $cooler = $this->part('cpu-cooler', 'Intel-only Cooler', ['Socket' => 'LGA1700']);

        $result = $this->service->analyse(['cpu' => $this->ryzenAm5(), 'cpu-cooler' => $cooler]);
        $issue = collect($result['issues'])->firstWhere('rule', 'cooler_socket');

        $this->assertSame(PcCompatibilityService::FAIL, $issue['status']);
    }

    public function test_an_atx_case_accepts_smaller_boards(): void
    {
        $case = $this->part('pc-case', 'Lian Li O11 Dynamic', [
            'Motherboard Support' => 'ATX',
        ]);

        $this->assertSame(['ATX', 'Micro-ATX', 'Mini-ITX'], $this->service->supportedFormFactors($case));
    }

    public function test_an_atx_board_does_not_fit_a_mini_itx_case(): void
    {
        $result = $this->service->analyse([
            'motherboard' => $this->boardAm5(),   // "ATX" appears in the product name
            'pc-case' => $this->part('pc-case', 'Tiny Case', ['Motherboard Support' => 'Mini-ITX']),
        ]);

        $issue = collect($result['issues'])->firstWhere('rule', 'case_form_factor');
        $this->assertSame(PcCompatibilityService::FAIL, $issue['status']);
    }

    // ------------------------------------------------------------ candidate annotation

    public function test_candidates_are_annotated_against_the_current_selection(): void
    {
        $selection = ['motherboard' => $this->boardLga1700()];

        $candidates = collect([$this->ryzenAm5(), $this->intelLga1700()]);
        $annotated = $this->service->annotateCandidates('cpu', $candidates, $selection);

        $this->assertSame(PcCompatibilityService::FAIL, $annotated[0]['status'], 'AM5 CPU on an Intel board');
        $this->assertNotNull($annotated[0]['reason']);
        $this->assertSame(PcCompatibilityService::PASS, $annotated[1]['status'], 'LGA1700 CPU on an Intel board');
    }

    public function test_candidates_are_all_selectable_when_nothing_constrains_them(): void
    {
        $annotated = $this->service->annotateCandidates(
            'cpu',
            collect([$this->ryzenAm5(), $this->intelLga1700()]),
            []
        );

        $this->assertSame(
            [PcCompatibilityService::PASS, PcCompatibilityService::PASS],
            $annotated->pluck('status')->all()
        );
    }
}
