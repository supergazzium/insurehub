<?php

declare(strict_types=1);

namespace App\Services\Policy;

use App\Models\Policy;
use App\Models\PolicyInstallment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PremiumDue
{
    public const ENDORSEMENT_TOTAL_SQL = "(SELECT COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.additionalTotal')) AS DECIMAL(15,2))), 0) FROM policy_events e WHERE e.policy_id = p.id AND e.type = 'endorsement.premium_change' AND e.voided_at IS NULL)";

    public static function due(float $main, float $compulsory, float $totalPremiumPaid, Collection $installments, float $endorsementTotal): float
    {
        if ($installments->isNotEmpty()) {
            return round($installments->sum(fn (PolicyInstallment $i) => (float) $i->amount_due), 2);
        }

        $base = $main + $compulsory;
        $gross = $totalPremiumPaid > 0 ? $totalPremiumPaid + $endorsementTotal : 0.0;

        return round(max($base, $gross), 2);
    }

    public static function paid(float $paymentsSum, int $paymentCount, bool $legacy, float $due): float
    {
        if ($legacy && $paymentCount === 0) {
            return round($due, 2);
        }

        return round($paymentsSum, 2);
    }

    public static function legacySettled(bool $legacy, int $paymentCount): bool
    {
        return $legacy && $paymentCount === 0;
    }

    public static function forPolicy(Policy $policy): array
    {
        $installments = $policy->installments()->get();
        $endorse = (float) DB::table('policy_events')
            ->where('policy_id', $policy->id)
            ->where('type', 'endorsement.premium_change')
            ->whereNull('voided_at')
            ->get(['payload'])
            ->sum(function (object $e): float {
                $payload = is_string($e->payload) ? (json_decode($e->payload, true) ?: []) : (array) $e->payload;

                return (float) ($payload['additionalTotal'] ?? 0);
            });
        $sum = (float) DB::table('policy_payments')->where('policy_id', $policy->id)->sum('amount');
        $count = (int) DB::table('policy_payments')->where('policy_id', $policy->id)->count();
        $due = self::due(
            (float) ($policy->main_premium ?? 0),
            (float) ($policy->compulsory_premium ?? 0),
            (float) ($policy->total_premium_paid ?? 0),
            $installments,
            $endorse,
        );
        $legacy = (bool) $policy->imported_from_legacy;

        return [
            'due' => $due,
            'paid' => self::paid($sum, $count, $legacy, $due),
            'paymentCount' => $count,
            'legacySettled' => self::legacySettled($legacy, $count),
        ];
    }
}
