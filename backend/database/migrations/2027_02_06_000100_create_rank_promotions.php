<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log for rank promotions.
     *
     * Every time RankPromotionService promotes an agent, one row is
     * written here. Ranks themselves live on agents.rank_id (the current
     * state); this table is the append-only history so admin can see
     * "when did agent X reach Lv7 and what volume qualified them?".
     *
     * Non-demotion rule (Excel Sheet2 rule 1): the service NEVER creates
     * a "demotion" row. If volume drops, current rank stays.
     *
     * Not tenant-scoped directly — inherited via agent.
     */
    public function up(): void
    {
        Schema::create('rank_promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('from_rank_id')->nullable()->constrained('ranks')->nullOnDelete();
            $table->foreignId('to_rank_id')->constrained('ranks')->cascadeOnDelete();
            // The volume snapshot that qualified the promotion.
            $table->decimal('qualifying_rolling_3_month_volume', 15, 2);
            $table->string('qualifying_period_year_month', 7);
            // Why the promotion fired. 'auto' = observer / reconciliation
            // triggered; 'manual' = admin forced (via UI, future work).
            $table->string('trigger', 16)->default('auto');
            $table->text('notes')->nullable();
            $table->timestamp('promoted_at');
            $table->timestamps();

            $table->index(['agent_id', 'promoted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_promotions');
    }
};
