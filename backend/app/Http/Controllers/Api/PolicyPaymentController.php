<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\PolicyInstallmentResource;
use App\Http\Resources\PolicyPaymentResource;
use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\PolicyPayment;
use App\Services\Policy\PremiumDue;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PolicyPaymentController extends ApiController
{
    /** List a policy's recorded payments, newest first. */
    public function index(Request $request, Policy $policy): AnonymousResourceCollection
    {
        $this->authorizeTenant($request, $policy);

        return PolicyPaymentResource::collection(
            $policy->payments()->orderByDesc('payment_date')->orderByDesc('id')->get()
                ->each(fn ($p) => $p->setRelation('policy', $policy))
        );
    }

    /** ตารางงวดชำระ — the policy's งวด with how much of each is paid. */
    public function installments(Request $request, Policy $policy): AnonymousResourceCollection
    {
        $this->authorizeTenant($request, $policy);

        return PolicyInstallmentResource::collection(
            $policy->installments()->get()->each(fn ($i) => $i->setRelation('policy', $policy))
        );
    }

    /**
     * Record one or more payment rows for a policy. The payment modal submits a
     * batch: a `payMode` (full / less_commission / with_discount / split /
     * installment), a `payee` (insurehub / carrier), and one `payments[]` row
     * per งวด. Each row becomes a policy_payments record — the PolicyPayment
     * observer fires the MGM pipeline (volume → rank → commission) per row.
     *
     * Back-compat: a single flat {paymentDate, amount, method} body is still
     * accepted (wrapped into a one-row batch).
     */
    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        // Normalize a legacy single-payment body into the batch shape.
        if (! $request->has('payments')) {
            $request->merge(['payments' => [[
                'paymentDate' => $request->input('paymentDate'),
                'amount' => $request->input('amount'),
                'method' => $request->input('method'),
                'reference' => $request->input('reference'),
            ]]]);
        }

        $data = $request->validate([
            'payMode' => ['sometimes', 'nullable', 'string', 'in:full,less_commission,with_discount,split,installment'],
            'payee' => ['sometimes', 'nullable', 'string', 'in:insurehub,carrier'],
            // Installment engine (per spec) — batch-level mode + agent costs.
            'installmentMode' => ['sometimes', 'nullable', 'string', 'in:A,B,C'],
            'agentFeeCost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'agentInterestCost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.paymentDate' => ['required', 'date'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.method' => ['required', 'string', 'in:bankTransfer,creditCard,cash,cheque,directDebit,transfer,credit_card'],
            'payments.*.reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            // งวดที่รับชำระ — settles this งวด first (partial OK); omitted =
            // applied to the oldest unpaid งวด. See InstallmentSchedule.
            'payments.*.installmentId' => ['sometimes', 'nullable', 'integer',
                Rule::exists('policy_installments', 'id')->where('policy_id', $policy->id)],
            'payments.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Tax decomposition of the actual amount paid (per งวด) + the สูตร used.
            'payments.*.taxFormula' => ['sometimes', 'nullable', 'integer', 'in:1,2,3,4'],
            'payments.*.netAmount' => ['sometimes', 'nullable', 'numeric'],
            'payments.*.dutyAmount' => ['sometimes', 'nullable', 'numeric'],
            'payments.*.vatAmount' => ['sometimes', 'nullable', 'numeric'],
            'confirmDuplicate' => ['sometimes', 'boolean'],
            'confirmOverpay' => ['sometimes', 'boolean'],
        ]);

        $payMode = $data['payMode'] ?? 'full';
        $payee = $data['payee'] ?? 'insurehub';

        $created = DB::transaction(function () use ($policy, $data, $payMode, $payee, $request) {
            $policy = Policy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $this->guardPayments($policy, $data['payments'], (bool) ($data['confirmDuplicate'] ?? false), (bool) ($data['confirmOverpay'] ?? false));
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
            foreach ($data['payments'] as $p) {
                $installmentId = $p['installmentId'] ?? null;
                if ($installmentId !== null && array_key_exists($installmentId, $installmentIds)) {
                    $installmentId = $installmentIds[$installmentId];
                }
                $payment = $policy->payments()->create([
                    'policy_installment_id' => $installmentId,
                    'payment_date' => $p['paymentDate'],
                    'amount' => $p['amount'],
                    'net_amount' => $p['netAmount'] ?? null,
                    'duty_amount' => $p['dutyAmount'] ?? null,
                    'vat_amount' => $p['vatAmount'] ?? null,
                    'method' => self::normalizeMethod($p['method']),
                    // No dedicated columns for note/payMode/payee — fold the
                    // operator note into `reference` so it's not lost.
                    'reference' => $p['reference'] ?? ($p['note'] ?? null),
                    'recorded_by_user_id' => $request->user()->id,
                ]);
                PolicyEvent::create([
                    'policy_id' => $policy->id,
                    'type' => 'premiumPaid',
                    'occurred_at' => now(),
                    'by_user_id' => $request->user()->id,
                    'payload' => [
                        'paymentId' => (string) $payment->id,
                        'installmentId' => $installmentId !== null ? (string) $installmentId : null,
                        'amount' => (float) $p['amount'],
                        'method' => self::normalizeMethod($p['method']),
                        'payMode' => $payMode,
                        'payee' => $payee,
                        'note' => $p['note'] ?? null,
                        'taxFormula' => $p['taxFormula'] ?? null,
                        'netAmount' => isset($p['netAmount']) ? (float) $p['netAmount'] : null,
                        'dutyAmount' => isset($p['dutyAmount']) ? (float) $p['dutyAmount'] : null,
                        'vatAmount' => isset($p['vatAmount']) ? (float) $p['vatAmount'] : null,
                    ],
                ]);
                $rows->push($payment);
            }
            // Flip issued → active once any premium is recorded.
            if ($policy->status === 'issued') {
                $policy->update(['status' => 'active']);
            }

            // Installment engine (spec §10): persist the chosen mode on the
            // policy and book the cost the AGENT bears (fee/interest) to
            // commission settlement as a negative-amount ledger row. Anchored
            // on the batch's first payment so it books exactly once.
            if ($instMode !== null && $rows->isNotEmpty()) {
                app(\App\Services\Commission\InstallmentAgentCost::class)->record(
                    $policy,
                    $rows->first(),
                    $instMode,
                    (float) ($data['agentFeeCost'] ?? 0),
                    (float) ($data['agentInterestCost'] ?? 0),
                );
            }

            return $rows;
        });

        return PolicyPaymentResource::collection($created)->response()->setStatusCode(201);
    }

    private function guardPayments(Policy $policy, array $payments, bool $confirmDuplicate, bool $confirmOverpay): void
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
            $adding = round(array_sum(array_map(fn ($p) => (float) $p['amount'], $payments)), 2);
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

    /** Map the modal's method values onto the stored enum. */
    private static function normalizeMethod(string $m): string
    {
        return match ($m) {
            'transfer' => 'bankTransfer',
            'credit_card' => 'creditCard',
            default => $m,
        };
    }

    public function destroy(Request $request, Policy $policy, PolicyPayment $payment): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        if ((int) $payment->policy_id !== (int) $policy->id) {
            abort(404);
        }
        $locked = DB::table('commission_ledgers')
            ->where('policy_payment_id', $payment->id)
            ->where(fn ($q) => $q->whereNotNull('payout_batch_id')->orWhere('status', 'settled'))
            ->exists();
        if ($locked) {
            return response()->json([
                'code' => 'payment_commission_paid',
                'message' => 'ลบการชำระนี้ไม่ได้ เพราะค่าคอมที่เกิดจากการชำระนี้อยู่ในรอบจ่ายค่าคอมแล้ว',
            ], 409);
        }
        $payment->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
