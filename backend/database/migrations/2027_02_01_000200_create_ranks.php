<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MGM commission system — rank definitions.
     *
     * Ranks are the axis that everything downstream keys off:
     *   - Volume accumulation (member_volume_accumulations, PR-B) tracks
     *     downlines at LOWER rank than a given member.
     *   - Rank promotion (PR-C) checks rolling volumes against
     *     monthly_avg_target and three_month_accum_target here.
     *   - Commission tiers (PR-A2) hold a per-(tier × rank) mgmt fee and
     *     referral fee curve.
     *   - MgmCommissionEngine (PR-D+) looks up the seller's rank to
     *     compute mgmt fee and walks the upline chain by rank for the
     *     differential.
     *
     * Ten ranks total. Lv7+ require a non-life broker license (per Sheet2
     * of the source Excel). Enforcement: agents.has_license must be true
     * for promotion into Lv7-Lv10; enforced in PR-C's promotion engine.
     *
     * Non-demotion rule (Excel Sheet2 rule 1): once an agent reaches a
     * rank, they keep it even if their volume drops. The promotion engine
     * enforces this — this table is inert reference data.
     *
     * Thresholds seeded from Sheet2 rows 15-25 of the source Excel. Lv1
     * and Lv2 are "no threshold" (ไม่บังคับยอด); we store them as 0 so
     * the engine's threshold comparison is uniform.
     */
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('level')->unique();  // 1..10
            $table->string('code', 8)->unique();             // "Lv1" .. "Lv10"
            $table->string('name_th', 64);                   // display label, editable
            $table->string('name_en', 64);
            $table->decimal('monthly_avg_target', 15, 2)->default(0);
            $table->decimal('three_month_accum_target', 15, 2)->default(0);
            // Lv7+ = licensed track (per Sheet2 rows 15-25 of the source
            // Excel — only agents with a non-life broker license can be
            // promoted into these ranks). Enforced in PR-C's promotion
            // engine against agents.has_license.
            $table->boolean('license_required')->default(false);
            // Free-text for admin notes ("Repriced Jan 2027", etc.).
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranks');
    }
};
