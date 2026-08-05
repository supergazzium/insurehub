<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Analytics endpoints — direct-query implementations of the `v_*` reporting
 * views from `insurehub_legacy`, ported to the Laravel schema. All queries
 * scope by `tenant_id` and paginate/limit their output. No writes.
 *
 * Design note: these are read-heavy screens (renewal pipeline, agent
 * scorecard) and use raw joins + aggregations rather than Eloquent
 * relations so we can control indexes and returned columns precisely.
 */
class ReportController extends ApiController
{
    /**
     * Renewal pipeline queue — policies expiring in a date window.
     *
     * Filters (all optional, all server-applied):
     *   from, to       — expiry_date range (YYYY-MM-DD). Missing bounds
     *                    default to today / today+days.
     *   days           — legacy shorthand for a rolling window; used only
     *                    when `from` AND `to` are both absent.
     *   carrierId, productId, productType, insureType — exact match filters
     *   q              — free-text across name/policyNo/applicationNo/
     *                    plate/customerCode + phone (digits-only)
     *   page, perPage  — server pagination (default 50, cap 500)
     *
     * The `summary` block reflects the filtered set (not the page), so
     * "total in window" and "urgent (≤7 days)" are correct KPIs.
     */
    public function expiringSoon(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        [$from, $to, $days] = $this->resolveExpiryWindow($request);

        // Base query — same joins as before, plus the renewal-event
        // sub-selects used by the UI's "contacted / notice sent / started"
        // status chips.
        $base = $this->expiringSoonBaseQuery($tenantId, $from, $to);
        $this->applyExpiringSoonFilters($base, $request);

        // Summary is computed against the FILTERED query (before pagination).
        // We clone and REPLACE the SELECT list — otherwise the base's row-
        // columns get mixed with the aggregate and MySQL's only_full_group_by
        // rejects the query.
        $summaryQ = clone $base;
        $summaryQ->columns = null;
        $summary = $summaryQ
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN DATEDIFF(p.expiry_date, CURDATE()) <= 7 THEN 1 ELSE 0 END) as urgent')
            ->first();

