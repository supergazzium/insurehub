<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Banded commission rates for Life products. Each row is one (SA range,
     * age range) band, with year-of-policy rates yr_1..yr_5 + yr_6_up.
     *
     * Life products in the real world (see docs/mgm carrier tariff PDF) have
     * multiple bands per product — the payout rate depends on the insured's
     * sum-assured range, entry-age range, AND the policy year. The single-row
     * product_commission_rates.scheme='life_years' shape from the prior
     * migration is a 1-band special case; this table is the general form.
     *
     * Resolution at accrual time:
     *   1. Load bands for (product_id, direction='hub_to_agent').
     *   2. Filter to the band whose sum_assured range covers policies.coverage
     *      AND entry_age range covers the insured's entry age.
     *   3. Read yr_{policy_year} column (yr_6_up for years 6+).
     *
     * Non-life products don't get bands — they stay on the single-row flat
     * product_commission_rates table. The resolver dispatches by scheme.
     *
     * Backfill: for every existing product_commission_rates row where
     * scheme='life_years' AND at least one yr_* column is non-null, insert
     * ONE band with SA/age unbounded and yr_1..yr_5 + yr_6_up copied
     * (yr_6_up = yr_6_10 to preserve current behavior; yr_11_up merges into
     * that band, losing the split — Life products in this codebase are
     * fresh so this is a no-op today).
     */
    public function up(): void
    {
        Schema::create('product_commission_bands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('direction', 32);
            $table->unsignedSmallInteger('band_seq')->default(1);
            // Nullable min = -infinity (band covers 0). Nullable max = +infinity.
            $table->decimal('sum_assured_min', 15, 2)->nullable();
            $table->decimal('sum_assured_max', 15, 2)->nullable();
            $table->unsignedSmallInteger('entry_age_min')->nullable();
            $table->unsignedSmallInteger('entry_age_max')->nullable();
            $table->decimal('yr_1', 8, 5)->nullable();
            $table->decimal('yr_2', 8, 5)->nullable();
            $table->decimal('yr_3', 8, 5)->nullable();
            $table->decimal('yr_4', 8, 5)->nullable();
            $table->decimal('yr_5', 8, 5)->nullable();
            $table->decimal('yr_6_up', 8, 5)->nullable();
            $table->date('effective_from')->nullable();
            $table->timestamps();

            // Resolver hot path.
            $table->index(['tenant_id', 'product_id', 'direction'], 'pcb_lookup_idx');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_commission_bands');
    }

    /**
     * Copy every life_years row from product_commission_rates into a single
     * unbounded band so pre-existing Life products keep working after the
     * resolver switches to bands.
     */
    private function backfill(): void
    {
        $now = now();
        $rows = [];

        $rates = DB::table('product_commission_rates')
            ->where('scheme', 'life_years')
            ->get();

        foreach ($rates as $r) {
            $yrs = [
                (float) ($r->yr_1 ?? 0),
                (float) ($r->yr_2 ?? 0),
                (float) ($r->yr_3 ?? 0),
                (float) ($r->yr_4 ?? 0),
                (float) ($r->yr_5 ?? 0),
                (float) ($r->yr_6_10 ?? 0),
                (float) ($r->yr_11_up ?? 0),
            ];
            if (array_sum($yrs) <= 0) {
                // Empty rate row — nothing worth backfilling.
                continue;
            }

            $rows[] = [
                'tenant_id' => $r->tenant_id,
                'product_id' => $r->product_id,
                'direction' => $r->direction,
                'band_seq' => 1,
                'sum_assured_min' => null,
                'sum_assured_max' => null,
                'entry_age_min' => null,
                'entry_age_max' => null,
                'yr_1' => $r->yr_1,
                'yr_2' => $r->yr_2,
                'yr_3' => $r->yr_3,
                'yr_4' => $r->yr_4,
                'yr_5' => $r->yr_5,
                'yr_6_up' => $r->yr_6_10,
                'effective_from' => $r->effective_from,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_commission_bands')->insert($chunk);
        }
    }
};
