<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Policy;
use App\Models\PolicyEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    private const ALLOWED_TYPES = [
        'endorsement.date_change',
        'endorsement.coverage_change',
        'endorsement.cancel_reissue',
        'endorsement.other',
        'endorsement.premium_change',
    ];

    public function index(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $rows = PolicyEvent::query()
            ->where('policy_id', $policy->id)
            ->where('type', 'like', 'endorsement.%')
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get(['id', 'type', 'occurred_at', 'by_user_id', 'payload']);
        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => (string) $r->id,
                'type' => $r->type,
                'occurredAt' => $r->occurred_at?->toIso8601String(),
                'byUserId' => $r->by_user_id !== null ? (string) $r->by_user_id : null,
                'payload' => $r->payload,
            ]),
        ]);
    }

    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_TYPES)],
            'reason' => ['required', 'string', 'max:500'],
            'effectiveDate' => ['sometimes', 'nullable', 'date'],
            // Free-form JSON payload for the change delta — validated by shape not by field.
            'changes' => ['sometimes', 'array'],
        ]);

        $event = PolicyEvent::create([
            'policy_id' => $policy->id,
            'type' => $data['type'],
            'occurred_at' => now(),
            'by_user_id' => $request->user()->id,
            'payload' => [
                'reason' => $data['reason'],
                'effectiveDate' => $data['effectiveDate'] ?? null,
                'changes' => $data['changes'] ?? new \stdClass(),
                'policyStatusAtEndorsement' => $policy->status,
            ],
        ]);

        return response()->json([
            'message' => 'Endorsement recorded.',
            'data' => [
                'id' => (string) $event->id,
                'type' => $event->type,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
                'payload' => $event->payload,
            ],
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

        // Endorsements are allowed on any non-draft policy (in force is the
        // common case, but late corrections on expired/other statuses are
        // permitted too). Drafts have no committed premium to amend.
        if ($policy->status === 'draft') {
            return response()->json([
                'message' => 'Endorsements are not allowed on a draft policy.',
            ], 422);
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

        $event = DB::transaction(function () use (
            $policy, $request, $data, $before, $after, $addlPremium, $addlDuty, $addlVat, $addlTotal
        ) {
            // Apply the new premium/coverage so renewal pulls the current figure.
            $policy->annual_premium = $after['annualPremium'];
            $policy->main_premium = $after['mainPremium'];
            $policy->coverage = $after['coverage'];
            $policy->save();

            return PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => 'endorsement.premium_change',
                'occurred_at' => now(),
                'by_user_id' => $request->user()->id,
                'payload' => [
                    'reason' => $data['reason'],
                    'effectiveDate' => $data['effectiveDate'],
                    'before' => $before,
                    'after' => $after,
                    'additionalPremium' => round($addlPremium, 2),
                    'additionalDutyStamp' => round($addlDuty, 2),
                    'additionalVat' => round($addlVat, 2),
                    'additionalTotal' => $addlTotal,
                    'policyStatusAtEndorsement' => $policy->status,
                ],
            ]);
        });

        return response()->json([
            'message' => 'Premium endorsement recorded.',
            'data' => [
                'id' => (string) $event->id,
                'type' => $event->type,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
                'payload' => $event->payload,
            ],
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
        $this->authorizeEvent($policy, $event);

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
            $policy, $event, $data, $before, $newCoverage, $addlPremium, $addlDuty, $addlVat
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

            $this->resyncPolicyPremium($policy);
        });

        return response()->json([
            'message' => 'Endorsement updated.',
            'data' => [
                'id' => (string) $event->id,
                'type' => $event->type,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
                'payload' => $event->fresh()->payload,
            ],
        ]);
    }

    /**
     * Delete a premium-change endorsement and re-sync the policy premium to
     * whatever endorsement is now the most recent (or to this one's `before`
     * snapshot when it was the only/last one).
     */
    public function destroyPremiumChange(Request $request, Policy $policy, PolicyEvent $event): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $this->authorizeEvent($policy, $event);

        $before = $event->payload['before'] ?? null;

        DB::transaction(function () use ($policy, $event, $before) {
            $event->delete();
            $this->resyncPolicyPremium($policy, $before);
        });

        return response()->json(['message' => 'Endorsement deleted.']);
    }

    /**
     * Point the policy's premium/coverage at the most-recent premium-change
     * endorsement's `after`. When there are none left, fall back to
     * `$fallbackBefore` (the deleted endorsement's `before`) if given.
     */
    private function resyncPolicyPremium(Policy $policy, ?array $fallbackBefore = null): void
    {
        $latest = PolicyEvent::query()
            ->where('policy_id', $policy->id)
            ->where('type', 'endorsement.premium_change')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first(['payload']);

        $target = $latest?->payload['after'] ?? $fallbackBefore;
        if ($target === null) {
            return; // nothing to sync to — leave the policy untouched
        }

        if (isset($target['annualPremium'])) {
            $policy->annual_premium = (float) $target['annualPremium'];
            $policy->main_premium = (float) $target['annualPremium'];
        }
        if (isset($target['coverage'])) {
            $policy->coverage = (float) $target['coverage'];
        }
        $policy->save();
    }

    /** Guard: the event must belong to this policy and be a premium endorsement. */
    private function authorizeEvent(Policy $policy, PolicyEvent $event): void
    {
        if ((int) $event->policy_id !== (int) $policy->id
            || $event->type !== 'endorsement.premium_change') {
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
