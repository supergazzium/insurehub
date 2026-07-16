<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\MyAgentResource;
use App\Mail\AgentApprovedMail;
use App\Mail\AgentRejectedMail;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Admin-only routes for approving/rejecting pending agent registrations,
 * inspecting per-agent audit trails, and walking the multi-level downline
 * tree across all agents in the current tenant.
 *
 * Role gate is enforced by a small helper (guardAdmin) rather than route
 * middleware — small surface, one place to update if roles evolve.
 */
class AdminAgentApprovalController extends ApiController
{
    /** Which user.role values may hit these endpoints. */
    private const ADMIN_ROLES = ['admin', 'super_admin'];

    public function pending(Request $request): AnonymousResourceCollection
    {
        $this->guardAdmin($request);
        $rows = Agent::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('approval_status', 'pending')
            ->orderBy('created_at')
            ->get();
        return MyAgentResource::collection($rows);
    }

    public function approve(Request $request, Agent $agent): JsonResponse
    {
        $this->guardAdmin($request);
        $this->authorizeTenant($request, $agent);

        if ($agent->approval_status === 'approved') {
            return response()->json(['message' => 'Agent already approved.', 'data' => (new MyAgentResource($agent))->resolve()]);
        }

        DB::transaction(function () use ($request, $agent): void {
            $agent->update([
                'approval_status' => 'approved',
                'approval_note' => null,
                'approved_by_user_id' => $request->user()->id,
                'approved_at' => now(),
                'rejected_at' => null,
                'active' => true,
            ]);

            // Activate the paired User (if any) so login goes from 422 → 200.
            User::query()
                ->where('agent_id', $agent->id)
                ->update(['active' => true]);

            // Decrement recruiter's pending_signups if this was a referral.
            if ($agent->signup_source) {
                DB::table('recruitment_links')
                    ->where('tenant_id', $agent->tenant_id)
                    ->where('token', $agent->signup_source)
                    ->where('pending_signups', '>', 0)
                    ->decrement('pending_signups');
            }

            AuditEntry::create([
                'tenant_id' => $agent->tenant_id,
                'user_id' => $request->user()->id,
                'occurred_at' => now(),
                'actor' => $request->user()->name,
                'action' => 'agent.approved',
                'target' => 'agent:'.$agent->id,
                'ip' => $request->ip(),
                'result' => 'success',
                'metadata' => ['agent_code' => $agent->agent_code],
            ]);
        });

        $this->sendMailSafely(
            $agent->email,
            new AgentApprovedMail(
                $agent->fresh(),
                loginUrl: rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173/insurehub')), '/').'/login',
            ),
        );

        return response()->json([
            'message' => 'Agent approved.',
            'data' => (new MyAgentResource($agent->fresh()))->resolve(),
        ]);
    }

    public function reject(Request $request, Agent $agent): JsonResponse
    {
        $this->guardAdmin($request);
        $this->authorizeTenant($request, $agent);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        if ($agent->approval_status === 'rejected') {
            return response()->json(['message' => 'Agent already rejected.', 'data' => (new MyAgentResource($agent))->resolve()]);
        }

        DB::transaction(function () use ($request, $agent, $data): void {
            $agent->update([
                'approval_status' => 'rejected',
                'approval_note' => $data['note'],
                'approved_by_user_id' => $request->user()->id,
                'approved_at' => null,
                'rejected_at' => now(),
                'active' => false,
            ]);

            // Deactivate paired User immediately.
            User::query()
                ->where('agent_id', $agent->id)
                ->update(['active' => false]);

            if ($agent->signup_source) {
                DB::table('recruitment_links')
                    ->where('tenant_id', $agent->tenant_id)
                    ->where('token', $agent->signup_source)
                    ->where('pending_signups', '>', 0)
                    ->decrement('pending_signups');
            }

            AuditEntry::create([
                'tenant_id' => $agent->tenant_id,
                'user_id' => $request->user()->id,
                'occurred_at' => now(),
                'actor' => $request->user()->name,
                'action' => 'agent.rejected',
                'target' => 'agent:'.$agent->id,
                'ip' => $request->ip(),
                'result' => 'success',
                'metadata' => ['agent_code' => $agent->agent_code, 'note' => $data['note']],
            ]);
        });

        $this->sendMailSafely(
            $agent->email,
            new AgentRejectedMail($agent->fresh(), $data['note']),
        );

        return response()->json([
            'message' => 'Agent rejected.',
            'data' => (new MyAgentResource($agent->fresh()))->resolve(),
        ]);
    }

