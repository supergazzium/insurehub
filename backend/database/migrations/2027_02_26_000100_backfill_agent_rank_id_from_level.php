<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sync agents.rank_id with the legacy agents.level string.
 *
 * rank_id is the source of truth the promotion engine + level-progress read,
 * but the imported agents carried only the `level` string ("l5") with a null
 * rank_id — so they were treated as level 0. This backfills rank_id from level
 * (l<N> → the ranks row whose level = N) and corrects any row where the two
 * disagree (e.g. agent 11, left mismatched by an early approval test).
 *
 * The two stay in sync going forward: AgentHierarchyController and
 * RankPromotionApproval both write level + rank_id together.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ranks.level == ranks.id in the seed, but resolve by level to be safe.
        $rankIdByLevel = DB::table('ranks')->pluck('id', 'level'); // [levelNum => id]

        $agents = DB::table('agents')
            ->whereNotNull('level')
            ->select('id', 'level', 'rank_id')
            ->get();

        foreach ($agents as $a) {
            if (! preg_match('/^l(\d{1,2})$/', (string) $a->level, $m)) {
                continue; // unexpected level string — leave untouched
            }
            $levelNum = (int) $m[1];
            $wantRankId = $rankIdByLevel[$levelNum] ?? null;
            if ($wantRankId !== null && (int) ($a->rank_id ?? 0) !== (int) $wantRankId) {
                DB::table('agents')->where('id', $a->id)->update(['rank_id' => $wantRankId]);
            }
        }
    }

    public function down(): void
    {
        // Non-reversible data backfill; leave rank_id as-is.
    }
};
