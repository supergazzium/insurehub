<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PendingChange;
use App\Models\Tenant;
use App\Services\Policy\PolicyVerification;
use App\Support\Freelook;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowUpController extends Controller
{
    private const CATEGORIES = ['approval', 'no_policy_no', 'not_delivered', 'freelook', 'unpaid', 'no_commission', 'cancelled', 'verify'];

    private const SUBMIT_EVENTS = "'submittedFromDraft','convertedToApplication','submittedToCarrier'";

    private const AGING_BUCKETS = ['0-7' => [0, 7], '8-30' => [8, 30], '30+' => [31, null]];

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        $tenant = Tenant::query()->find($tenantId);
        $category = (string) $request->input('category', 'approval');
        if (! in_array($category, self::CATEGORIES, true)) {
            $category = 'approval';
        }
        $stage = (string) $request->input('stage', '');
        $aging = (string) $request->input('aging', '');
        $reviewed = $request->boolean('reviewed');
        $search = trim((string) $request->input('q', ''));

        $counts = [];
        foreach (self::CATEGORIES as $c) {
            $counts[$c] = $c === 'verify'
                ? $this->verifyQuery($tenantId)->count()
                : (int) $this->baseQuery($tenantId, $c, $tenant, '', false)->count();
        }

        if ($category === 'verify') {
            return $this->verifyIndex($tenantId, $counts, $aging);
        }

        $base = fn () => $this->search($this->baseQuery($tenantId, $category, $tenant, $stage, $reviewed), $search);
        $agingExpr = $this->agingExpr($category, $stage);

        $agingCounts = [];
        foreach (self::AGING_BUCKETS as $key => [$from, $to]) {
            $agingCounts[$key] = (int) $this->bucket($base(), $agingExpr, $from, $to)->count();
        }

        $q = $base();
        if (isset(self::AGING_BUCKETS[$aging])) {
            [$from, $to] = self::AGING_BUCKETS[$aging];
            $this->bucket($q, $agingExpr, $from, $to);
        }

        $rows = $q->select([
            'p.id', 'p.policy_no', 'p.application_no', 'p.status',
            'p.effective_date', 'p.expiry_date', 'p.issue_date', 'p.received_date', 'p.mailing_date',
            'p.shipped_date', 'p.delivered_date', 'p.sales_channel',
            'p.freelook_end_date', 'p.freelook_doc_sent_at', 'p.freelook_doc_signed_at', 'p.freelook_doc_returned_at',
            'p.cancel_date', 'p.cancel_status', 'p.cancellation_reviewed_at',
            'p.cancel_check_refund', 'p.cancel_check_clawback', 'p.cancel_check_carrier_deduct',
            'p.comm_carrier_to_hub_amount', 'p.comm_hub_to_agent_amount',
            'ov.oldest_due', 'ov.overdue_count', 'ov.overdue_amount',
            'pr.type as product_type', 'pr.sub_category_2 as product_sc2',
            'c.customer_code', DB::raw("COALESCE(NULLIF(c.phone, ''), c.tel_phone) as customer_phone"),
            DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
            'a.agent_code', DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
            'ca.name as carrier_name', 'pr.name as product_name',
            DB::raw("{$agingExpr} as aging_days"),
        ])
            ->orderByRaw("{$agingExpr} IS NULL")
            ->orderByDesc(DB::raw($agingExpr))
            ->orderByDesc('p.id')
            ->limit(500)
            ->get()
            ->map(fn ($r): array => $this->row($r, $category, $tenant));

        return response()->json([
            'data' => $rows,
            'meta' => [
                'category' => $category,
                'counts' => $counts,
                'aging' => $agingCounts,
                'stages' => $this->stageCounts($tenantId, $category, $tenant, $reviewed),
                'verifyEnabled' => PolicyVerification::enabled($tenantId),
                'noCommissionWindowDays' => (int) ($tenant?->no_commission_window_days ?? 180),
            ],
        ]);
    }

    private function row(object $r, string $category, ?Tenant $tenant): array
    {
        return [
            'policyId' => (string) $r->id,
            'policyNo' => $r->policy_no,
            'applicationNo' => $r->application_no,
            'status' => $r->status,
            'customerCode' => $r->customer_code,
            'customerName' => $r->customer_name,
            'customerPhone' => $r->customer_phone,
            'agentCode' => $r->agent_code,
            'agentName' => $r->agent_name,
            'carrierName' => $r->carrier_name,
            'productName' => $r->product_name,
            'effectiveDate' => $r->effective_date,
            'issueDate' => $r->issue_date,
            'receivedDate' => $r->received_date,
            'mailingDate' => $r->mailing_date,
            'shippedDate' => $r->shipped_date ?? $r->mailing_date,
            'deliveredDate' => $r->delivered_date,
            'salesChannel' => $r->sales_channel,
            'freelookEndDate' => $r->freelook_end_date,
            'freelookDocSentAt' => $r->freelook_doc_sent_at,
            'freelookDocSignedAt' => $r->freelook_doc_signed_at,
            'freelookDocReturnedAt' => $r->freelook_doc_returned_at,
            'cancelDate' => $r->cancel_date,
            'cancelStatus' => $r->cancel_status,
            'cancellationReviewedAt' => $r->cancellation_reviewed_at,
            'cancelChecks' => [
                'refund' => $r->cancel_check_refund,
                'clawback' => $r->cancel_check_clawback,
                'carrierDeduct' => $r->cancel_check_carrier_deduct,
            ],
            'oldestDueDate' => $r->oldest_due,
            'overdueCount' => $r->overdue_count !== null ? (int) $r->overdue_count : 0,
            'overdueAmount' => $r->overdue_amount !== null ? round((float) $r->overdue_amount, 2) : 0.0,
            'agingDays' => $r->aging_days !== null ? max(0, (int) $r->aging_days) : null,
            'alsoIn' => $this->otherCategories($r, $category, $tenant),
        ];
    }

    private function verifyIndex(int $tenantId, array $counts, string $aging): JsonResponse
    {
        $all = $this->verifyQuery($tenantId)->with(['maker:id,name', 'policy'])->orderBy('id')->get();
        $rows = $all->map(function (PendingChange $c): array {
            $row = PolicyVerification::row($c);
            $row['agingDays'] = (int) $c->created_at?->startOfDay()->diffInDays(now()->startOfDay());

            return $row;
        });
        $agingCounts = [];
        foreach (self::AGING_BUCKETS as $key => [$from, $to]) {
            $agingCounts[$key] = $rows->filter(fn ($r) => $r['agingDays'] >= $from && ($to === null || $r['agingDays'] <= $to))->count();
        }
        if (isset(self::AGING_BUCKETS[$aging])) {
            [$from, $to] = self::AGING_BUCKETS[$aging];
            $rows = $rows->filter(fn ($r) => $r['agingDays'] >= $from && ($to === null || $r['agingDays'] <= $to));
        }

        return response()->json([
            'data' => $rows->sortByDesc('agingDays')->values(),
            'meta' => [
                'category' => 'verify',
                'counts' => $counts,
                'aging' => $agingCounts,
                'stages' => [],
                'verifyEnabled' => PolicyVerification::enabled($tenantId),
            ],
        ]);
    }

    private function verifyQuery(int $tenantId)
    {
        return PendingChange::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', PolicyVerification::ENTITY)
            ->where('status', PendingChange::STATUS_PENDING);
    }

    private function stageCounts(int $tenantId, string $category, ?Tenant $tenant, bool $reviewed): array
    {
        $stages = match ($category) {
            'not_delivered' => ['', 'in_transit'],
            'freelook' => ['', 'not_sent', 'awaiting_return', 'near_end', 'no_delivery_date'],
            'cancelled' => [],
            default => [],
        };
        $out = [];
        foreach ($stages as $s) {
            $out[$s === '' ? 'all' : $s] = (int) $this->baseQuery($tenantId, $category, $tenant, $s, $reviewed)->count();
        }
        if ($category === 'cancelled') {
            $out['unreviewed'] = (int) $this->baseQuery($tenantId, 'cancelled', $tenant, '', false)->count();
            $out['reviewed'] = (int) $this->baseQuery($tenantId, 'cancelled', $tenant, '', true)->count();
        }

        return $out;
    }

    private function search(Builder $q, string $search): Builder
    {
        if ($search === '') {
            return $q;
        }
        $like = "%{$search}%";

        return $q->where(function ($w) use ($like): void {
            $w->where('p.policy_no', 'like', $like)
                ->orWhere('p.application_no', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?", [$like])
                ->orWhere('c.customer_code', 'like', $like)
                ->orWhere('a.agent_code', 'like', $like);
        });
    }

    private function bucket(Builder $q, string $expr, int $from, ?int $to): Builder
    {
        $q->whereRaw("{$expr} >= ?", [$from]);
        if ($to !== null) {
            $q->whereRaw("{$expr} <= ?", [$to]);
        }

        return $q;
    }

    private function agingExpr(string $category, string $stage): string
    {
        $ref = match ($category) {
            'approval' => '(SELECT MAX(pe.occurred_at) FROM policy_events pe WHERE pe.policy_id = p.id AND pe.type IN ('.self::SUBMIT_EVENTS.')), p.app_date, p.updated_at',
            'no_policy_no' => 'p.approved_date, p.issue_date, p.effective_date',
            'not_delivered' => $stage === 'in_transit' ? 'p.shipped_date, p.mailing_date' : 'p.received_date, p.issue_date, p.effective_date',
            'freelook' => $stage === 'awaiting_return' ? 'p.freelook_doc_sent_at' : 'p.freelook_doc_sent_at, p.delivered_date, p.issue_date, p.effective_date',
            'unpaid' => 'ov.oldest_due',
            'no_commission' => 'p.effective_date',
            'cancelled' => 'p.cancel_date, p.updated_at',
            default => 'p.updated_at',
        };

        $today = now()->toDateString();

        return "DATEDIFF('{$today}', COALESCE({$ref}))";
    }

    private function overdueSub(int $tenantId)
    {
        return DB::table('policy_installments')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'paid')
            ->where('due_date', '<=', now()->toDateString())
            ->groupBy('policy_id')
            ->select([
                'policy_id',
                DB::raw('MIN(due_date) as oldest_due'),
                DB::raw('COUNT(*) as overdue_count'),
                DB::raw('SUM(amount_due - COALESCE(amount_paid, 0)) as overdue_amount'),
            ]);
    }

    private function baseQuery(int $tenantId, string $category, ?Tenant $tenant, string $stage, bool $reviewed): Builder
    {
        $today = now()->toDateString();
        $q = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->leftJoinSub($this->overdueSub($tenantId), 'ov', 'ov.policy_id', '=', 'p.id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at');

        return match ($category) {
            'approval' => $q->where('p.status', 'submitted'),
            'no_policy_no' => $q->whereIn('p.status', ['active', 'issued'])
                ->where(fn ($w) => $w->whereNull('p.policy_no')->orWhere('p.policy_no', '')),
            'not_delivered' => $stage === 'in_transit'
                ? $q->whereIn('p.status', ['issued', 'active'])->whereRaw('COALESCE(p.shipped_date, p.mailing_date) IS NOT NULL')->whereNull('p.delivered_date')
                : $q->whereIn('p.status', ['issued', 'active'])->whereNull('p.shipped_date')->whereNull('p.mailing_date'),
            'freelook' => $this->freelookStage(Freelook::whereLife($q->whereIn('p.status', ['issued', 'active'])->whereNull('p.freelook_doc_returned_at'))
                ->where(fn ($w) => $w->whereNull('p.freelook_end_date')->orWhere('p.freelook_end_date', '>=', now()->subDays(30)->toDateString())), $stage, $today),
            'unpaid' => $q->whereIn('p.status', ['active', 'issued'])->whereNotNull('ov.policy_id'),
            'no_commission' => $q->where('p.status', 'active')
                ->whereNotNull('p.policy_no')->where('p.policy_no', '!=', '')
                ->whereRaw('COALESCE(p.shipped_date, p.mailing_date) IS NOT NULL')
                ->where('p.effective_date', '>=', now()->subDays((int) ($tenant?->no_commission_window_days ?? 180))->toDateString())
                ->where(fn ($w) => $w->whereNull('p.comm_carrier_to_hub_amount')
                    ->orWhere('p.comm_carrier_to_hub_amount', 0)
                    ->orWhereNull('p.comm_hub_to_agent_amount')
                    ->orWhere('p.comm_hub_to_agent_amount', 0)),
            'cancelled' => $reviewed
                ? $q->where('p.status', 'cancelled')->whereNotNull('p.cancellation_reviewed_at')
                : $q->where('p.status', 'cancelled')->whereNull('p.cancellation_reviewed_at'),
            default => $q->whereRaw('1=0'),
        };
    }

    private function freelookStage(Builder $q, string $stage, string $today): Builder
    {
        return match ($stage) {
            'not_sent' => $q->whereNull('p.freelook_doc_sent_at'),
            'awaiting_return' => $q->whereNotNull('p.freelook_doc_sent_at'),
            'near_end' => $q->whereBetween('p.freelook_end_date', [$today, now()->addDays(7)->toDateString()]),
            'no_delivery_date' => $q->whereNull('p.delivered_date'),
            default => $q,
        };
    }

    private function otherCategories(object $r, string $current, ?Tenant $tenant): array
    {
        $hits = [];
        $status = (string) $r->status;
        $inForce = in_array($status, ['active', 'issued'], true);
        $noPolicyNo = ($r->policy_no === null || $r->policy_no === '');
        $noComm = ($r->comm_carrier_to_hub_amount === null || (float) $r->comm_carrier_to_hub_amount == 0.0
            || $r->comm_hub_to_agent_amount === null || (float) $r->comm_hub_to_agent_amount == 0.0);
        $isLife = in_array((string) $r->product_type, Freelook::LIFE_TYPES, true) && strtolower((string) $r->product_sc2) !== 'motor';
        $window = now()->subDays((int) ($tenant?->no_commission_window_days ?? 180))->toDateString();

        if ($status === 'submitted') {
            $hits['approval'] = true;
        }
        if ($inForce && $noPolicyNo) {
            $hits['no_policy_no'] = true;
        }
        if ($inForce && $r->shipped_date === null && $r->mailing_date === null) {
            $hits['not_delivered'] = true;
        }
        if ($inForce && $isLife && $r->freelook_doc_returned_at === null) {
            $hits['freelook'] = true;
        }
        if ($inForce && $r->oldest_due !== null) {
            $hits['unpaid'] = true;
        }
        if ($status === 'active' && ! $noPolicyNo && ($r->shipped_date !== null || $r->mailing_date !== null) && $noComm
            && $r->effective_date !== null && (string) $r->effective_date >= $window) {
            $hits['no_commission'] = true;
        }
        if ($status === 'cancelled' && $r->cancellation_reviewed_at === null) {
            $hits['cancelled'] = true;
        }

        unset($hits[$current]);

        return array_keys($hits);
    }
}
