<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\PolicyInstallmentResource;
use App\Http\Resources\PolicyPaymentResource;
use App\Models\Policy;
use App\Models\PolicyPayment;
use App\Services\Policy\PolicyPaymentService;
use App\Services\Policy\PremiumDue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PolicyPaymentController extends ApiController
{
    public function __construct(private readonly PolicyPaymentService $payments) {}

    public function index(Request $request, Policy $policy): AnonymousResourceCollection
    {
        $this->authorizeTenant($request, $policy);

        $query = $request->boolean('withVoided')
            ? PolicyPayment::withoutGlobalScopes()->where('policy_id', $policy->id)
            : $policy->payments();

        $rows = $query
            ->with(['user:id,name', 'voidedBy:id,name', 'proof:id,file_name', 'installment'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get()
            ->each(fn ($p) => $p->setRelation('policy', $policy));

        return PolicyPaymentResource::collection($rows)->additional(['meta' => $this->summary($policy)]);
    }

    public function installments(Request $request, Policy $policy): AnonymousResourceCollection
    {
        $this->authorizeTenant($request, $policy);

        return PolicyInstallmentResource::collection(
            $policy->installments()->get()->each(fn ($i) => $i->setRelation('policy', $policy))
        );
    }

    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        Gate::authorize('payments.record');

        if (! $request->has('payments')) {
            $request->merge(['payments' => [[
                'paymentDate' => $request->input('paymentDate'),
                'amount' => $request->input('amount'),
                'method' => $request->input('method'),
                'reference' => $request->input('reference'),
            ]]]);
        }

        $today = Carbon::now('Asia/Bangkok')->toDateString();

        $data = $request->validate([
            'payMode' => ['sometimes', 'nullable', 'string', 'in:full,less_commission,with_discount,split,installment'],
            'payee' => ['sometimes', 'nullable', 'string', 'in:insurehub,carrier'],
            'installmentMode' => ['sometimes', 'nullable', 'string', 'in:A,B,C'],
            'agentFeeCost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'agentInterestCost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'adjustmentAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payments' => ['required', 'array', 'min:1', 'max:24'],
            'payments.*.paymentDate' => ['required', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:'.$today],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'payments.*.method' => ['required', 'string', 'in:bankTransfer,creditCard,cash,cheque,directDebit,transfer,credit_card'],
            'payments.*.reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payments.*.installmentId' => ['sometimes', 'nullable', 'integer',
                Rule::exists('policy_installments', 'id')->where('policy_id', $policy->id)],
            'payments.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payments.*.proofDocumentId' => ['sometimes', 'nullable', 'integer',
                Rule::exists('policy_documents', 'id')->where('policy_id', $policy->id)->where('type', 'receipt')],
            'payments.*.taxFormula' => ['sometimes', 'nullable', 'integer', 'in:1,2,3,4'],
            'payments.*.netAmount' => ['sometimes', 'nullable', 'numeric'],
            'payments.*.dutyAmount' => ['sometimes', 'nullable', 'numeric'],
            'payments.*.vatAmount' => ['sometimes', 'nullable', 'numeric'],
            'confirmDuplicate' => ['sometimes', 'boolean'],
            'confirmOverpay' => ['sometimes', 'boolean'],
        ], [
            'payments.*.paymentDate.before_or_equal' => 'วันที่ชำระต้องไม่เป็นวันในอนาคต',
            'payments.*.amount.gt' => 'ยอดเงินต้องมากกว่า 0',
        ]);

        $created = $this->payments->record($policy, $request->user(), $data);

        return PolicyPaymentResource::collection($created->each(fn ($p) => $p->setRelation('policy', $policy)))
            ->additional(['meta' => $this->summary($policy->refresh())])
            ->response()
            ->setStatusCode(201);
    }

    public function void(Request $request, Policy $policy, int $payment): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        Gate::authorize('payments.void');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'กรุณาระบุเหตุผลที่ยกเลิกรายการ',
            'reason.min' => 'เหตุผลสั้นเกินไป',
        ]);

        $row = PolicyPayment::withoutGlobalScopes()->where('policy_id', $policy->id)->findOrFail($payment);
        $voided = $this->payments->void($policy, $row, $request->user(), $data['reason']);
        $voided->setRelation('policy', $policy);

        return (new PolicyPaymentResource($voided))
            ->additional(['meta' => $this->summary($policy->refresh())])
            ->response();
    }

    private function summary(Policy $policy): array
    {
        $state = PremiumDue::forPolicy($policy);
        $blocked = PolicyPaymentService::blockedReason($policy);

        return $state + [
            'status' => PolicyPaymentService::paymentStatus($state),
            'policyStatus' => $policy->status,
            'payable' => $blocked === null,
            'blockedReason' => $blocked,
            'canRecord' => Gate::allows('payments.record'),
            'canVoid' => Gate::allows('payments.void'),
            'installmentCount' => $policy->installments()->count(),
        ];
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
