<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: set NULL commission on imported policies to explicit 0.
 *
 * A missing commission figure in the legacy source means INTENTIONALLY ZERO
 * commission (overwhelmingly พรบ compulsory motor + ต่อภาษี tax renewals, which
 * are non-commissionable). The importer now zero-fills these at import time, but
 * policies imported BEFORE that change still carry NULL. This command resolves
 * them to 0 so they stop tripping the "ยังไม่บันทึกค่าคอม" (no_commission)
 * follow-up warning — which now flags only NULL (genuinely-unset) commission.
 *
 * Scope: imported_from_legacy policies only. A new policy an operator leaves
 * blank stays NULL (keeps warning). Only touches NULL → 0; any real commission
 * value is untouched. No money changes (NULL and 0 both contribute 0 to sums),
 * which the command asserts.
 *
 *   php artisan policies:zerofill-imported-commission [--tenant=1] [--dry-run]
 */
class ZeroFillImportedCommission extends Command
{
    protected $signature = 'policies:zerofill-imported-commission
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}';

    protected $description = 'Set NULL commission on imported policies to explicit 0 (intentional-zero), so they stop warning.';

    /**
     * The RATE is the source of truth for "is there commission". A policy with a
     * rate but a NULL amount is NOT zero-commission — the amount is derived on
     * demand, so we must not zero it. We therefore act per LEG: only when a leg's
     * RATE is NULL (never recorded) do we resolve that leg to explicit 0 (rate +
     * amount). A leg that has a rate keeps its rate and its (possibly-NULL) amount
     * untouched.
     *
     * @var array<string, array{rate:string, amount:string}>
     */
    private const LEGS = [
        'agent' => ['rate' => 'comm_hub_to_agent_rate', 'amount' => 'comm_hub_to_agent_amount'],
        'carrier' => ['rate' => 'comm_carrier_to_hub_rate', 'amount' => 'comm_carrier_to_hub_amount'],
    ];

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        // Money guard: the agent + carrier commission-amount totals must be
        // identical before and after (NULL and 0 both sum to the same value).
        $sumBefore = $this->commissionSum($tenant);

        $base = fn () => DB::table('policies')
            ->where('tenant_id', $tenant)
            ->whereNull('deleted_at')
            ->where('imported_from_legacy', true);

        $rows = [];
        foreach (self::LEGS as $leg => $cols) {
            // Only legs whose RATE was never recorded = intentional zero.
            $q = (clone $base())->whereNull($cols['rate']);
            $count = (clone $q)->count();
            if (! $dry && $count > 0) {
                (clone $q)->update([
                    $cols['rate'] => 0,
                    $cols['amount'] => 0,
                    'updated_at' => DB::raw('NOW()'),
                ]);
            }
            $rows[] = [$leg.' (rate NULL → rate+amount 0)', $count];
        }

        $sumAfter = $this->commissionSum($tenant);

        $this->table(['leg', 'policies resolved to 0'], $rows);
        $this->line('agent+carrier commission $ before: '.number_format($sumBefore, 2));
        $this->line('agent+carrier commission $ after:  '.number_format($sumAfter, 2).' (must equal before)');

        if (! $dry && abs($sumAfter - $sumBefore) > 0.01) {
            $this->error('MONEY CHANGED — abort. Review.');

            return self::FAILURE;
        }
        $this->info($dry ? '[dry-run] no writes.' : 'commission NULL→0 backfill applied (money unchanged).');

        return self::SUCCESS;
    }

    private function commissionSum(int $tenant): float
    {
        return (float) DB::table('policies')
            ->where('tenant_id', $tenant)
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(comm_hub_to_agent_amount),0) + COALESCE(SUM(comm_carrier_to_hub_amount),0) AS s')
            ->value('s');
    }
}
