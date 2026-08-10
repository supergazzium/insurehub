<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Product;
use App\Models\ProductCommissionRate;
use Illuminate\Support\Facades\DB;

/**
 * Persists the `commissionRates` payload from ProductRequest into the two
 * physical rate tables:
 *
 *   flat     -> product_commission_rate_installments   (party × installment_term)
 *   per-year -> product_commission_rates               (wide yr_1..yr_11up)
 *
 * "Replace" semantics: on each call, existing rows for the product in the
 * target table are wiped and the payload becomes the new source of truth.
 * That keeps the UI (which sends the whole grid every time) trivially
 * correct — no diffing.
 *
 * Rate values arrive as percents (0..100). We persist them unchanged, matching
 * how the current `commissionPercent` shorthand and the Access import behave.
 * CommissionEngine treats stored values as either 0..1 or 0..100 already
 * (see ProductDetailDrawer::fmtRate).
 */
class ProductRateSeeder
{
    /**
     * @param  array<string, mixed>  $payload  The validated `commissionRates`
     *                                         object from ProductRequest.
     */
    public function seed(Product $product, array $payload): void
    {
        $shape = $payload['shape'] ?? 'skip';
        if ($shape === 'skip') {
            return;
        }
        if ($shape === 'flat') {
            $this->seedFlat($product, $payload['installments'] ?? []);

            return;
        }
        if ($shape === 'per-year') {
            $this->seedPerYear($product, $payload['years'] ?? []);
        }
    }

    /**
     * Legacy shorthand — a single percent applied to every year for the `com`
     * (in-house) party. Kept so old callers keep working; new UI uses seed().
     */
    public function seedFlatPercent(Product $product, float $percent): void
    {
        $rateRow = ['product_id' => $product->id];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, '11up'] as $year) {
            $rateRow["com_rate_yr_{$year}"] = $percent;
        }
        ProductCommissionRate::create($rateRow);
    }

    /**
     * @param  array<string, array{inh?: float|null, ag?: float|null, ov?: float|null}>  $installments
     *                                                                                                  Keyed by installment_term ("main", "3", "6", "12", ...).
     */
    private function seedFlat(Product $product, array $installments): void
    {
        DB::transaction(function () use ($product, $installments): void {
            DB::table('product_commission_rate_installments')
                ->where('product_id', $product->id)
                ->delete();

            $now = now();
            $rows = [];
            foreach ($installments as $term => $parties) {
                foreach ($this->partyMap() as $key => $party) {
                    $rate = $parties[$key] ?? null;
                    if ($rate === null) {
                        continue;
                    }
                    $rows[] = [
                        'product_id' => $product->id,
                        'party' => $party,
                        'installment_term' => (string) $term,
                        'rate' => $rate,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if ($rows !== []) {
                DB::table('product_commission_rate_installments')->insert($rows);
            }
        });
    }

    /**
     * @param  array<int|string, array{inh?: float|null, ag?: float|null, ov?: float|null}>  $years
     *                                                                                               Keyed by policy year (1..6). Year 6 fans out across yr_6..yr_11up.
     */
    private function seedPerYear(Product $product, array $years): void
    {
        DB::transaction(function () use ($product, $years): void {
            ProductCommissionRate::query()
                ->where('product_id', $product->id)
                ->delete();

            $row = ['product_id' => $product->id];
            foreach ($years as $yearKey => $parties) {
                $year = (int) $yearKey;
                if ($year < 1 || $year > 6) {
                    continue;
                }
                // Year 6 in the UI represents "year 6+" — fan out into every
                // real column from yr_6 up to yr_11up so the engine returns
                // this rate for renewals in year 7, 8, 9, 10, and 11+.
                $columns = $year === 6 ? [6, 7, 8, 9, 10, '11up'] : [$year];
                foreach ($columns as $col) {
                    if (($parties['inh'] ?? null) !== null) {
                        $row["com_rate_yr_{$col}"] = $parties['inh'];
                    }
                    if (($parties['ag'] ?? null) !== null) {
                        $row["ag_rate_yr_{$col}"] = $parties['ag'];
                    }
                    if (($parties['ov'] ?? null) !== null) {
                        $row["in_rate_yr_{$col}"] = $parties['ov'];
                    }
                }
            }
            if (count($row) > 1) {
                ProductCommissionRate::create($row);
            }
        });
    }

    /**
     * UI-camelCase party keys → the storage codes used in
     * product_commission_rate_installments.party.
     *
     * @return array<string, string>
     */
    private function partyMap(): array
    {
        return [
            'inh' => 'com', // in-house / broker share (Access "ComCommission_*")
            'ag' => 'ag',
            'ov' => 'in',   // override / upline; Access column is "in" (influencer)
        ];
    }
}
