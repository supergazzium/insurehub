<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-22: full per-year commission override vector on the policy.
 *
 * C-21 added single-scalar overrides (comm_hub_to_agent_rate etc.) — one rate
 * per direction, applied to every policy year. That's correct for FLAT
 * (non-life) products but wrong for LIFE, whose commission is a per-year
 * vector (yr1 40%, yr2 10%, …). This column stores the full editable vector
 * so the operator can renegotiate the whole schedule per policy.
 *
 * Shape (band 6-column form — yr_1..yr_5 + yr_6_up, the form the wizard grid
 * shows):
 *   {
 *     "hubToAgent":   { "yr_1": 0.35, "yr_2": 0.10, ..., "yr_6_up": 0.02 },
 *     "carrierToHub": { "yr_1": 0.50, ..., "yr_6_up": 0.03 }
 *   }
 *
 * Precedence at accrual (LifeRateResolver):
 *   1. comm_override[hubToAgent][yearColumn(policy_year)]   ← this column
 *   2. comm_hub_to_agent_rate (C-21 scalar, still used by flat products)
 *   3. snapshot band / rate row
 *   4. live product tables
 * Null = no vector override; fall through to the C-21 scalar / snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->json('comm_override')->nullable()->after('comm_carrier_to_hub_amount');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn('comm_override');
        });
    }
};
