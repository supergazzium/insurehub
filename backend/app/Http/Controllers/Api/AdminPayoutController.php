<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AgentPayout;
use App\Models\AuditEntry;
use App\Services\Commission\PayoutService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 7b — admin payout cycle. All routes gated to admin roles inline
 * (matches AdminAgentApprovalController).
 */
class AdminPayoutController extends ApiController
{
    public function __construct(private readonly PayoutService $service)
    {
    }

    /** Preview per-agent aggregation for a period — no writes. */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('payouts.preview');
        $data = $request->validate([
            'periodFrom' => ['required', 'date'],
            'periodTo' => ['required', 'date', 'after_or_equal:periodFrom'],
            'agentIds' => ['sometimes', 'nullable', 'array'],
            'agentIds.*' => ['integer'],
        ]);

        $groups = $this->service->previewByAgent(
            $this->tenantId($request),
            $data['periodFrom'],
            $data['periodTo'],
            $data['agentIds'] ?? null,
        );

        $totalGross = array_sum(array_column($groups, 'gross'));
        return response()->json([
            'periodFrom' => $data['periodFrom'],
            'periodTo' => $data['periodTo'],
            'agentCount' => count($groups),
            'totalGross' => round($totalGross, 2),
            'groups' => $groups,
        ]);
    }

    /** Create draft payouts (one per agent) for the requested period. */
    public function create(Request $request): JsonResponse
    {
        Gate::authorize('payouts.approve');
        $data = $request->validate([
            'periodFrom' => ['required', 'date'],
            'periodTo' => ['required', 'date', 'after_or_equal:periodFrom'],
            'agentIds' => ['sometimes', 'nullable', 'array'],
            'agentIds.*' => ['integer'],
        ]);

        $payouts = $this->service->createBatch(
            $this->tenantId($request),
            $data['periodFrom'],
            $data['periodTo'],
            $data['agentIds'] ?? null,
            $request->user()->id,
        );

        AuditEntry::create([
            'tenant_id' => $this->tenantId($request),
            'user_id' => $request->user()->id,
            'occurred_at' => now(),
            'actor' => $request->user()->name,
            'action' => 'payout.batch_created',
            'target' => 'payout-batch:'.now()->timestamp,
            'ip' => $request->ip(),
            'result' => 'success',
            'metadata' => [
                'periodFrom' => $data['periodFrom'],
                'periodTo' => $data['periodTo'],
                'count' => count($payouts),
            ],
        ]);

        return response()->json([
            'created' => count($payouts),
            'payouts' => array_map(fn (AgentPayout $p) => $this->shape($p), $payouts),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('payouts.view');
        $q = AgentPayout::query()
            ->where('tenant_id', $this->tenantId($request))
            ->with('agent:id,agent_code,first_name,last_name')
            ->orderByDesc('period_to')
            ->orderByDesc('id');

        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($agentId = $request->input('agentId')) {
            $q->where('agent_id', $agentId);
        }

        $rows = $q->limit($this->perPage($request, 50, 200))->get();
        return response()->json([
            'data' => $rows->map(fn ($p) => $this->shape($p))->values(),
        ]);
    }

    public function show(Request $request, AgentPayout $payout): JsonResponse
    {
        Gate::authorize('payouts.view');
        $this->authorizeTenant($request, $payout);
        return response()->json([
            'data' => $this->shape($payout->load('agent', 'transactions.policy'), verbose: true),
        ]);
    }

    public function issue(Request $request, AgentPayout $payout): JsonResponse
    {
        Gate::authorize('payouts.approve');
        $this->authorizeTenant($request, $payout);
        $p = $this->service->markIssued($payout, $request->user()->id);
        AuditEntry::create([
            'tenant_id' => $p->tenant_id, 'user_id' => $request->user()->id,
            'occurred_at' => now(), 'actor' => $request->user()->name,
            'action' => 'payout.issued', 'target' => 'payout:'.$p->id,
            'ip' => $request->ip(), 'result' => 'success', 'metadata' => [],
        ]);
        return response()->json(['data' => $this->shape($p->fresh('agent'))]);
    }

    public function pay(Request $request, AgentPayout $payout): JsonResponse
    {
        Gate::authorize('payouts.mark_paid');
        $this->authorizeTenant($request, $payout);
        $data = $request->validate(['bankRef' => ['required', 'string', 'max:128']]);
        $p = $this->service->markPaid($payout, $data['bankRef'], $request->user()->id);
        AuditEntry::create([
            'tenant_id' => $p->tenant_id, 'user_id' => $request->user()->id,
            'occurred_at' => now(), 'actor' => $request->user()->name,
            'action' => 'payout.paid', 'target' => 'payout:'.$p->id,
            'ip' => $request->ip(), 'result' => 'success',
            'metadata' => ['bank_ref' => $data['bankRef']],
        ]);
        return response()->json(['data' => $this->shape($p->fresh('agent'))]);
    }

    public function void(Request $request, AgentPayout $payout): JsonResponse
    {
        Gate::authorize('payouts.approve');
        $this->authorizeTenant($request, $payout);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $p = $this->service->void($payout, $data['reason']);
        AuditEntry::create([
            'tenant_id' => $p->tenant_id, 'user_id' => $request->user()->id,
            'occurred_at' => now(), 'actor' => $request->user()->name,
            'action' => 'payout.void', 'target' => 'payout:'.$p->id,
            'ip' => $request->ip(), 'result' => 'success',
            'metadata' => ['reason' => $data['reason']],
        ]);
        return response()->json(['data' => $this->shape($p->fresh('agent'))]);
    }

    public function pdf(Request $request, AgentPayout $payout): Response
    {
        Gate::authorize('payouts.view');
        $this->authorizeTenant($request, $payout);
        $payout->load(['agent', 'transactions.policy']);
        $tenant = $payout->tenant ?? \App\Models\Tenant::find($payout->tenant_id);

        $pdf = Pdf::loadView('pdf.commission-statement', [
            'payout' => $payout,
            'tenant' => $tenant,
        ])->setPaper('a4', 'portrait');

        $filename = 'commission-statement-'.($payout->agent?->agent_code ?? $payout->agent_id)
            .'-'.$payout->period_from->format('Ymd').'-'.$payout->period_to->format('Ymd').'.pdf';

        return $pdf->download($filename);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    // guardAdmin removed — authorization is per-action via Gate::authorize().

    private function authorizeTenant(Request $request, AgentPayout $payout): void
    {
        if ((int) $payout->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function shape(AgentPayout $p, bool $verbose = false): array
    {
        $out = [
            'id' => (string) $p->id,
            'agentId' => (string) $p->agent_id,
            'agentCode' => $p->agent?->agent_code ?? '',
            'agentName' => trim(($p->agent?->first_name ?? '').' '.($p->agent?->last_name ?? '')),
            'periodFrom' => $p->period_from?->toDateString(),
            'periodTo' => $p->period_to?->toDateString(),
            'status' => $p->status,
            'grossAmount' => (float) $p->gross_amount,
            'whtRate' => (float) $p->wht_rate,
            'whtAmount' => (float) $p->wht_amount,
            'netAmount' => (float) $p->net_amount,
            'bankRef' => $p->bank_ref,
            'issuedAt' => $p->issued_at?->toIso8601String(),
            'paidAt' => $p->paid_at?->toIso8601String(),
            'createdAt' => $p->created_at?->toIso8601String(),
        ];
        if ($verbose) {
            $out['transactions'] = $p->transactions->map(fn ($t) => [
                'id' => (string) $t->id,
                'type' => $t->type,
                'policyId' => (string) $t->policy_id,
                'policyNo' => $t->policy?->policy_no,
                'applicationNo' => $t->policy?->application_no,
                'basePremium' => (float) $t->base_premium,
                'diffPct' => (float) $t->diff_pct,
                'amount' => (float) $t->amount,
                'isReversal' => $t->reverses_txn_id !== null,
            ])->values();
        }
        return $out;
    }
}
