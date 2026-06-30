<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CommissionTransactionResource;
use App\Models\CommissionTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CommissionController extends ApiController
{
    /** GET /commissions/transactions — Pinia stores/commissions.ts: transactions */
    public function transactions(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(CommissionTransaction::query(), $request);
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($agentId = $request->input('agentId')) {
            $q->where('agent_id', $agentId);
        }
        if ($policyId = $request->input('policyId')) {
            $q->where('policy_id', $policyId);
        }
        return CommissionTransactionResource::collection(
            $q->orderBy('created_at', 'desc')->paginate($this->perPage($request))
        );
    }

    /**
     * POST /commissions/transactions — append a ledger row.
     *
     * The client computes commissions and posts each row with an
     * `idempotencyKey`. The DB has a unique constraint on
     * (tenant_id, idempotency_key), so a duplicate insert just returns the
     * existing row instead of 500ing.
     */
    public function storeTransaction(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'type' => ['required', 'string', 'in:earning,override,clawback,referralBonus'],
            'status' => ['sometimes', 'string', 'in:unsettled,settled,reversed'],
            'agentId' => ['required', Rule::exists('agents', 'id')->where('tenant_id', $tenantId)],
            'policyId' => ['required', Rule::exists('policies', 'id')->where('tenant_id', $tenantId)],
            'policyEventId' => ['nullable', Rule::exists('policy_events', 'id')],
            'idempotencyKey' => ['required', 'string', 'max:64'],
            'reversesTxnId' => ['nullable', Rule::exists('commission_transactions', 'id')->where('tenant_id', $tenantId)],
            'basePremium' => ['required', 'numeric', 'min:0'],
            'payerLevel' => ['nullable', 'string', 'in:l1,l2,l3,l4,l5'],
            'diffPct' => ['sometimes', 'numeric'],
            'amount' => ['required', 'numeric'],
        ]);

        $payload = [
            'tenant_id' => $tenantId,
            'type' => $data['type'],
            'status' => $data['status'] ?? 'unsettled',
            'agent_id' => $data['agentId'],
            'policy_id' => $data['policyId'],
            'policy_event_id' => $data['policyEventId'] ?? null,
            'idempotency_key' => $data['idempotencyKey'],
            'reverses_txn_id' => $data['reversesTxnId'] ?? null,
            'base_premium' => $data['basePremium'],
            'payer_level' => $data['payerLevel'] ?? null,
            'diff_pct' => $data['diffPct'] ?? 0,
            'amount' => $data['amount'],
        ];

        try {
            $txn = CommissionTransaction::create($payload);
            return (new CommissionTransactionResource($txn))->response()->setStatusCode(201);
        } catch (QueryException $e) {
            // Idempotency-key collision → return the existing row instead of 500ing.
            if ($e->getCode() === '23000') {
                $existing = CommissionTransaction::query()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $data['idempotencyKey'])
                    ->firstOrFail();
                return (new CommissionTransactionResource($existing))->response()->setStatusCode(200);
            }
            throw $e;
        }
    }
}
