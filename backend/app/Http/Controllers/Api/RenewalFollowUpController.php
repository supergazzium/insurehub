<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\RenewalFollowup;
use App\Support\RenewalFollowUpOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RenewalFollowUpController extends ApiController
{
    public function index(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $rows = RenewalFollowup::query()
            ->with('createdBy:id,name')
            ->where('policy_id', $policy->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (RenewalFollowup $f) => self::row($f))->values(),
            'meta' => [
                'declined' => self::isDeclined($policy->id),
                'reasons' => collect(RenewalFollowUpOptions::DECLINE_REASONS)
                    ->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            ],
        ]);
    }

    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'channel' => ['required', 'string', 'in:'.implode(',', RenewalFollowUpOptions::CHANNELS)],
            'result' => ['required', 'string', 'in:'.implode(',', RenewalFollowUpOptions::RESULTS)],
            'reasonCode' => ['nullable', 'required_if:result,not_renewing', 'string', 'in:'.implode(',', array_keys(RenewalFollowUpOptions::DECLINE_REASONS))],
            'reasonNote' => ['nullable', 'required_if:reasonCode,other', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'nextFollowupAt' => ['nullable', 'date', 'after_or_equal:today', 'prohibited_if:result,not_renewing'],
        ]);
        $declining = $data['result'] === 'not_renewing';

        $followup = DB::transaction(function () use ($request, $policy, $data, $declining): RenewalFollowup {
            $followup = RenewalFollowup::create([
                'tenant_id' => $policy->tenant_id,
                'policy_id' => $policy->id,
                'channel' => $data['channel'],
                'result' => $data['result'],
                'reason_code' => $declining ? $data['reasonCode'] : null,
                'reason_note' => $declining ? ($data['reasonNote'] ?? null) : null,
                'note' => $data['note'] ?? null,
                'next_followup_at' => $declining ? null : ($data['nextFollowupAt'] ?? null),
                'created_by_user_id' => $request->user()?->id,
            ]);

            PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => 'renewalContacted',
                'occurred_at' => now(),
                'by_user_id' => $request->user()?->id,
                'payload' => [
                    'channel' => $followup->channel,
                    'note' => $followup->note,
                    'result' => $followup->result,
                    'followupId' => (string) $followup->id,
                ],
            ]);

            if ($declining) {
                PolicyEvent::create([
                    'policy_id' => $policy->id,
                    'type' => 'renewalDeclined',
                    'occurred_at' => now(),
                    'by_user_id' => $request->user()?->id,
                    'payload' => [
                        'reason' => RenewalFollowUpOptions::reasonLabel($followup->reason_code, $followup->reason_note),
                        'reasonCode' => $followup->reason_code,
                        'followupId' => (string) $followup->id,
                    ],
                ]);
            }

            return $followup;
        });

        return response()->json([
            'message' => 'Follow-up recorded.',
            'data' => self::row($followup->load('createdBy:id,name')),
        ], 201);
    }

    public function reopen(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if (! self::isDeclined($policy->id)) {
            return response()->json([
                'code' => 'renewal_not_declined',
                'message' => 'This renewal is not marked as declined.',
            ], 422);
        }

        $event = PolicyEvent::create([
            'policy_id' => $policy->id,
            'type' => 'renewalReopened',
            'occurred_at' => now(),
            'by_user_id' => $request->user()?->id,
            'payload' => ['note' => $data['note'] ?? null],
        ]);

        return response()->json([
            'message' => 'Renewal follow-up reopened.',
            'event' => [
                'id' => (string) $event->id,
                'type' => $event->type,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
            ],
        ]);
    }

    public static function isDeclined(int|string $policyId): bool
    {
        $latest = PolicyEvent::query()
            ->where('policy_id', $policyId)
            ->whereIn('type', ['renewalDeclined', 'renewalReopened'])
            ->orderByDesc('id')
            ->value('type');

        return $latest === 'renewalDeclined';
    }

    public static function row(RenewalFollowup $f): array
    {
        return [
            'id' => (string) $f->id,
            'channel' => $f->channel,
            'result' => $f->result,
            'reasonCode' => $f->reason_code,
            'reasonLabel' => RenewalFollowUpOptions::reasonLabel($f->reason_code),
            'reasonNote' => $f->reason_note,
            'note' => $f->note,
            'nextFollowupAt' => $f->next_followup_at?->toDateString(),
            'createdAt' => $f->created_at?->toIso8601String(),
            'byUserName' => $f->relationLoaded('createdBy') ? $f->createdBy?->name : null,
        ];
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
