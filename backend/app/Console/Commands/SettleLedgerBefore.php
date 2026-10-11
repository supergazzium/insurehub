<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditEntry;
use App\Models\CommissionLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SettleLedgerBefore extends Command
{
    protected $signature = 'payouts:settle-ledger-before
        {date : YYYY-MM-DD — rows created before this date are marked settled}
        {--tenant= : Restrict to one tenant id}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Mark unpaid commission ledger rows created before a cutover date as settled (paid outside InsureHub).';

    public function handle(): int
    {
        $cutoff = Carbon::parse((string) $this->argument('date'), 'Asia/Bangkok')->startOfDay();
        $query = DB::table('commission_ledgers')
            ->where('status', CommissionLedger::STATUS_UNSETTLED)
            ->whereNull('payout_batch_id')
            ->where('created_at', '<', $cutoff)
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t));

        $summary = (clone $query)
            ->groupBy('tenant_id', 'payout_type')
            ->selectRaw('tenant_id, payout_type, COUNT(*) as rows_count, SUM(amount) as total')
            ->get();
        $this->table(['tenant', 'type', 'rows', 'amount'], $summary->map(fn ($r) => [
            $r->tenant_id, $r->payout_type, $r->rows_count, number_format((float) $r->total, 2),
        ])->all());

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $count = $query->update(['status' => CommissionLedger::STATUS_SETTLED, 'paid_at' => now()]);
        foreach ($summary->groupBy('tenant_id') as $tenantId => $rows) {
            AuditEntry::create([
                'tenant_id' => $tenantId,
                'user_id' => null,
                'occurred_at' => now(),
                'actor' => 'system',
                'action' => 'commission_ledger.settled_before_cutover',
                'target' => 'tenant:'.$tenantId,
                'result' => 'success',
                'metadata' => ['cutoff' => $cutoff->toDateString(), 'rows' => (int) $rows->sum('rows_count')],
            ]);
        }
        $this->info("Settled {$count} ledger rows created before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
