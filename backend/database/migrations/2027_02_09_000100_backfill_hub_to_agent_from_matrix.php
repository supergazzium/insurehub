<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill product_commission_rates.hub_to_agent.flat_rate from the
     * carrier_product_type_rates matrix, so removing the matrix fallback
     * in NonLifeRateResolver doesn't silently zero out DIRECT accrual for
     * existing products.
     *
     * Only touches rows where hub_to_agent is completely empty (flat and
     * per-year both null) — never overwrites a rate an admin has already
     * set on the product page.
     *
     * Scope: scheme='flat' rows only. Life products (scheme='life_years')
     * intentionally skipped because Life bands / life_years rates come
     * from the product's own tariff, not the flat matrix.
     */
    public function up(): void
    {
        $rows = DB::table('product_commission_rates as pcr')
            ->join('products as p', 'p.id', '=', 'pcr.product_id')
            ->join('carrier_product_type_rates as cptr', function ($join): void {
                $join->on('cptr.tenant_id', '=', 'p.tenant_id')
                    ->on('cptr.carrier_id', '=', 'p.carrier_id')
                    ->on('cptr.product_type_id', '=', 'p.product_type_id');
            })
            ->where('pcr.direction', 'hub_to_agent')
            ->where('pcr.scheme', 'flat')
            ->whereNull('pcr.flat_rate')
            ->whereNotNull('cptr.standard_rate')
            ->select('pcr.id', 'cptr.standard_rate')
            ->get();

        foreach ($rows as $row) {
            DB::table('product_commission_rates')
                ->where('id', $row->id)
                ->update([
                    'flat_rate' => $row->standard_rate,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // No-op — the backfilled values are indistinguishable from admin
        // edits. Reversal would risk clobbering intentional data.
    }
};
