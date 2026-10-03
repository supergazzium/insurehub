<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Policy;
use App\Services\Policy\InstallmentSchedule;
use Illuminate\Console\Command;

/**
 * Build ตารางงวดชำระ (task 1.2) for existing policies that have none, then
 * apply their recorded payments to the งวด. New policies get a schedule
 * automatically when issued (PolicyObserver); this covers everything that was
 * issued before the feature shipped.
 *
 * Idempotent: skips policies that already have installment rows. Payments are
 * only read (no policy_payments row is written), so the commission pipeline
 * never fires. Run manually when ready:
 *   php artisan policies:backfill-installments [--tenant=ID] [--status=issued,active] [--dry-run]
 */
class BackfillPolicyInstallments extends Command
{
    protected $signature = 'policies:backfill-installments
        {--tenant= : restrict to one tenant id}
        {--status=issued,active : comma-separated policy statuses to cover}
        {--dry-run : report only, write nothing}';

    protected $description = 'Create installment schedules for existing policies that have none';

    public function handle(InstallmentSchedule $schedule): int
    {
        $dry = (bool) $this->option('dry-run');
        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('status')))));

        $q = Policy::query()
            ->whereIn('status', $statuses)
            ->whereDoesntHave('installments');
        if ($this->option('tenant') !== null) {
            $q->where('tenant_id', (int) $this->option('tenant'));
        }

        $total = (clone $q)->count();
        $this->info(($dry ? '[dry-run] ' : '').'Found '.$total.' policies without a schedule ('.implode(', ', $statuses).').');

        $byCount = [];
        $skipped = 0;
        $written = 0;
        $samples = [];

        $q->orderBy('id')->chunkById(200, function ($policies) use ($dry, $schedule, &$byCount, &$skipped, &$written, &$samples): void {
            foreach ($policies as $policy) {
                $plan = InstallmentSchedule::plan($policy);
                if ($plan === []) {
                    $skipped++;   // no premium to schedule

                    continue;
                }
                $n = count($plan);
                $byCount[$n] = ($byCount[$n] ?? 0) + 1;
                if (count($samples) < 5) {
                    $samples[] = [
                        $policy->id,
                        $policy->policy_no ?? $policy->application_no ?? '—',
                        $policy->premium_mode,
                        $n,
                        number_format(array_sum(array_column($plan, 'amount')), 2),
                        $plan[0]['dueDate'] ?? '—',
                    ];
                }
                if (! $dry && $schedule->sync($policy)) {
                    $written++;
                }
            }
        });

        ksort($byCount);
        $this->table(['งวด', 'policies'], array_map(fn ($n, $c) => [$n, $c], array_keys($byCount), $byCount));
        if ($samples !== []) {
            $this->line('Sample:');
            $this->table(['id', 'policy', 'mode', 'งวด', 'total', 'first due'], $samples);
        }
        $this->info(sprintf(
            '%s%d scheduled, %d skipped (no premium).',
            $dry ? '[dry-run] would have ' : '',
            $dry ? array_sum($byCount) : $written,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
