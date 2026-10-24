<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ประกันชีวิต — ฐานการคำนวณระยะคุ้มครอง (coverage basis).
 *
 * #16 เพิ่ม coverage_to_age (แผน "คุ้มครองถึงอายุ M" → วันเกิด + M ปี). This
 * adds the OTHER half the model couldn't express — the "คุ้มครอง N ปี" plan
 * whose end date is counted from the policy's effective_date, not the
 * insured's birthday.
 *
 *   coverage_basis:
 *     - 'age'       → end = insured_person_birth_date + coverage_to_age ปี
 *     - 'years'     → end = effective_date + coverage_years ปี
 *     - 'renewable' → ปีต่อปี (ประกันทั่วไป); end stays expiry_date as-is
 *
 * Both columns live on products (the plan default) AND policies (a per-policy
 * override that falls back to the product). Same two-level shape as
 * pay_years / coverage_to_age so the model's effective_* getters extend
 * cleanly.
 *
 * Backfill: existing to-age plans already carry coverage_to_age, so any row
 * with a non-null coverage_to_age is stamped basis='age'; everything else is
 * left NULL (unknown) rather than guessed — Policy::getEffectiveCoverageBasis
 * infers a sensible default at read time instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // How this plan's coverage end date is computed. Nullable = unset;
            // the model infers age|years|renewable from the other columns.
            $table->string('coverage_basis', 16)->nullable()->after('coverage_to_age');
            // Coverage length in years — used only when basis = 'years'.
            $table->unsignedSmallInteger('coverage_years')->nullable()->after('coverage_basis');
        });

        Schema::table('policies', function (Blueprint $table) {
            $table->string('coverage_basis', 16)->nullable()->after('coverage_to_age');
            $table->unsignedSmallInteger('coverage_years')->nullable()->after('coverage_basis');
        });

        // Backfill: a plan that already pins coverage_to_age is a to-age plan.
        DB::table('products')->whereNotNull('coverage_to_age')->whereNull('coverage_basis')
            ->update(['coverage_basis' => 'age']);
        DB::table('policies')->whereNotNull('coverage_to_age')->whereNull('coverage_basis')
            ->update(['coverage_basis' => 'age']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['coverage_basis', 'coverage_years']);
        });
        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn(['coverage_basis', 'coverage_years']);
        });
    }
};
