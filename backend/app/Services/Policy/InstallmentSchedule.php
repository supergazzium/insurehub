<?php

declare(strict_types=1);

namespace App\Services\Policy;

use App\Models\Policy;
use App\Models\PolicyInstallment;
use App\Models\PolicyPayment;
use App\Support\PremiumMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ตารางงวดชำระ — builds a policy's installment rows and re-derives how much
 * of each งวด its recorded payments have settled.
 *
 * How many งวด: installment_term when it is 2+, otherwise the premium_mode's
 * count (monthly 12 / quarterly 4 / semiannual 2 / annual|single 1).
 *
 * How much per งวด (total owed = main premium + พ.ร.บ., same basis as the
 * collections page):
 *   - InsureHub plan (installment_mode A/B/C, 2–10 งวด): the installment
 *     engine — down payment, fee + interest and พ.ร.บ. loaded on งวด 1,
 *     every งวด ceiling-rounded (Insurehub installment spec, 17 Sep 2026).
 *   - Otherwise an even split, rounded down to the satang, the remainder on
 *     the last งวด so the งวด sum equals the total exactly.
 *
 * Due dates step from first_due_inst_date (else effective_date): monthly for
 * InsureHub plans, otherwise by the frequency (1 / 3 / 6 months).
 */
class InstallmentSchedule
{
    public const DOWN_RATE = 0.25;
    public const FEE_RATE = 0.02;
    public const INT_RATE = 0.01;

    /** Statuses at which a policy carries a live schedule. */
    public const SCHEDULED_STATUSES = ['issued', 'active'];

    /** Policy columns the plan is derived from — a change re-plans an unpaid schedule. */
    public const PLAN_COLUMNS = [
        'premium_mode', 'installment_term', 'installment_mode', 'main_premium', 'net_premium',
        'annual_premium', 'compulsory_premium', 'first_due_inst_date', 'effective_date',
    ];

