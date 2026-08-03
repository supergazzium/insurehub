<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor-specific product columns. `coverage_class` stores the Thai motor
 * insurance tier ("1" / "2+" / "2" / "3+" / "3"). Vehicle age range is
 * separate from insured-person age (which stays in the existing min_age /
 * max_age columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->string('coverage_class', 8)->nullable()->after('coverage');
            $t->unsignedSmallInteger('vehicle_age_min')->nullable()->after('coverage_class');
            $t->unsignedSmallInteger('vehicle_age_max')->nullable()->after('vehicle_age_min');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropColumn(['coverage_class', 'vehicle_age_min', 'vehicle_age_max']);
        });
    }
};
