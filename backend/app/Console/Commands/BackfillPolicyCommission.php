<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The importer originally mapped only com_rec_check and dropped the legacy
 * per-policy commission figures. This backfills them from the legacy
 * applications table onto the migrated policies (by application_no):
 *   main_commission_ag_amount  → comm_hub_to_agent_amount
 *   main_commission_ag_pct     → comm_hub_to_agent_rate
 *   main_commission_inh_amount → comm_carrier_to_hub_amount
 *   main_commission_inh_pct    → comm_carrier_to_hub_rate
 * Reads the legacy connection; idempotent.
 */
class BackfillPolicyCommission extends Command
{
    protected $signature = 'policies:backfill-commission {--dry-run} {--tenant=1}';

    protected $description = 'Backfill legacy per-policy commission amounts/rates onto migrated policies.';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $legacy = DB::connection('insurehub_legacy');
        $updated = 0;
        $sumAgent = 0.0;
        $sumInh = 0.0;

        foreach ($legacy->table('applications')
            ->select('application_code', 'main_commission_ag_amount', 'main_commission_ag_pct',
                'main_commission_inh_amount', 'main_commission_inh_pct')
            ->where(function ($q) {
                $q->where('main_commission_ag_amount', '>', 0)->orWhere('main_commission_inh_amount', '>', 0)
                    ->orWhere('main_commission_ag_pct', '>', 0)->orWhere('main_commission_inh_pct', '>', 0);
            })->cursor() as $a) {
            $payload = array_filter([
                'comm_hub_to_agent_amount' => $a->main_commission_ag_amount ?: null,
                'comm_hub_to_agent_rate' => $a->main_commission_ag_pct ?: null,
                'comm_carrier_to_hub_amount' => $a->main_commission_inh_amount ?: null,
                'comm_carrier_to_hub_rate' => $a->main_commission_inh_pct ?: null,
            ], fn ($v) => $v !== null);
            if ($payload === []) {
                continue;
            }
            $sumAgent += (float) ($a->main_commission_ag_amount ?? 0);
            $sumInh += (float) ($a->main_commission_inh_amount ?? 0);
            if (! $dry) {
                $n = DB::table('policies')->where('tenant_id', $tenantId)
                    ->where('application_no', trim((string) $a->application_code))->update($payload);
                $updated += $n;
            } else {
                $updated++;
            }
        }

        $this->table(['metric', 'value'], [
            ['policies updated', $updated],
            ['Σ agent commission (hub_to_agent)', number_format($sumAgent, 2)],
            ['Σ in-house commission (carrier_to_hub)', number_format($sumInh, 2)],
        ]);
        $this->info($dry ? '[DRY-RUN] no writes' : 'commission backfilled');

        return self::SUCCESS;
    }
}
