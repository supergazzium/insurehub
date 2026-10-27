<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PolicyResource;
use App\Models\AuditEntry;
use App\Models\CommissionPayoutAdjustment;
use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\PolicyPayoutOverride;
use App\Services\Commission\PayoutEligibility;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PolicyFollowUpActionController extends Controller
{
    private const LOADS = ['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType'];

    public function freelook(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'sentAt' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'signedAt' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'returnedAt' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
        ]);

        $map = ['sentAt' => 'freelook_doc_sent_at', 'signedAt' => 'freelook_doc_signed_at', 'returnedAt' => 'freelook_doc_returned_at'];
        $changes = [];
        foreach ($map as $camel => $col) {
            if (array_key_exists($camel, $data)) {
                $changes[$col] = $data[$camel];
            }
        }
        $sent = $changes['freelook_doc_sent_at'] ?? $policy->freelook_doc_sent_at?->toDateString();
        $signed = $changes['freelook_doc_signed_at'] ?? $policy->freelook_doc_signed_at?->toDateString();
        $returned = $changes['freelook_doc_returned_at'] ?? $policy->freelook_doc_returned_at?->toDateString();
        if ($signed !== null && ($sent === null || $signed < $sent)) {
            throw ValidationException::withMessages(['signedAt' => 'วันลูกค้าเซ็นต้องไม่ก่อนวันส่งเอกสาร']);
        }
        if ($returned !== null && ($signed === null || $returned < $signed)) {
            throw ValidationException::withMessages(['returnedAt' => 'วันส่งคืนบริษัทต้องไม่ก่อนวันลูกค้าเซ็น']);
        }

        DB::transaction(function () use ($policy, $changes, $request): void {
            $policy->update($changes);
            PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => 'freelookDocUpdated',
                'occurred_at' => now(),
                'by_user_id' => $request->user()->id,
                'payload' => $changes,
            ]);
        });

        return new PolicyResource($policy->fresh()->load(self::LOADS));
    }

    public function showCancellationReview(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        return response()->json(['data' => $this->reviewPayload($policy)]);
    }

    public function cancellationReview(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        if ($policy->status !== 'cancelled') {
            throw ValidationException::withMessages(['policy' => 'ตรวจได้เฉพาะกรมธรรม์ที่ยกเลิกแล้ว']);
        }
        $data = $request->validate([
            'refundAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'clawbackAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'checks.refund' => ['sometimes', 'in:pending,done,na'],
            'checks.clawback' => ['sometimes', 'in:pending,done,na'],
            'checks.carrierDeduct' => ['sometimes', 'in:pending,done,na'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cancelDate' => ['sometimes', 'nullable', 'date'],
            'markReviewed' => ['sometimes', 'boolean'],
        ]);

        $updates = [];
        foreach (['refundAmount' => 'refund_amount', 'clawbackAmount' => 'commission_clawback_amount', 'note' => 'cancel_review_note', 'cancelDate' => 'cancel_date'] as $camel => $col) {
            if (array_key_exists($camel, $data)) {
                $updates[$col] = $data[$camel];
            }
        }
        foreach (['refund' => 'cancel_check_refund', 'clawback' => 'cancel_check_clawback', 'carrierDeduct' => 'cancel_check_carrier_deduct'] as $key => $col) {
            if (isset($data['checks'][$key])) {
                $updates[$col] = $data['checks'][$key];
            }
        }

        $markReviewed = (bool) ($data['markReviewed'] ?? false);
        $checks = [
            $updates['cancel_check_refund'] ?? $policy->cancel_check_refund,
            $updates['cancel_check_clawback'] ?? $policy->cancel_check_clawback,
            $updates['cancel_check_carrier_deduct'] ?? $policy->cancel_check_carrier_deduct,
        ];
        if ($markReviewed && in_array('pending', $checks, true)) {
            throw ValidationException::withMessages(['checks' => 'ทุกข้อในรายการตรวจต้องเป็น "เสร็จแล้ว" หรือ "ไม่เกี่ยวข้อง" ก่อนกดตรวจแล้ว']);
        }
        if ($markReviewed) {
            $updates['cancellation_reviewed_at'] = now();
            $updates['cancellation_reviewed_by_user_id'] = $request->user()->id;
        }

        DB::transaction(function () use ($policy, $updates, $request, $markReviewed): void {
            $policy->update($updates);
            $policy->refresh();
            if ($policy->cancellation_reviewed_at !== null) {
                $this->syncClawback($policy, (int) $request->user()->id);
            }
            AuditEntry::create([
                'tenant_id' => $policy->tenant_id,
                'user_id' => $request->user()->id,
                'occurred_at' => now(),
                'actor' => $request->user()->name,
                'action' => $markReviewed ? 'policy.cancellation_reviewed' : 'policy.cancellation_review_saved',
                'target' => 'policy:'.$policy->id,
                'ip' => $request->ip(),
                'result' => 'success',
                'metadata' => [
                    'refund_amount' => $policy->refund_amount !== null ? (float) $policy->refund_amount : null,
                    'clawback_amount' => $policy->commission_clawback_amount !== null ? (float) $policy->commission_clawback_amount : null,
                    'checks' => [
                        'refund' => $policy->cancel_check_refund,
                        'clawback' => $policy->cancel_check_clawback,
                        'carrier_deduct' => $policy->cancel_check_carrier_deduct,
                    ],
                ],
            ]);
        });

        return response()->json(['data' => $this->reviewPayload($policy->fresh())]);
    }

    private function syncClawback(Policy $policy, int $userId): void
    {
        $label = $policy->policy_no ?: $policy->application_no;
        if ($policy->writing_agent_id !== null) {
            $this->syncAgentClawback($policy, (int) $policy->writing_agent_id, round((float) ($policy->commission_clawback_amount ?? 0), 2), $userId, "เรียกคืนค่าคอม กรมธรรม์ {$label} (ยกเลิก)", true);
        }
        $ratio = $this->uplineRatio($policy);
        $uplines = $this->uplinePaid($policy);
        $targets = [];
        foreach ($uplines as $agentId => $row) {
            $targets[(int) $agentId] = round((float) $row->paid * $ratio, 2);
        }
        $previous = CommissionPayoutAdjustment::query()
            ->where('policy_id', $policy->id)
            ->where('type', CommissionPayoutAdjustment::TYPE_CLAWBACK)
            ->when($policy->writing_agent_id !== null, fn ($q) => $q->where('agent_id', '!=', $policy->writing_agent_id))
            ->pluck('agent_id')
            ->all();
        foreach (array_unique(array_merge(array_keys($targets), array_map('intval', $previous))) as $agentId) {
            $this->syncAgentClawback($policy, (int) $agentId, $targets[$agentId] ?? 0.0, $userId, "เรียกคืนค่าแนะนำ/ค่าบริหารสายงาน กรมธรรม์ {$label} (ยกเลิก)", false);
        }
    }

    private function syncAgentClawback(Policy $policy, int $agentId, float $amount, int $userId, string $reason, bool $strict): void
    {
        $existing = CommissionPayoutAdjustment::query()
            ->where('policy_id', $policy->id)
            ->where('agent_id', $agentId)
            ->where('type', CommissionPayoutAdjustment::TYPE_CLAWBACK)
            ->orderBy('id')
            ->get();
        $appliedTotal = round((float) $existing->where('status', CommissionPayoutAdjustment::STATUS_APPLIED)->sum('amount'), 2);
        $pending = $existing->where('status', CommissionPayoutAdjustment::STATUS_PENDING)->whereNull('batch_id')->values();
        $remaining = round($amount - $appliedTotal, 2);

        if ($remaining < 0 && $strict) {
            throw ValidationException::withMessages([
                'clawbackAmount' => 'หักค่าคอมคืนไปแล้ว ฿'.number_format($appliedTotal, 2).' ยอดเรียกคืนต้องไม่น้อยกว่านี้',
            ]);
        }
        if ($remaining <= 0) {
            CommissionPayoutAdjustment::query()->whereIn('id', $pending->pluck('id'))->delete();

            return;
        }
        if ($pending->isNotEmpty()) {
            $pending->first()->update(['amount' => $remaining, 'reason' => $reason]);
            CommissionPayoutAdjustment::query()->whereIn('id', $pending->slice(1)->pluck('id'))->delete();

            return;
        }
        CommissionPayoutAdjustment::create([
            'batch_id' => null,
            'tenant_id' => $policy->tenant_id,
            'agent_id' => $agentId,
            'agent_code' => DB::table('agents')->where('id', $agentId)->value('agent_code'),
            'policy_id' => $policy->id,
            'type' => CommissionPayoutAdjustment::TYPE_CLAWBACK,
            'status' => CommissionPayoutAdjustment::STATUS_PENDING,
            'amount' => $remaining,
            'reason' => $reason,
            'created_by_user_id' => $userId,
        ]);
    }

    private function sellerPaid(Policy $policy): float
    {
        if ($policy->writing_agent_id === null) {
            return 0.0;
        }

        return round((float) DB::table('commission_payout_batch_items')
            ->where('policy_id', $policy->id)
            ->where('agent_id', $policy->writing_agent_id)
            ->where('item_status', 'paid')
            ->whereIn('line_type', ['base', 'self_mgmt'])
            ->selectRaw('COALESCE(SUM(snapshot_agent_commission + snapshot_rider_commission), 0) as total')
            ->value('total'), 2);
    }

    private function uplinePaid(Policy $policy)
    {
        return DB::table('commission_payout_batch_items as bi')
            ->leftJoin('agents as a', 'a.id', '=', 'bi.agent_id')
            ->where('bi.policy_id', $policy->id)
            ->where('bi.item_status', 'paid')
            ->whereIn('bi.line_type', ['referral', 'override'])
            ->whereNotNull('bi.agent_id')
            ->groupBy('bi.agent_id', 'a.agent_code', 'a.first_name', 'a.last_name')
            ->selectRaw("bi.agent_id, a.agent_code, CONCAT_WS(' ', a.first_name, a.last_name) as name, SUM(bi.snapshot_agent_commission + bi.snapshot_rider_commission) as paid")
            ->get()
            ->keyBy('agent_id');
    }

    private function uplineRatio(Policy $policy): float
    {
        $claw = (float) ($policy->commission_clawback_amount ?? 0);
        if ($claw <= 0 || $policy->cancel_check_clawback === 'na') {
            return 0.0;
        }
        $paid = $this->sellerPaid($policy);

        return $paid > 0 ? min(1.0, $claw / $paid) : 1.0;
    }

    private function reviewPayload(Policy $policy): array
    {
        $paid = $this->sellerPaid($policy);
        $ratio = $this->uplineRatio($policy);
        $adjustments = CommissionPayoutAdjustment::query()
            ->with('agent:id,agent_code,first_name,last_name')
            ->where('policy_id', $policy->id)
            ->where('type', CommissionPayoutAdjustment::TYPE_CLAWBACK)
            ->orderBy('id')
            ->get()
            ->map(fn (CommissionPayoutAdjustment $a): array => [
                'id' => (string) $a->id,
                'agentCode' => $a->agent?->agent_code ?? $a->agent_code,
                'agentName' => $a->agent ? trim(($a->agent->first_name ?? '').' '.($a->agent->last_name ?? '')) : null,
                'isSeller' => (int) $a->agent_id === (int) $policy->writing_agent_id,
                'amount' => (float) $a->amount,
                'status' => $a->status,
                'batchId' => $a->batch_id !== null ? (string) $a->batch_id : null,
                'appliedAt' => $a->applied_at?->toIso8601String(),
            ]);
        $uplines = $this->uplinePaid($policy)->map(fn ($u): array => [
            'agentId' => (string) $u->agent_id,
            'agentCode' => $u->agent_code,
            'agentName' => trim((string) $u->name),
            'paid' => round((float) $u->paid, 2),
            'clawback' => round((float) $u->paid * $ratio, 2),
        ])->values();
        $reviewer = $policy->cancellation_reviewed_by_user_id !== null
            ? DB::table('users')->where('id', $policy->cancellation_reviewed_by_user_id)->value('name')
            : null;

        return [
            'policyId' => (string) $policy->id,
            'status' => $policy->status,
            'cancelDate' => $policy->cancel_date?->toDateString(),
            'cancelReason' => $policy->cancel_status,
            'reviewedAt' => $policy->cancellation_reviewed_at?->toIso8601String(),
            'reviewedByName' => $reviewer,
            'refundAmount' => $policy->refund_amount !== null ? (float) $policy->refund_amount : null,
            'clawbackAmount' => $policy->commission_clawback_amount !== null ? (float) $policy->commission_clawback_amount : null,
            'checks' => [
                'refund' => $policy->cancel_check_refund ?? 'pending',
                'clawback' => $policy->cancel_check_clawback ?? 'pending',
                'carrierDeduct' => $policy->cancel_check_carrier_deduct ?? 'pending',
            ],
            'note' => $policy->cancel_review_note,
            'suggestion' => [
                'paidCommission' => $paid,
                'netRefundAmount' => $policy->net_refund_amount !== null ? (float) $policy->net_refund_amount : null,
            ],
            'uplineRatio' => round($ratio, 4),
            'uplines' => $uplines,
            'clawbacks' => $adjustments,
        ];
    }

    /**
     * POST /policies/{policy}/payout-override — bypass the payout eligibility
     * gates (freelook / insurer / customer-unpaid) for this policy. A reason is
     * mandatory; the currently-blocking reasons are snapshotted into the audit
     * so the override is always traceable to what it waved through.
     */
    public function createPayoutOverride(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        Gate::authorize('payouts.override');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1'],
        ]);

        // Snapshot what this override is bypassing RIGHT NOW (before it exists).
        $gate = app(PayoutEligibility::class)->evaluate((int) $policy->tenant_id, [(int) $policy->id])[$policy->id] ?? null;
        $bypassed = array_merge(
            $gate['gates'] ?? [],
            (($gate['customerReason'] ?? null) !== null) ? [$gate['customerReason']] : [],
        );

        $override = DB::transaction(function () use ($policy, $request, $data, $bypassed): PolicyPayoutOverride {
            // One active override per policy — revoke any prior live one first.
            PolicyPayoutOverride::query()
                ->where('tenant_id', $policy->tenant_id)->where('policy_id', $policy->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by_user_id' => $request->user()->id]);

            $override = PolicyPayoutOverride::create([
                'tenant_id' => $policy->tenant_id,
                'policy_id' => $policy->id,
                'reason' => $data['reason'],
                'created_by_user_id' => $request->user()->id,
            ]);

            AuditEntry::create([
                'tenant_id' => $policy->tenant_id,
                'user_id' => $request->user()->id,
                'occurred_at' => now(),
                'actor' => $request->user()->name,
                'action' => 'commission_payout.override',
                'target' => 'policy:'.$policy->id,
                'ip' => $request->ip(),
                'result' => 'success',
                'metadata' => ['reason' => $data['reason'], 'bypassed' => $bypassed],
            ]);

            return $override;
        });

        return response()->json(['data' => ['id' => (string) $override->id, 'bypassed' => $bypassed]], 201);
    }

    /** DELETE /policies/{policy}/payout-override — revoke every active override on the policy. */
    public function revokePayoutOverride(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        Gate::authorize('payouts.override');

        $revoked = PolicyPayoutOverride::query()
            ->where('tenant_id', $policy->tenant_id)->where('policy_id', $policy->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $request->user()->id]);

        if ($revoked > 0) {
            AuditEntry::create([
                'tenant_id' => $policy->tenant_id, 'user_id' => $request->user()->id, 'occurred_at' => now(),
                'actor' => $request->user()->name, 'action' => 'commission_payout.override_revoked',
                'target' => 'policy:'.$policy->id, 'ip' => $request->ip(), 'result' => 'success',
                'metadata' => ['revoked' => $revoked],
            ]);
        }

        return response()->json(['data' => ['revoked' => $revoked]]);
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        abort_unless((int) $policy->tenant_id === $tenantId, 404);
    }
}