    private const MONTHS_BETWEEN = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6];

    /**
     * The schedule a policy should have. Pure — reads the model only.
     *
     * @return list<array{no: int, total: int, dueDate: ?string, amount: float}>
     */
    public static function plan(Policy $policy): array
    {
        $main = (float) ($policy->main_premium ?: $policy->net_premium ?: $policy->annual_premium ?: 0);
        $compulsory = (float) ($policy->compulsory_premium ?? 0);
        $total = round($main + $compulsory, 2);
        if ($total <= 0) {
            return [];
        }

        $term = (int) ($policy->installment_term ?? 0);
        $count = $term >= 2 ? $term : PremiumMode::installmentsFor($policy->premium_mode);
        $mode = $policy->installment_mode;

        if (in_array($mode, ['A', 'B', 'C'], true) && $count >= 2 && $count <= 10 && $main > 0) {
            $amounts = self::financePlan($main, $compulsory, $count, $mode);
            $step = 1;
        } else {
            $amounts = self::evenSplit($total, $count);
            $step = self::MONTHS_BETWEEN[$policy->premium_mode ?? ''] ?? 1;
        }

        $baseDate = $policy->first_due_inst_date ?? $policy->effective_date;
        $base = $baseDate ? Carbon::parse($baseDate) : null;

        $out = [];
        foreach ($amounts as $i => $amount) {
            $out[] = [
                'no' => $i + 1,
                'total' => $count,
                'dueDate' => $base?->copy()->addMonthsNoOverflow($i * $step)->toDateString(),
                'amount' => (float) $amount,
            ];
        }

        return $out;
    }

    /**
     * InsureHub installment engine — mirrors frontend utils/installmentCalc.ts.
     *
     * @return list<int>
     */
    public static function financePlan(float $main, float $compulsory, int $count, string $mode): array
    {
        $first = $count <= 4 ? $main / $count : $main * self::DOWN_RATE;
        $customerFee = in_array($mode, ['B', 'C'], true) ? $main * self::FEE_RATE : 0;
        $customerInterest = $mode === 'C' ? $main * self::INT_RATE * $count : 0;
        $inst1 = (int) ceil($first + $compulsory + $customerFee + $customerInterest - 1e-9);
        $rest = (int) ceil(($main - $first) / ($count - 1) - 1e-9);

        return array_merge([$inst1], array_fill(0, $count - 1, $rest));
    }

    /**
     * Even split, satang-floored, remainder on the last งวด.
     *
     * @return list<float>
     */
    public static function evenSplit(float $total, int $count): array
    {
        $count = max(1, $count);
        $cents = (int) round($total * 100);
        $per = intdiv($cents, $count);
        $amounts = array_fill(0, $count, $per / 100);
        $amounts[$count - 1] = ($cents - $per * ($count - 1)) / 100;

        return $amounts;
    }

    /**
     * Create the schedule if the policy has none, or re-plan it while no
     * money has been applied. A schedule with payments is never rebuilt —
     * it reflects what the customer was billed. Returns true when rows
     * were (re)written.
     */
    public function sync(Policy $policy): bool
    {
        return DB::transaction(function () use ($policy): bool {
            $existing = $policy->installments()->lockForUpdate()->get();
            $plan = self::plan($policy);

            if ($existing->isNotEmpty()) {
                $applied = $existing->sum(fn (PolicyInstallment $i) => (float) $i->amount_paid) > 0
                    || PolicyPayment::query()->whereIn('policy_installment_id', $existing->pluck('id'))->exists();
                if ($applied || self::matches($existing->all(), $plan)) {
                    return false;
                }
                PolicyInstallment::query()->whereIn('id', $existing->pluck('id'))->delete();
            }

            if ($plan === []) {
                return $existing->isNotEmpty();
            }

            foreach ($plan as $row) {
                PolicyInstallment::create([
                    'tenant_id' => $policy->tenant_id,
                    'policy_id' => $policy->id,
                    'installment_no' => $row['no'],
                    'installment_total' => $row['total'],
                    'due_date' => $row['dueDate'],
                    'amount_due' => $row['amount'],
                ]);
            }
            $this->reallocate($policy);

            return true;
        });
    }

    /**
     * Re-derive amount_paid / status / paid_date for every งวด from the
     * policy's payments, oldest first. A payment linked to a งวด settles
     * that งวด first; anything left (or an unlinked payment) fills the
     * oldest unpaid งวด onward. Overpayment beyond the last งวด lands on it.
     */
    public function reallocate(Policy $policy): void
    {
        $installments = $policy->installments()->get()->values();
        if ($installments->isEmpty()) {
            return;
        }

        $remaining = $installments->map(fn (PolicyInstallment $i) => (int) round((float) $i->amount_due * 100))->all();
        $paid = array_fill(0, count($remaining), 0);
        $paidDate = array_fill(0, count($remaining), null);
        $indexById = $installments->pluck('id')->flip()->all();

        $payments = $policy->payments()->orderBy('payment_date')->orderBy('id')->get();
        foreach ($payments as $payment) {
            $left = (int) round($payment->creditAmount() * 100);
            $date = $payment->payment_date?->toDateString();

            $order = array_keys($remaining);
            $linked = $indexById[$payment->policy_installment_id] ?? null;
            if ($linked !== null) {
                $order = array_merge([$linked], array_values(array_diff($order, [$linked])));
            }
            foreach ($order as $k) {
                if ($left <= 0) {
                    break;
                }
                if ($remaining[$k] <= 0) {
                    continue;
                }
                $take = min($left, $remaining[$k]);
                $remaining[$k] -= $take;
                $paid[$k] += $take;
                $left -= $take;
                if ($remaining[$k] === 0) {
                    $paidDate[$k] = $date;
                }
            }
            if ($left > 0) {
                $paid[count($paid) - 1] += $left;
            }
        }

        foreach ($installments as $k => $installment) {
            $status = $remaining[$k] <= 0
                ? PolicyInstallment::STATUS_PAID
                : ($paid[$k] > 0 ? PolicyInstallment::STATUS_PARTIAL : PolicyInstallment::STATUS_PENDING);
            $installment->fill([
                'amount_paid' => $paid[$k] / 100,
                'status' => $status,
                'paid_date' => $status === PolicyInstallment::STATUS_PAID ? $paidDate[$k] : null,
            ]);
            if ($installment->isDirty()) {
                $installment->save();
            }
        }
    }

    /** @param list<PolicyInstallment> $rows */
    private static function matches(array $rows, array $plan): bool
    {
        if (count($rows) !== count($plan)) {
            return false;
        }
        foreach ($plan as $i => $p) {
            $r = $rows[$i];
            if ((int) $r->installment_no !== $p['no']
                || (int) $r->installment_total !== $p['total']
                || $r->due_date?->toDateString() !== $p['dueDate']
                || abs((float) $r->amount_due - $p['amount']) > 0.004) {
                return false;
            }
        }

        return true;
    }
}
