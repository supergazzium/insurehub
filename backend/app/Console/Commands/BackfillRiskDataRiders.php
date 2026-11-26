<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate risk_data['riders.rows'] from the policy_riders table.
 *
 * Migrated life policies carry their riders in the policy_riders table, but the
 * policy edit page reads the rider list, the premium total, and the per-rider
 * commission from risk_data['riders.rows'] (the risk JSON bag). The migration
 * never wrote that key, so a life policy with riders shows
 * "ยังไม่มีสัญญาเพิ่มเติม" even though the riders exist (e.g. policy 20765: net
 * ฿314,763 vs main ฿18,800 = ฿298,197 of riders, none displayed).
 *
 * This mirrors each policy's policy_riders rows into risk_data['riders.rows']
 * with the exact shape the wizard consumes:
 *   { name, premium, notes, product_code, rate_inh, rate_ag }
 *
 * The policy_riders table stays the source of truth; this only makes the data
 * visible/editable in the risk bag the UI binds to.
 *
 * SAFETY
 *   - Writes only policies.risk_data (adds/replaces the 'riders.rows' key,
 *     preserving any other risk keys such as motor/property). No money fields,
 *     no policy_riders rows touched.
 *   - Raw update → no observers. Idempotent (rebuilds the same rows). --dry-run.
 *
 *   php artisan policies:backfill-riskdata-riders [--tenant=1] [--dry-run]
 */
class BackfillRiskDataRiders extends Command
{
    protected $signature = 'policies:backfill-riskdata-riders
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}';

    protected $description = 'Mirror policy_riders into risk_data[riders.rows] so riders show on the policy page.';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        // product_id → code (for the rider picker's product_code field)
        $productCode = DB::table('products')->pluck('code', 'id');

        // riders grouped by policy
        $ridersByPolicy = [];
        foreach (DB::table('policy_riders')->orderBy('policy_id')->orderBy('slot')->orderBy('id')->get() as $r) {
            $ridersByPolicy[$r->policy_id][] = [
                'name' => $r->name ?? '',
                'premium' => (float) $r->premium,
                'notes' => $r->notes ?? '',
                'product_code' => $r->product_id !== null ? ($productCode[$r->product_id] ?? null) : null,
                'rate_inh' => $r->com_rate_inh !== null ? (float) $r->com_rate_inh : null,
                'rate_ag' => $r->com_rate_ag !== null ? (float) $r->com_rate_ag : null,
            ];
        }

        if ($ridersByPolicy === []) {
            $this->info('No policy_riders — nothing to mirror.');

            return self::SUCCESS;
        }

        $policies = DB::table('policies')
            ->where('tenant_id', $tenant)
            ->whereNull('deleted_at')
            ->whereIn('id', array_keys($ridersByPolicy))
            ->get(['id', 'risk_data']);

        $written = 0;
        $skipped = 0;
        foreach ($policies as $p) {
            $rows = $ridersByPolicy[$p->id] ?? [];
            if ($rows === []) {
                continue;
            }
            $risk = $p->risk_data !== null
                ? (json_decode($p->risk_data, true) ?: [])
                : [];

            // Idempotent: skip if the key already holds the same number of rows
            // with matching premiums (a re-run is then a no-op).
            $existing = $risk['riders.rows'] ?? null;
            if (is_array($existing) && count($existing) === count($rows)) {
                $same = true;
                foreach ($rows as $i => $row) {
                    if (abs((float) ($existing[$i]['premium'] ?? -1) - $row['premium']) > 0.001) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    $skipped++;

                    continue;
                }
            }

            $risk['riders.rows'] = $rows;

            if (! $dry) {
                DB::table('policies')->where('id', $p->id)->update([
                    'risk_data' => json_encode($risk, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
            $written++;
        }

        $this->info(sprintf('%sriders.rows written on %d policies (%d already current).',
            $dry ? '[dry-run] ' : '', $written, $skipped));
        if ($dry) {
            $this->warn('DRY RUN — nothing written.');
        }

        return self::SUCCESS;
    }
}
