<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\PolicyRequest;
use App\Http\Resources\PolicyResource;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PolicyController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(Policy::query(), $request);

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('policy_no', 'like', $like)
                    ->orWhere('application_no', 'like', $like)
                    ->orWhere('quote_no', 'like', $like);
            });
        }
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($customerId = $request->input('customerId')) {
            $q->where('customer_id', $customerId);
        }
        if ($writingAgentId = $request->input('writingAgentId')) {
            $q->where('writing_agent_id', $writingAgentId);
        }

        return PolicyResource::collection(
            $q->orderBy('id', 'desc')->paginate($this->perPage($request))
        );
    }

    public function store(PolicyRequest $request): JsonResponse
    {
        $policy = DB::transaction(function () use ($request) {
            $payload = $request->toModel() + ['tenant_id' => $this->tenantId($request)];
            $policy = Policy::create($payload);
            $this->syncChildren($request, $policy);
            return $policy->load(['riders', 'beneficiaries', 'events', 'payments', 'documents']);
        });
        return (new PolicyResource($policy))->response()->setStatusCode(201);
    }

    public function show(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        return new PolicyResource(
            $policy->load(['riders', 'beneficiaries', 'events', 'payments', 'documents'])
        );
    }

    public function update(PolicyRequest $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        DB::transaction(function () use ($request, $policy): void {
            $policy->update($request->toModel());
            $this->syncChildren($request, $policy);
        });
        return new PolicyResource(
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents'])
        );
    }

    public function destroy(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $policy->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function syncChildren(PolicyRequest $request, Policy $policy): void
    {
        $data = $request->validated();
        if (array_key_exists('riders', $data)) {
            $policy->riders()->delete();
            foreach ($data['riders'] as $r) {
                $policy->riders()->create([
                    'name' => $r['name'],
                    'premium' => $r['premium'],
                    'notes' => $r['notes'] ?? null,
                ]);
            }
        }
        if (array_key_exists('beneficiaries', $data)) {
            $policy->beneficiaries()->delete();
            foreach ($data['beneficiaries'] as $b) {
                $policy->beneficiaries()->create([
                    'name' => $b['name'],
                    'relation' => $b['relation'] ?? null,
                    'share' => $b['share'],
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
