<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent follow-up notes (ประวัติ note) — list + append. Used to track new
 * applicants (e.g. "โทรคุยแล้ว") and any other running notes on an agent.
 */
class AgentNoteController extends Controller
{
    public function index(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeTenant($request, $agent);
        $rows = AgentNote::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AgentNote $n): array => [
                'id' => (string) $n->id,
                'note' => $n->note,
                'kind' => $n->kind,
                'createdAt' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeTenant($request, $agent);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'kind' => ['sometimes', 'nullable', 'string', 'in:general,call,followup,document'],
        ]);

        $note = AgentNote::create([
            'tenant_id' => (int) $agent->tenant_id,
            'agent_id' => $agent->id,
            'note' => $data['note'],
            'kind' => $data['kind'] ?? 'general',
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => ['id' => (string) $note->id]], 201);
    }

    private function authorizeTenant(Request $request, Agent $agent): void
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        abort_unless((int) $agent->tenant_id === $tenantId, 404);
    }
}
