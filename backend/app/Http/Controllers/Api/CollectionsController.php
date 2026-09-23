<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentReminder;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * การติดตามเงิน (payment collections / dunning).
 *
 * Lists every non-cancelled policy whose paid amount is short of what is owed,
 * classified by payment kind (cash / ผ่อน / แบ่งชำระ). For installment plans it
 * also computes the per-งวด schedule so staff can chase the overdue งวด.
 * Cancelled policies are excluded (spec: ยกเลิกแล้วไม่ต้องเตือน).
 */
class CollectionsController extends Controller
{
    // Installment engine rates (mirror utils/installmentCalc.ts).
    private const DOWN_RATE = 0.25;
    private const FEE_RATE = 0.02;
    private const INT_RATE = 0.01;

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        $kind = (string) $request->input('kind', 'all'); // all|cash|installment|split
        $search = trim((string) $request->input('q', ''));

        $rows = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->where('p.status', '!=', 'cancelled')        // ยกเลิกแล้วไม่เตือน
            ->whereIn('p.status', ['active', 'issued'])     // must be in force / issued
            ->when($search !== '', function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where(function ($w) use ($like) {
                    $w->where('p.policy_no', 'like', $like)
                        ->orWhere('p.application_no', 'like', $like)
                        ->orWhereRaw("CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?", [$like])
                        ->orWhere('c.customer_code', 'like', $like);
                });
            })
            ->select([
                'p.id', 'p.policy_no', 'p.application_no', 'p.status',
                'p.main_premium', 'p.compulsory_premium', 'p.total_premium_paid',
                'p.installment_mode', 'p.installment_term',
                'p.effective_date', 'p.first_due_inst_date',
                'c.customer_code', 'c.phone as customer_phone',
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'a.agent_code',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                DB::raw('(SELECT COALESCE(SUM(pp.amount),0) FROM policy_payments pp WHERE pp.policy_id = p.id) as paid_total'),
                DB::raw('(SELECT COUNT(*) FROM policy_payments pp WHERE pp.policy_id = p.id) as paid_count'),
                DB::raw('(SELECT COUNT(*) FROM payment_reminders pr WHERE pr.policy_id = p.id) as reminder_count'),
                DB::raw('(SELECT MAX(pr.created_at) FROM payment_reminders pr WHERE pr.policy_id = p.id) as last_reminder_at'),
            ])
            ->orderByDesc('p.id')
            ->limit(500)
            ->get();

        $today = Carbon::today();
        $out = [];
        foreach ($rows as $r) {
            $main = (float) ($r->main_premium ?? 0);
            $compulsory = (float) ($r->compulsory_premium ?? 0);
            // Total owed = main premium + พ.ร.บ.
            $totalDue = round($main + $compulsory, 2);
            // Amount already paid = the sum of recorded policy_payments rows —
            // the auditable source of truth (one verifiable record per payment).
            // Legacy imported balances were backfilled into policy_payments
            // (method 'legacyImport') by `payments:backfill-legacy`, so this
            // covers old and new alike. total_premium_paid is only a fallback if
            // a policy somehow has no rows at all.
            $paid = (float) $r->paid_total;
            if ($paid <= 0 && (float) ($r->total_premium_paid ?? 0) > 0) {
                $paid = (float) $r->total_premium_paid;
            }
            $paid = round($paid, 2);
            // If we somehow have no main premium, treat the paid figure as the
            // whole thing (nothing to chase).
            if ($totalDue <= 0) {
                $totalDue = $paid;
            }
            $outstanding = round($totalDue - $paid, 2);

            // Treat anything under ฿1 as fully paid — that residue is only
            // per-installment ceiling rounding, not a real balance owed.
            if ($outstanding < 1.0) {
                continue;
            }

            $rowKind = $this->classify($r);
            if ($kind !== 'all' && $rowKind !== $kind) {
                continue;
            }

            $schedule = null;
            $overdueCount = 0;
            if (($rowKind === 'installment' || $rowKind === 'split') && $r->installment_mode !== null) {
                $schedule = $this->buildSchedule($r, (int) $r->paid_count, $today);
                $overdueCount = collect($schedule)->where('overdue', true)->count();
            }

            $out[] = [
                'policyId' => (string) $r->id,
                'policyNo' => $r->policy_no,
                'applicationNo' => $r->application_no,
                'status' => $r->status,
                'kind' => $rowKind,
                'customerCode' => $r->customer_code,
                'customerName' => $r->customer_name,
                'customerPhone' => $r->customer_phone,
                'agentCode' => $r->agent_code,
                'agentName' => $r->agent_name,
                'totalDue' => $totalDue,
                'paid' => $paid,
                'outstanding' => $outstanding,
                'installmentMode' => $r->installment_mode,
                'installmentCount' => $r->installment_term !== null ? (int) $r->installment_term : null,
                'paidCount' => (int) $r->paid_count,
                'overdueCount' => $overdueCount,
                'schedule' => $schedule,
                'reminderCount' => (int) $r->reminder_count,
                'lastReminderAt' => $r->last_reminder_at ? Carbon::parse($r->last_reminder_at)->toIso8601String() : null,
                'effectiveDate' => $r->effective_date,
            ];
        }

        return response()->json([
            'data' => $out,
            'meta' => ['count' => count($out)],
        ]);
    }

    /**
     * GET /collections/{policy} — one policy's collection detail: the same
     * computed row as the list, plus the actual payment records and the full
     * reminder history, for a focused money-collection view.
     */
    public function show(Request $request, Policy $policy): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        abort_unless((int) $policy->tenant_id === $tenantId, 404);

        $r = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('p.id', $policy->id)
            ->select([
                'p.id', 'p.policy_no', 'p.application_no', 'p.status',
                'p.main_premium', 'p.compulsory_premium', 'p.total_premium_paid',
                'p.installment_mode', 'p.installment_term',
                'p.effective_date', 'p.first_due_inst_date',
                'c.customer_code', 'c.phone as customer_phone',
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'a.agent_code',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                'ca.name as carrier_name', 'pr.name as product_name',
                DB::raw('(SELECT COALESCE(SUM(pp.amount),0) FROM policy_payments pp WHERE pp.policy_id = p.id) as paid_total'),
                DB::raw('(SELECT COUNT(*) FROM policy_payments pp WHERE pp.policy_id = p.id) as paid_count'),
                DB::raw('(SELECT COUNT(*) FROM payment_reminders pr WHERE pr.policy_id = p.id) as reminder_count'),
                DB::raw('(SELECT MAX(pr.created_at) FROM payment_reminders pr WHERE pr.policy_id = p.id) as last_reminder_at'),
            ])
            ->first();
        if ($r === null) {
            abort(404);
        }

        $row = $this->buildRow($r, Carbon::today());

        // Actual payment records (auditable source of truth).
        $payments = DB::table('policy_payments')
            ->where('policy_id', $policy->id)
            ->orderBy('payment_date')->orderBy('id')
            ->get(['id', 'payment_date', 'amount', 'method', 'reference'])
            ->map(fn ($p): array => [
                'id' => (string) $p->id,
                'paymentDate' => $p->payment_date,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'reference' => $p->reference,
            ]);

        return response()->json([
            'data' => array_merge($row, [
                'carrierName' => $r->carrier_name,
                'productName' => $r->product_name,
                'payments' => $payments,
            ]),
        ]);
    }

    /**
     * Build one collection row from a policies row (shared by index + show).
     * Returns null when the outstanding is under ฿1 (nothing to chase) — the
     * caller decides whether to skip it; show() always returns it.
     *
     * @return array<string, mixed>
     */
    private function buildRow(object $r, Carbon $today): array
    {
        $main = (float) ($r->main_premium ?? 0);
        $compulsory = (float) ($r->compulsory_premium ?? 0);
        $totalDue = round($main + $compulsory, 2);
        $paid = (float) $r->paid_total;
        if ($paid <= 0 && (float) ($r->total_premium_paid ?? 0) > 0) {
            $paid = (float) $r->total_premium_paid;
        }
        $paid = round($paid, 2);
        if ($totalDue <= 0) {
            $totalDue = $paid;
        }
        $outstanding = round($totalDue - $paid, 2);

        $rowKind = $this->classify($r);
        $schedule = null;
        $overdueCount = 0;
        if (($rowKind === 'installment' || $rowKind === 'split') && $r->installment_mode !== null) {
            $schedule = $this->buildSchedule($r, (int) $r->paid_count, $today);
            $overdueCount = collect($schedule)->where('overdue', true)->count();
        }

        return [
            'policyId' => (string) $r->id,
            'policyNo' => $r->policy_no,
            'applicationNo' => $r->application_no,
            'status' => $r->status,
            'kind' => $rowKind,
            'customerCode' => $r->customer_code,
            'customerName' => $r->customer_name,
            'customerPhone' => $r->customer_phone,
            'agentCode' => $r->agent_code,
            'agentName' => $r->agent_name,
            'totalDue' => $totalDue,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'installmentMode' => $r->installment_mode,
            'installmentCount' => $r->installment_term !== null ? (int) $r->installment_term : null,
            'paidCount' => (int) $r->paid_count,
            'overdueCount' => $overdueCount,
            'schedule' => $schedule,
            'reminderCount' => (int) $r->reminder_count,
            'lastReminderAt' => $r->last_reminder_at ? Carbon::parse($r->last_reminder_at)->toIso8601String() : null,
            'effectiveDate' => $r->effective_date,
        ];
    }

    /** cash | installment | split — from installment_mode + payment-event payee. */
    private function classify(object $r): string
    {
        if ($r->installment_mode === null && (int) ($r->installment_term ?? 0) <= 1) {
            return 'cash';
        }
        // Distinguish ผ่อน (paid to InsureHub) from แบ่งชำระ (paid to carrier) by
        // the latest payment event's payee, defaulting to installment.
        $payee = DB::table('policy_events')
            ->where('policy_id', $r->id)
            ->where('type', 'premiumPaid')
            ->orderByDesc('occurred_at')
            ->value(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.payee'))"));

        return $payee === 'carrier' ? 'split' : 'installment';
    }

    /**
     * Per-งวด schedule with overdue flags. งวด 1..paidCount are treated as paid;
     * งวด paidCount+1 is the next one due. Overdue = a due-date has passed and
     * the งวด isn't paid. Due dates step monthly from first_due_inst_date (or
     * effective_date) if present; otherwise งวด beyond paidCount are "due now".
     */
    private function buildSchedule(object $r, int $paidCount, Carbon $today): array
    {
        $count = (int) ($r->installment_term ?? 0);
        $mode = (string) $r->installment_mode;
        $main = (float) ($r->main_premium ?? 0);
        $compulsory = (float) ($r->compulsory_premium ?? 0);
        if ($count < 2 || $count > 10 || $main <= 0) {
            return [];
        }

        $first = $count <= 4 ? $main / $count : $main * self::DOWN_RATE;
        $stdFee = $main * self::FEE_RATE;
        $stdInt = $main * self::INT_RATE * $count;
        $customerFee = in_array($mode, ['B', 'C'], true) ? $stdFee : 0;
        $customerInterest = $mode === 'C' ? $stdInt : 0;
        $inst1 = (int) ceil($first + $compulsory + $customerFee + $customerInterest - 1e-9);
        $rest = (int) ceil(($main - $first) / ($count - 1) - 1e-9);

        $baseDate = $r->first_due_inst_date ?? $r->effective_date;
        $base = $baseDate ? Carbon::parse($baseDate) : null;

        $sched = [];
        for ($i = 1; $i <= $count; $i++) {
            $amount = $i === 1 ? $inst1 : $rest;
            $paid = $i <= $paidCount;
            $dueDate = $base ? $base->copy()->addMonths($i - 1)->toDateString() : null;
            $overdue = ! $paid && ($dueDate !== null ? Carbon::parse($dueDate)->lte($today) : $i <= $paidCount + 1);
            $sched[] = [
                'no' => $i,
                'amount' => $amount,
                'paid' => $paid,
                'dueDate' => $dueDate,
                'overdue' => $overdue,
            ];
        }

        return $sched;
    }

    // ── Reminders (การทวง) ────────────────────────────────────────────────────

    public function reminders(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $rows = PaymentReminder::query()
            ->where('policy_id', $policy->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PaymentReminder $x) => [
                'id' => (string) $x->id,
                'installmentNo' => $x->installment_no,
                'channel' => $x->channel,
                'note' => $x->note,
                'amountDue' => $x->amount_due !== null ? (float) $x->amount_due : null,
                'createdAt' => $x->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function storeReminder(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'installmentNo' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            'channel' => ['sometimes', 'nullable', 'string', 'in:phone,line,email,sms,inperson,other'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'amountDue' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $reminder = PaymentReminder::create([
            'tenant_id' => (int) $policy->tenant_id,
            'policy_id' => $policy->id,
            'installment_no' => $data['installmentNo'] ?? null,
            'channel' => $data['channel'] ?? 'phone',
            'note' => $data['note'] ?? null,
            'amount_due' => $data['amountDue'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => ['id' => (string) $reminder->id]], 201);
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        abort_unless((int) $policy->tenant_id === $tenantId, 404);
    }
}