    public function audit(Request $request, Agent $agent): JsonResponse
    {
        $this->guardAdmin($request);
        $this->authorizeTenant($request, $agent);

        $rows = AuditEntry::query()
            ->where('tenant_id', $agent->tenant_id)
            ->where('target', 'agent:'.$agent->id)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get(['id', 'occurred_at', 'actor', 'action', 'result', 'ip', 'metadata']);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => (string) $r->id,
                'occurredAt' => $r->occurred_at?->toIso8601String(),
                'actor' => $r->actor,
                'action' => $r->action,
                'result' => $r->result,
                'ip' => $r->ip,
                'metadata' => $r->metadata,
            ]),
        ]);
    }

    /**
     * Recursive multi-level downline as a flat list with a `level` column
     * and each row's `parentAgentId` — the frontend builds the tree.
     * Uses a MySQL CTE (supported on 8.0+, which the project already uses).
     */
    public function downlineTree(Request $request, Agent $agent): JsonResponse
    {
        $this->guardAdmin($request);
        $this->authorizeTenant($request, $agent);

        $tenantId = $agent->tenant_id;
        $rows = DB::select("
            WITH RECURSIVE tree AS (
                SELECT id, parent_agent_id, agent_code, first_name, last_name,
                       email, phone, line_id, joined_at, approval_status, 0 AS level
                FROM agents
                WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT a.id, a.parent_agent_id, a.agent_code, a.first_name, a.last_name,
                       a.email, a.phone, a.line_id, a.joined_at, a.approval_status, tree.level + 1
                FROM agents a
                INNER JOIN tree ON a.parent_agent_id = tree.id
                WHERE a.tenant_id = ? AND a.deleted_at IS NULL AND tree.level < 10
            )
            SELECT * FROM tree ORDER BY level, agent_code
        ", [$agent->id, $tenantId, $tenantId]);

        return response()->json([
            'data' => array_map(fn ($r) => [
                'id' => (string) $r->id,
                'parentAgentId' => $r->parent_agent_id !== null ? (string) $r->parent_agent_id : null,
                'agentCode' => $r->agent_code,
                'firstName' => $r->first_name ?? '',
                'lastName' => $r->last_name ?? '',
                'email' => $r->email ?? '',
                'phone' => $r->phone ?? '',
                'lineId' => $r->line_id ?? '',
                'joinedAt' => $r->joined_at,
                'approvalStatus' => $r->approval_status,
                'level' => (int) $r->level,
            ], $rows),
        ]);
    }

    // ── Internal helpers ─────────────────────────────────────────────────

    private function guardAdmin(Request $request): void
    {
        $role = $request->user()->role;
        if (! in_array($role, self::ADMIN_ROLES, true)) {
            abort(403, 'Admin role required.');
        }
    }

    private function authorizeTenant(Request $request, Agent $agent): void
    {
        if ((int) $agent->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    /**
     * Send a mail without letting SMTP failures roll back the DB write.
     * Approval/rejection is a real business event — the record of it is
     * more important than the email delivery.
     */
    private function sendMailSafely(?string $to, $mailable): void
    {
        if (empty($to)) {
            return;
        }
        try {
            Mail::to($to)->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Agent notification email failed', [
                'to' => $to,
                'mailable' => $mailable::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
