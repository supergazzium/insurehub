<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentReminder;
use App\Models\Policy;
use App\Models\PolicyInstallment;
use App\Services\Policy\InstallmentSchedule;
use App\Services\Policy\PremiumDue;
use App\Support\PolicyPeriodLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    private const MAX_ROWS = 500;

    private function rowColumns(): array
    {
        return [
            'p.id', 'p.policy_no', 'p.application_no', 'p.status',
            'p.main_premium', 'p.compulsory_premium', 'p.total_premium_paid', 'p.imported_from_legacy',
            'p.installment_mode', 'p.installment_term',
            'p.effective_date', 'p.first_due_inst_date',
            DB::raw(PremiumDue::PAID_TOTAL_SQL.' as paid_total'),
            DB::raw(PremiumDue::PAID_COUNT_SQL.' as paid_count'),
            // Dunning history is about STAFF follow-ups only — system anniversary
            // alerts (kind='system') are not "chased N times".
            DB::raw("(SELECT COUNT(*) FROM payment_reminders pr WHERE pr.policy_id = p.id AND pr.kind = 'manual') as reminder_count"),
            DB::raw("(SELECT MAX(pr.created_at) FROM payment_reminders pr WHERE pr.policy_id = p.id AND pr.kind = 'manual') as last_reminder_at"),
            DB::raw(PremiumDue::ENDORSEMENT_TOTAL_SQL.' as endorsement_total'),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        $kind = (string) $request->input('kind', 'all'); // all|cash|installment|split
        $search = trim((string) $request->input('q', ''));

        $query = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereIn('p.status', ['active', 'issued'])
            ->where(function ($w): void {
                $w->where('p.imported_from_legacy', false)
                    ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('policy_payments as px')->whereColumn('px.policy_id', 'p.id')->whereNull('px.voided_at'));
            })
            ->when($search !== '', function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where(function ($w) use ($like) {
                    $w->where('p.policy_no', 'like', $like)
                        ->orWhere('p.application_no', 'like', $like)
                        ->orWhereRaw("CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?", [$like])
                        ->orWhere('c.customer_code', 'like', $like);
                });
            })
            ->select(array_merge($this->rowColumns(), [
                'c.customer_code', DB::raw("COALESCE(NULLIF(c.phone, ''), c.tel_phone) as customer_phone"),
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'a.agent_code',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
            ]));

        $today = Carbon::today();
        $out = [];
        $query->chunkByIdDesc(500, function (Collection $rows) use (&$out, $today, $kind): bool {
            $installments = PolicyInstallment::query()
                ->whereIn('policy_id', $rows->pluck('id'))
                ->orderBy('installment_no')
                ->get()
                ->groupBy('policy_id');
            foreach ($rows as $r) {
                $row = $this->buildRow($r, $today, $installments->get($r->id, collect()));
                if ($row['outstanding'] < 1.0) {
                    continue;
                }
                if ($kind !== 'all' && $row['kind'] !== $kind) {
                    continue;
                }
                $out[] = $row;
                if (count($out) >= self::MAX_ROWS) {
                    return false;
                }
            }

            return true;
        }, 'p.id', 'id');

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
            ->select(array_merge($this->rowColumns(), [
                'c.customer_code', DB::raw("COALESCE(NULLIF(c.phone, ''), c.tel_phone) as customer_phone"),
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'a.agent_code',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                'ca.name as carrier_name', 'pr.name as product_name',
            ]))
            ->first();
        if ($r === null) {
            abort(404);
        }

        $row = $this->buildRow($r, Carbon::today(), $policy->installments()->get());
        $row['periodLabel'] = PolicyPeriodLabel::forPolicy($policy);
        if (is_array($row['schedule'])) {
            $total = count($row['schedule']);
            foreach ($row['schedule'] as $k => $s) {
                $row['schedule'][$k]['periodLabel'] = PolicyPeriodLabel::format(
                    (int) $policy->policy_year,
                    PolicyPeriodLabel::endorsementNo($policy->id, $s['dueDate'] ?? null),
                    (int) $s['no'],
                    $total,
                );
            }
        }

        // Actual payment records (auditable source of truth).
        $payments = DB::table('policy_payments')
            ->where('policy_id', $policy->id)
            ->whereNull('voided_at')
            ->orderBy('payment_date')->orderBy('id')
            ->get(['id', 'payment_date', 'amount', 'adjustment_amount', 'method', 'payee', 'reference'])
            ->map(fn ($p): array => [
                'id' => (string) $p->id,
                'paymentDate' => $p->payment_date,
                'amount' => (float) $p->amount,
                'adjustmentAmount' => (float) $p->adjustment_amount,
                'method' => $p->method,
                'payee' => $p->payee,
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
    private function buildRow(object $r, Carbon $today, Collection $installments): array
    {
        $legacy = (bool) ($r->imported_from_legacy ?? false);
        $totalDue = PremiumDue::due(
            (float) ($r->main_premium ?? 0),
            (float) ($r->compulsory_premium ?? 0),
            (float) ($r->total_premium_paid ?? 0),
            $installments,
            (float) ($r->endorsement_total ?? 0),
        );
        $paid = PremiumDue::paid((float) $r->paid_total, (int) $r->paid_count, $legacy, $totalDue);
        if ($totalDue <= 0) {
            $totalDue = $paid;
        }
        $outstanding = round($totalDue - $paid, 2);

        $rowKind = $this->classify($r);
        $schedule = null;
        $overdueCount = 0;
        $paidCount = (int) $r->paid_count;
        $installmentCount = $r->installment_term !== null ? (int) $r->installment_term : null;
        if ($installments->count() > 1) {
            $schedule = $installments->map(fn (PolicyInstallment $i): array => [
                'no' => (int) $i->installment_no,
                'installmentId' => (string) $i->id,
                'amount' => (float) $i->amount_due,
                'amountPaid' => (float) $i->amount_paid,
                'status' => $i->status,
                'paid' => $i->status === PolicyInstallment::STATUS_PAID,
                'dueDate' => $i->due_date?->toDateString(),
                'overdue' => $i->status !== PolicyInstallment::STATUS_PAID && $i->due_date !== null && $i->due_date->lte($today),
            ])->values()->all();
            $overdueCount = collect($schedule)->where('overdue', true)->count();
            $paidCount = $installments->where('status', PolicyInstallment::STATUS_PAID)->count();
            $installmentCount = $installments->count();
        } elseif ($installments->isEmpty() && ($rowKind === 'installment' || $rowKind === 'split') && $r->installment_mode !== null) {
            $schedule = $this->buildSchedule($r, (int) $r->paid_count, $today);
            $overdueCount = collect($schedule)->where('overdue', true)->count();
        }

        $nextDue = null;
        if ($installments->isNotEmpty()) {
            $unpaid = $installments->filter(fn (PolicyInstallment $i) => $i->status !== PolicyInstallment::STATUS_PAID);
            $remaining = fn (PolicyInstallment $i): float => max(0.0, (float) $i->amount_due - (float) $i->amount_paid);
            $dueToDate = $unpaid
                ->filter(fn (PolicyInstallment $i) => $i->due_date !== null && $i->due_date->lte($today))
                ->sum($remaining);
            $next = $unpaid->first();
            if ($next !== null) {
                $nextDue = [
                    'no' => (int) $next->installment_no,
                    'dueDate' => $next->due_date?->toDateString(),
                    'amount' => round($remaining($next), 2),
                ];
            }
        } elseif ($schedule !== null) {
            $dueToDate = collect($schedule)->where('overdue', true)->sum('amount');
        } else {
            $dueToDate = $r->effective_date !== null && Carbon::parse($r->effective_date)->lte($today) ? $outstanding : 0.0;
        }
        $dueToDate = round(min(max(0.0, (float) $dueToDate), max(0.0, $outstanding)), 2);

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
            'legacySettled' => PremiumDue::legacySettled($legacy, (int) $r->paid_count),
            'outstanding' => $outstanding,
            'dueToDate' => $dueToDate,
            'nextDue' => $nextDue,
            'installmentMode' => $r->installment_mode,
            'installmentCount' => $installmentCount,
            'paidCount' => $paidCount,
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

        $amounts = InstallmentSchedule::financePlan($main, $compulsory, $count, $mode);

        $baseDate = $r->first_due_inst_date ?? $r->effective_date;
        $base = $baseDate ? Carbon::parse($baseDate) : null;

        $sched = [];
        for ($i = 1; $i <= $count; $i++) {
            $amount = $amounts[$i - 1];
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
            ->where('kind', 'manual')
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
            'kind' => 'manual',
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
