<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Policy;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * ปีของกรมธรรม์ (policy_year) + ปีของลูกค้า (customer_year) — task 1.4.
 *
 *   policy_year   — renewal of the same product: source + 1; a new policy or a
 *                   renewal onto a different product starts again at 1.
 *   customer_year — renewal: source + 1; otherwise the year (ordinal) since
 *                   the customer's first policy with us, 1 for a first policy.
 *
 * Both are suggested once, then stored. Past the quotation stage only an
 * admin may change them, and every such change is audited (PolicyObserver).
 */
final class PolicyYears
{
    /** Roles allowed to correct the stored years after quotation. */
    public const ADMIN_ROLES = ['super_admin', 'admin'];

    /** Statuses where the years are still the wizard's working values. */
    public const FREE_EDIT_STATUSES = ['draft', 'quotation'];

    /** @return array{policyYear: int, customerYear: int} */
    public static function suggest(Policy $policy): array
    {
        $source = $policy->ref_app_to_id !== null
            ? Policy::query()->where('tenant_id', $policy->tenant_id)->find($policy->ref_app_to_id)
            : null;

        $policyYear = 1;
        if ($source !== null && (string) $source->product_id === (string) $policy->product_id) {
            $policyYear = max(1, (int) $source->policy_year) + 1;
        }

        $customerYear = $source?->customer_year !== null
            ? (int) $source->customer_year + 1
            : self::customerYearFromHistory($policy);

        return ['policyYear' => $policyYear, 'customerYear' => $customerYear];
    }

    /** Ordinal year since the customer's earliest other policy (1 = first year / first policy). */
    public static function customerYearFromHistory(Policy $policy): int
    {
        if ($policy->customer_id === null) {
            return 1;
        }
        $first = Policy::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('customer_id', $policy->customer_id)
            ->when($policy->id !== null, fn ($q) => $q->where('id', '!=', $policy->id))
            ->whereNotNull('effective_date')
            ->min('effective_date');

        return self::ordinalYear($first, $policy->effective_date);
    }

    /**
     * ปีที่ N from `$from` to `$at` (default today): within the first 12
     * months = 1, each completed year adds one. 1 when `$from` is unknown or
     * not before `$at`.
     */
    public static function ordinalYear(mixed $from, mixed $at = null): int
    {
        if ($from === null) {
            return 1;
        }
        $start = Carbon::parse($from)->startOfDay();
        $end = ($at !== null ? Carbon::parse($at) : Carbon::today())->startOfDay();
        if ($end->lte($start)) {
            return 1;
        }

        return (int) $start->diffInYears($end) + 1;
    }

    public static function canOverride(?User $user): bool
    {
        return $user !== null && in_array($user->role, self::ADMIN_ROLES, true);
    }

    public const BASIS_COLUMNS = ['product_id', 'ref_app_to_id', 'customer_id'];

    public static function resolve(Policy $policy, ?User $user, ?array $override = null): void
    {
        $policy->yearsResolved = true;
        $basisChanged = ! $policy->exists || $policy->isDirty(self::BASIS_COLUMNS);
        $suggested = self::suggest($policy);

        if ($override !== null && self::canOverride($user)) {
            $chosen = [];
            foreach (['policy_year' => 'policyYear', 'customer_year' => 'customerYear'] as $col => $key) {
                $val = $override[$col] ?? null;
                $chosen[$col] = ($val === null || $val === '') ? $suggested[$key] : max(1, (int) $val);
                $policy->{$col} = $chosen[$col];
            }
            $policy->years_overridden = true;
            $wasFree = ! $policy->exists || in_array($policy->getOriginal('status'), self::FREE_EDIT_STATUSES, true);
            if ($wasFree) {
                $policy->pendingYearsAudit = [
                    'suggested' => ['policy_year' => $suggested['policyYear'], 'customer_year' => $suggested['customerYear']],
                    'chosen' => $chosen,
                ];
            }

            return;
        }

        if ($policy->years_overridden && ! $basisChanged) {
            return;
        }

        $free = in_array($policy->status ?? 'draft', self::FREE_EDIT_STATUSES, true);
        if ($basisChanged || $free) {
            $policy->policy_year = $suggested['policyYear'];
            $policy->customer_year = $suggested['customerYear'];
            $policy->years_overridden = false;
        }
    }

    /**
     * Error message when `$user` tries to change a stored year they may not
     * touch, null when the change is allowed.
     *
     * @param  array{policy_year?: mixed, customer_year?: mixed}  $incoming
     */
    public static function overrideError(?User $user, ?Policy $policy, array $incoming): ?string
    {
        if ($policy === null || in_array($policy->status, self::FREE_EDIT_STATUSES, true) || self::canOverride($user)) {
            return null;
        }
        foreach (['policy_year', 'customer_year'] as $col) {
            if (array_key_exists($col, $incoming) && $incoming[$col] !== null
                && (int) $incoming[$col] !== (int) $policy->{$col}) {
                return 'เฉพาะผู้ดูแลระบบเท่านั้นที่แก้ปีของกรมธรรม์ / ปีของลูกค้าได้หลังขั้นใบเสนอราคา';
            }
        }

        return null;
    }
}
