<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Policy;
use App\Models\PolicyPayoutOverride;
use App\Models\Tenant;
use App\Services\Policy\InstallmentSchedule;
use App\Support\Freelook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PayoutEligibility
{
    public const REASON_FREELOOK = 'freelook';

    public const REASON_NO_DELIVERY = 'no_delivery_date';

    public const REASON_INSURER = 'insurer_not_received';

    public const REASON_NO_COMMISSION = 'insurer_no_commission';

    public const REASON_CUSTOMER = 'customer_unpaid';

    public static function settings(?Tenant $tenant): array
    {
        return [
            // Policy: pay once the CUSTOMER has paid; freelook + insurer are off
            // by default (see migration ..._payout_gate_defaults_customer_only).
            'freelook' => (bool) ($tenant?->payout_require_freelook ?? false),
            'insurer' => (bool) ($tenant?->payout_require_insurer_received ?? false),
            'customer' => (bool) ($tenant?->payout_require_customer_paid ?? true),
        ];
    }

    public function evaluate(int $tenantId, array $policyIds): array
    {
        $policyIds = array_values(array_unique(array_map('intval', $policyIds)));
        if ($policyIds === []) {
            return [];
        }
        $settings = self::settings(Tenant::query()->find($tenantId));
        $today = Carbon::now('Asia/Bangkok')->toDateString();

        $policies = Policy::query()->with('product')->whereIn('id', $policyIds)->get()->keyBy('id');
        $receivables = DB::table('commission_receivables')
            ->whereIn('policy_id', $policyIds)
            ->where('commission_type', 'MAIN')
            ->pluck('status', 'policy_id')
            ->all();
        $installments = DB::table('policy_installments')
            ->whereIn('policy_id', $policyIds)
            ->groupBy('policy_id')
            ->selectRaw("policy_id, COUNT(*) as total, SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid")
            ->get()
            ->keyBy('policy_id');
        $payments = DB::table('policy_payments')
            ->whereIn('policy_id', $policyIds)
            ->whereNull('voided_at')
            ->groupBy('policy_id')
            ->selectRaw('policy_id, SUM(amount + adjustment_amount) as paid')
            ->pluck('paid', 'policy_id')
            ->all();
        $overrides = PolicyPayoutOverride::query()
            ->with('createdBy:id,name')
            ->where('tenant_id', $tenantId)
            ->whereIn('policy_id', $policyIds)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->get()
            ->unique('policy_id')
            ->keyBy('policy_id');

        $out = [];
        foreach ($policyIds as $id) {
            $policy = $policies->get($id);
            if ($policy === null) {
                continue;
            }
            $override = $overrides->get($id);
            [$num, $den, $label] = $this->customerRatio($policy, $installments->get($id), (float) ($payments[$id] ?? 0));

            $reasons = [];
            if ($settings['freelook'] && Freelook::isLife($policy->product)) {
                $end = $policy->freelook_end_date?->toDateString();
                if ($end === null) {
                    $reasons[] = ['code' => self::REASON_NO_DELIVERY, 'label' => 'ยังไม่มีวันที่ลูกค้าได้รับกรมธรรม์ (คำนวณ Freelook ไม่ได้)'];
                } elseif ($end >= $today) {
                    $reasons[] = ['code' => self::REASON_FREELOOK, 'label' => 'อยู่ในช่วง Freelook ถึง '.Carbon::parse($end)->format('d/m/Y')];
                }
            }
            if ($settings['insurer']) {
                $status = $receivables[$id] ?? null;
                if ($status === 'No Commission') {
                    $reasons[] = ['code' => self::REASON_NO_COMMISSION, 'label' => 'บริษัทประกันแจ้งไม่จ่ายค่าคอม'];
                } elseif ($status !== 'Received') {
                    $reasons[] = ['code' => self::REASON_INSURER, 'label' => 'รอรับค่าคอมจากบริษัทประกัน'.($status ? " ({$status})" : '')];
                }
            }
            $customerBlocked = false;
            if (! $settings['customer']) {
                [$num, $den, $label] = [1, 1, null];
            } elseif ($num === 0) {
                $customerBlocked = true;
            }

            if ($override !== null) {
                $out[$id] = [
                    'ratio' => [1, 1], 'ratioLabel' => $label, 'gates' => [], 'customerReason' => null,
                    'override' => [
                        'id' => (string) $override->id,
                        'reason' => $override->reason,
                        'by' => $override->createdBy?->name,
                        'at' => $override->created_at?->toIso8601String(),
                        'bypassed' => array_merge($reasons, $customerBlocked ? [['code' => self::REASON_CUSTOMER, 'label' => 'ลูกค้ายังไม่ชำระ']] : []),
                    ],
                ];

                continue;
            }
            $out[$id] = [
                'ratio' => [$num, $den],
                'ratioLabel' => $label,
                'gates' => $reasons,
                'customerReason' => $customerBlocked ? ['code' => self::REASON_CUSTOMER, 'label' => 'ลูกค้ายังไม่ชำระ'.($label ? " ({$label})" : '')] : null,
                'override' => null,
            ];
        }

        return $out;
    }

    private function customerRatio(Policy $policy, ?object $installments, float $paid): array
    {
        if ($installments !== null && (int) $installments->total > 0) {
            $total = (int) $installments->total;
            $done = (int) $installments->paid;
            $label = $total > 1 ? "ชำระ {$done}/{$total} งวด" : ($done >= 1 ? 'ชำระแล้ว' : 'ยังไม่ชำระ');

            return [$done, $total, $label];
        }
        $plan = InstallmentSchedule::plan($policy);
        $due = round(array_sum(array_column($plan, 'amount')), 2);
        if ($due <= 0) {
            return $paid > 0 ? [1, 1, 'ชำระแล้ว'] : [0, 1, 'ยังไม่มีข้อมูลการชำระ'];
        }

        return $paid + 0.005 >= $due ? [1, 1, 'ชำระแล้ว'] : [0, 1, $paid > 0 ? 'ชำระบางส่วน' : 'ยังไม่ชำระ'];
    }
}
