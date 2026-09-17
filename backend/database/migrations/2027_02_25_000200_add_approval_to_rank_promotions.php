<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Promotion approval gate.
 *
 * Promotions are auto-DETECTED by RankPromotionService but must be
 * user-APPROVED before they take effect on the agent's rank. This adds the
 * workflow columns to rank_promotions:
 *   status: pending | approved | rejected
 *   requested_at: when the engine proposed it
 *   decided_by_user_id / decided_at: who approved/rejected and when
 *
 * Existing rows (the single test row) are marked 'approved' so history stays
 * consistent — they were written under the old apply-immediately behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rank_promotions', function (Blueprint $table): void {
            $table->string('status', 16)->default('pending')->after('trigger');
            $table->timestamp('requested_at')->nullable()->after('status');
            $table->foreignId('decided_by_user_id')->nullable()->after('requested_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by_user_id');
            $table->index(['status'], 'rank_promotions_status_idx');
        });

        // Old rows predate the gate — treat them as already applied/approved.
        DB::table('rank_promotions')->update([
            'status' => 'approved',
            'requested_at' => DB::raw('promoted_at'),
            'decided_at' => DB::raw('promoted_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('rank_promotions', function (Blueprint $table): void {
            $table->dropIndex('rank_promotions_status_idx');
            $table->dropConstrainedForeignId('decided_by_user_id');
            $table->dropColumn(['status', 'requested_at', 'decided_at']);
        });
    }
};
