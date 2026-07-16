<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\PolicyResource;
use App\Models\MotorActTariff;
use App\Models\Policy;
use App\Services\Insurance\PremiumCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Quotation module (Phase 5). All new policies begin as a quote — this
 * controller manages the quote lifecycle up to conversion:
 *
 *   POST /quotes                 create a new quote (status='quote')
 *   GET  /quotes                 list quotes for the current tenant
 *   GET  /quotes/{policy}        show one quote
 *   PATCH /quotes/{policy}       update a draft quote
 *   POST /quotes/{policy}/convert  flip quote → application, assign
 *                                    application_no, keep the quote_no
 *
 * Supporting endpoints:
 *   GET  /quotes/act-tariffs           list compulsory-motor tariffs
 *   POST /quotes/premium/preview       stateless calc for the frontend widget
 */
class QuoteController extends ApiController
{
    // ── Lifecycle ────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(Policy::query(), $request)
            ->where('status', 'quote')
            ->whereNull('deleted_at');

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where(function ($w) use ($like): void {
                $w->where('quote_no', 'like', $like)
                    ->orWhere('policy_no', 'like', $like);
            });
        }
        if ($agentId = $request->input('agentId')) {
            $q->where('writing_agent_id', $agentId);
        }
        return PolicyResource::collection($q->orderByDesc('quote_date')->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        // writing_agent_id is NOT NULL on the policies table. Agents create
        // quotes for themselves; admins/staff must specify writingAgentId.
        $writingAgentId = $request->user()->agent_id ?? ($data['writingAgentId'] ?? null);
        if ($writingAgentId === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'writingAgentId' => ['Writing agent is required when creating a quote as an admin.'],
            ]);
        }

        $payload = $this->toDbShape($data) + [
            'tenant_id' => $this->tenantId($request),
            'status' => 'quote',
            'quote_no' => $this->nextQuoteNo($this->tenantId($request)),
            'quote_date' => now()->toDateString(),
            'writing_agent_id' => $writingAgentId,
        ];

        $policy = Policy::create($payload);
        return (new PolicyResource($policy->fresh()))->response()->setStatusCode(201);
    }

    public function show(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        return new PolicyResource($policy);
    }

    public function update(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        if ($policy->status !== 'quote') {
            abort(409, 'This policy is no longer a quote and cannot be edited via /quotes.');
        }
        $data = $this->validated($request);
        $policy->update($this->toDbShape($data));
        return new PolicyResource($policy->fresh());
    }

    /**
     * Convert quote → application. Assigns application_no if missing;
     * keeps quote_no so the trail from quote to policy is traceable.
     */
    public function convert(Request $request, Policy $policy): PolicyResource
    {
        $this->authorizeTenant($request, $policy);
        if ($policy->status !== 'quote') {
            abort(409, 'Only quotes can be converted to applications.');
        }

        $policy->update([
            'status' => 'application',
            'application_no' => $policy->application_no ?? $this->nextApplicationNo($policy->tenant_id),
            'app_date' => now()->toDateString(),
        ]);

        return new PolicyResource($policy->fresh());
    }

    // ── Supporting endpoints ─────────────────────────────────────────────

    public function actTariffs(): JsonResponse
    {
        $rows = MotorActTariff::query()->where('active', true)->orderBy('vehicle_type_code')->get();
        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => (string) $r->id,
                'vehicleTypeCode' => $r->vehicle_type_code,
                'labelTh' => $r->label_th,
                'labelEn' => $r->label_en,
                'premium' => (float) $r->premium,
            ]),
        ]);
    }

    /**
     * Stateless premium calculator — the frontend widget hits this on any
     * input change. Returns net premium + duty stamp + VAT + total for the
     * chosen mode.
     */
    public function premiumPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:iterativeVat,vatInclusive,fixedDuty,bare'],
            'netPremium' => ['sometimes', 'numeric', 'min:0'],
            'totalPaid' => ['sometimes', 'numeric', 'min:0'],
            'fixedDuty' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $result = match ($data['mode']) {
            'iterativeVat' => PremiumCalculator::iterativeVat((float) ($data['netPremium'] ?? 0)),
            'vatInclusive' => PremiumCalculator::vatInclusive((float) ($data['totalPaid'] ?? 0)),
            'fixedDuty' => PremiumCalculator::fixedDuty((float) ($data['netPremium'] ?? 0), (float) ($data['fixedDuty'] ?? 20)),
            'bare' => PremiumCalculator::bare((float) ($data['netPremium'] ?? 0)),
        };

        return response()->json($result);
    }

    // ── Internal helpers ─────────────────────────────────────────────────

    /**
     * Whitelist + validate the quote payload. Kept narrow — most of the
     * 120-column policy row isn't touched at quote stage; the operator
     * fills it during the application phase.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return Validator::make($request->all(), [
            'customerId' => ['sometimes', 'nullable', 'integer', 'exists:customers,id'],
            'carrierId' => ['sometimes', 'nullable', 'integer', 'exists:carriers,id'],
            'productId' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'writingAgentId' => ['sometimes', 'nullable', 'integer', 'exists:agents,id'],
            'quoteNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'effectiveDate' => ['sometimes', 'nullable', 'date'],
            'expiryDate' => ['sometimes', 'nullable', 'date', 'after:effectiveDate'],
            'coverage' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'annualPremium' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'mainPremium' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'netPremium' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'dutyStamp' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'vat' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'totalPremiumPaid' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'premiumMode' => ['sometimes', 'nullable', 'string', 'in:monthly,quarterly,semiannual,annual,single'],
            'newOrRenew' => ['sometimes', 'nullable', 'string', 'in:new,renew'],
            'internalNote' => ['sometimes', 'nullable', 'string'],
        ])->validate();
    }

    /**
     * Map camelCase request keys → snake_case column names.
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function toDbShape(array $v): array
    {
        $map = [
            'customerId' => 'customer_id',
            'carrierId' => 'carrier_id',
            'productId' => 'product_id',
            'writingAgentId' => 'writing_agent_id',
            'quoteNo' => 'quote_no',
            'effectiveDate' => 'effective_date',
            'expiryDate' => 'expiry_date',
            'coverage' => 'coverage',
            'annualPremium' => 'annual_premium',
            'mainPremium' => 'main_premium',
            'netPremium' => 'net_premium',
            'dutyStamp' => 'duty_stamp',
            'vat' => 'vat',
            'totalPremiumPaid' => 'total_premium_paid',
            'premiumMode' => 'premium_mode',
            'newOrRenew' => 'new_or_renew',
            'internalNote' => 'internal_note',
        ];
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }
        return $out;
    }

    /**
     * Q<YY><4-digit-serial> — matches the convention we chose in Phase 5 plan.
     * MAX(numeric-suffix)+1 across all statuses so a re-issue doesn't collide.
     */
    private function nextQuoteNo(int $tenantId): string
    {
        $prefix = 'Q'.now()->format('y');
        $maxNum = (int) DB::table('policies')
            ->where('tenant_id', $tenantId)
            ->where('quote_no', 'like', $prefix.'%')
            ->whereRaw('SUBSTRING(quote_no, ?) REGEXP \'^[0-9]+$\'', [strlen($prefix) + 1])
            ->max(DB::raw('CAST(SUBSTRING(quote_no, '.(strlen($prefix) + 1).') AS UNSIGNED)'));
        $next = $maxNum + 1;
        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * A<YY><6-digit-serial> — matches Access's application_code pattern
     * that the importer already writes (e.g. A2507160006).
     */
    private function nextApplicationNo(int $tenantId): string
    {
        $prefix = 'A'.now()->format('y');
        $maxNum = (int) DB::table('policies')
            ->where('tenant_id', $tenantId)
            ->where('application_no', 'like', $prefix.'%')
            ->whereRaw('SUBSTRING(application_no, ?) REGEXP \'^[0-9]+$\'', [strlen($prefix) + 1])
            ->max(DB::raw('CAST(SUBSTRING(application_no, '.(strlen($prefix) + 1).') AS UNSIGNED)'));
        $next = $maxNum + 1;
        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
