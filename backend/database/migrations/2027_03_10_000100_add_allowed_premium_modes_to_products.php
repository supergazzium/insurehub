<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * งวดการชำระที่ผลิตภัณฑ์รองรับ (monthly / quarterly / semiannual / annual /
 * single). Null = fall back to the insure-type default (Life → all,
 * Non-Life/Tax → annual) — see App\Support\PremiumMode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('allowed_premium_modes')->nullable()->after('premium_mode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('allowed_premium_modes');
        });
    }
};
