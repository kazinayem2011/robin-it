<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Compatibility rules for the PC Builder.
 *
 * The builder advertises an "Instant Compatibility Matrix" but previously filtered
 * on category alone, so a customer could pair an AM5 CPU with an LGA1700 board and
 * buy parts that physically do not fit.
 *
 * Every check is three-state on purpose:
 *
 *   PASS     a rule ran against real spec data and the parts fit
 *   FAIL     a rule ran and the parts genuinely conflict
 *   UNKNOWN  the spec needed is missing from the catalogue
 *
 * UNKNOWN must never be reported as compatible. Claiming a fit we have not
 * verified is the bug we are fixing, and quietly guessing would just reintroduce
 * it in a new form. Missing data is surfaced to the customer as "can't verify".
 */
class PcCompatibilityService
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const UNKNOWN = 'unknown';

    /** Slots the builder understands, keyed by category slug. */
    public const SLOT_CPU = 'cpu';

    public const SLOT_MOTHERBOARD = 'motherboard';

    public const SLOT_RAM = 'ram';

    public const SLOT_GPU = 'graphics-card';

    public const SLOT_PSU = 'power-supply';

    public const SLOT_COOLER = 'cpu-cooler';

    public const SLOT_CASE = 'pc-case';

    /**
     * Headroom over measured draw before we consider a PSU adequate.
     *
     * The figures being summed are already peak values (Intel MTP, GPU TGP), so
     * this covers transient spikes and efficiency derating only. A larger factor
     * double-counts headroom and starts rejecting perfectly good supplies — a
     * 1000W unit is the standard recommendation for an i9 + RTX 4090, and telling
     * that customer it is too small would be a false alarm.
     */
    private const PSU_HEADROOM = 1.2;

    /** Assumed draw for parts we do not have a TDP for (board, drives, fans). */
    private const BASE_SYSTEM_DRAW = 100;

    /**
     * Analyse a full or partial build.
     *
     * @param  array<string, Product>  $selection  slot => product
     * @return array{
     *     status: string,
     *     issues: array<int, array<string, mixed>>,
     *     checks: array<int, array<string, mixed>>,
     *     power: array<string, mixed>
     * }
     */
    public function analyse(array $selection): array
    {
        $checks = array_values(array_filter([
            $this->checkCpuSocket($selection),
            $this->checkCoolerSocket($selection),
            $this->checkMemoryType($selection),
            $this->checkCaseFormFactor($selection),
            $this->checkPowerSupply($selection),
        ]));

        $issues = array_values(array_filter(
            $checks,
            fn ($c) => $c['status'] !== self::PASS
        ));

        return [
            'status' => $this->overallStatus($checks),
            'issues' => $issues,
            'checks' => $checks,
            'power' => $this->powerSummary($selection),
        ];
    }

    /**
     * Annotate candidate products for one slot against the current selection, so
     * the picker can show what actually fits instead of claiming everything does.
     *
     * @param  array<string, Product>  $selection
     * @return Collection<int, array{product: Product, status: string, reason: ?string}>
     */
    public function annotateCandidates(string $slot, Collection $candidates, array $selection): Collection
    {
        return $candidates->map(function (Product $candidate) use ($slot, $selection) {
            // Test the candidate as though it were chosen for this slot.
            $hypothetical = array_merge($selection, [$slot => $candidate]);
            $result = $this->analyse($hypothetical);

            // Only conflicts that actually involve this slot matter here.
            $relevant = array_values(array_filter(
                $result['issues'],
                fn ($i) => in_array($slot, $i['slots'], true)
            ));

            $failing = array_values(array_filter($relevant, fn ($i) => $i['status'] === self::FAIL));

            return [
                'product' => $candidate,
                'status' => $failing !== []
                    ? self::FAIL
                    : ($relevant !== [] ? self::UNKNOWN : self::PASS),
                'reason' => $failing[0]['message'] ?? ($relevant[0]['message'] ?? null),
            ];
        });
    }

    // ---------------------------------------------------------------- rules

    /**
     * The load-bearing rule: a CPU only physically seats in a matching socket.
     */
    private function checkCpuSocket(array $selection): ?array
    {
        $cpu = $selection[self::SLOT_CPU] ?? null;
        $board = $selection[self::SLOT_MOTHERBOARD] ?? null;

        if (! $cpu || ! $board) {
            return null;
        }

        $cpuSocket = $this->socket($cpu);
        $boardSocket = $this->socket($board);

        if (! $cpuSocket || ! $boardSocket) {
            return $this->check(
                'cpu_socket', self::UNKNOWN, [self::SLOT_CPU, self::SLOT_MOTHERBOARD],
                'We could not confirm the socket for '
                    .(! $cpuSocket ? "\"{$cpu->name}\"" : "\"{$board->name}\"")
                    .'. Please check the socket matches before ordering.'
            );
        }

        if ($cpuSocket !== $boardSocket) {
            return $this->check(
                'cpu_socket', self::FAIL, [self::SLOT_CPU, self::SLOT_MOTHERBOARD],
                "\"{$cpu->name}\" uses the {$cpuSocket} socket, but \"{$board->name}\" has an {$boardSocket} socket. "
                ."These cannot be used together — choose a motherboard with the {$cpuSocket} socket."
            );
        }

        return $this->check(
            'cpu_socket', self::PASS, [self::SLOT_CPU, self::SLOT_MOTHERBOARD],
            "Processor and motherboard both use the {$cpuSocket} socket."
        );
    }

    /**
     * A cooler must have a mounting kit for the CPU socket.
     */
    private function checkCoolerSocket(array $selection): ?array
    {
        $cooler = $selection[self::SLOT_COOLER] ?? null;
        $cpu = $selection[self::SLOT_CPU] ?? null;

        if (! $cooler || ! $cpu) {
            return null;
        }

        $cpuSocket = $this->socket($cpu);
        $supported = $this->supportedSockets($cooler);

        if (! $cpuSocket || $supported === []) {
            return $this->check(
                'cooler_socket', self::UNKNOWN, [self::SLOT_COOLER, self::SLOT_CPU],
                "We could not confirm that \"{$cooler->name}\" ships with a bracket for this processor. "
                .'Please check the supported sockets before ordering.'
            );
        }

        if (! in_array($cpuSocket, $supported, true)) {
            return $this->check(
                'cooler_socket', self::FAIL, [self::SLOT_COOLER, self::SLOT_CPU],
                "\"{$cooler->name}\" does not list support for the {$cpuSocket} socket used by \"{$cpu->name}\"."
            );
        }

        return $this->check(
            'cooler_socket', self::PASS, [self::SLOT_COOLER, self::SLOT_CPU],
            "Cooler supports the {$cpuSocket} socket."
        );
    }

    /**
     * DDR generations are physically keyed differently — DDR4 will not seat in a
     * DDR5 slot.
     */
    private function checkMemoryType(array $selection): ?array
    {
        $ram = $selection[self::SLOT_RAM] ?? null;
        $board = $selection[self::SLOT_MOTHERBOARD] ?? null;

        if (! $ram || ! $board) {
            return null;
        }

        $ramType = $this->memoryType($ram);
        $boardType = $this->memoryType($board);

        if (! $ramType || ! $boardType) {
            return $this->check(
                'memory_type', self::UNKNOWN, [self::SLOT_RAM, self::SLOT_MOTHERBOARD],
                'We could not confirm the memory generation for this pairing. '
                .'Please check the motherboard supports this RAM before ordering.'
            );
        }

        if ($ramType !== $boardType) {
            return $this->check(
                'memory_type', self::FAIL, [self::SLOT_RAM, self::SLOT_MOTHERBOARD],
                "\"{$ram->name}\" is {$ramType} memory, but \"{$board->name}\" takes {$boardType}. "
                ."These are not interchangeable — pick {$boardType} memory."
            );
        }

        return $this->check(
            'memory_type', self::PASS, [self::SLOT_RAM, self::SLOT_MOTHERBOARD],
            "Memory and motherboard are both {$ramType}."
        );
    }

    /**
     * A full ATX board will not fit a Mini-ITX chassis.
     */
    private function checkCaseFormFactor(array $selection): ?array
    {
        $case = $selection[self::SLOT_CASE] ?? null;
        $board = $selection[self::SLOT_MOTHERBOARD] ?? null;

        if (! $case || ! $board) {
            return null;
        }

        $boardSize = $this->formFactor($board);
        $caseSupports = $this->supportedFormFactors($case);

        if (! $boardSize || $caseSupports === []) {
            return $this->check(
                'case_form_factor', self::UNKNOWN, [self::SLOT_CASE, self::SLOT_MOTHERBOARD],
                'We could not confirm the case supports this motherboard size. '
                .'Please check the form factor before ordering.'
            );
        }

        if (! in_array($boardSize, $caseSupports, true)) {
            return $this->check(
                'case_form_factor', self::FAIL, [self::SLOT_CASE, self::SLOT_MOTHERBOARD],
                "\"{$board->name}\" is {$boardSize}, which \"{$case->name}\" does not list support for."
            );
        }

        return $this->check(
            'case_form_factor', self::PASS, [self::SLOT_CASE, self::SLOT_MOTHERBOARD],
            "Case accepts {$boardSize} motherboards."
        );
    }

    /**
     * An undersized PSU is the most common cause of an unstable new build.
     */
    private function checkPowerSupply(array $selection): ?array
    {
        $psu = $selection[self::SLOT_PSU] ?? null;

        if (! $psu) {
            return null;
        }

        $power = $this->powerSummary($selection);
        $available = $this->psuWattage($psu);

        if (! $available) {
            return $this->check(
                'power_supply', self::UNKNOWN, [self::SLOT_PSU],
                "We could not read the wattage for \"{$psu->name}\". "
                ."This build is estimated to need around {$power['recommended']}W."
            );
        }

        if ($available < $power['recommended']) {
            return $this->check(
                'power_supply', self::FAIL, [self::SLOT_PSU],
                "\"{$psu->name}\" supplies {$available}W, but this build draws about {$power['estimated']}W "
                ."and we recommend at least {$power['recommended']}W for stable operation under load."
            );
        }

        return $this->check(
            'power_supply', self::PASS, [self::SLOT_PSU],
            "{$available}W is enough for this build (about {$power['estimated']}W under load)."
        );
    }

    // ---------------------------------------------------------------- power

    /**
     * @param  array<string, Product>  $selection
     * @return array{estimated: int, recommended: int, breakdown: array<string, int>}
     */
    public function powerSummary(array $selection): array
    {
        $breakdown = [];
        $total = self::BASE_SYSTEM_DRAW;

        foreach ([self::SLOT_CPU, self::SLOT_GPU] as $slot) {
            $product = $selection[$slot] ?? null;
            if (! $product) {
                continue;
            }

            $draw = $this->powerDraw($product);
            if ($draw !== null) {
                $breakdown[$slot] = $draw;
                $total += $draw;
            }
        }

        $breakdown['base'] = self::BASE_SYSTEM_DRAW;

        return [
            'estimated' => $total,
            // Rounded up to the nearest 50W, the way PSUs are actually sold.
            'recommended' => (int) (ceil(($total * self::PSU_HEADROOM) / 50) * 50),
            'breakdown' => $breakdown,
        ];
    }

    // ---------------------------------------------------------------- spec parsing

    /** e.g. "AM5", "LGA1700" */
    public function socket(Product $product): ?string
    {
        $raw = $product->spec(['Socket', 'CPU Socket']);

        if (! $raw) {
            return null;
        }

        return $this->normaliseSocket($raw);
    }

    /**
     * Coolers list several sockets, e.g. "LGA1700 / AM5 / AM4".
     *
     * @return array<int, string>
     */
    public function supportedSockets(Product $product): array
    {
        $raw = $product->spec(['Socket', 'Compatibility', 'Supported Sockets']);

        if (! $raw) {
            return [];
        }

        $parts = preg_split('/[,\/|+]|\s+and\s+/i', $raw) ?: [];

        return array_values(array_unique(array_filter(
            array_map(fn ($p) => $this->normaliseSocket($p), $parts)
        )));
    }

    /** e.g. "DDR5" */
    public function memoryType(Product $product): ?string
    {
        $raw = $product->spec(['Memory Type', 'Memory', 'Speed', 'Type']);

        if ($raw && preg_match('/DDR\s*-?\s*(\d)/i', $raw, $m)) {
            return 'DDR'.$m[1];
        }

        // Fall back to the product name: boards and kits nearly always say DDR5.
        if (preg_match('/DDR\s*-?\s*(\d)/i', $product->name, $m)) {
            return 'DDR'.$m[1];
        }

        return null;
    }

    /** e.g. "ATX", "Micro-ATX", "Mini-ITX" */
    public function formFactor(Product $product): ?string
    {
        $raw = $product->spec(['Form Factor', 'Motherboard Support', 'Size']) ?? $product->name;

        return $this->normaliseFormFactor($raw);
    }

    /**
     * @return array<int, string>
     */
    public function supportedFormFactors(Product $product): array
    {
        $raw = $product->spec(['Motherboard Support', 'Form Factor', 'Compatibility']);

        if (! $raw) {
            return [];
        }

        $parts = preg_split('/[,\/|+]|\s+and\s+/i', $raw) ?: [];

        $found = array_values(array_unique(array_filter(
            array_map(fn ($p) => $this->normaliseFormFactor($p), $parts)
        )));

        // A chassis that takes full ATX also takes the smaller sizes.
        if (in_array('ATX', $found, true)) {
            $found = array_values(array_unique(array_merge($found, ['Micro-ATX', 'Mini-ITX'])));
        } elseif (in_array('Micro-ATX', $found, true)) {
            $found = array_values(array_unique(array_merge($found, ['Mini-ITX'])));
        }

        return $found;
    }

    /**
     * Peak draw in watts. Intel lists two figures ("150W PBP / 253W MTP");
     * sizing a PSU against the lower one would under-spec the build, so take the max.
     */
    public function powerDraw(Product $product): ?int
    {
        $raw = $product->spec(['TDP', 'Power Draw', 'Wattage', 'Power']);

        if (! $raw || ! preg_match_all('/(\d+)\s*W/i', $raw, $m)) {
            return null;
        }

        return max(array_map('intval', $m[1]));
    }

    /** Rated output of a power supply. */
    public function psuWattage(Product $product): ?int
    {
        $raw = $product->spec(['Wattage', 'Power Output', 'Output', 'Power']);

        if ($raw && preg_match('/(\d+)\s*W/i', $raw, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/(\d{3,4})\s*W/i', $product->name, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    // ---------------------------------------------------------------- helpers

    private function normaliseSocket(string $raw): ?string
    {
        $value = strtoupper(preg_replace('/[\s\-_]/', '', $raw) ?? '');

        // "SOCKETAM5" -> "AM5"
        $value = preg_replace('/^SOCKET/', '', $value) ?? $value;

        if (preg_match('/(LGA\d{3,4})/', $value, $m)) {
            return $m[1];
        }

        if (preg_match('/\b(AM[45]|TR5|STRX4|SP\d)\b/', $value, $m)) {
            return $m[1];
        }

        // Bare "AM5"/"AM4" with no word boundary once punctuation is stripped.
        if (preg_match('/^(AM[45])$/', $value, $m)) {
            return $m[1];
        }

        return $value !== '' ? $value : null;
    }

    private function normaliseFormFactor(string $raw): ?string
    {
        $value = strtolower($raw);

        return match (true) {
            str_contains($value, 'mini-itx') || str_contains($value, 'mini itx') || str_contains($value, 'itx') => 'Mini-ITX',
            str_contains($value, 'micro-atx') || str_contains($value, 'micro atx') || str_contains($value, 'matx') || str_contains($value, 'm-atx') => 'Micro-ATX',
            str_contains($value, 'e-atx') || str_contains($value, 'eatx') => 'E-ATX',
            str_contains($value, 'atx') => 'ATX',
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $slots
     * @return array<string, mixed>
     */
    private function check(string $rule, string $status, array $slots, string $message): array
    {
        return [
            'rule' => $rule,
            'status' => $status,
            'slots' => $slots,
            'message' => $message,
        ];
    }

    /**
     * A build is only "compatible" when every applicable rule passed. Any
     * unverifiable rule downgrades the whole build to "unknown".
     *
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function overallStatus(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check['status'] === self::FAIL) {
                return self::FAIL;
            }
        }

        foreach ($checks as $check) {
            if ($check['status'] === self::UNKNOWN) {
                return self::UNKNOWN;
            }
        }

        return $checks === [] ? self::UNKNOWN : self::PASS;
    }
}
