<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Policy;
use App\Models\PolicyEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9 — endorsement history + create endorsement.
 *
 * Endorsements are just `policy_events` rows with a well-known `type` prefix
 * (`endorsement.*`) and a JSON `payload` describing the delta. We DO NOT mutate
 * the policy row here (Q9-B option "a") — the record is the endorsement,
 * applying the change to the policy is a separate manual step via the section
 * editor. This keeps the Q4 lock consistent and the audit trail explicit.
 */
class EndorsementController extends ApiController
{
    private const ALLOWED_TYPES = [
        'endorsement.date_change',
        'endorsement.coverage_change',
        'endorsement.cancel_reissue',
        'endorsement.other',
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

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
