<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\PolicyRequest;
use App\Http\Resources\PolicyListResource;
use App\Http\Resources\PolicyResource;
use App\Mail\PolicyRenewalNoticeMail;
use App\Models\Policy;
use App\Services\Commission\CommissionBandCoverage;
use App\Models\PolicyEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
            // Prefer join on the current status code (10-code enum after
            // C-1/C-2) with a fallback to legacy_policy_status_id for the
            // 7 pre-existing rows where the code column is NULL. Post-C-20
            // we drop legacy_policy_status_id and simplify this to the
            // code-only join.
            ->leftJoin('policy_statuses as ps', function ($j) {
                $j->on('ps.code', '=', 'p.status')
                    ->orOn('ps.id', '=', 'p.legacy_policy_status_id');
            })
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
                // Motor columns surfaced on the list so the UI can show
                // license plate + brand/model without an N+1 detail fetch.
                'p.motor_license_no', 'p.motor_vehicle_brand', 'p.motor_vehicle_model',
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
                // Identifiers
                $w->where('p.policy_no', 'like', $like)
                    ->orWhere('p.application_no', 'like', $like)
                    ->orWhere('p.quote_no', 'like', $like)
                    // Customer identity + contact
                    ->orWhere('c.customer_code', 'like', $like)
                    ->orWhere('c.first_name', 'like', $like)
                    ->orWhere('c.last_name', 'like', $like)
                    ->orWhere('c.id_card', 'like', $like)
                    ->orWhere('c.passport', 'like', $like)
                    ->orWhere('c.phone', 'like', $like)
                    ->orWhere('c.tel_phone', 'like', $like)
                    ->orWhere('c.contact_phone', 'like', $like)
                    // Agent + motor plate
                    ->orWhere('a.agent_code', 'like', $like)
                    ->orWhere('p.motor_license_no', 'like', $like);
            });
        }
        // Customer type filter (individual / corporate / foreign)
        if ($customerType = $request->input('customerType')) {
            $q->where('c.customer_type', $customerType);
        }
        // Insurance type filter (life / non-life / tax) via the joined carrier row.
        if ($insureType = $request->input('insureType')) {
            $q->where('ca.insure_type', $insureType);
        }
        // Create date range filter (distinct from effective-date range).
        if ($createdFrom = $request->input('createdFrom')) {
            $q->where('p.created_at', '>=', $createdFrom.' 00:00:00');
        }
        if ($createdTo = $request->input('createdTo')) {
            $q->where('p.created_at', '<=', $createdTo.' 23:59:59');
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

            return $policy->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType']);
        });

        return $this->withBandWarning(new PolicyResource($policy))->response()->setStatusCode(201);
    }

    public function show(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);

        return new PolicyResource(
            $policy->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType'])
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
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType'])
        );
    }

    public function destroy(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        // C-11: only drafts are truly deletable. Non-draft rows go through
        // the Cancel workflow so the audit trail and refund pipeline stay
        // authoritative. Prevents accidental delete of an in-force policy.
        if ($policy->status !== 'draft') {
            abort(response()->json([
                'code' => 'delete_non_draft',
                'message' => 'Only draft policies can be deleted. Cancel this policy instead.',
                'currentStatus' => $policy->status,
            ], 409));
        }

        $policy->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    // ── C-11: Draft endpoints ─────────────────────────────────────────────
    //
    // The 5-step wizard (C-14) needs a way to auto-save partial state without
    // consuming a quote_no or application_no serial. These endpoints back
    // the wizard's "Save draft" action + the resume-from-drafts flow. See
    // docs/audit-2026-08-21/B3-wizard-ia.md §7.

    /**
     * POST /policies/draft — permissive create for the wizard's auto-save.
     *
     * Any subset of PolicyRequest fields accepted; status defaults to
     * 'draft'. Emits a draftCreated PolicyEvent so the audit trail records
     * the wizard opening. Does NOT mint quote_no / application_no —
     * promote endpoints below do that when the operator commits.
     */
    public function storeDraft(PolicyRequest $request): JsonResponse
    {
        $policy = DB::transaction(function () use ($request) {
            $payload = $request->toModel() + [
                'tenant_id' => $this->tenantId($request),
                'status' => 'draft',
                'writing_agent_id' => $request->input('writingAgentId')
                    ?? $request->user()?->agent_id
                    ?? null,
            ];
            $policy = Policy::create($payload);
            $this->syncChildren($request, $policy);
            \App\Models\PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => 'draftCreated',
                'occurred_at' => now(),
                'by_user_id' => $request->user()?->id,
                'payload' => ['source' => 'wizard'],
            ]);

            return $policy->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType']);
        });

        return $this->withBandWarning(new PolicyResource($policy))->response()->setStatusCode(201);
    }

    /**
     * PATCH /policies/{policy}/draft — permissive update for the wizard's
     * auto-save. 409 if the row already promoted past draft (racing wizard
     * autosave vs a manual promote button).
     */
    public function updateDraft(PolicyRequest $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        if ($policy->status !== 'draft') {
            abort(response()->json([
                'code' => 'not_draft',
                'message' => 'Only draft policies can be updated via /draft.',
                'currentStatus' => $policy->status,
            ], 409));
        }
        DB::transaction(function () use ($request, $policy): void {
            $policy->update($request->toModel());
            $this->syncChildren($request, $policy);
        });

        return new PolicyResource(
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType'])
        );
    }

    /**
     * POST /policies/{policy}/promote-to-quotation — flips draft → quotation
     * and mints a quote_no via the shared PolicyNumbering allocator.
     *
     * Emits a quotationMinted event so the audit trail records who
     * committed the draft. The state-machine matrix (B1 §2) rejects any
     * other source state at this transition.
     */
    public function promoteToQuotation(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        if ($policy->status !== 'draft') {
            abort(response()->json([
                'code' => 'invalid_transition',
                'message' => 'Only draft policies can promote to quotation.',
                'currentStatus' => $policy->status,
            ], 409));
        }

        $quoteNo = \App\Support\PolicyNumbering::nextQuoteNo($policy->tenant_id);

        DB::transaction(function () use ($policy, $quoteNo, $request): void {
            $policy->update([
                'status' => 'quotation',
                'quote_no' => $quoteNo,
                'quote_date' => now()->toDateString(),
            ]);
            \App\Models\PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => 'quotationMinted',
                'occurred_at' => now(),
                'by_user_id' => $request->user()?->id,
                'payload' => ['quoteNo' => $quoteNo, 'source' => 'wizard'],
            ]);
        });

        return new PolicyResource(
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType'])
        );
    }

    /**
     * POST /policies/{policy}/promote-to-submitted — flips draft → submitted
     * OR quotation → submitted (state machine handles both source states).
     * Mints application_no via the shared allocator.
     *
     * The wizard's "Submit to carrier" button hits this on both the draft
     * short-path and the two-step draft → quotation → submit path.
     */
    public function promoteToSubmitted(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        if (! in_array($policy->status, ['draft', 'quotation', 'quote'], true)) {
            abort(response()->json([
                'code' => 'invalid_transition',
                'message' => 'Only draft or quotation policies can promote to submitted.',
                'currentStatus' => $policy->status,
                'allowedFrom' => ['draft', 'quotation'],
            ], 409));
        }

        // C-23: for banded products, refuse to finalize a policy whose insured
        // age / sum-assured falls outside every RATED commission band — that
        // would silently accrue zero agent commission. Operator can override
        // with ?allowNoCommission=1 after a conscious decision.
        if (! $request->boolean('allowNoCommission')) {
            $cov = CommissionBandCoverage::check($policy->loadMissing('product'));
            if ($cov['banded'] && ! $cov['covered']) {
                abort(response()->json([
                    'code' => 'commission_band_gap',
                    'message' => 'ไม่พบอัตราค่าคอมมิชชั่นสำหรับกรมธรรม์นี้ — '.$cov['reason'],
                    'reason' => $cov['reason'],
                    'entryAge' => $cov['entryAge'],
                    'sumAssured' => $cov['sumAssured'],
                    'overridable' => true,
                ], 422));
            }
        }

        $applicationNo = $policy->application_no
            ?? \App\Support\PolicyNumbering::nextApplicationNo($policy->tenant_id);
        $appDate = now()->toDateString();
        $verb = $policy->status === 'draft' ? 'submittedFromDraft' : 'convertedToApplication';

        DB::transaction(function () use ($policy, $applicationNo, $appDate, $verb, $request): void {
            $policy->update([
                'status' => 'submitted',
                'application_no' => $applicationNo,
                'app_date' => $appDate,
            ]);
            \App\Models\PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => $verb,
                'occurred_at' => now(),
                'by_user_id' => $request->user()?->id,
                'payload' => ['applicationNo' => $applicationNo, 'appDate' => $appDate, 'source' => 'wizard'],
            ]);
        });

        return new PolicyResource(
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType'])
        );
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

    // Statuses that gate LOCKED_AFTER_ISSUED fields (see PATCH section
    // handlers). Per B1 §3: add `approved` + `rejected`; retire
    // `reinstated`. Approved locks financial fields; issued/active/
    // terminal states lock everything except notes + mailing.
    private const LOCK_TRIGGER_STATUSES = ['approved', 'issued', 'active', 'expired', 'cancelled', 'rejected', 'lapsed'];

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
    ];

    public function patchSection(Request $request, Policy $policy, string $section): PolicyResource
    {
        $this->authorizeTenant($request, $policy);

        $rules = self::SECTION_RULES[$section] ?? abort(404, "Unknown section: {$section}");
        $map = self::SECTION_MAPS[$section];

        $v = Validator::make($request->all(), $rules)->validate();

        $updates = [];
        foreach ($map as $camel => $snake) {
            if (! array_key_exists($camel, $v)) {
                continue;
            }
            // Q4 lock — reject writes to locked columns once the policy is in force.
            if (in_array($policy->status, self::LOCK_TRIGGER_STATUSES, true)
                && in_array($snake, self::LOCKED_AFTER_ISSUED, true)) {
                throw ValidationException::withMessages([
                    $camel => ["Field is locked because policy status is '{$policy->status}'. Use an endorsement to change it."],
                ]);
            }
            $updates[$snake] = $v[$camel];
        }

        if ($updates !== []) {
            $policy->update($updates);
        }

        return new PolicyResource(
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType'])
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

        DB::transaction(function () use ($policy, $data): void {
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
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType'])
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
            throw ValidationException::withMessages([
                'beneficiaries' => ["Beneficiary shares must sum to 100 or less (got {$totalShare})."],
            ]);
        }

        DB::transaction(function () use ($policy, $data): void {
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
            $policy->fresh()->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType'])
        );
    }

    /**
     * C-23: attach a NON-blocking commission-band warning to a policy resource
     * response (used on draft/create). The wizard reads
     * `commissionBandWarning` to alert the operator that this policy currently
     * resolves to no agent commission — without blocking the save. Finalize
     * (promoteToSubmitted) turns the same gap into a hard 422.
     */
    private function withBandWarning(PolicyResource $resource): PolicyResource
    {
        $cov = CommissionBandCoverage::check($resource->resource->loadMissing('product'));
        if ($cov['banded'] && ! $cov['covered']) {
            $resource->additional(['commissionBandWarning' => [
                'reason' => $cov['reason'],
                'entryAge' => $cov['entryAge'],
                'sumAssured' => $cov['sumAssured'],
            ]]);
        }

        return $resource;
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
    public function markRenewalContacted(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'channel' => ['sometimes', 'nullable', 'string', 'in:phone,line,email,inperson,other'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $event = PolicyEvent::create([
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
    public function markRenewalStarted(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        PolicyEvent::create([
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
    public function sendRenewalNotice(Request $request, Policy $policy): JsonResponse
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
            throw ValidationException::withMessages([
                'email' => ['Neither the customer nor the writing agent has an email on file.'],
            ]);
        }

        try {
            Mail::to($to)
                ->send(new PolicyRenewalNoticeMail($policy, $sentToAgent));
        } catch (\Throwable $e) {
            Log::warning('Renewal notice send failed', [
                'policy_id' => $policy->id, 'to' => $to, 'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['Failed to send email: '.$e->getMessage()],
            ]);
        }

        PolicyEvent::create([
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
}
