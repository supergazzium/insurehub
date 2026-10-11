<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CommissionReceivable;
use Illuminate\Support\Facades\DB;

final class ReceivableHints
{
    public static function endorsementTotals(array $policyIds): array
    {
        $policyIds = array_values(array_unique(array_filter($policyIds)));
        if ($policyIds === []) {
            return [];
        }

        $out = [];
        DB::table('policy_events')
            ->whereIn('policy_id', $policyIds)
            ->where('type', 'endorsement.premium_change')
            ->whereNull('voided_at')
            ->get(['policy_id', 'payload'])
            ->each(function (object $e) use (&$out): void {
                $payload = is_string($e->payload) ? (json_decode($e->payload, true) ?: []) : (array) $e->payload;
                $pid = (int) $e->policy_id;
                $out[$pid] ??= ['premium' => 0.0, 'count' => 0];
                $out[$pid]['premium'] += (float) ($payload['additionalPremium'] ?? 0);
                $out[$pid]['count']++;
            });

        return $out;
    }

    public static function forRow(array $row, ?array $endorsement): array
    {
        $hints = [];
        $suggested = null;
        $type = $row['commissionType'];
        $expected = (float) $row['expectedAmount'];
        $actual = $row['receivedAmount'] ?? $row['statementAmount'];
        $rate = $row['rate'] !== null ? (float) $row['rate'] : null;
        $base = (float) ($row['netPremium'] ?? 0);
        $hasDiff = $actual !== null && abs((float) $actual - $expected) > CommissionReceivable::TOLERANCE;

        if (($row['policyStatus'] ?? null) === 'cancelled') {
            $hints[] = [
                'code' => CommissionReceivable::REASON_CANCELLATION,
                'label' => 'กรมธรรม์ยกเลิกแล้ว'.($row['cancelDate'] ? ' ('.self::date($row['cancelDate']).')' : ''),
            ];
            $suggested ??= CommissionReceivable::REASON_CANCELLATION;
        }

        if ($type === CommissionReceivable::TYPE_MAIN && $endorsement !== null && abs($endorsement['premium']) > 0.009) {
            $estimate = $rate !== null ? round($endorsement['premium'] * $rate, 2) : null;
            $hints[] = [
                'code' => CommissionReceivable::REASON_ENDORSEMENT,
                'label' => 'มีสลักหลังเบี้ย '.$endorsement['count'].' ครั้ง เบี้ย '.self::signed($endorsement['premium'])
                    .($estimate !== null ? ' · ค่าคอมโดยประมาณ '.self::signed($estimate) : ''),
                'estimate' => $estimate,
            ];
            if ($hasDiff) {
                $suggested ??= CommissionReceivable::REASON_ENDORSEMENT;
            }
        }

        if ($type === CommissionReceivable::TYPE_MAIN && abs($expected) < CommissionReceivable::TOLERANCE) {
            $hints[] = ['code' => CommissionReceivable::REASON_NO_POLICY_COMMISSION, 'label' => 'ยังไม่มียอดค่าคอมในกรมธรรม์'];
            $suggested ??= CommissionReceivable::REASON_NO_POLICY_COMMISSION;
        }

        if ($type === CommissionReceivable::TYPE_MAIN && $hasDiff && $rate !== null && $base > 0 && $suggested === null) {
            $implied = (float) $actual / $base;
            if (abs($implied - $rate) > 0.0005) {
                $hints[] = [
                    'code' => CommissionReceivable::REASON_RATE,
                    'label' => 'อัตราตามยอดบริษัท '.self::percent($implied).' แต่ในกรมธรรม์ '.self::percent($rate),
                ];
                $suggested ??= CommissionReceivable::REASON_RATE;
            }
        }

        return ['hints' => $hints, 'suggestedReason' => $hasDiff || $expected == 0.0 ? $suggested : null];
    }

    private static function signed(float $v): string
    {
        return ($v >= 0 ? '+' : '−').number_format(abs($v), 2);
    }

    private static function percent(float $v): string
    {
        return rtrim(rtrim(number_format($v * 100, 2), '0'), '.').'%';
    }

    private static function date(string $iso): string
    {
        $d = substr($iso, 0, 10);
        [$y, $m, $day] = array_pad(explode('-', $d), 3, '');

        return $day !== '' ? "{$day}/{$m}/{$y}" : $d;
    }
}
