<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #16 — "ระยะชำระเบี้ย / ระยะคุ้มครอง" (e.g. 15/90 = จ่ายเบี้ย 15 ปี,
 * คุ้มครองถึงอายุ 90).
 *
 * The premium-paying term already exists (products.pay_years as the default,
 * policies.premium_paying_term as the per-policy override). This adds the
 * missing half — coverage-until-age — in the same shape:
 *   - products.coverage_to_age  = the plan's default (e.g. 90)
 *   - policies.coverage_to_age  = per-policy override (nullable → use product)
 *
 * The coverage-end DATE is derived (insured_person_birth_date + N years), not
 * stored, so it stays correct if the birthdate is corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Coverage until this age (years). Nullable = not a to-age plan
            // (e.g. yearly-renewable non-life).
            $table->unsignedSmallInteger('coverage_to_age')->nullable()->after('pay_years');
        });

        Schema::table('policies', function (Blueprint $table) {
            // Per-policy override of the product's coverage_to_age. Nullable =
            // inherit the product default.
            $table->unsignedSmallInteger('coverage_to_age')->nullable()->after('premium_paying_term');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('coverage_to_age');
        });
        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn('coverage_to_age');
        });
    }
};
