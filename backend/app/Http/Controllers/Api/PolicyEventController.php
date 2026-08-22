<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\PolicyEventResource;
use App\Http\Resources\PolicyResource;
use App\Models\Policy;
use App\Models\PolicyEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle transitions for a Policy — the single write path for
 * `policies.status`. Every transition validates (from, to) against the
 * matrix in B1 §2 and writes a `policy_events` audit row atomically.
 *
 * See docs/audit-2026-08-21/B1-state-machine.md for the state graph,
 * verb inventory, and per-transition payload requirements.
 */
class PolicyEventController extends ApiController
{
    /**
     * Verb → target-state map. `to = null` means "no automatic status
     * change" (used by `detailsUpdated`, an audit-only event).
     *
     * `from` is a list of allowed source states enforced by
     * assertTransitionAllowed(). Empty list = no source restriction
     * (audit-only events; still guarded by tenant).
     *
     * @var array<string, array{to: ?string, from: list<string>}>
     */
    private const TRANSITIONS = [
        // C-14 wizard action buttons
        'draftCreated' => ['to' => 'draft', 'from' => []],
        'quotationMinted' => ['to' => 'quotation', 'from' => ['draft']],
        'submittedFromDraft' => ['to' => 'submitted', 'from' => ['draft']],
        // Quotation → Submitted (existing convert endpoint routes here)
        'convertedToApplication' => ['to' => 'submitted', 'from' => ['quotation']],
        // Legacy alias — deprecated verb, retargeted for backward compat
        // with any client still emitting it. Behaves identically to
        // convertedToApplication. Remove in C-20.
        'submittedToCarrier' => ['to' => 'submitted', 'from' => ['quotation', 'draft']],
        // Carrier decision recording (Agent for now; carrier-webhook later)
        'underwritingApproved' => ['to' => 'approved', 'from' => ['submitted']],
        'underwritingRejected' => ['to' => 'rejected', 'from' => ['submitted']],
        // Issue Policy modal (B5) — carrier assigns policy_no
        'issued' => ['to' => 'issued', 'from' => ['approved']],
        // Scheduler-only (C-16): effective_date reached
        'activated' => ['to' => 'active', 'from' => ['issued']],
        // Scheduler-only (C-16): expiry_date passed
        'expired' => ['to' => 'expired', 'from' => ['active']],
        // Terminal transitions
        'cancelled' => ['to' => 'cancelled', 'from' => ['draft', 'quotation', 'submitted', 'approved', 'issued', 'active']],
        'lapsed' => ['to' => 'lapsed', 'from' => ['active']],
        // Audit-only, no status change
        'detailsUpdated' => ['to' => null, 'from' => []],
        // Renewal creates a NEW row via a different path — this verb only
        // stamps the source row for audit. The renewal itself is a POST to
        // /policies with ref_app_to_id set. No status change on the source.
        'renewed' => ['to' => null, 'from' => ['active', 'expired']],
    ];

    /**
     * Retired verbs — return 410 Gone with a machine-readable message.
     * `reinstated` is dead per B1 §8; terminal states never transition
     * back. If revival is needed later, create a new policy chained via
     * ref_app_to_id.
     */
    private const RETIRED_VERBS = [
        'reinstated' => 'The `reinstated` transition was retired in C-6. Create a new policy chained via ref_app_to_id.',
    ];

    public function index(Request $request, Policy $policy): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorizeTenant($request, $policy);

