<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CommissionTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionTransaction
 */
class CommissionTransactionResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'agentId' => (string) $this->agent_id,
            'policyId' => (string) $this->policy_id,
            'policyEventId' => $this->policy_event_id !== null ? (string) $this->policy_event_id : null,
            'idempotencyKey' => $this->idempotency_key,
            'reversesTxnId' => $this->reverses_txn_id !== null ? (string) $this->reverses_txn_id : null,
            'basePremium' => (float) $this->base_premium,
            'payerLevel' => $this->payer_level,
            'diffPct' => (float) $this->diff_pct,
            'amount' => (float) $this->amount,
            'createdAt' => $this->created_at?->toIso8601String(),
            'settledByPayoutId' => $this->settled_by_payout_id !== null ? (string) $this->settled_by_payout_id : null,
        ];
    }
}
