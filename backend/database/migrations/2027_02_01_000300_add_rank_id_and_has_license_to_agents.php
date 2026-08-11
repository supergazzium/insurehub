<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds:
     *   agents.rank_id      — FK → ranks.id. New MGM source of truth.
     *   agents.has_license  — business flag: "may be promoted to Lv7+".
     *
     * Kept: agents.level (varchar 'l1'..'l5', legacy display column).
     * The MGM engine reads rank_id; UI code that still shows `level` keeps
     * working. A follow-up PR can consolidate once every consumer moves.
     *
     * has_license is a business flag, NOT derived from license_non_life_no.
     * Rationale: license expiry shouldn't silently demote an agent (would
     * conflict with the non-demotion rule). Admin toggles this explicitly
     * during onboarding / compliance review.
     *
     * rank_id is nullable during transition so existing agents don't need
     * an immediate backfill. PR-A2's seeder + a future data-fix migration
     * will populate rank_id from the legacy `level` column.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->foreignId('rank_id')
                ->nullable()
                ->after('level')
                ->constrained('ranks')
                ->nullOnDelete();
            $table->boolean('has_license')
                ->default(false)
                ->after('rank_id');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropForeign(['rank_id']);
            $table->dropColumn(['rank_id', 'has_license']);
        });
    }
};
