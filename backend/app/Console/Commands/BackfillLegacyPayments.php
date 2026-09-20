<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PolicyEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Backfill a policy_payments record for every existing policy that has a
 * total_premium_paid figure but no recorded payment rows, so the imported
 * legacy data becomes an auditable payment record too.
 *
 * - amount     = total_premium_paid (the imported "paid so far")
 * - net/duty/vat = the policy's own decomposition
 * - payment_date = effective_date (→ issue_date → created_at date fallback)
 * - method     = 'legacyImport', reference = 'ข้อมูลเดิม (นำเข้า)'
 *
 * Idempotent: skips any policy that already has a policy_payments row. Does NOT
 * fire the commission pipeline (raw insert, no observer) — this is historical
 * data, not a new accrual. Run manually when ready:
 *   php artisan payments:backfill-legacy [--tenant=ID] [--dry-run]
 */
class BackfillLegacyPayments extends Command
{
    protected $signature = 'payments:backfill-legacy {--tenant= : restrict to one tenant id} {--dry-run : report only, write nothing}';

    protected $description = 'Create a policy_payments record from total_premium_paid for legacy policies that have none';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $tenant = $this->option('tenant');

        $q = DB::table('policies as p')
            ->whereNull('p.deleted_at')
            ->whereRaw('COALESCE(p.total_premium_paid, 0) > 0')
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))->from('policy_payments as pp')->whereColumn('pp.policy_id', 'p.id');
            });
        if ($tenant !== null) {
            $q->where('p.tenant_id', (int) $tenant);
        }

        $policies = $q->select([
            'p.id', 'p.tenant_id', 'p.total_premium_paid',
            'p.net_premium', 'p.duty_stamp', 'p.vat',
            'p.effective_date', 'p.issue_date', 'p.created_at',
        ])->get();

        $this->info(($dry ? '[dry-run] ' : '').'Found '.$policies->count().' policies to backfill.');
        if ($policies->isEmpty()) {
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $written = 0;

        foreach ($policies as $p) {
            $refDate = $p->effective_date
                ?? $p->issue_date
                ?? ($p->created_at !== null ? Carbon::parse($p->created_at)->toDateString() : $now->toDateString());

            $row = [
                'policy_id' => $p->id,
                'payment_date' => $refDate,
                'amount' => (float) $p->total_premium_paid,
                'net_amount' => $p->net_premium !== null ? (float) $p->net_premium : null,
                'duty_amount' => $p->duty_stamp !== null ? (float) $p->duty_stamp : null,
                'vat_amount' => $p->vat !== null ? (float) $p->vat : null,
                'method' => 'legacyImport',
                'reference' => 'ข้อมูลเดิม (นำเข้า)',
                'recorded_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($dry) {
                $written++;
                continue;
            }

            DB::transaction(function () use ($row, $p, $now): void {
                $id = DB::table('policy_payments')->insertGetId($row);
                // Audit event, flagged as backfilled legacy data.
                PolicyEvent::create([
                    'policy_id' => $p->id,
                    'type' => 'premiumPaid',
                    'occurred_at' => $now,
                    'by_user_id' => null,
                    'payload' => [
                        'paymentId' => (string) $id,
                        'amount' => (float) $p->total_premium_paid,
                        'method' => 'legacyImport',
                        'source' => 'legacy-backfill',
                        'note' => 'ข้อมูลเดิม (นำเข้า)',
                    ],
                ]);
            });
            $written++;
        }

        $this->info(($dry ? '[dry-run] would write ' : 'Wrote ').$written.' payment records.');

        return self::SUCCESS;
    }
}
