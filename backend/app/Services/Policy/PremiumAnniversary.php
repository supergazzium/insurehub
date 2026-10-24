<?php

declare(strict_types=1);

namespace App\Services\Policy;

use App\Models\Policy;
use Illuminate\Support\Carbon;

/**
 * วันนัดทวงเบี้ยรายปี (annual premium anniversary) — computed on the fly, no
 * rows persisted ahead of time (per the design: a nightly cron walks live
 * policies and emits a reminder when the next anniversary is near).
 *
 * The model:
 *   - A premium-paying-term policy is billed once a year on the กรมธรรม์
 *     anniversary = effective_date + N whole years.
 *   - We chase for premium_paying_term years, then the policy is จ่ายครบ and
 *     reminders stop (coverage may continue — e.g. 15/90 — but no more
 *     premium is owed).
 *
 * Distinct from InstallmentSchedule, which splits ONE year's premium into
 * sub-งวด (monthly/quarterly). This is the across-year axis.
 *
 * Pure — reads the model only. `asOf` is injectable so tests and the cron
 * can pin "today".
 */
final class PremiumAnniversary
{
    /**
     * Whole coverage years elapsed since the effective date, i.e. how many
     * annual premiums should already have been billed. 0 on/within the first
     * year. Null when there is no effective date.
     */
    public static function yearsElapsed(Policy $policy, ?Carbon $asOf = null): ?int
    {
        $eff = $policy->effective_date;
        if ($eff === null) {
            return null;
        }
        $asOf ??= Carbon::today();
        $years = $eff->diffInYears($asOf);

        return (int) max(0, $years);
    }

    /**
     * The next annual premium due date, or null when:
     *   - the effective date is unknown, or
     *   - the policy is not on a premium-paying term (single-pay / n/a), or
     *   - the paying term is complete ("จ่ายครบแล้ว").
     *
     * due(k) = effective_date + k years, for the next unbilled year k.
     */
    public static function nextDueDate(Policy $policy, ?Carbon $asOf = null): ?Carbon
    {
        $term = $policy->effective_premium_term;
        $eff = $policy->effective_date;
        if ($term === null || $eff === null) {
            return null;
        }
        $asOf ??= Carbon::today();

        // The next anniversary that has not yet passed. If today is exactly an
        // anniversary, that one is still "due today" (not skipped).
        $elapsed = (int) max(0, $eff->diffInYears($asOf));
        $nextYear = $eff->copy()->addYears($elapsed);
        if ($nextYear->lt($asOf)) {
            $nextYear = $eff->copy()->addYears($elapsed + 1);
        }

        // k is the 1-based premium year this anniversary bills (year 1 is the
        // first anniversary at effective_date + 1 year).
        $k = (int) $eff->diffInYears($nextYear);
        if ($k < 1 || $k > $term) {
            return null; // before the first bill is impossible here; past term = จ่ายครบ
        }

        return $nextYear;
    }

    /**
     * 1-based index of the premium year the next due date bills (null when no
     * next due date). For "ปีที่ 3/15" style labels.
     */
    public static function nextDueYear(Policy $policy, ?Carbon $asOf = null): ?int
    {
        $eff = $policy->effective_date;
        $next = self::nextDueDate($policy, $asOf);
        if ($eff === null || $next === null) {
            return null;
        }

        return (int) $eff->diffInYears($next);
    }

    /**
     * Has the paying term been fully billed? True once every annual premium
     * (1..term) is behind us. Null when term/effective date unknown.
     */
    public static function termComplete(Policy $policy, ?Carbon $asOf = null): ?bool
    {
        $term = $policy->effective_premium_term;
        if ($term === null || $policy->effective_date === null) {
            return null;
        }

        return self::nextDueDate($policy, $asOf) === null;
    }

    /**
     * Full snapshot for the API / followup page.
     *
     * @return array{term: ?int, yearsElapsed: ?int, nextDueDate: ?string, nextDueYear: ?int, termComplete: ?bool}
     */
    public static function forPolicy(Policy $policy, ?Carbon $asOf = null): array
    {
        $next = self::nextDueDate($policy, $asOf);

        return [
            'term' => $policy->effective_premium_term,
            'yearsElapsed' => self::yearsElapsed($policy, $asOf),
            'nextDueDate' => $next?->toDateString(),
            'nextDueYear' => self::nextDueYear($policy, $asOf),
            'termComplete' => self::termComplete($policy, $asOf),
        ];
    }
}