        // Sortable columns — whitelisted so callers can't order by arbitrary
        // SQL. Default is expiry-date ascending (soonest first).
        $sortMap = [
            'expiryDate' => 'p.expiry_date',
            'annualPremium' => 'p.annual_premium',
            'customerName' => 'c.first_name',
        ];
        $sortBy = $sortMap[$request->input('sortBy', 'expiryDate')] ?? 'p.expiry_date';
        $sortDir = strtolower((string) $request->input('sortDir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $perPage = $this->perPage($request, 50, 500);
        $paginator = $base
            ->orderBy($sortBy, $sortDir)
            ->orderBy('p.id') // deterministic tiebreak within equal sort values
            ->paginate($perPage);

        $items = collect($paginator->items())->map(fn ($r) => [
            'policyId' => (string) $r->id,
            'applicationNo' => $r->application_no,
            'policyNo' => $r->policy_no,
            'expiryDate' => $r->expiry_date,
            'daysRemaining' => (int) $r->days_remaining,
            'annualPremium' => (float) $r->annual_premium,
            'customerCode' => $r->customer_code,
            'customerName' => $r->customer_name,
            'customerEmail' => $r->customer_email,
            'customerPhone' => $r->customer_phone,
            'customerType' => $r->customer_type,
            'agentCode' => $r->agent_code,
            'agentName' => $r->agent_name,
            'agentEmail' => $r->agent_email,
            'carrierId' => $r->carrier_id_out !== null ? (string) $r->carrier_id_out : null,
            'carrierCode' => $r->carrier_code,
            'carrierName' => $r->carrier_name,
            'carrierInsureType' => $r->carrier_insure_type,
            'productId' => $r->product_id_out !== null ? (string) $r->product_id_out : null,
            'productCode' => $r->product_code,
            'productName' => $r->product_name,
            'productType' => $r->product_type,
            'productMainRider' => $r->product_main_rider,
            'motorLicenseNo' => $r->motor_license_no,
            'lastContactedAt' => $r->last_contacted_at,
            'lastNoticeSentAt' => $r->last_notice_sent_at,
            'renewalStartedAt' => $r->renewal_started_at,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'days' => $days,
                'from' => $from,
                'to' => $to,
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
            'summary' => [
                'totalInWindow' => (int) ($summary->total ?? 0),
                'urgentCount' => (int) ($summary->urgent ?? 0),
            ],
        ]);
    }

    /**
     * Build the base joined query for the renewal-pipeline endpoints. Keeps
     * the FROM/JOIN chain identical between the JSON and PDF exports.
     */
    private function expiringSoonBaseQuery(int $tenantId, string $from, string $to): \Illuminate\Database\Query\Builder
    {
        $latestContacted = DB::table('policy_events')
            ->select('policy_id', DB::raw('MAX(occurred_at) as latest'))
            ->where('type', 'renewalContacted')
            ->groupBy('policy_id');
        $latestNoticeSent = DB::table('policy_events')
            ->select('policy_id', DB::raw('MAX(occurred_at) as latest'))
            ->where('type', 'renewalNoticeSent')
            ->groupBy('policy_id');
        $latestRenewStarted = DB::table('policy_events')
            ->select('policy_id', DB::raw('MAX(occurred_at) as latest'))
            ->where('type', 'renewalStarted')
            ->groupBy('policy_id');

        return DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->leftJoinSub($latestContacted, 'ev_c', fn ($j) => $j->on('ev_c.policy_id', '=', 'p.id'))
            ->leftJoinSub($latestNoticeSent, 'ev_n', fn ($j) => $j->on('ev_n.policy_id', '=', 'p.id'))
            ->leftJoinSub($latestRenewStarted, 'ev_r', fn ($j) => $j->on('ev_r.policy_id', '=', 'p.id'))
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->where('p.status', 'active')
            ->whereBetween('p.expiry_date', [$from, $to])
            ->select([
                'p.id', 'p.application_no', 'p.policy_no',
                'p.expiry_date', 'p.annual_premium',
                'p.motor_license_no', 'p.motor_vehicle_brand', 'p.motor_vehicle_model',
                DB::raw('DATEDIFF(p.expiry_date, CURDATE()) as days_remaining'),
                'c.customer_code', 'c.email as customer_email',
                'c.phone as customer_phone', 'c.customer_type',
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'a.agent_code', 'a.email as agent_email',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                'ca.id as carrier_id_out', 'ca.code as carrier_code',
                'ca.name as carrier_name', 'ca.insure_type as carrier_insure_type',
                'pr.id as product_id_out', 'pr.code as product_code',
                'pr.name as product_name', 'pr.type as product_type',
                'pr.main_rider as product_main_rider',
                'ev_c.latest as last_contacted_at',
                'ev_n.latest as last_notice_sent_at',
                'ev_r.latest as renewal_started_at',
            ]);
    }

    /**
     * Apply the shared filter set (carrier/product/type/insureType/q) to a
     * base query. Mutates the builder in place; also used by the PDF export.
     */
    private function applyExpiringSoonFilters(\Illuminate\Database\Query\Builder $q, Request $request): void
    {
        if ($carrierId = $request->input('carrierId')) {
            $q->where('p.carrier_id', $carrierId);
        }
        if ($productId = $request->input('productId')) {
            $q->where('p.product_id', $productId);
        }
        if ($productType = $request->input('productType')) {
            $q->where('pr.type', $productType);
        }
        if ($insureType = $request->input('insureType')) {
            $q->where('ca.insure_type', $insureType);
        }
        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $like = "%{$search}%";
            $digits = preg_replace('/\D/', '', $search) ?? '';
            $q->where(function ($w) use ($like, $digits, $search): void {
                $w->where('c.first_name', 'like', $like)
                    ->orWhere('c.last_name', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?", [$like])
                    ->orWhere('c.customer_code', 'like', $like)
                    ->orWhere('p.policy_no', 'like', $like)
                    ->orWhere('p.application_no', 'like', $like)
                    ->orWhere('p.motor_license_no', 'like', $like);
                // Phone match — 4+ digit substring on the digit-only phone.
                if (strlen($digits) >= 4) {
                    $w->orWhereRaw("REGEXP_REPLACE(COALESCE(c.phone, ''), '[^0-9]', '') LIKE ?", ["%{$digits}%"]);
                }
                // Also let the raw string hit the phone as-typed (covers
                // stored formats that already include separators).
                $w->orWhere('c.phone', 'like', $like);
            });
        }
    }

    /**
     * Resolve the expiry-date window from request inputs. Returns
     * [$from, $to, $days] where $days is the effective width (for the meta
     * block only). from/to override; days is fallback.
     */
    private function resolveExpiryWindow(Request $request): array
    {
        $from = (string) $request->input('from', '');
        $to = (string) $request->input('to', '');
        if ($from === '' && $to === '') {
            $days = max(1, min(3650, (int) ($request->input('days') ?? 60)));
            $from = Carbon::today()->toDateString();
            $to = Carbon::today()->addDays($days)->toDateString();
        } else {
            if ($from === '') $from = Carbon::today()->toDateString();
            if ($to === '') $to = Carbon::parse($from)->addDays(60)->toDateString();
            $days = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to));
        }
        return [$from, $to, $days];
    }

    /**
     * PDF export of the renewal pipeline — same query as expiringSoon() plus
     * client-side filters echoed via query params (so the PDF matches what
     * the operator sees on screen after filtering).
     *
     * Filename per spec: "Renewals-{Ndays}d-{fromDate}-to-{toDate}.pdf"
     */
    public function expiringSoonPdf(Request $request): Response
    {
        // dompdf's Cellmap can be memory-heavy on wide tables; bump the
        // per-request limit so large PDFs render reliably.
        ini_set('memory_limit', '512M');
        $tenantId = $this->tenantId($request);
        [$from, $to, $days] = $this->resolveExpiryWindow($request);

        // Same base query + filter chain as the JSON endpoint — the PDF is
        // guaranteed to match what the user just saw on screen.
        $q = $this->expiringSoonBaseQuery($tenantId, $from, $to);
        $this->applyExpiringSoonFilters($q, $request);
        $rows = $q->orderBy('p.expiry_date')->limit(2000)->get();

        // Filename per spec — always includes the effective window.
        $filename = "Renewals-{$days}d-{$from}-to-{$to}.pdf";

        $pdf = Pdf::loadView('pdf.renewals', [
            'rows' => $rows,
            'meta' => [
                'days' => $days,
                'windowFrom' => $from,
                'windowTo' => $to,
                'rangeFrom' => $from,
                'rangeTo' => $to,
                'totalWindow' => $rows->count(),
                'filteredCount' => $rows->count(),
                'generatedAt' => Carbon::now()->format('Y-m-d H:i'),
                'urgent' => $rows->filter(fn ($r) => (int) $r->days_remaining <= 7)->count(),
                'filters' => array_filter([
                    'ค้นหา' => $request->input('q') ?: null,
                    'ช่วงวันหมดอายุ' => "{$from} → {$to}",
                    'บริษัท' => $request->input('carrierId') ?: null,
                    'แผน' => $request->input('productId') ?: null,
                    'ประเภทผลิตภัณฑ์' => $request->input('productType') ?: null,
                    'ประเภทประกัน' => $request->input('insureType') ?: null,
                ]),
            ],
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    /**
     * Book of business — one row per in-force policy. Analogue of
     * `v_active_policies`.
     */
    public function activePolicies(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $rows = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->where('p.status', 'active')
            ->orderBy('p.effective_date', 'desc')
            ->paginate($this->perPage($request, 50, 200));

        return response()->json([
            'data' => collect($rows->items())->map(fn ($r) => [
                'policyId' => (string) $r->id,
                'applicationNo' => $r->application_no,
                'policyNo' => $r->policy_no,
                'customerCode' => $r->customer_code,
                'customerName' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
                'agentCode' => $r->agent_code,
                'productCode' => $r->code,
                'productName' => $r->name,
                'effectiveDate' => $r->effective_date,
                'expiryDate' => $r->expiry_date,
                'annualPremium' => (float) $r->annual_premium,
                'coverage' => (float) $r->coverage,
            ]),
            'meta' => [
                'currentPage' => $rows->currentPage(),
                'perPage' => $rows->perPage(),
                'total' => $rows->total(),
                'lastPage' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * Agent commission ledger — unified InH + AG main + rider commissions.
     * Analogue of `v_agent_commission_ledger`. Filterable by agent_code,
     * party (inh|ag), and app_date range.
     */
    public function agentCommissionLedger(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $agentCode = $request->string('agentCode')->toString();
        $party = $request->string('party')->toString();
        $fromDate = $request->string('fromDate')->toString();
        $toDate = $request->string('toDate')->toString();

        // Main commissions.
        $main = DB::table('policies as p')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->select([
                DB::raw("'main' as source"),
                'p.id as policy_id',
                'p.application_no',
                'p.app_date',
                'a.agent_code',
                DB::raw("'inh' as party"),
                DB::raw('p.main_com_rate_inh as com_rate'),
                DB::raw('p.main_com_amt_inh as com_amount'),
                'p.main_premium as base_premium',
            ])
            ->unionAll(
                DB::table('policies as p')
                    ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
                    ->where('p.tenant_id', $tenantId)
                    ->whereNull('p.deleted_at')
                    ->select([
                        DB::raw("'main' as source"),
                        'p.id as policy_id',
                        'p.application_no',
                        'p.app_date',
                        'a.agent_code',
                        DB::raw("'ag' as party"),
                        DB::raw('p.main_com_rate_ag as com_rate'),
                        DB::raw('p.main_com_amt_ag as com_amount'),
                        'p.main_premium as base_premium',
                    ])
            );

        // Rider commissions (both parties on each rider).
        $riders = DB::table('policy_riders as r')
            ->join('policies as p', 'p.id', '=', 'r.policy_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->select([
                DB::raw("'rider' as source"),
                'r.policy_id',
                'p.application_no',
                'p.app_date',
                'a.agent_code',
                DB::raw("'inh' as party"),
                DB::raw('r.com_rate_inh as com_rate'),
                DB::raw('r.com_amt_inh as com_amount'),
                'r.premium as base_premium',
            ])
            ->unionAll(
                DB::table('policy_riders as r')
                    ->join('policies as p', 'p.id', '=', 'r.policy_id')
                    ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
                    ->where('p.tenant_id', $tenantId)
                    ->whereNull('p.deleted_at')
                    ->select([
                        DB::raw("'rider' as source"),
                        'r.policy_id',
                        'p.application_no',
                        'p.app_date',
                        'a.agent_code',
                        DB::raw("'ag' as party"),
                        DB::raw('r.com_rate_ag as com_rate'),
                        DB::raw('r.com_amt_ag as com_amount'),
                        'r.premium as base_premium',
                    ])
            );

        $sub = DB::query()->fromSub($main->unionAll($riders), 'ledger');

        if ($agentCode !== '') {
            $sub->where('agent_code', $agentCode);
        }
        if ($party !== '') {
            $sub->where('party', $party);
        }
        if ($fromDate !== '') {
            $sub->where('app_date', '>=', $fromDate);
        }
        if ($toDate !== '') {
            $sub->where('app_date', '<=', $toDate);
        }

        $rows = $sub->orderBy('app_date', 'desc')->orderBy('policy_id')
            ->limit($this->perPage($request, 200, 1000))
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'source' => $r->source,
                'policyId' => (string) $r->policy_id,
                'applicationNo' => $r->application_no,
                'appDate' => $r->app_date,
                'agentCode' => $r->agent_code,
                'party' => $r->party,
                'commissionRate' => $r->com_rate !== null ? (float) $r->com_rate : null,
                'commissionAmount' => $r->com_amount !== null ? (float) $r->com_amount : null,
                'basePremium' => $r->base_premium !== null ? (float) $r->base_premium : null,
            ]),
            'meta' => [
                'total' => $rows->count(),
                'filter' => [
                    'agentCode' => $agentCode ?: null,
                    'party' => $party ?: null,
                    'fromDate' => $fromDate ?: null,
                    'toDate' => $toDate ?: null,
                ],
            ],
        ]);
    }

    /**
     * Monthly agent scorecard — apps written + total premium + total
     * commission. Analogue of `v_agent_performance`.
     */
    public function agentPerformance(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $fromMonth = $request->string('fromMonth')->toString() ?: Carbon::now()->subMonths(11)->format('Y-m');
        $toMonth = $request->string('toMonth')->toString() ?: Carbon::now()->format('Y-m');

        $rows = DB::table('policies as p')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.app_date')
            ->whereBetween(DB::raw("DATE_FORMAT(p.app_date, '%Y-%m')"), [$fromMonth, $toMonth])
            ->select([
                DB::raw("DATE_FORMAT(p.app_date, '%Y-%m') as month"),
                'a.agent_code',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                DB::raw('COUNT(*) as apps_written'),
                DB::raw("SUM(CASE WHEN p.status = 'active' THEN 1 ELSE 0 END) as apps_approved"),
                DB::raw('SUM(COALESCE(p.annual_premium, 0)) as premium_total'),
                DB::raw('SUM(COALESCE(p.main_com_amt_ag, 0)) as commission_ag_total'),
            ])
            ->groupBy(DB::raw("DATE_FORMAT(p.app_date, '%Y-%m'), a.agent_code, a.first_name, a.last_name"))
            ->orderBy('month', 'desc')
            ->orderBy('premium_total', 'desc')
            ->limit($this->perPage($request, 200, 1000))
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'month' => $r->month,
                'agentCode' => $r->agent_code,
                'agentName' => $r->agent_name,
                'appsWritten' => (int) $r->apps_written,
                'appsApproved' => (int) $r->apps_approved,
                'premiumTotal' => (float) $r->premium_total,
                'commissionAgTotal' => (float) $r->commission_ag_total,
            ]),
            'meta' => [
                'fromMonth' => $fromMonth,
                'toMonth' => $toMonth,
                'total' => $rows->count(),
            ],
        ]);
    }

    /**
     * Product performance — sold count, avg premium, cancellation rate.
     * Analogue of `v_product_performance`.
     */
    public function productPerformance(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $rows = DB::table('products as pr')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'pr.carrier_id')
            ->leftJoin('policies as p', function ($join) use ($tenantId): void {
                $join->on('p.product_id', '=', 'pr.id')
                    ->whereNull('p.deleted_at')
                    ->where('p.tenant_id', $tenantId);
            })
            ->where('pr.tenant_id', $tenantId)
            ->whereNull('pr.deleted_at')
            ->select([
                'pr.code as product_code',
                'pr.name as product_name',
                'ca.code as carrier_code',
                'pr.type as product_type',
                DB::raw('COUNT(p.id) as policies_sold'),
                DB::raw('AVG(COALESCE(p.annual_premium, 0)) as avg_premium'),
                DB::raw("SUM(CASE WHEN p.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count"),
                DB::raw('SUM(COALESCE(p.annual_premium, 0)) as total_premium'),
            ])
            ->groupBy('pr.id', 'pr.code', 'pr.name', 'ca.code', 'pr.type')
            ->orderBy('total_premium', 'desc')
            ->limit($this->perPage($request, 200, 1000))
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'productCode' => $r->product_code,
                'productName' => $r->product_name,
                'carrierCode' => $r->carrier_code,
                'productType' => $r->product_type,
                'policiesSold' => (int) $r->policies_sold,
                'avgPremium' => (float) $r->avg_premium,
                'cancelledCount' => (int) $r->cancelled_count,
                'cancellationRate' => $r->policies_sold > 0
                    ? round((float) $r->cancelled_count / (float) $r->policies_sold, 4)
                    : 0.0,
                'totalPremium' => (float) $r->total_premium,
            ]),
        ]);
    }

    /**
     * Sales pipeline mix by month — new vs renew count + premium split.
     * Analogue of `v_new_vs_renew_by_month`.
     */
    public function newVsRenewByMonth(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $rows = DB::table('policies as p')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.app_date')
            ->select([
                DB::raw("DATE_FORMAT(p.app_date, '%Y-%m') as month"),
                'p.new_or_renew as kind',
                DB::raw('COUNT(*) as policies'),
                DB::raw('SUM(COALESCE(p.annual_premium, 0)) as premium_total'),
            ])
            ->groupBy(DB::raw("DATE_FORMAT(p.app_date, '%Y-%m'), p.new_or_renew"))
            ->orderBy('month', 'desc')
            ->limit(1000)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'month' => $r->month,
                'kind' => $r->kind,
                'policies' => (int) $r->policies,
                'premiumTotal' => (float) $r->premium_total,
            ]),
        ]);
    }

    /**
     * Cancellation ledger — one row per cancelled or rejected policy.
     * Analogue of `v_cancellation_ledger`.
     */
    public function cancellationLedger(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $rows = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.cancel_status')
            ->select([
                'p.id',
                'p.application_no',
                'p.policy_no',
                'p.cancel_status',
                'p.cancel_date',
                'p.annual_premium',
                'p.refund_premium',
                'p.refund_total_premium',
                'p.net_refund_amount',
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'a.agent_code',
            ])
            ->orderBy('p.cancel_date', 'desc')
            ->paginate($this->perPage($request, 50, 200));

        return response()->json([
            'data' => collect($rows->items())->map(fn ($r) => [
                'policyId' => (string) $r->id,
                'applicationNo' => $r->application_no,
                'policyNo' => $r->policy_no,
                'cancelStatus' => $r->cancel_status,
                'cancelDate' => $r->cancel_date,
                'annualPremium' => (float) $r->annual_premium,
                'refundPremium' => $r->refund_premium !== null ? (float) $r->refund_premium : null,
                'refundTotalPremium' => $r->refund_total_premium !== null ? (float) $r->refund_total_premium : null,
                'netRefundAmount' => $r->net_refund_amount !== null ? (float) $r->net_refund_amount : null,
                'customerName' => $r->customer_name,
                'agentCode' => $r->agent_code,
            ]),
            'meta' => [
                'currentPage' => $rows->currentPage(),
                'perPage' => $rows->perPage(),
                'total' => $rows->total(),
                'lastPage' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * Rebate reconciliation — calc vs actual on InH, OV, and AG legs.
     * Analogue of `v_rebate_reconciliation`.
     */
    public function rebateReconciliation(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $rows = DB::table('policy_rebates as r')
            ->join('policies as p', 'p.id', '=', 'r.policy_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->where('r.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->select([
                'r.id as rebate_id',
                'p.id as policy_id',
                'p.application_no',
                'r.rebate_status',
                'r.earn_date',
                'r.calculated_amount',
                'r.actual_amount',
                DB::raw('r.actual_amount - r.calculated_amount as inh_delta'),
                'r.calculated_ov',
                'r.actual_ov',
                DB::raw('r.actual_ov - r.calculated_ov as ov_delta'),
                'r.calculated_agent_amount',
                'r.actual_agent_amount',
                DB::raw('r.actual_agent_amount - r.calculated_agent_amount as ag_delta'),
                'a.agent_code',
            ])
            ->orderBy('r.earn_date', 'desc')
            ->paginate($this->perPage($request, 50, 200));

        return response()->json([
            'data' => collect($rows->items())->map(fn ($r) => [
                'rebateId' => (string) $r->rebate_id,
                'policyId' => (string) $r->policy_id,
                'applicationNo' => $r->application_no,
                'rebateStatus' => $r->rebate_status,
                'earnDate' => $r->earn_date,
                'agentCode' => $r->agent_code,
                'inh' => [
                    'calculated' => $r->calculated_amount !== null ? (float) $r->calculated_amount : null,
                    'actual' => $r->actual_amount !== null ? (float) $r->actual_amount : null,
                    'delta' => $r->inh_delta !== null ? (float) $r->inh_delta : null,
                ],
                'ov' => [
                    'calculated' => $r->calculated_ov !== null ? (float) $r->calculated_ov : null,
                    'actual' => $r->actual_ov !== null ? (float) $r->actual_ov : null,
                    'delta' => $r->ov_delta !== null ? (float) $r->ov_delta : null,
                ],
                'ag' => [
                    'calculated' => $r->calculated_agent_amount !== null ? (float) $r->calculated_agent_amount : null,
                    'actual' => $r->actual_agent_amount !== null ? (float) $r->actual_agent_amount : null,
                    'delta' => $r->ag_delta !== null ? (float) $r->ag_delta : null,
                ],
            ]),
            'meta' => [
                'currentPage' => $rows->currentPage(),
                'perPage' => $rows->perPage(),
                'total' => $rows->total(),
                'lastPage' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * Dashboard KPIs — headline counts + totals.
     */
    public function dashboardKpis(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $counts = DB::table('policies')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->selectRaw("
                COUNT(*) as total_policies,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_policies,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_policies,
                SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                    AND status = 'active' THEN 1 ELSE 0 END) as expiring_60d,
                SUM(CASE WHEN status = 'active' THEN COALESCE(annual_premium, 0) ELSE 0 END) as in_force_premium
            ")
            ->first();

        $customerCount = DB::table('customers')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count();
        $agentCount = DB::table('agents')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count();

        return response()->json([
            'data' => [
                'totalPolicies' => (int) $counts->total_policies,
                'activePolicies' => (int) $counts->active_policies,
                'cancelledPolicies' => (int) $counts->cancelled_policies,
                'expiring60d' => (int) $counts->expiring_60d,
                'inForcePremium' => (float) $counts->in_force_premium,
                'totalCustomers' => $customerCount,
                'totalAgents' => $agentCount,
            ],
        ]);
    }

    // ── Phase 8a — operational reports ───────────────────────────────────

    /**
     * Freelook report — policies whose freelook_active flag is true. Access
     * uses Freelook_Status=True as one of the gating conditions for commission
     * pay-out; this view lets ops see who's still in the freelook window.
     *
     * `?days=15` optionally filters to policies whose effective_date is within
     * the last N days (the actual freelook window in Thailand is 15 days).
     */
    public function freelook(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $days = (int) ($request->input('days') ?? 30);
        $days = max(1, min(365, $days));

        $q = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->where('p.freelook_active', true)
            ->where('p.effective_date', '>=', Carbon::today()->subDays($days)->toDateString())
            ->orderByDesc('p.effective_date');

        $rows = $q->limit($this->perPage($request, 200, 500))->get([
            'p.id', 'p.application_no', 'p.policy_no',
            'p.effective_date', 'p.expiry_date', 'p.annual_premium',
            'c.customer_code',
            DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
            'a.agent_code',
            DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
            'ca.code as carrier_code', 'ca.name as carrier_name',
            'pr.code as product_code', 'pr.name as product_name',
            DB::raw('DATEDIFF(CURDATE(), p.effective_date) as days_since_effective'),
        ]);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'policyId' => (string) $r->id,
                'applicationNo' => $r->application_no,
                'policyNo' => $r->policy_no,
                'effectiveDate' => $r->effective_date,
                'expiryDate' => $r->expiry_date,
                'daysSinceEffective' => (int) $r->days_since_effective,
                'annualPremium' => (float) ($r->annual_premium ?? 0),
                'customerCode' => $r->customer_code,
                'customerName' => $r->customer_name,
                'agentCode' => $r->agent_code,
                'agentName' => $r->agent_name,
                'carrierCode' => $r->carrier_code,
                'carrierName' => $r->carrier_name,
                'productCode' => $r->product_code,
                'productName' => $r->product_name,
            ]),
            'meta' => [
                'days' => $days,
                'total' => $rows->count(),
            ],
        ]);
    }

    /**
     * Mailing dispatch tracker — policies whose mailing_date falls within the
     * requested window. Analogue of `v_mailing_pipeline`. Access exports this
     * to a spreadsheet for the courier team.
     */
    public function mailingPipeline(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $from = $request->input('from') ?: Carbon::today()->subDays(30)->toDateString();
        $to = $request->input('to') ?: Carbon::today()->addDays(30)->toDateString();

        $rows = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereBetween('p.mailing_date', [$from, $to])
            ->orderBy('p.mailing_date')
            ->limit($this->perPage($request, 500, 2000))
            ->get([
                'p.id', 'p.application_no', 'p.policy_no',
                'p.mailing_date', 'p.mailing_add_by_policy', 'p.mailing_note',
                'c.customer_code',
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'a.agent_code',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                'ca.code as carrier_code', 'ca.name as carrier_name',
            ]);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'policyId' => (string) $r->id,
                'applicationNo' => $r->application_no,
                'policyNo' => $r->policy_no,
                'mailingDate' => $r->mailing_date,
                'mailingAddress' => $r->mailing_add_by_policy,
                'mailingNote' => $r->mailing_note,
                'customerCode' => $r->customer_code,
                'customerName' => $r->customer_name,
                'agentCode' => $r->agent_code,
                'agentName' => $r->agent_name,
                'carrierCode' => $r->carrier_code,
                'carrierName' => $r->carrier_name,
            ]),
            'meta' => ['from' => $from, 'to' => $to, 'total' => $rows->count()],
        ]);
    }

    /**
     * Payment history — one row per policy_payment in the window. Access
     * exports this as the monthly premium-received report.
     */
    public function paymentHistory(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $from = $request->input('from') ?: Carbon::today()->subDays(30)->toDateString();
        $to = $request->input('to') ?: Carbon::today()->toDateString();

        $rows = DB::table('policy_payments as pp')
            ->join('policies as p', 'p.id', '=', 'pp.policy_id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereBetween('pp.payment_date', [$from, $to])
            ->orderByDesc('pp.payment_date')
            ->limit($this->perPage($request, 500, 5000))
            ->get([
                'pp.id as payment_id', 'pp.payment_date', 'pp.amount', 'pp.method', 'pp.reference',
                'p.id as policy_id', 'p.application_no', 'p.policy_no',
                'c.customer_code',
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'a.agent_code',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                'ca.code as carrier_code',
            ]);

        $totalAmount = $rows->sum(fn ($r) => (float) $r->amount);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'paymentId' => (string) $r->payment_id,
                'paymentDate' => $r->payment_date,
                'amount' => (float) $r->amount,
                'method' => $r->method,
                'reference' => $r->reference,
                'policyId' => (string) $r->policy_id,
                'applicationNo' => $r->application_no,
                'policyNo' => $r->policy_no,
                'customerCode' => $r->customer_code,
                'customerName' => $r->customer_name,
                'agentCode' => $r->agent_code,
                'agentName' => $r->agent_name,
                'carrierCode' => $r->carrier_code,
            ]),
            'meta' => [
                'from' => $from, 'to' => $to,
                'total' => $rows->count(),
                'totalAmount' => round($totalAmount, 2),
            ],
        ]);
    }
}
