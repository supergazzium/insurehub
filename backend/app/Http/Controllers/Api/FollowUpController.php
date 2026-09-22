<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ติดตามงานค้าง — outstanding work follow-up. One endpoint returns the items
 * for a chosen category (or the counts for every category). Payment follow-up
 * lives on its own /collections page and is intentionally not here.
 *
 * Categories:
 *   approval    — สถานะกรมธรรม์รออนุมัติ (status=submitted)
 *   no_policy_no— เลขกรมธรรม์ยังไม่บันทึก (policy_no empty, active/issued)
 *   not_delivered — รายการยังไม่จัดส่ง (mailing_date null, active)
 *   freelook    — ติดตามใบ Freelook (freelook_active + within freelook_end_date)
 *   no_commission — ยังไม่บันทึกค่าคอม (missing carrier→hub or hub→agent amount)
 *   cancelled   — ตรวจสอบรายการยกเลิก (status=cancelled)
 */
class FollowUpController extends Controller
{
    /** How far back a missing-commission sale is still worth chasing. */
    private const NO_COMMISSION_WINDOW_DAYS = 180;

    private const CATEGORIES = ['approval', 'no_policy_no', 'not_delivered', 'freelook', 'no_commission', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        $category = (string) $request->input('category', 'approval');
        $search = trim((string) $request->input('q', ''));
        if (! in_array($category, self::CATEGORIES, true)) {
            $category = 'approval';
        }

        // Counts for every category (for the filter chips).
        $counts = [];
        foreach (self::CATEGORIES as $c) {
            $counts[$c] = (int) $this->baseQuery($tenantId, $c)->count();
        }

        $q = $this->baseQuery($tenantId, $category);
        if ($search !== '') {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('p.policy_no', 'like', $like)
                    ->orWhere('p.application_no', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?", [$like])
                    ->orWhere('c.customer_code', 'like', $like);
            });
        }

        $rows = $q->select([
            'p.id', 'p.policy_no', 'p.application_no', 'p.status',
            'p.effective_date', 'p.expiry_date', 'p.issue_date',
            'p.received_date', 'p.mailing_date', 'p.freelook_end_date',
            'p.cancel_date', 'p.cancel_status',
            'p.comm_carrier_to_hub_amount', 'p.comm_hub_to_agent_amount',
            'c.customer_code', 'c.phone as customer_phone',
            DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
            'a.agent_code',
            DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
            'ca.name as carrier_name', 'pr.name as product_name',
        ])
            ->orderByDesc('p.id')
            ->limit(500)
            ->get()
            ->map(fn ($r): array => [
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
                'freelookEndDate' => $r->freelook_end_date,
                'cancelDate' => $r->cancel_date,
                'cancelStatus' => $r->cancel_status,
                // Which OTHER follow-up lists this same policy is currently in,
                // so staff see a multi-issue policy at a glance (H1).
                'alsoIn' => $this->otherCategories($r, $category),
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => ['category' => $category, 'counts' => $counts],
        ]);
    }

    /**
     * Evaluate which follow-up categories (other than the current one) this
     * row also belongs to, from the already-fetched columns — no extra queries.
     * Only the status/field-derivable categories are checked here; the
     * age-scoped no_commission window is approximated by the amount test.
     *
     * @return array<int, string>
     */
    private function otherCategories(object $r, string $current): array
    {
        $hits = [];
        $status = (string) $r->status;
        $noPolicyNo = ($r->policy_no === null || $r->policy_no === '');
        $noMailing = $r->mailing_date === null;
        $noComm = ($r->comm_carrier_to_hub_amount === null || (float) $r->comm_carrier_to_hub_amount == 0.0
            || $r->comm_hub_to_agent_amount === null || (float) $r->comm_hub_to_agent_amount == 0.0);

        if ($status === 'submitted') {
            $hits['approval'] = true;
        }
        if (in_array($status, ['active', 'issued'], true) && $noPolicyNo) {
            $hits['no_policy_no'] = true;
        }
        if ($status === 'active' && $noMailing) {
            $hits['not_delivered'] = true;
        }
        if ($status === 'active' && ! $noPolicyNo && ! $noMailing && $noComm) {
            $hits['no_commission'] = true;
        }
        if ($status === 'cancelled') {
            $hits['cancelled'] = true;
        }
        if ($r->freelook_end_date === null) {
            // freelook is life-only; the row already passed the category filter
            // if current is freelook, so only surface it as a cross-tag when the
            // policy is a life one — approximated by presence of freelook context.
        }

        unset($hits[$current]);

        return array_keys($hits);
    }

    private function baseQuery(int $tenantId, string $category)
    {
        $q = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at');

        return match ($category) {
            'approval' => $q->where('p.status', 'submitted'),
            'no_policy_no' => $q->whereIn('p.status', ['active', 'issued'])
                ->where(fn ($w) => $w->whereNull('p.policy_no')->orWhere('p.policy_no', '')),
            'not_delivered' => $q->where('p.status', 'active')->whereNull('p.mailing_date'),
            // Free Look = life-product policies whose Free Look date has not
            // been recorded yet — the empty date is the thing to follow up on.
            'freelook' => $q->where('pr.type', 'life')->whereNull('p.freelook_end_date'),
            // Scoped to be actionable: a *completed* sale (issued policy number +
            // delivered) that is recent enough to still be chasing, but whose
            // commission hasn't been recorded. Without this scope the category
            // matches ~every active policy (commission is rarely pre-filled) and
            // becomes noise rather than a worklist.
            'no_commission' => $q->where('p.status', 'active')
                ->whereNotNull('p.policy_no')->where('p.policy_no', '!=', '')
                ->whereNotNull('p.mailing_date')
                ->where('p.created_at', '>=', now()->subDays(self::NO_COMMISSION_WINDOW_DAYS))
                ->where(fn ($w) => $w->whereNull('p.comm_carrier_to_hub_amount')
                    ->orWhere('p.comm_carrier_to_hub_amount', 0)
                    ->orWhereNull('p.comm_hub_to_agent_amount')
                    ->orWhere('p.comm_hub_to_agent_amount', 0)),
            'cancelled' => $q->where('p.status', 'cancelled'),
            default => $q->whereRaw('1=0'),
        };
    }
}
