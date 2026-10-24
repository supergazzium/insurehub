<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentReminder;
use App\Models\Policy;
use App\Services\Policy\PremiumAnniversary;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ทวงเบี้ยรายปี — raises a SYSTEM payment reminder when a policy's next annual
 * premium anniversary is within the lead window.
 *
 * Mirrors TransitionPoliciesDaily's shape (daily, --dry-run, --tenant,
 * idempotent). One system reminder per policy per anniversary (enforced by the
 * pr_policy_kind_due_uniq unique index — a second run the same window is a
 * no-op). Only fires for active/issued policies that are still within their
 * premium-paying term; a จ่ายครบแล้ว policy yields no next due date and is
 * skipped (PremiumAnniversary::nextDueDate returns null).
 *
 * Scheduled from routes/console.php at 00:20 Asia/Bangkok — after the policy
 * transitions (00:15) so a policy activated today is already 'active'.
 */
class PremiumAnniversaryReminders extends Command
{
    protected $signature = 'premium:anniversary-reminders
        {--dry-run : Print what would be created without writing}
        {--tenant= : Restrict to a single tenant id (default: all)}
        {--lead-days=30 : Raise the alert this many days before the anniversary}';

    protected $description = 'Raise annual premium-anniversary reminders (ทวงเบี้ยรายปี) for policies due within the lead window.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $leadDays = max(0, (int) $this->option('lead-days'));

        $header = $dryRun ? 'DRY RUN — no writes' : 'LIVE RUN';
        $this->info("premium:anniversary-reminders [{$header}]");
        if ($tenantId !== null) {
            $this->line("  Scope: tenant_id = {$tenantId}");
        }
        $this->line("  Lead window: {$leadDays} days");
        $this->newLine();

        $today = Carbon::today();
        $windowEnd = $today->copy()->addDays($leadDays);

        $q = Policy::query()
            ->whereIn('status', ['active', 'issued'])
            ->whereNull('deleted_at')
            ->whereNotNull('effective_date')
            ->with('product')
            ->when($tenantId !== null, fn ($qq) => $qq->where('tenant_id', $tenantId));

        $created = 0;
        $skippedExisting = 0;

        foreach ($q->cursor() as $policy) {
            $due = PremiumAnniversary::nextDueDate($policy, $today);
            if ($due === null || $due->gt($windowEnd)) {
                continue; // no term / จ่ายครบ / not yet in the lead window
            }

            $year = PremiumAnniversary::nextDueYear($policy, $today);
            $dueStr = $due->toDateString();
            $this->line("  [due] policy {$policy->id} (".($policy->policy_no ?: $policy->application_no ?: '#'.$policy->id).") ปีที่ {$year}/{$policy->effective_premium_term} due {$dueStr}");

            if ($dryRun) {
                $created++;

                continue;
            }

            // Idempotent insert: the unique index (policy_id, kind, due_date)
            // makes a repeat run a no-op, so we never double-alert.
            $ok = $this->raise($policy, $dueStr, $year);
            $ok ? $created++ : $skippedExisting++;
        }

        $this->newLine();
        $this->info(sprintf('Summary: reminders=%d, already_present=%d', $created, $skippedExisting));

        return self::SUCCESS;
    }

    /** Insert one system reminder; returns false if it already existed. */
    private function raise(Policy $policy, string $dueDate, ?int $year): bool
    {
        $term = $policy->effective_premium_term;
        $note = $year !== null
            ? "ครบกำหนดชำระเบี้ยปีที่ {$year}".($term !== null ? "/{$term}" : '')." (วันนัด {$dueDate})"
            : "ครบกำหนดชำระเบี้ยรายปี (วันนัด {$dueDate})";

        try {
            PaymentReminder::create([
                'tenant_id' => (int) $policy->tenant_id,
                'policy_id' => $policy->id,
                'kind' => 'system',
                'due_date' => $dueDate,
                'channel' => 'system',
                'note' => $note,
                'amount_due' => $policy->annual_premium !== null ? (float) $policy->annual_premium : null,
                'created_by_user_id' => null,
            ]);

            return true;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Already raised for this anniversary — the idempotency guard.
            return false;
        }
    }
}
