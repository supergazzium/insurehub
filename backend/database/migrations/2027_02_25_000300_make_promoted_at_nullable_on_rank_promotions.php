<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending promotions (added in ..._000200) have no promoted_at until approved,
 * but the original column was NOT NULL. Make it nullable so the engine can
 * write a pending row. Split from 000200 because that migration may already
 * have run on the server — a standalone migration guarantees the change runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rank_promotions', function (Blueprint $table): void {
            $table->timestamp('promoted_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rank_promotions', function (Blueprint $table): void {
            $table->timestamp('promoted_at')->nullable(false)->change();
        });
    }
};
