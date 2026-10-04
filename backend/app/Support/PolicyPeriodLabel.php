<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\PolicyInstallment;
use App\Models\PolicyPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PolicyPeriodLabel
{
    public static function format(int $policyYear, ?int $endorsementNo, ?int $installmentNo, ?int $installmentTotal): string
    {
        $parts = [max(1, $policyYear).'#'];
        if ($endorsementNo !== null && $endorsementNo > 0) {
            $parts[] = '$'.$endorsementNo;
        }
        if ($installmentNo !== null && $installmentTotal !== null && $installmentTotal > 0) {
            $parts[] = $installmentNo.'/'.$installmentTotal;
        }

        return implode(' ', $parts);
    }

    public static function forPolicy(Policy $policy): string
    {
        [$no, $total] = self::currentInstallment($policy);

        return self::format((int) $policy->policy_year, self::endorsementNo($policy->id), $no, $total);
    }

    public static function currentInstallment(Policy $policy): array
    {
        $rows = $policy->relationLoaded('installments')
            ? $policy->installments
            : ($policy->exists ? $policy->installments()->get(['installment_no', 'installment_total', 'status']) : collect());

        if ($rows->isNotEmpty()) {
            $row = $rows->first(fn ($r) => $r->status !== 'paid') ?? $rows->last();

            return [(int) $row->installment_no, (int) $row->installment_total];
        }

        return [1, self::plannedCount($policy->installment_term, $policy->premium_mode)];
    }

    public static function forInstallment(PolicyInstallment $installment, Policy $policy): string
    {
        return self::format(
            (int) $policy->policy_year,
            self::endorsementNo($policy->id, $installment->due_date),
            (int) $installment->installment_no,
            (int) $installment->installment_total,
        );
    }

    public static function forPayment(PolicyPayment $payment, Policy $policy): string
    {
        $inst = $payment->policy_installment_id !== null
            ? $payment->installment
            : self::installmentCoveredBy($payment, $policy);

        return self::format(
            (int) $policy->policy_year,
            self::endorsementNo($policy->id, $payment->payment_date),
            $inst !== null ? (int) $inst->installment_no : null,
            $inst !== null ? (int) $inst->installment_total : null,
        );
    }

    public static function installmentCoveredBy(PolicyPayment $payment, Policy $policy): ?PolicyInstallment
    {
        $installments = $policy->relationLoaded('installments') ? $policy->installments : $policy->installments()->get();
        if ($installments->isEmpty() || $payment->id === null) {
            return null;
        }
        $paidBefore = (float) PolicyPayment::query()
            ->where('policy_id', $policy->id)
            ->whereNull('policy_installment_id')
            ->where(function ($q) use ($payment): void {
                $q->where('payment_date', '<', $payment->payment_date)
                    ->orWhere(fn ($q) => $q->where('payment_date', $payment->payment_date)->where('id', '<', $payment->id));
            })
            ->sum('amount');

        $cumulative = 0.0;
        foreach ($installments as $i) {
            $cumulative += (float) $i->amount_due;
            if ($cumulative > $paidBefore + 0.005) {
                return $i;
            }
        }

        return $installments->last();
    }

    public static function endorsementNo(int|string|null $policyId, mixed $asOf = null): int
    {
        if ($policyId === null) {
            return 0;
        }
        $q = PolicyEvent::query()
            ->where('policy_id', $policyId)
            ->where('type', 'like', 'endorsement.%')
            ->whereNotNull('endorsement_seq')
            ->whereNull('voided_at');
        if ($asOf !== null) {
            $q->where('occurred_at', '<=', Carbon::parse($asOf)->endOfDay());
        }

        return (int) $q->max('endorsement_seq');
    }

    public static function forListRows(iterable $rows): array
    {
        $rows = collect($rows);
        $ids = $rows->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $installments = DB::table('policy_installments')
            ->whereIn('policy_id', $ids)
            ->orderBy('installment_no')
            ->get(['policy_id', 'installment_no', 'installment_total', 'status'])
            ->groupBy('policy_id');
        $endorsements = DB::table('policy_events')
            ->whereIn('policy_id', $ids)
            ->where('type', 'like', 'endorsement.%')
            ->whereNotNull('endorsement_seq')
            ->whereNull('voided_at')
            ->selectRaw('policy_id, MAX(endorsement_seq) as n')
            ->groupBy('policy_id')
            ->pluck('n', 'policy_id');

        $out = [];
        foreach ($rows as $r) {
            $sched = $installments->get($r->id);
            if ($sched !== null && $sched->isNotEmpty()) {
                $row = $sched->first(fn ($i) => $i->status !== 'paid') ?? $sched->last();
                [$no, $total] = [(int) $row->installment_no, (int) $row->installment_total];
            } else {
                [$no, $total] = [1, self::plannedCount($r->installment_term ?? null, $r->premium_mode ?? null)];
            }
            $out[(string) $r->id] = self::format((int) ($r->policy_year ?? 1), (int) ($endorsements[$r->id] ?? 0), $no, $total);
        }

        return $out;
    }

    private static function plannedCount(mixed $term, ?string $mode): int
    {
        $term = (int) ($term ?? 0);

        return $term >= 2 ? $term : PremiumMode::installmentsFor($mode);
    }
}
