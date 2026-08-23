<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-20: Freeze the product's commission basis onto the policy at create time.
 *
 * Problem: commission rates live on the Product (product_commission_rates +
 * product_commission_bands) and are edited in place — the ProductController
 * upserts a single row per (product, direction). The MGM engine's resolvers
 * (NonLifeRateResolver / LifeRateResolver) read those live rows at PAYMENT
 * time. So editing a product's commission retroactively changes the rate that
 * accrues on policies created BEFORE the edit — an invoice that re-prices
 * itself after the sale.
 *
 * Fix: capture the full commission basis (both directions' rate rows + all
 * bands) into this JSON column when the policy is created (PolicyObserver on
 * `created`). The resolvers prefer this snapshot when present and fall back to
 * the live product tables when null. Pre-existing policies (snapshot = null)
 * keep resolving live exactly as before — no behavioural change for them.
 *
 * Shape (see App\Services\Commission\CommissionSnapshot):
 *   {
 *     "v": 1,
 *     "captured_at": "2026-08-23T…Z",
 *     "product_id": 872,
 *     "rates": [ { direction, scheme, flat_rate, yr_1..yr_11_up, effective_from } ],
 *     "bands": [ { direction, band_seq, sum_assured_min/max, entry_age_min/max,
 *                  yr_1..yr_6_up, effective_from } ]
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            // Nullable: null = "resolve live" (all legacy rows + any policy
            // created without a product). Placed after risk_data for locality
            // with the other JSON payloads.
            $table->json('commission_snapshot')->nullable()->after('risk_data');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn('commission_snapshot');
        });
    }
};
