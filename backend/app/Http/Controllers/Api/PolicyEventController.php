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
 * Lifecycle transitions for a Policy.
 *
 * The frontend store models each transition (issue, cancel, lapse, etc.) as a
 * named method. We expose a single endpoint that takes a `type` + `payload`
 * and applies the transition atomically with a `policy_events` row, so the
 * audit trail can never drift from the state.
 */
class PolicyEventController extends ApiController
{
    /** Map of frontend PolicyEventType → ['status' => new policy status, 'dateField' => column to set]. */
    private const TRANSITIONS = [
        'convertedToApplication' => ['status' => 'application'],
        'submittedToCarrier' => ['status' => 'submitted'],
        'issued' => ['status' => 'issued'],
        'renewed' => ['status' => 'active'],
        'cancelled' => ['status' => 'cancelled'],
        'lapsed' => ['status' => 'lapsed'],
        'reinstated' => ['status' => 'active'],
        'detailsUpdated' => [], // no automatic status change
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
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(self::TRANSITIONS))],
            'payload' => ['sometimes', 'array'],
        ]);
        $type = $data['type'];
        $payload = $data['payload'] ?? [];

        DB::transaction(function () use ($policy, $type, $payload, $request): void {
            $updates = $this->updatesForTransition($type, $payload);
            if ($updates !== []) {
                $policy->update($updates);
            }
            PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => $type,
                'occurred_at' => now(),
                'by_user_id' => $request->user()->id,
                'payload' => $payload,
            ]);
        });

        $policy->refresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents']);
        return (new PolicyResource($policy))->response();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function updatesForTransition(string $type, array $payload): array
    {
        $spec = self::TRANSITIONS[$type];
        $updates = [];
        if (isset($spec['status'])) {
            $updates['status'] = $spec['status'];
        }

        switch ($type) {
            case 'issued':
                $this->require($payload, ['policyNo', 'effectiveDate']);
                $updates['policy_no'] = $payload['policyNo'];
                $updates['effective_date'] = $payload['effectiveDate'];
                $updates['issue_date'] = now()->toDateString();
                break;
            case 'renewed':
                $this->require($payload, ['newExpiry']);
                $updates['expiry_date'] = $payload['newExpiry'];
                if (isset($payload['newPremium'])) {
                    $updates['annual_premium'] = $payload['newPremium'];
                }
                $updates['policy_year'] = ($updates['policy_year'] ?? 0)
                    + 1; // best-effort; the caller can PATCH the exact value if needed
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
            case 'reinstated':
                $this->require($payload, ['reinstateDate']);
                $updates['lapse_date'] = null;
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
