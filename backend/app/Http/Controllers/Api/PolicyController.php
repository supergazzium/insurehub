<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\PolicyRequest;
use App\Http\Resources\PolicyListResource;
use App\Http\Resources\PolicyResource;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PolicyController extends ApiController
{
    /**
     * Paginated policy list — supports server-side search and filters, and
     * joins in customer/agent/carrier/product display columns so the client
     * needs no follow-up lookups. Returns lean PolicyListResource rows
     * (not the heavy PolicyResource used by show/store/update).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->tenantId($request);

        $q = DB::table('policies as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->leftJoin('policy_statuses as ps', 'ps.id', '=', 'p.legacy_policy_status_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->select([
                'p.id', 'p.quote_no', 'p.application_no', 'p.policy_no',
                'p.customer_id', 'p.product_id', 'p.carrier_id', 'p.writing_agent_id',
                'p.status', 'p.legacy_policy_status_id', 'p.new_or_renew',
                'p.coverage', 'p.annual_premium', 'p.premium_mode',
                'p.app_date', 'p.effective_date', 'p.expiry_date',
                'p.issue_date', 'p.cancel_date',
                'p.freelook_active', 'p.premium_check',
                'c.customer_code',
                'c.first_name as customer_first_name',
                'c.last_name as customer_last_name',
                'a.agent_code',
                'a.first_name as agent_first_name',
                'a.last_name as agent_last_name',
                'ca.code as carrier_code',
                'ca.name as carrier_name',
                'pr.code as product_code',
                'pr.name as product_name',
                'ps.name_th as status_label',
                'ps.group_name_th as status_group',
            ]);

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('p.policy_no', 'like', $like)
                    ->orWhere('p.application_no', 'like', $like)
                    ->orWhere('p.quote_no', 'like', $like)
                    ->orWhere('c.customer_code', 'like', $like)
                    ->orWhere('c.first_name', 'like', $like)
                    ->orWhere('c.last_name', 'like', $like)
                    ->orWhere('a.agent_code', 'like', $like);
            });
        }
        if ($status = $request->input('status')) {
            $q->where('p.status', $status);
        }
        if ($customerId = $request->input('customerId')) {
            $q->where('p.customer_id', $customerId);
        }
        if ($writingAgentId = $request->input('writingAgentId')) {
            $q->where('p.writing_agent_id', $writingAgentId);
        }
        if ($carrierId = $request->input('carrierId')) {
            $q->where('p.carrier_id', $carrierId);
        }
        if ($productId = $request->input('productId')) {
            $q->where('p.product_id', $productId);
        }
        if ($newOrRenew = $request->input('newOrRenew')) {
            $q->where('p.new_or_renew', $newOrRenew);
        }
        if ($from = $request->input('fromDate')) {
            $q->where('p.effective_date', '>=', $from);
        }
        if ($to = $request->input('toDate')) {
            $q->where('p.effective_date', '<=', $to);
        }

        $paginator = $q->orderBy('p.id', 'desc')->paginate($this->perPage($request));

        return PolicyListResource::collection($paginator);
    }

    public function store(PolicyRequest $request): JsonResponse
    {
        $policy = DB::transaction(function () use ($request) {
            $payload = $request->toModel() + ['tenant_id' => $this->tenantId($request)];
            $policy = Policy::create($payload);
            $this->syncChildren($request, $policy);
            return $policy->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus']);
        });
        return (new PolicyResource($policy))->response()->setStatusCode(201);
    }

    public function show(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        return new PolicyResource(
            $policy->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus'])
        );
    }

    public function update(PolicyRequest $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        DB::transaction(function () use ($request, $policy): void {
            $policy->update($request->toModel());
            $this->syncChildren($request, $policy);
        });
        return new PolicyResource(
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus'])
        );
    }

    public function destroy(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $policy->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // ── Phase 6: sectioned edit ──────────────────────────────────────────

    /**
     * Fields protected once a policy reaches `issued` or later. These are
     * the core commercial terms — customer, product, premium — which should
     * only change through the endorsement flow (Phase 9), not silent edits.
     *
     * Access lets you edit any field at any status; the new system takes the
     * stricter path so the audit trail reflects real commercial history.
     */
    private const LOCKED_AFTER_ISSUED = [
        'customer_id', 'product_id', 'carrier_id', 'writing_agent_id',
        'net_premium', 'main_premium', 'vat', 'duty_stamp', 'total_premium_paid',
        'coverage', 'effective_date', 'expiry_date',
    ];

    private const LOCK_TRIGGER_STATUSES = ['issued', 'active', 'lapsed', 'cancelled', 'reinstated', 'expired'];

    /**
     * Per-section update maps: request key (camelCase) → DB column (snake_case).
     * Whitelist per section — anything outside its map is silently dropped
     * so a stray key can't sneak through (mirrors MeAgentController's shape).
     *
     * @var array<string, array<string, string>>
     */
    private const SECTION_MAPS = [
        'dates' => [
            'effectiveDate' => 'effective_date',
            'expiryDate' => 'expiry_date',
            'policyEnd' => 'policy_end',
            'periodPaidEnd' => 'period_paid_end',
            'mailingDate' => 'mailing_date',
            'appDate' => 'app_date',
            'policyYear' => 'policy_year',
            'actYear' => 'act_year',
            'newOrRenew' => 'new_or_renew',
        ],
        'premium' => [
            'netPremium' => 'net_premium',
            'mainPremium' => 'main_premium',
            'dutyStamp' => 'duty_stamp',
            'vat' => 'vat',
            'totalPremiumPaid' => 'total_premium_paid',
            'annualPremium' => 'annual_premium',
            'coverage' => 'coverage',
            'creditCardFee' => 'credit_card_fee',
            'discountAmount' => 'discount_amount',
            'whtAmt' => 'wht_amt',
            'whtStatus' => 'wht_status',
            'frontEndFee' => 'front_end_fee',
        ],
        'payment' => [
            'paymentMethodId' => 'payment_method_id',
            'typeOfPaid' => 'type_of_paid',
            'typeOfPaidNote' => 'type_of_paid_note',
            'financeCompany' => 'finance_company',
            'installmentTerm' => 'installment_term',
            'firstDueInst' => 'first_due_inst',
            'nextDueInst' => 'next_due_inst',
            'firstDueInstDate' => 'first_due_inst_date',
            'lastDueInstDate' => 'last_due_inst_date',
            'premiumMode' => 'premium_mode',
            'subsidiseFromAgent' => 'subsidise_from_agent',
            'subsidiseToFinance' => 'subsidise_to_finance',
        ],
        'notes' => [
            'internalNote' => 'internal_note',
            'mailingNote' => 'mailing_note',
            'statusNote' => 'status_note',
        ],
        'identifiers' => [
            'policyNo' => 'policy_no',
            'notionNo' => 'notion_no',
        ],
        // Phase 6b — motor-specific vehicle fields (all under the motor_* prefix).
        'motor' => [
            'motorTypeVehicle' => 'motor_type_vehicle',
            'motorTypeDriver' => 'motor_type_driver',
            'motorVehicleBrand' => 'motor_vehicle_brand',
            'motorVehicleModel' => 'motor_vehicle_model',
            'motorLicenseNo' => 'motor_license_no',
            'motorEngineNo' => 'motor_engine_no',
            'motorChassisNo' => 'motor_chassis_no',
            'motorRegisterYear' => 'motor_register_year',
            'motorNoPassenger' => 'motor_no_passenger',
            'motorNotes' => 'motor_notes',
        ],
        // Phase 9b — main-policy commission entry (per-rider commissions live
        // on policy_riders and use the /riders sync endpoint).
        'commission' => [
            'mainComRateInh' => 'main_com_rate_inh',
            'mainComAmtInh' => 'main_com_amt_inh',
            'mainComRateAg' => 'main_com_rate_ag',
            'mainComAmtAg' => 'main_com_amt_ag',
            'comRecCheck' => 'com_rec_check',
        ],
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private const SECTION_RULES = [
        'dates' => [
            'effectiveDate' => ['sometimes', 'nullable', 'date'],
            'expiryDate' => ['sometimes', 'nullable', 'date'],
            'policyEnd' => ['sometimes', 'nullable', 'date'],
            'periodPaidEnd' => ['sometimes', 'nullable', 'date'],
            'mailingDate' => ['sometimes', 'nullable', 'date'],
            'appDate' => ['sometimes', 'nullable', 'date'],
            'policyYear' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'actYear' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'newOrRenew' => ['sometimes', 'nullable', 'string', 'in:new,renew'],
        ],
        'premium' => [
            'netPremium' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'mainPremium' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'dutyStamp' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'vat' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'totalPremiumPaid' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'annualPremium' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'coverage' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'creditCardFee' => ['sometimes', 'nullable', 'numeric'],
            'discountAmount' => ['sometimes', 'nullable', 'numeric'],
            'whtAmt' => ['sometimes', 'nullable', 'numeric'],
            'whtStatus' => ['sometimes', 'nullable', 'string', 'max:32'],
            'frontEndFee' => ['sometimes', 'nullable', 'numeric'],
        ],
        'payment' => [
            'paymentMethodId' => ['sometimes', 'nullable', 'integer'],
            'typeOfPaid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'typeOfPaidNote' => ['sometimes', 'nullable', 'string', 'max:255'],
            'financeCompany' => ['sometimes', 'nullable', 'string', 'max:128'],
            'installmentTerm' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            'firstDueInst' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'nextDueInst' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'firstDueInstDate' => ['sometimes', 'nullable', 'date'],
            'lastDueInstDate' => ['sometimes', 'nullable', 'date'],
            'premiumMode' => ['sometimes', 'nullable', 'string', 'in:monthly,quarterly,semiannual,annual,single'],
            'subsidiseFromAgent' => ['sometimes', 'nullable', 'numeric'],
            'subsidiseToFinance' => ['sometimes', 'nullable', 'numeric'],
        ],
        'notes' => [
            'internalNote' => ['sometimes', 'nullable', 'string'],
            'mailingNote' => ['sometimes', 'nullable', 'string'],
            'statusNote' => ['sometimes', 'nullable', 'string'],
        ],
        'identifiers' => [
            'policyNo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notionNo' => ['sometimes', 'nullable', 'string', 'max:64'],
        ],
        'motor' => [
            'motorTypeVehicle' => ['sometimes', 'nullable', 'string', 'max:64'],
            'motorTypeDriver' => ['sometimes', 'nullable', 'string', 'max:64'],
            'motorVehicleBrand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'motorVehicleModel' => ['sometimes', 'nullable', 'string', 'max:255'],
            'motorLicenseNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'motorEngineNo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'motorChassisNo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'motorRegisterYear' => ['sometimes', 'nullable', 'string', 'max:8'],
            'motorNoPassenger' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
            'motorNotes' => ['sometimes', 'nullable', 'string'],
        ],
        'commission' => [
            // Rates stored as decimal (0.12 = 12%). Accept 0..1.
            'mainComRateInh' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'mainComAmtInh' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'mainComRateAg' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'mainComAmtAg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'comRecCheck' => ['sometimes', 'nullable', 'string', 'in:Complete,Pending,'],
        ],
    ];

    public function patchSection(Request $request, Policy $policy, string $section): PolicyResource
    {
        $this->authorizeTenant($request, $policy);

        $rules = self::SECTION_RULES[$section] ?? abort(404, "Unknown section: {$section}");
        $map = self::SECTION_MAPS[$section];

        $v = \Illuminate\Support\Facades\Validator::make($request->all(), $rules)->validate();

        $updates = [];
        foreach ($map as $camel => $snake) {
            if (! array_key_exists($camel, $v)) {
                continue;
            }
            // Q4 lock — reject writes to locked columns once the policy is in force.
            if (in_array($policy->status, self::LOCK_TRIGGER_STATUSES, true)
                && in_array($snake, self::LOCKED_AFTER_ISSUED, true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $camel => ["Field is locked because policy status is '{$policy->status}'. Use an endorsement to change it."],
                ]);
            }
            $updates[$snake] = $v[$camel];
        }

        if ($updates !== []) {
            $policy->update($updates);
        }

        return new PolicyResource(
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus'])
        );
    }

    /**
     * Phase 6b — sync riders (replace-all shape). Client sends the full
     * desired list; server deletes existing + inserts fresh. Simpler than
     * per-row diff, matches the syncChildren pattern already used by the
     * giant PolicyRequest.
     */
    public function syncRiders(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'riders' => ['present', 'array'],
            'riders.*.name' => ['required', 'string', 'max:255'],
            'riders.*.premium' => ['required', 'numeric', 'min:0'],
            'riders.*.slot' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20'],
            'riders.*.productId' => ['sometimes', 'nullable', 'integer'],
            'riders.*.comRateInh' => ['sometimes', 'nullable', 'numeric'],
            'riders.*.comRateAg' => ['sometimes', 'nullable', 'numeric'],
            'riders.*.comAmtInh' => ['sometimes', 'nullable', 'numeric'],
            'riders.*.comAmtAg' => ['sometimes', 'nullable', 'numeric'],
            'riders.*.notes' => ['sometimes', 'nullable', 'string'],
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($policy, $data): void {
            $policy->riders()->delete();
            foreach ($data['riders'] as $i => $r) {
                $policy->riders()->create([
                    'slot' => $r['slot'] ?? ($i + 1),
                    'product_id' => $r['productId'] ?? null,
                    'name' => $r['name'],
                    'premium' => $r['premium'],
                    'com_rate_inh' => $r['comRateInh'] ?? null,
                    'com_rate_ag' => $r['comRateAg'] ?? null,
                    'com_amt_inh' => $r['comAmtInh'] ?? null,
                    'com_amt_ag' => $r['comAmtAg'] ?? null,
                    'notes' => $r['notes'] ?? null,
                ]);
            }
        });

        return new PolicyResource(
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus'])
        );
    }

    /**
     * Phase 6b — sync beneficiaries (replace-all shape). Shares must sum to
     * ≤100 (Access allowed >100 with warnings — we enforce it here so the
     * data is legally sound).
     */
    public function syncBeneficiaries(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'beneficiaries' => ['present', 'array'],
            'beneficiaries.*.name' => ['required', 'string', 'max:255'],
            'beneficiaries.*.relation' => ['sometimes', 'nullable', 'string', 'max:32'],
            'beneficiaries.*.share' => ['required', 'numeric', 'min:0', 'max:100'],
            'beneficiaries.*.slot' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20'],
        ]);

        $totalShare = array_sum(array_column($data['beneficiaries'], 'share'));
        if ($totalShare > 100.001) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'beneficiaries' => ["Beneficiary shares must sum to 100 or less (got {$totalShare})."],
            ]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($policy, $data): void {
            $policy->beneficiaries()->delete();
            foreach ($data['beneficiaries'] as $i => $b) {
                $policy->beneficiaries()->create([
                    'slot' => $b['slot'] ?? ($i + 1),
                    'name' => $b['name'],
                    'relation' => $b['relation'] ?? null,
                    'share' => $b['share'],
                ]);
            }
        });

        return new PolicyResource(
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus'])
        );
    }

    private function syncChildren(PolicyRequest $request, Policy $policy): void
    {
        $data = $request->validated();
        if (array_key_exists('riders', $data)) {
            $policy->riders()->delete();
            foreach ($data['riders'] as $r) {
                $policy->riders()->create([
                    'name' => $r['name'],
                    'premium' => $r['premium'],
                    'slot' => $r['slot'] ?? null,
                    'product_id' => $r['productId'] ?? null,
                    'com_rate_inh' => $r['comRateInh'] ?? null,
                    'com_rate_ag' => $r['comRateAg'] ?? null,
                    'com_amt_inh' => $r['comAmtInh'] ?? null,
                    'com_amt_ag' => $r['comAmtAg'] ?? null,
                    'notes' => $r['notes'] ?? null,
                ]);
            }
        }
        if (array_key_exists('beneficiaries', $data)) {
            $policy->beneficiaries()->delete();
            foreach ($data['beneficiaries'] as $b) {
                $policy->beneficiaries()->create([
                    'name' => $b['name'],
                    'relation' => $b['relation'] ?? null,
                    'share' => $b['share'],
                    'slot' => $b['slot'] ?? null,
                ]);
            }
        }
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    // ── Phase 8b — renewal actions ───────────────────────────────────────

    /**
     * Log a "renewal contacted" event on the policy. No status change on
     * the policy itself — this is a lightweight touchpoint marker. The
     * renewal pipeline UI computes "last contacted N days ago" from the
     * most recent event of this type.
     */
    public function markRenewalContacted(Request $request, Policy $policy): \Illuminate\Http\JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'channel' => ['sometimes', 'nullable', 'string', 'in:phone,line,email,inperson,other'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $event = \App\Models\PolicyEvent::create([
            'policy_id' => $policy->id,
            'type' => 'renewalContacted',
            'occurred_at' => now(),
            'by_user_id' => $request->user()->id,
            'payload' => [
                'channel' => $data['channel'] ?? 'other',
                'note' => $data['note'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'Contact logged.',
            'event' => [
                'id' => (string) $event->id,
                'type' => $event->type,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
                'payload' => $event->payload,
            ],
        ]);
    }

    /**
     * Mark policy as "we're renewing this" — log the event, and (optionally)
     * return a hint payload the frontend uses to jump to /quotes/new with the
     * customer/product pre-filled. Sets `ref_app_to_id` if a new_policy_id is
     * provided (rare — usually the new quote gets created and links itself).
     */
    public function markRenewalStarted(Request $request, Policy $policy): \Illuminate\Http\JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        \App\Models\PolicyEvent::create([
            'policy_id' => $policy->id,
            'type' => 'renewalStarted',
            'occurred_at' => now(),
            'by_user_id' => $request->user()->id,
            'payload' => [
                'oldPolicyNo' => $policy->policy_no,
                'expiryDate' => $policy->expiry_date?->toDateString(),
            ],
        ]);

        return response()->json([
            'message' => 'Renewal started.',
            'quoteHint' => [
                'customerId' => $policy->customer_id !== null ? (string) $policy->customer_id : null,
                'productId' => $policy->product_id !== null ? (string) $policy->product_id : null,
                'carrierId' => $policy->carrier_id !== null ? (string) $policy->carrier_id : null,
                'writingAgentId' => $policy->writing_agent_id !== null ? (string) $policy->writing_agent_id : null,
                'refAppToId' => (string) $policy->id,
                'newOrRenew' => 'renew',
            ],
        ]);
    }

    /**
     * Send the renewal notice email. Falls back to the writing agent when the
     * customer has no email. Refuses if there's nowhere to send.
     */
    public function sendRenewalNotice(Request $request, Policy $policy): \Illuminate\Http\JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $policy->loadMissing(['customer', 'writingAgent']);

        $customerEmail = $policy->customer?->email;
        $agentEmail = $policy->writingAgent?->email;
        $sentToAgent = false;
        $to = null;

        if (! empty($customerEmail)) {
            $to = $customerEmail;
        } elseif (! empty($agentEmail)) {
            $to = $agentEmail;
            $sentToAgent = true;
        } else {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['Neither the customer nor the writing agent has an email on file.'],
            ]);
        }

        try {
            \Illuminate\Support\Facades\Mail::to($to)
                ->send(new \App\Mail\PolicyRenewalNoticeMail($policy, $sentToAgent));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Renewal notice send failed', [
                'policy_id' => $policy->id, 'to' => $to, 'error' => $e->getMessage(),
            ]);
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['Failed to send email: '.$e->getMessage()],
            ]);
        }

        \App\Models\PolicyEvent::create([
            'policy_id' => $policy->id,
            'type' => 'renewalNoticeSent',
            'occurred_at' => now(),
            'by_user_id' => $request->user()->id,
            'payload' => [
                'sentTo' => $to,
                'sentToAgent' => $sentToAgent,
            ],
        ]);

        return response()->json([
            'message' => $sentToAgent
                ? 'Notice sent to the agent (customer has no email on file).'
                : 'Notice sent to the customer.',
            'sentTo' => $to,
            'sentToAgent' => $sentToAgent,
        ]);
    }

    /**
     * Recompute all commission accruals for this policy at current rates.
     * Reverses every existing txn, then re-accrues each payment. Result
     * always nets to the *new* rate; the full ledger preserves history.
     */
    public function recomputeCommission(Request $request, Policy $policy): \Illuminate\Http\JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        // Refuse if any of this policy's txns are already settled (part of a
        // paid payout) — reversing those would break the agent's bank record.
        // Admin must void the payout first.
        $settledCount = \DB::table('commission_transactions')
            ->where('policy_id', $policy->id)
            ->where('status', 'settled')
            ->count();
        if ($settledCount > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'settled' => ["Cannot recompute — {$settledCount} txn(s) are already settled to a payout. Void the payout first."],
            ]);
        }

        $result = app(\App\Services\Commission\CommissionEngine::class)->recomputeForPolicy($policy);

        \App\Models\AuditEntry::create([
            'tenant_id' => $policy->tenant_id,
            'user_id' => $request->user()->id,
            'occurred_at' => now(),
            'actor' => $request->user()->name,
            'action' => 'commission.recomputed',
            'target' => 'policy:'.$policy->id,
            'ip' => $request->ip(),
            'result' => 'success',
            'metadata' => $result,
        ]);

        return response()->json([
            'message' => "Recomputed at current rates. {$result['reversed']} reversed, {$result['created']} fresh txns created.",
            ...$result,
        ]);
    }
}
