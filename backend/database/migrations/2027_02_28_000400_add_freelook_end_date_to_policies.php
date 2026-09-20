<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freelook period end date (mainly for life policies) — the last day the
 * customer can exercise their free-look right. Drives the "ติดตามใบ Freelook"
 * follow-up (policies still inside the freelook window).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->date('freelook_end_date')->nullable()->after('freelook_active');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn('freelook_end_date');
        });
    }
};
