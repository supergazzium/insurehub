<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Policy;
use App\Models\Product;
use App\Models\ProductCommissionBand;
use App\Models\ProductCommissionRate;

/**
 * C-20: A frozen copy of a product's commission basis, captured onto a policy
 * at create time (PolicyObserver) and read back by the rate resolvers instead
 * of the live product tables.
 *
 * WHY: product_commission_rates / product_commission_bands are edited in place
 * on the Product form. The MGM resolvers read them at PAYMENT time, so a rate
 * edit retroactively re-prices commission on already-created policies. Freezing
 * the basis at creation makes a policy's commission immutable to later product
 * edits — the requirement is "commission for this new policy keeps the rate
 * that was in force when the policy was created".
 *
 * This class is both:
 *   - a BUILDER: `capture(Product)` walks the product's live rate rows + bands
 *     and returns the array persisted into policies.commission_snapshot.
 *   - a READER: `fromPolicy(Policy)` rehydrates the array and exposes the same
 *     lookups the resolvers need (`rateRow(direction)`, `bands(direction)`),
 *     returning plain (unsaved) ProductCommissionRate / ProductCommissionBand
 *     models so the resolvers' existing matching/column logic works unchanged.
 *
 * Snapshot format version `v` guards future shape changes; readers ignore
 * versions they don't understand and fall back to live resolution.
 */
final class CommissionSnapshot
{
    /** Current snapshot schema version. Bump when the array shape changes. */
    public const VERSION = 1;

    /** Rate-row columns frozen per product_commission_rates row. */
    private const RATE_COLUMNS = [
        'direction', 'scheme', 'flat_rate',
        'yr_1', 'yr_2', 'yr_3', 'yr_4', 'yr_5', 'yr_6_10', 'yr_11_up',
        'effective_from',
    ];

