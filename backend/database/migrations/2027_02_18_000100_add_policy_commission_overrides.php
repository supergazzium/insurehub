<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-21: per-policy commission overrides for the two directions shown in the
 * wizard's เบี้ย + การชำระ section:
 *
 *   carrier_to_hub  — how much the insurance company pays InsureHub
 *   hub_to_agent    — how much InsureHub pays the selling agent
 *
 * On policy creation these default from the resolved snapshot (the headline
 * year-1 rate of the matching sum-assured band, frozen by PolicyObserver).
 * The operator may then override the RATE or the AMOUNT per policy — e.g. a
 * one-off renegotiation on this specific case. When an override rate is set,
 * the MGM accrual engine uses it instead of the resolved/snapshot rate.
 *
 * Rate columns are decimal(8,5) fractions (0.35 = 35%), matching
 * product_commission_rates. Amount columns are decimal(15,2) baht. All
 * nullable: null = "no override, use the resolved snapshot rate".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->decimal('comm_hub_to_agent_rate', 8, 5)->nullable()->after('commission_snapshot');
            $table->decimal('comm_hub_to_agent_amount', 15, 2)->nullable()->after('comm_hub_to_agent_rate');
            $table->decimal('comm_carrier_to_hub_rate', 8, 5)->nullable()->after('comm_hub_to_agent_amount');
            $table->decimal('comm_carrier_to_hub_amount', 15, 2)->nullable()->after('comm_carrier_to_hub_rate');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn([
                'comm_hub_to_agent_rate',
                'comm_hub_to_agent_amount',
                'comm_carrier_to_hub_rate',
                'comm_carrier_to_hub_amount',
            ]);
        });
    }
};
