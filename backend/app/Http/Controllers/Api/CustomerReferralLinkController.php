<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CustomerReferralLinkResource;
use App\Models\CustomerReferralLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerReferralLinkController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(CustomerReferralLink::query(), $request);
        if ($agentId = $request->input('agentId')) {
            $q->where('agent_id', $agentId);
        }
        if ($request->boolean('activeOnly')) {
            $q->where('revoked', false);
        }
        return CustomerReferralLinkResource::collection(
            $q->orderBy('id', 'desc')->paginate($this->perPage($request))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'agentId' => ['required', Rule::exists('agents', 'id')->where('tenant_id', $tenantId)],
            'productId' => ['nullable', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'campaign' => ['nullable', 'string', 'max:255'],
        ]);

        $link = CustomerReferralLink::create([
            'tenant_id' => $tenantId,
            'agent_id' => $data['agentId'],
            'product_id' => $data['productId'] ?? null,
            'campaign' => $data['campaign'] ?? null,
            'token' => Str::lower(Str::random(14)),
            'generated_at' => now(),
            'clicks' => 0,
            'leads' => 0,
            'policies' => 0,
            'revoked' => false,
        ]);

        return (new CustomerReferralLinkResource($link))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, CustomerReferralLink $customerReferralLink): JsonResponse
    {
        if ((int) $customerReferralLink->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
        $customerReferralLink->update(['revoked' => true]);
        return response()->json(['message' => 'Revoked.']);
    }
}
