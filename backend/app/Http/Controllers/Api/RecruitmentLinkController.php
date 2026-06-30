<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\RecruitmentLinkResource;
use App\Models\Agent;
use App\Models\RecruitmentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RecruitmentLinkController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(RecruitmentLink::query(), $request);
        if ($agentId = $request->input('agentId')) {
            $q->where('agent_id', $agentId);
        }
        if ($request->boolean('activeOnly')) {
            $q->where('revoked', false);
        }
        return RecruitmentLinkResource::collection(
            $q->orderBy('id', 'desc')->paginate($this->perPage($request))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'agentId' => ['required', Rule::exists('agents', 'id')->where('tenant_id', $tenantId)],
        ]);

        // Rotate: revoke any active link for this agent first.
        RecruitmentLink::query()
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $data['agentId'])
            ->where('revoked', false)
            ->update(['revoked' => true]);

        $link = RecruitmentLink::create([
            'tenant_id' => $tenantId,
            'agent_id' => $data['agentId'],
            'token' => Str::lower(Str::random(14)),
            'generated_at' => now(),
            'clicks' => 0,
            'signups' => 0,
            'pending_signups' => 0,
            'revoked' => false,
        ]);

        return (new RecruitmentLinkResource($link))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, RecruitmentLink $recruitmentLink): JsonResponse
    {
        if ((int) $recruitmentLink->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
        $recruitmentLink->update(['revoked' => true]);
        return response()->json(['message' => 'Revoked.']);
    }
}