    /** Band columns frozen per product_commission_bands row. */
    private const BAND_COLUMNS = [
        'direction', 'band_seq',
        'sum_assured_min', 'sum_assured_max', 'entry_age_min', 'entry_age_max',
        'yr_1', 'yr_2', 'yr_3', 'yr_4', 'yr_5', 'yr_6_up',
        'effective_from',
    ];

    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data) {}

    /**
     * BUILDER — freeze a product's commission basis. Returns the array to
     * persist into policies.commission_snapshot, or null when the product
     * carries no commission rows at all (nothing to freeze → resolve live).
     *
     * @return array<string,mixed>|null
     */
    public static function capture(Product $product): ?array
    {
        $rates = ProductCommissionRate::query()
            ->where('product_id', $product->id)
            ->get()
            ->map(fn (ProductCommissionRate $r): array => self::pluck($r, self::RATE_COLUMNS))
            ->all();

        $bands = ProductCommissionBand::query()
            ->where('product_id', $product->id)
            ->orderBy('band_seq')
            ->get()
            ->map(fn (ProductCommissionBand $b): array => self::pluck($b, self::BAND_COLUMNS))
            ->all();

        if ($rates === [] && $bands === []) {
            return null;
        }

        return [
            'v' => self::VERSION,
            'captured_at' => now()->toIso8601String(),
            'product_id' => (int) $product->id,
            'rates' => $rates,
            'bands' => $bands,
        ];
    }

    /**
     * READER — rehydrate the snapshot off a policy. Returns null when the
     * policy has no snapshot or the version is unrecognised, signalling the
     * caller to resolve against live product tables.
     */
    public static function fromPolicy(Policy $policy): ?self
    {
        $data = $policy->commission_snapshot;
        if (! is_array($data) || ($data['v'] ?? null) !== self::VERSION) {
            return null;
        }

        return new self($data);
    }

    /**
     * The frozen product_commission_rates row for a direction, as an unsaved
     * model the resolver can read columns off (mirrors the live
     * orderByDesc(effective_from)->first() single-row semantics). Null when
     * the snapshot didn't capture that direction.
     */
    public function rateRow(string $direction): ?ProductCommissionRate
    {
        $rows = array_values(array_filter(
            $this->data['rates'] ?? [],
            fn (array $r): bool => ($r['direction'] ?? null) === $direction,
        ));
        if ($rows === []) {
            return null;
        }

        // Match the live resolvers: newest effective_from wins. Frozen rows
        // preserve their original effective_from; nulls sort last.
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($b['effective_from'] ?? ''), (string) ($a['effective_from'] ?? ''));
        });

        return (new ProductCommissionRate())->forceFill($rows[0]);
    }

    /**
     * The frozen product_commission_bands for a direction, ordered by band_seq,
     * as unsaved models so LifeRateResolver's `->matches()` + `yearColumn()`
     * work unchanged.
     *
     * @return list<ProductCommissionBand>
     */
    public function bands(string $direction): array
    {
        $rows = array_values(array_filter(
            $this->data['bands'] ?? [],
            fn (array $b): bool => ($b['direction'] ?? null) === $direction,
        ));

        usort($rows, static fn (array $a, array $b): int => ($a['band_seq'] ?? 0) <=> ($b['band_seq'] ?? 0));

        return array_map(
            static fn (array $b): ProductCommissionBand => (new ProductCommissionBand())->forceFill($b),
            $rows,
        );
    }

    /** Provenance — when the basis was frozen (for the ledger source string). */
    public function capturedAt(): ?string
    {
        return $this->data['captured_at'] ?? null;
    }

    /**
     * The single "headline" commission rate for a direction, given the policy
     * context — mirrors the resolver logic so the wizard and accrual agree:
     *   1. the matching sum-assured / entry-age band's yr_{year} column, else
     *   2. the single rate row (flat_rate for flat, yr_{year} for life_years).
     * Returns null when nothing resolves.
     *
     * This is what the policy's editable commission defaults to at creation
     * (year 1), for both hub_to_agent and carrier_to_hub.
     */
    public function headlineRate(string $direction, float $sumAssured, ?int $entryAge, int $policyYear): ?float
    {
        $year = max(1, $policyYear);

        foreach ($this->bands($direction) as $band) {
            if (! $band->matches($sumAssured, $entryAge)) {
                continue;
            }
            $col = ProductCommissionBand::yearColumn($year);
            $rate = $band->{$col};
            if ($rate !== null) {
                return (float) $rate;
            }
        }

        $row = $this->rateRow($direction);
        if ($row === null) {
            return null;
        }
        if ($row->scheme === ProductCommissionRate::SCHEME_LIFE_YEARS) {
            $col = ProductCommissionRate::lifeYearColumn($year);
            return $row->{$col} !== null ? (float) $row->{$col} : null;
        }

        return $row->flat_rate !== null ? (float) $row->flat_rate : null;
    }

    /**
     * The full per-year commission vector for a direction, in band form
     * (yr_1..yr_5 + yr_6_up), given the policy context. Prefers the matching
     * sum-assured / entry-age band; falls back to the single rate row mapped
     * onto the band columns (yr_6_up ← yr_6_10). Returns null when nothing
     * resolves. Used to seed the editable per-policy override vector.
     *
     * @return array<string,float>|null
     */
    public function bandVector(string $direction, float $sumAssured, ?int $entryAge): ?array
    {
        $cols = ['yr_1', 'yr_2', 'yr_3', 'yr_4', 'yr_5', 'yr_6_up'];

        foreach ($this->bands($direction) as $band) {
            if (! $band->matches($sumAssured, $entryAge)) {
                continue;
            }
            $out = [];
            foreach ($cols as $c) {
                if ($band->{$c} !== null) {
                    $out[$c] = (float) $band->{$c};
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        // Fallback: single life_years rate row → band columns.
        $row = $this->rateRow($direction);
        if ($row === null) {
            return null;
        }
        if ($row->scheme === ProductCommissionRate::SCHEME_LIFE_YEARS) {
            $map = [
                'yr_1' => 'yr_1', 'yr_2' => 'yr_2', 'yr_3' => 'yr_3',
                'yr_4' => 'yr_4', 'yr_5' => 'yr_5', 'yr_6_up' => 'yr_6_10',
            ];
            $out = [];
            foreach ($map as $bandCol => $rateCol) {
                if ($row->{$rateCol} !== null) {
                    $out[$bandCol] = (float) $row->{$rateCol};
                }
            }
            return $out === [] ? null : $out;
        }

        // Flat product: same rate for every year.
        if ($row->flat_rate !== null) {
            $flat = (float) $row->flat_rate;
            return array_fill_keys($cols, $flat);
        }

        return null;
    }

    /** Map a policy_year to the band-form override column. */
    public static function overrideYearColumn(int $policyYear): string
    {
        return match (true) {
            $policyYear <= 1 => 'yr_1',
            $policyYear === 2 => 'yr_2',
            $policyYear === 3 => 'yr_3',
            $policyYear === 4 => 'yr_4',
            $policyYear === 5 => 'yr_5',
            default => 'yr_6_up',
        };
    }

    /** @param list<string> $columns @return array<string,mixed> */
    private static function pluck(object $model, array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            // getAttribute applies the model's casts (decimals stay strings,
            // dates become Carbon). Normalise dates to Y-m-d strings so the
            // JSON round-trips cleanly.
            $val = $model->getAttribute($col);
            if ($val instanceof \DateTimeInterface) {
                $val = $val->format('Y-m-d');
            }
            $out[$col] = $val;
        }

        return $out;
    }
}