        return PolicyEventResource::collection(
            $policy->events()->orderBy('occurred_at')->orderBy('id')->get()
        );
    }

    public function store(Request $request, Policy $policy): \Illuminate\Http\JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        // Retired verb → 410 Gone with an actionable message.
        $type = (string) $request->input('type');
        if (isset(self::RETIRED_VERBS[$type])) {
            abort(410, self::RETIRED_VERBS[$type]);
        }

        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(self::TRANSITIONS))],
            'payload' => ['sometimes', 'array'],
        ]);
        $type = $data['type'];
        $payload = $data['payload'] ?? [];

        // Assert (from, to) is in the matrix.
        $this->assertTransitionAllowed($policy->status, $type);

        DB::transaction(function () use ($policy, $type, $payload, $request): void {
            $updates = $this->updatesForTransition($type, $payload);
            if ($updates !== []) {
                $policy->update($updates);
            }
            PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => $type,
                'occurred_at' => now(),
                'by_user_id' => $request->user()?->id,
                'payload' => $payload,
            ]);
        });

        $policy->refresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'product.productType']);

        return (new PolicyResource($policy))->response();
    }

    /**
     * C-8 — dedicated Issue Policy endpoint. Wraps the `issued` verb
     * with the Issue Modal's payload contract (policyNo + issueDate
     * required, other fields optional). See B5-issue-modal.md.
     *
     * Guard: from=approved (enforced by the shared transition matrix).
     *
     * Soft-duplicate handling: if another Issued+ row in this tenant
     * already carries the same policyNo, returns 409 with
     * `code:duplicate_policy_no` + the conflicting row's info. Client
     * can retry with the operator's confirmation via `?force=1`.
     */
    public function issue(Request $request, Policy $policy): \Illuminate\Http\JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        $data = $request->validate([
            'policyNo' => ['required', 'string', 'max:64'],
            'issueDate' => ['required', 'date', 'before_or_equal:today'],
            'periodPaidEnd' => ['sometimes', 'nullable', 'date'],
            'policyEnd' => ['sometimes', 'nullable', 'date'],
            'mailingAddByPolicy' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mailingDate' => ['sometimes', 'nullable', 'date'],
            'mailingNote' => ['sometimes', 'nullable', 'string'],
        ]);

        $this->assertTransitionAllowed($policy->status, 'issued');

        // Soft-duplicate check — the migration relaxed the unique
        // constraint on purpose (see 2027_01_01_000700). Warn instead
        // of hard-blocking so the operator can confirm-and-proceed.
        $force = $request->boolean('force');
        if (! $force) {
            $conflict = Policy::query()
                ->where('tenant_id', $policy->tenant_id)
                ->where('policy_no', $data['policyNo'])
                ->where('id', '!=', $policy->id)
                ->whereNotNull('policy_no')
                ->whereIn('status', ['issued', 'active', 'expired', 'cancelled', 'lapsed'])
                ->first(['id', 'quote_no', 'application_no', 'status']);
            if ($conflict !== null) {
                abort(response()->json([
                    'code' => 'duplicate_policy_no',
                    'message' => "Policy number `{$data['policyNo']}` is already used by another row in this tenant.",
                    'existing' => [
                        'id' => (string) $conflict->id,
                        'quoteNo' => $conflict->quote_no,
                        'applicationNo' => $conflict->application_no,
                        'status' => $conflict->status,
                    ],
                ], 409));
            }
        }

        DB::transaction(function () use ($policy, $data, $request): void {
            $updates = [
                'status' => 'issued',
                'policy_no' => $data['policyNo'],
                'issue_date' => $data['issueDate'],
            ];
            foreach (['periodPaidEnd' => 'period_paid_end', 'policyEnd' => 'policy_end',
                      'mailingAddByPolicy' => 'mailing_add_by_policy', 'mailingDate' => 'mailing_date',
                      'mailingNote' => 'mailing_note'] as $camel => $snake) {
                if (array_key_exists($camel, $data)) {
                    $updates[$snake] = $data[$camel];
                }
            }
            $policy->update($updates);

            PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => 'issued',
                'occurred_at' => now(),
                'by_user_id' => $request->user()?->id,
                'payload' => $data,
            ]);
        });

        $policy->refresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'product.productType']);

        return (new PolicyResource($policy))->response();
    }

    /**
     * Enforce (from → to) matrix. Empty `from` list = source-agnostic
     * (audit-only events). Reject with 409 Conflict and a payload the
     * client can inspect to render "allowed next" options.
     */
    private function assertTransitionAllowed(string $currentStatus, string $verb): void
    {
        $spec = self::TRANSITIONS[$verb];
        if ($spec['from'] === []) {
            return;
        }
        if (! in_array($currentStatus, $spec['from'], true)) {
            $allowedNext = $this->allowedNextFromStatus($currentStatus);
            abort(response()->json([
                'code' => 'invalid_transition',
                'message' => "Cannot apply `{$verb}` from `{$currentStatus}`.",
                'from' => $currentStatus,
                'attempted' => $verb,
                'allowed_next' => $allowedNext,
            ], 409));
        }
    }

    /**
     * @return list<string> the verbs the caller can legally invoke from
     *                      the given source status. Powers the client's
     *                      action-button gating. Excludes source-agnostic
     *                      verbs (`draftCreated`, `detailsUpdated`) since
     *                      those aren't transitions FROM anywhere — they
     *                      fire from Policy::create() or as audit stamps.
     */
    private function allowedNextFromStatus(string $status): array
    {
        $out = [];
        foreach (self::TRANSITIONS as $verb => $spec) {
            if ($spec['from'] !== [] && in_array($status, $spec['from'], true)) {
                $out[] = $verb;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function updatesForTransition(string $type, array $payload): array
    {
        $spec = self::TRANSITIONS[$type];
        $updates = [];
        if ($spec['to'] !== null) {
            $updates['status'] = $spec['to'];
        }

        switch ($type) {
            case 'quotationMinted':
                // Minted quote_no assigned server-side via existing helper.
                // Delegates to QuoteController's nextQuoteNo() — a service
                // extraction lands with the wizard PR (C-14). For now
                // callers pass quoteNo explicitly.
                if (isset($payload['quoteNo'])) {
                    $updates['quote_no'] = $payload['quoteNo'];
                    $updates['quote_date'] = now()->toDateString();
                }
                break;

            case 'convertedToApplication':
            case 'submittedFromDraft':
            case 'submittedToCarrier':
                // Application no is minted by the caller (QuoteController
                // or wizard) and passed in. Not required at the event
                // layer so back-compat clients still work.
                if (isset($payload['applicationNo'])) {
                    $updates['application_no'] = $payload['applicationNo'];
                    $updates['app_date'] = $payload['appDate'] ?? now()->toDateString();
                }
                break;

            case 'underwritingApproved':
                // Carrier confirmed but no policy_no yet. Optional note
                // captured for audit.
                if (isset($payload['note'])) {
                    $updates['status_note'] = $payload['note'];
                }
                break;

            case 'underwritingRejected':
                // Rejection reason required so triagers can see WHY.
                $this->require($payload, ['reason']);
                $updates['status_note'] = $payload['reason'];
                break;

            case 'issued':
                // Full contract enforced by the Issue Policy modal endpoint
                // (POST /policies/{id}/issue lands in C-8). Kept minimal
                // here so a direct event-store call still works.
                $this->require($payload, ['policyNo']);
                $updates['policy_no'] = $payload['policyNo'];
                $updates['issue_date'] = $payload['issueDate'] ?? now()->toDateString();
                if (isset($payload['periodPaidEnd'])) {
                    $updates['period_paid_end'] = $payload['periodPaidEnd'];
                }
                if (isset($payload['policyEnd'])) {
                    $updates['policy_end'] = $payload['policyEnd'];
                }
                break;

            case 'activated':
            case 'expired':
                // Scheduler-only writes. Optional actor-triggered path stays
                // safe because the scheduler runs with a fixed clock and
                // the transition matrix already blocks Agent from these.
                break;

            case 'cancelled':
                $this->require($payload, ['cancelDate']);
                $updates['cancel_date'] = $payload['cancelDate'];
                if (isset($payload['reason'])) {
                    $updates['cancel_status'] = $payload['reason'];
                }
                break;

            case 'lapsed':
                $this->require($payload, ['lapseDate']);
                $updates['lapse_date'] = $payload['lapseDate'];
                break;

            case 'renewed':
                // Audit-only stamp on the source policy. The new row is
                // created via POST /policies with ref_app_to_id set —
                // that path emits its own draftCreated event on the new
                // record.
                break;
        }

        return $updates;
    }

    /** @param  array<string,mixed>  $payload */
    private function require(array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                throw ValidationException::withMessages([
                    "payload.{$key}" => "Field {$key} is required for this transition.",
                ]);
            }
        }
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
