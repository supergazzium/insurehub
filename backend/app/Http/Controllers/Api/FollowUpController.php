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
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => ['category' => $category, 'counts' => $counts],
        ]);
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
            'no_commission' => $q->where('p.status', 'active')
                ->where(fn ($w) => $w->whereNull('p.comm_carrier_to_hub_amount')
                    ->orWhere('p.comm_carrier_to_hub_amount', 0)
                    ->orWhereNull('p.comm_hub_to_agent_amount')
                    ->orWhere('p.comm_hub_to_agent_amount', 0)),
            'cancelled' => $q->where('p.status', 'cancelled'),
            default => $q->whereRaw('1=0'),
        };
    }
}
