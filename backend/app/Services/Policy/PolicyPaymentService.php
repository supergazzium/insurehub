<?php

declare(strict_types=1);

namespace App\Services\Policy;

use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\PolicyPayment;
use App\Models\User;
use App\Services\Commission\CommissionDeductedAtSource;
use App\Services\Commission\InstallmentAgentCost;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class PolicyPaymentService
{
    public const BLOCKED_STATUSES = ['cancelled', 'expired'];

    public function __construct(
        private readonly CommissionDeductedAtSource $deducted,
        private readonly InstallmentAgentCost $agentCost,
    ) {}

    public static function blockedReason(Policy $policy): ?string
    {
        return match ($policy->status) {
            'cancelled' => 'กรมธรรม์นี้ถูกยกเลิกแล้ว จึงรับชำระเบี้ยเพิ่มไม่ได้ (ถ้าบันทึกเงินผิด ให้ยกเลิกรายการชำระเดิมแทน)',
            'expired' => 'กรมธรรม์นี้หมดอายุแล้ว ต้องต่ออายุก่อนจึงจะรับชำระเบี้ยของปีใหม่ได้',
            default => null,
        };
    }

    public static function paymentStatus(array $state): string
    {
        if ($state['legacySettled']) {
            return 'legacy_settled';
        }
        if ($state['due'] <= 0 && $state['paid'] <= 0) {
            return 'unknown';
        }
        if ($state['outstanding'] < -1.0) {
            return 'overpaid';
        }
        if ($state['outstanding'] <= 1.0) {
            return 'paid';
        }

        return $state['paid'] > 0 ? 'partial' : 'unpaid';
    }

    public function record(Policy $policy, User $user, array $data): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($policy, $user, $data) {
            $policy = Policy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $reason = self::blockedReason($policy);
            if ($reason !== null) {
                throw new HttpResponseException(response()->json(['code' => 'policy_not_payable', 'message' => $reason], 422));
            }

            $payMode = $data['payMode'] ?? PolicyPayment::MODE_FULL;
            $payee = $data['payee'] ?? PolicyPayment::PAYEE_INSUREHUB;
            [$adjustment, $adjustmentKind] = $this->resolveAdjustment($policy, $payMode, (float) ($data['adjustmentAmount'] ?? 0));

            $this->guard(
                $policy,
                $data['payments'],
                $adjustment,
                (bool) ($data['confirmDuplicate'] ?? false),
                (bool) ($data['confirmOverpay'] ?? false),
            );

            $instMode = $data['installmentMode'] ?? null;
            $installmentIds = [];
            if ($instMode !== null && $policy->installment_mode !== $instMode) {
                $noById = $policy->installments()->pluck('installment_no', 'id');
                $policy->update(['installment_mode' => $instMode]);
                $idByNo = $policy->installments()->pluck('id', 'installment_no');
                foreach ($noById as $oldId => $no) {
                    $installmentIds[$oldId] = $idByNo[$no] ?? null;
                }
            }

            $rows = collect();
            foreach ($data['payments'] as $index => $p) {
                $installmentId = $p['installmentId'] ?? null;
                if ($installmentId !== null && array_key_exists($installmentId, $installmentIds)) {
                    $installmentId = $installmentIds[$installmentId];
                }
                $method = self::normalizeMethod($p['method']);
                $rowAdjustment = $index === 0 ? $adjustment : 0.0;

                $payment = $policy->payments()->create([
                    'policy_installment_id' => $installmentId,
                    'payment_date' => $p['paymentDate'],
                    'amount' => $p['amount'],
                    'net_amount' => $p['netAmount'] ?? null,
                    'duty_amount' => $p['dutyAmount'] ?? null,
                    'vat_amount' => $p['vatAmount'] ?? null,
                    'adjustment_amount' => $rowAdjustment,
                    'adjustment_kind' => $rowAdjustment > 0 ? $adjustmentKind : null,
                    'method' => $method,
                    'payee' => $payee,
                    'pay_mode' => $payMode,
                    'reference' => $p['reference'] ?? null,
                    'note' => $p['note'] ?? null,
                    'proof_document_id' => $p['proofDocumentId'] ?? null,
                    'recorded_by_user_id' => $user->id,
                ]);
                $this->deducted->record($policy, $payment);

                PolicyEvent::create([
                    'policy_id' => $policy->id,
                    'type' => 'premiumPaid',
                    'occurred_at' => now(),
                    'by_user_id' => $user->id,
                    'payload' => [
                        'paymentId' => (string) $payment->id,
                        'installmentId' => $installmentId !== null ? (string) $installmentId : null,
                        'amount' => (float) $p['amount'],
                        'method' => $method,
                        'payMode' => $payMode,
                        'payee' => $payee,
                        'note' => $p['note'] ?? null,
                        'adjustmentAmount' => $rowAdjustment,
                        'adjustmentKind' => $rowAdjustment > 0 ? $adjustmentKind : null,
                        'proofDocumentId' => $p['proofDocumentId'] ?? null,
                        'taxFormula' => $p['taxFormula'] ?? null,
                        'netAmount' => isset($p['netAmount']) ? (float) $p['netAmount'] : null,
                        'dutyAmount' => isset($p['dutyAmount']) ? (float) $p['dutyAmount'] : null,
                        'vatAmount' => isset($p['vatAmount']) ? (float) $p['vatAmount'] : null,
                    ],
                ]);
                $rows->push($payment);
            }

            if ($policy->status === 'issued') {
                $policy->update(['status' => 'active']);
            }

            if ($instMode !== null && $rows->isNotEmpty()) {
                $this->agentCost->record(
                    $policy,
                    $rows->first(),
                    $instMode,
                    (float) ($data['agentFeeCost'] ?? 0),
                    (float) ($data['agentInterestCost'] ?? 0),
                );
            }

            return $rows;
        });
    }

    public function void(Policy $policy, PolicyPayment $payment, User $user, string $reason): PolicyPayment
    {
        return DB::transaction(function () use ($policy, $payment, $user, $reason) {
            $payment = PolicyPayment::withoutGlobalScopes()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->voided_at !== null) {
                throw new HttpResponseException(response()->json([
                    'code' => 'payment_already_voided',
                    'message' => 'รายการชำระนี้ถูกยกเลิกไปแล้ว',
                ], 409));
            }
            if ($this->deducted->hasLockedRows($payment)) {
                throw new HttpResponseException(response()->json([
                    'code' => 'payment_commission_paid',
                    'message' => 'ยกเลิกรายการนี้ไม่ได้ เพราะค่าคอมที่เกิดจากการชำระนี้อยู่ในรอบจ่ายค่าคอมแล้ว',
                ], 409));
            }

            $reversed = $this->deducted->reverseForPayment($payment);
            $payment->update([
                'voided_at' => now(),
                'voided_by_user_id' => $user->id,
                'void_reason' => $reason,
            ]);

            PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => 'premiumPaymentVoided',
                'occurred_at' => now(),
                'by_user_id' => $user->id,
                'payload' => [
                    'paymentId' => (string) $payment->id,
                    'amount' => (float) $payment->amount,
                    'adjustmentAmount' => (float) $payment->adjustment_amount,
                    'reason' => $reason,
                    'commissionRowsReversed' => $reversed,
                ],
            ]);

            return $payment->refresh();
        });
    }

    private function resolveAdjustment(Policy $policy, string $payMode, float $requested): array
    {
        $requested = round(max(0.0, $requested), 2);
        if ($requested <= 0 || ! in_array($payMode, [PolicyPayment::MODE_LESS_COMMISSION, PolicyPayment::MODE_WITH_DISCOUNT], true)) {
            return [0.0, null];
        }
        if ($payMode === PolicyPayment::MODE_LESS_COMMISSION) {
            $agentCommission = (float) ($policy->comm_hub_to_agent_amount ?? 0);
            if ($agentCommission > 0 && $requested - $agentCommission > 0.005) {
                throw new HttpResponseException(response()->json([
                    'code' => 'commission_deduction_exceeds',
                    'message' => 'ค่าคอมที่หักมากกว่าค่าคอมของตัวแทนในกรมธรรม์นี้ ('.number_format($agentCommission, 2).' บาท)',
                ], 422));
            }

            return [$requested, PolicyPayment::ADJ_COMMISSION_DEDUCTED];
        }

        return [$requested, PolicyPayment::ADJ_DISCOUNT];
    }

    private function guard(Policy $policy, array $payments, float $adjustment, bool $confirmDuplicate, bool $confirmOverpay): void
    {
        if (! $confirmDuplicate) {
            $duplicates = [];
            $seen = [];
            foreach ($payments as $i => $p) {
                $amount = round((float) $p['amount'], 2);
                $date = substr((string) $p['paymentDate'], 0, 10);
                $inst = $p['installmentId'] ?? null;
                $key = $date.'|'.$amount.'|'.($inst ?? '-');
                $existing = DB::table('policy_payments')
                    ->where('policy_id', $policy->id)
                    ->whereNull('voided_at')
                    ->whereDate('payment_date', $date)
                    ->whereBetween('amount', [$amount - 0.005, $amount + 0.005])
                    ->when($inst !== null, fn ($q) => $q->where('policy_installment_id', $inst), fn ($q) => $q->whereNull('policy_installment_id'))
                    ->where('created_at', '>=', now()->subMinutes(10))
                    ->exists();
                if ($existing || isset($seen[$key])) {
                    $duplicates[] = ['row' => $i + 1, 'paymentDate' => $date, 'amount' => $amount];
                }
                $seen[$key] = true;
            }
            if ($duplicates !== []) {
                throw new HttpResponseException(response()->json([
                    'code' => 'payment_duplicate',
                    'message' => 'มีรายการชำระวันที่และยอดเดียวกันบันทึกไว้แล้ว อาจเป็นการบันทึกซ้ำ',
                    'duplicates' => $duplicates,
                ], 409));
            }
        }

        if (! $confirmOverpay) {
            $state = PremiumDue::forPolicy($policy);
            $adding = round(array_sum(array_map(fn ($p) => (float) $p['amount'], $payments)) + $adjustment, 2);
            if ($state['due'] > 0 && $state['paid'] + $adding - $state['due'] > 1.0) {
                throw new HttpResponseException(response()->json([
                    'code' => 'payment_overpay',
                    'message' => $state['legacySettled']
                        ? 'กรมธรรม์นี้มาจากระบบเดิมและถือว่าชำระครบแล้ว การบันทึกเพิ่มจะทำให้ยอดชำระเกินเบี้ยที่ต้องชำระ'
                        : 'ยอดชำระรวมจะเกินเบี้ยที่ต้องชำระ',
                    'due' => $state['due'],
                    'paid' => $state['paid'],
                    'adding' => $adding,
                    'over' => round($state['paid'] + $adding - $state['due'], 2),
                ], 409));
            }
        }
    }

    public static function normalizeMethod(string $m): string
    {
        return match ($m) {
            'transfer' => 'bankTransfer',
            'credit_card' => 'creditCard',
            default => $m,
        };
    }
}
