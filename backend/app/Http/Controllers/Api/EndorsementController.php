<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Services\Policy\EndorsementRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 9 — endorsement history + create endorsement.
 *
 * Endorsements are `policy_events` rows with a well-known `type` prefix
 * (`endorsement.*`) and a JSON `payload` describing the delta.
 *
 * Two shapes coexist:
 *  - Legacy `store()` — pure audit record; DOES NOT mutate the policy row.
 *    Applying the change is a separate manual step (date/coverage/reissue).
 *  - `storePremiumChange()` (สลักหลังเบี้ยเพิ่ม, v1) — DOES mutate the policy
 *    premium (so renewal pulls the new figure) AND writes an event carrying a
 *    full `before`/`after` snapshot for delta-log trace-back. The additional
 *    (pro-rata) premium the operator enters is recorded on the event and
 *    surfaced as an outstanding item; we intentionally DO NOT insert a
 *    policy_payments row here (that would fire the MGM commission pipeline on
 *    an uncollected amount) — collection flows through the normal payment UI.
 */
class EndorsementController extends ApiController
{
    public function index(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $rows = PolicyEvent::query()
            ->with(['user:id,name', 'voidedBy:id,name'])
            ->where('policy_id', $policy->id)
            ->where('type', 'like', 'endorsement.%')
            ->orderByRaw('endorsement_seq IS NULL')
            ->orderBy('endorsement_seq')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (PolicyEvent $r) => $this->row($r))->values(),
            'meta' => ['nextSeq' => (int) $policy->endorsement_count + 1],
        ]);
    }

    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', EndorsementRecorder::TYPES)],
            'reason' => ['required', 'string', 'max:500'],
            'effectiveDate' => ['sometimes', 'nullable', 'date'],
            'detail' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Free-form JSON payload for the change delta — validated by shape not by field.
            'changes' => ['sometimes', 'array'],
        ]);
        if ($blocked = $this->rejectDraft($policy)) {
            return $blocked;
        }

        $event = EndorsementRecorder::record($policy, $data['type'], [
            'reason' => $data['reason'],
            'effectiveDate' => $data['effectiveDate'] ?? null,
            'detail' => $data['detail'] ?? null,
            'changes' => $data['changes'] ?? new \stdClass(),
            'policyStatusAtEndorsement' => $policy->status,
        ], $request->user()?->id);

        return response()->json([
            'message' => 'Endorsement recorded.',
            'data' => $this->row($event->load(['user:id,name'])),
        ], 201);
    }

    /**
     * สลักหลังเบี้ยเพิ่ม (v1) — premium-increase endorsement.
     *
     * Captures the policy's current premium/coverage as `before`, applies the
     * operator-entered new figures to the policy, records the additional
     * (pro-rata) premium for this remaining period, and writes an append-only
     * event with the full delta for trace-back. All in one transaction.
     */
    public function storePremiumChange(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'effectiveDate' => ['required', 'date'],
            // เบี้ยใหม่ (annual) — becomes the policy's premium going forward.
            'newAnnualPremium' => ['required', 'numeric', 'min:0'],
            // ทุนประกันใหม่ (optional) — 0/absent leaves coverage unchanged.
            'newCoverage' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // เบี้ยเพิ่มที่ต้องชำระงวดนี้ (manual, pro-rata) + optional duty/vat.
            'additionalPremium' => ['required', 'numeric', 'min:0'],
            'additionalDutyStamp' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'additionalVat' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        if ($blocked = $this->rejectDraft($policy)) {
            return $blocked;
        }

        $before = [
            'annualPremium' => (float) ($policy->annual_premium ?? 0),
            'mainPremium' => $policy->main_premium !== null ? (float) $policy->main_premium : null,
            'coverage' => (float) ($policy->coverage ?? 0),
        ];

        $newCoverage = isset($data['newCoverage']) && $data['newCoverage'] !== null
            ? (float) $data['newCoverage']
            : $before['coverage'];

        $after = [
            'annualPremium' => (float) $data['newAnnualPremium'],
            'mainPremium' => (float) $data['newAnnualPremium'],
            'coverage' => $newCoverage,
        ];

        $addlDuty = isset($data['additionalDutyStamp']) ? (float) $data['additionalDutyStamp'] : 0.0;
        $addlVat = isset($data['additionalVat']) ? (float) $data['additionalVat'] : 0.0;
        $addlPremium = (float) $data['additionalPremium'];
        $addlTotal = round($addlPremium + $addlDuty + $addlVat, 2);

        $event = EndorsementRecorder::record($policy, 'endorsement.premium_change', [
            'reason' => $data['reason'],
            'effectiveDate' => $data['effectiveDate'],
            'before' => $before,
            'after' => $after,
            'additionalPremium' => round($addlPremium, 2),
            'additionalDutyStamp' => round($addlDuty, 2),
            'additionalVat' => round($addlVat, 2),
            'additionalTotal' => $addlTotal,
            'policyStatusAtEndorsement' => $policy->status,
        ], $request->user()?->id, function (Policy $p) use ($after): void {
            $p->annual_premium = $after['annualPremium'];
            $p->main_premium = $after['mainPremium'];
            $p->coverage = $after['coverage'];
            $p->save();
        });

        return response()->json([
            'message' => 'Premium endorsement recorded.',
            'data' => $this->row($event->load(['user:id,name'])),
        ], 201);
    }

    /**
     * Edit an existing premium-change endorsement. Rewrites the event's
     * payload (keeping its original `before` snapshot immutable), recomputes
     * the additional total, then re-syncs the policy premium/coverage to the
     * most-recent endorsement so the current figures stay consistent.
     */
    public function updatePremiumChange(Request $request, Policy $policy, PolicyEvent $event): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $this->authorizeEvent($policy, $event, 'endorsement.premium_change');
        Gate::authorize('policies.update');
        if ($blocked = $this->rejectVoided($event)) {
            return $blocked;
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'effectiveDate' => ['required', 'date'],
            'newAnnualPremium' => ['required', 'numeric', 'min:0'],
            'newCoverage' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'additionalPremium' => ['required', 'numeric', 'min:0'],
            'additionalDutyStamp' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'additionalVat' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        // Preserve the original `before` snapshot — that's the audit anchor.
        $payload = $event->payload ?? [];
        $before = $payload['before'] ?? [
            'annualPremium' => (float) ($policy->annual_premium ?? 0),
            'mainPremium' => $policy->main_premium !== null ? (float) $policy->main_premium : null,
            'coverage' => (float) ($policy->coverage ?? 0),
        ];

        $newCoverage = isset($data['newCoverage']) && $data['newCoverage'] !== null
            ? (float) $data['newCoverage']
            : (float) ($before['coverage'] ?? 0);

        $addlDuty = isset($data['additionalDutyStamp']) ? (float) $data['additionalDutyStamp'] : 0.0;
        $addlVat = isset($data['additionalVat']) ? (float) $data['additionalVat'] : 0.0;
        $addlPremium = (float) $data['additionalPremium'];

        DB::transaction(function () use (
            $policy, $event, $data, $before, $newCoverage, $addlPremium, $addlDuty, $addlVat, $payload
        ) {
            $event->payload = [
                'reason' => $data['reason'],
                'effectiveDate' => $data['effectiveDate'],
                'before' => $before,
                'after' => [
                    'annualPremium' => (float) $data['newAnnualPremium'],
                    'mainPremium' => (float) $data['newAnnualPremium'],
                    'coverage' => $newCoverage,
                ],
                'additionalPremium' => round($addlPremium, 2),
                'additionalDutyStamp' => round($addlDuty, 2),
                'additionalVat' => round($addlVat, 2),
                'additionalTotal' => round($addlPremium + $addlDuty + $addlVat, 2),
                'policyStatusAtEndorsement' => $payload['policyStatusAtEndorsement'] ?? $policy->status,
                'editedAt' => now()->toIso8601String(),
            ];
            $event->save();

            EndorsementRecorder::resyncPremium($policy);
        });

        return response()->json([
            'message' => 'Endorsement updated.',
            'data' => $this->row($event->fresh(['user:id,name', 'voidedBy:id,name'])),
        ]);
    }

    public function update(Request $request, Policy $policy, PolicyEvent $event): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $this->authorizeEvent($policy, $event);
        Gate::authorize('policies.update');
        if ($event->type === 'endorsement.premium_change') {
            return response()->json(['message' => 'Use the premium endorsement endpoint to edit this endorsement.'], 422);
        }
        if ($blocked = $this->rejectVoided($event)) {
            return $blocked;
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'effectiveDate' => ['sometimes', 'nullable', 'date'],
            'detail' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $event->payload = array_merge($event->payload ?? [], [
            'reason' => $data['reason'],
            'effectiveDate' => $data['effectiveDate'] ?? ($event->payload['effectiveDate'] ?? null),
            'detail' => array_key_exists('detail', $data) ? $data['detail'] : ($event->payload['detail'] ?? null),
            'editedAt' => now()->toIso8601String(),
        ]);
        $event->save();

        return response()->json([
            'message' => 'Endorsement updated.',
            'data' => $this->row($event->fresh(['user:id,name', 'voidedBy:id,name'])),
        ]);
    }

    public function void(Request $request, Policy $policy, PolicyEvent $event): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $this->authorizeEvent($policy, $event);
        Gate::authorize('policies.update');
        if ($blocked = $this->rejectVoided($event)) {
            return $blocked;
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $voided = EndorsementRecorder::void($policy, $event, $request->user()?->id, $data['reason']);

        return response()->json([
            'message' => 'Endorsement voided.',
            'data' => $this->row($voided),
        ]);
    }

    private function row(PolicyEvent $r): array
    {
        return [
            'id' => (string) $r->id,
            'seq' => $r->endorsement_seq !== null ? (int) $r->endorsement_seq : null,
            'type' => $r->type,
            'occurredAt' => $r->occurred_at?->toIso8601String(),
            'byUserId' => $r->by_user_id !== null ? (string) $r->by_user_id : null,
            'byUserName' => $r->relationLoaded('user') ? $r->user?->name : null,
            'payload' => $r->payload,
            'voidedAt' => $r->voided_at?->toIso8601String(),
            'voidedByName' => $r->relationLoaded('voidedBy') ? $r->voidedBy?->name : null,
            'voidReason' => $r->void_reason,
        ];
    }

    private function rejectDraft(Policy $policy): ?JsonResponse
    {
        if ($policy->status === 'draft') {
            return response()->json([
                'message' => 'Endorsements are not allowed on a draft policy.',
            ], 422);
        }

        return null;
    }

    private function rejectVoided(PolicyEvent $event): ?JsonResponse
    {
        if ($event->voided_at !== null) {
            return response()->json([
                'code' => 'endorsement_voided',
                'message' => 'This endorsement has been voided and can no longer be changed.',
            ], 422);
        }

        return null;
    }

    private function authorizeEvent(Policy $policy, PolicyEvent $event, ?string $type = null): void
    {
        if ((int) $event->policy_id !== (int) $policy->id
            || ! str_starts_with((string) $event->type, 'endorsement.')
            || ($type !== null && $event->type !== $type)) {
            abort(404);
        }
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
