<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        // C-11 permissive draft path: POST /policies/draft + PATCH /draft
        // both accept partial state. All required rules relax to sometimes
        // + nullable so the wizard's autosave can post whatever's filled.
        // The promote endpoints (C-11) or regular POST /policies enforce
        // the full contract at commit time.
        $isDraft = str_contains($this->path(), '/draft');
        $strict = $isCreate && ! $isDraft;
        $tenantId = $this->user()->tenant_id;

        return [
            'quoteNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'applicationNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'policyNo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notionNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'customerId' => [$strict ? 'required' : 'sometimes', 'nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'productId' => [$strict ? 'required' : 'sometimes', 'nullable', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'carrierId' => [$strict ? 'required' : 'sometimes', 'nullable', Rule::exists('carriers', 'id')->where('tenant_id', $tenantId)],
            'writingAgentId' => [$strict ? 'required' : 'sometimes', 'nullable', Rule::exists('agents', 'id')->where('tenant_id', $tenantId)],
            'refAppToId' => ['sometimes', 'nullable', Rule::exists('policies', 'id')->where('tenant_id', $tenantId)],
            'coverage' => ['sometimes', 'numeric', 'min:0'],
            // Premium breakdown (Access parity).
            'annualPremium' => ['sometimes', 'numeric', 'min:0'],
            'mainPremium' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'netPremium' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'dutyStamp' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'vat' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'whtStatus' => ['sometimes', 'nullable', 'string', 'max:32'],
            'whtAmt' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'totalPremiumPaid' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'netCustomerPaid' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'premiumMode' => ['sometimes', 'string', 'in:monthly,quarterly,semiannual,annual,single'],
            // Installment plan.
            'installmentTerm' => ['sometimes', 'nullable', 'string', 'max:32'],
            'firstDueInst' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'firstDueInstDate' => ['sometimes', 'nullable', 'date'],
            'nextDueInst' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lastDueInstDate' => ['sometimes', 'nullable', 'date'],
            'typeOfPaid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'typeOfPaidNote' => ['sometimes', 'nullable', 'string'],
            'financeCompany' => ['sometimes', 'nullable', 'string', 'max:255'],
            'frontEndFee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'discountAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'subsidiseFromAgent' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'subsidiseToFinance' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'creditCardFee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // C-21: per-policy commission overrides (both directions).
            'commHubToAgentRate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commHubToAgentAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'commCarrierToHubRate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commCarrierToHubAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // C-22: per-year override vector (both directions).
            'commOverride' => ['sometimes', 'nullable', 'array'],
            'commOverride.hubToAgent' => ['sometimes', 'nullable', 'array'],
            'commOverride.carrierToHub' => ['sometimes', 'nullable', 'array'],
            'commOverride.*.*' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            // Dates.
            'quoteDate' => ['sometimes', 'nullable', 'date'],
            'appDate' => ['sometimes', 'nullable', 'date'],
            'effectiveDate' => ['sometimes', 'nullable', 'date'],
            'expiryDate' => ['sometimes', 'nullable', 'date'],
            'issueDate' => ['sometimes', 'nullable', 'date'],
            'nextPremiumDue' => ['sometimes', 'nullable', 'date'],
            'cancelDate' => ['sometimes', 'nullable', 'date'],
            'lapseDate' => ['sometimes', 'nullable', 'date'],
            'policyYear' => ['sometimes', 'integer', 'min:1'],
            'actYear' => ['sometimes', 'integer', 'min:1'],
            'newOrRenew' => ['sometimes', 'string', 'in:new,renew'],
            'freelookActive' => ['sometimes', 'boolean'],
            // 7-state model per B1 §1. Legacy codes (quote/application/
            // reinstated) still accepted on READ paths for the shim window;
            // writes go through PolicyEventController which enforces the
            // full transition matrix on top of this basic enum check.
            'status' => ['sometimes', 'string', 'in:draft,quotation,submitted,approved,issued,active,expired,cancelled,rejected,lapsed'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'internalNote' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Motor block — sent when the picked product is motor.
            'motorTypeDriver' => ['sometimes', 'nullable', 'string', 'max:64'],
            'motorTypeVehicle' => ['sometimes', 'nullable', 'string', 'max:64'],
            'motorVehicleBrand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'motorVehicleModel' => ['sometimes', 'nullable', 'string', 'max:255'],
            'motorLicenseNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'motorEngineNo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'motorChassisNo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'motorRegisterYear' => ['sometimes', 'nullable', 'string', 'max:8'],
            'motorNoPassenger' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
            'motorNotes' => ['sometimes', 'nullable', 'string'],
            // Property block — sent when the picked product is fire/property.
            'propertyInsuredName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'propertyInsuredAddress' => ['sometimes', 'nullable', 'string', 'max:255'],
            'propertyBuildingCov' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'propertyFurnitureCov' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'propertyStockCov' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'propertyOtherCov' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'propertyOtherDetail' => ['sometimes', 'nullable', 'string'],
            'propertyNotes' => ['sometimes', 'nullable', 'string'],
            'propertyPhone' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Travel block — sent when the picked product is travel.
            'tripDestination' => ['sometimes', 'nullable', 'string', 'max:128'],
            'tripStart' => ['sometimes', 'nullable', 'date'],
            'tripEnd' => ['sometimes', 'nullable', 'date'],
            'travelerCount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'travelerPassport' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Life / Health insured person + coverage details.
            'insuredPersonName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'insuredPersonIdCard' => ['sometimes', 'nullable', 'string', 'max:32'],
            'insuredPersonBirthDate' => ['sometimes', 'nullable', 'date'],
            'sumAssured' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'premiumPayingTerm' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            'healthDeclaration' => ['sometimes', 'nullable', 'string'],
            // Health-only single beneficiary.
            'healthBeneficiaryName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'healthBeneficiaryRelation' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Mailing.
            'mailingAddByPolicy' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mailingDate' => ['sometimes', 'nullable', 'date'],
            'mailingNote' => ['sometimes', 'nullable', 'string'],
            // Children — accepted; persisted by PolicyController::syncChildren.
            'riders' => ['sometimes', 'array'],
            'riders.*.name' => ['required_with:riders', 'string', 'max:255'],
            'riders.*.premium' => ['required_with:riders', 'numeric', 'min:0'],
            'riders.*.slot' => ['nullable', 'integer', 'min:1', 'max:5'],
            'riders.*.productId' => ['nullable', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'riders.*.comRateInh' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'riders.*.comRateAg' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'riders.*.comAmtInh' => ['nullable', 'numeric', 'min:0'],
            'riders.*.comAmtAg' => ['nullable', 'numeric', 'min:0'],
            'riders.*.notes' => ['nullable', 'string'],
            'beneficiaries' => ['sometimes', 'array'],
            'beneficiaries.*.name' => ['required_with:beneficiaries', 'string', 'max:255'],
            'beneficiaries.*.relation' => ['nullable', 'string', 'max:32'],
            'beneficiaries.*.share' => ['required_with:beneficiaries', 'numeric', 'min:0', 'max:100'],
            'beneficiaries.*.slot' => ['nullable', 'integer', 'min:1', 'max:4'],
            // Phase C-4 writer shim payload — see toModel() + PolicyRiskShim.
            // Loose validation because the schema-driven wizard is
            // authoritative for per-field constraints (B4). Kind must
            // be one of the known 6; data is any assoc array (renderer
            // enforces types).
            'risk' => ['sometimes', 'nullable', 'array'],
            'risk.kind' => ['required_with:risk', 'string', 'in:motor,travel,fire,health,life,misc'],
            'risk.data' => ['required_with:risk', 'array'],
        ];
    }

    /** @return array<string, mixed> */
    public function toModel(): array
    {
        $v = $this->validated();

        // Phase C-4 writer shim: if the wizard sent a `risk` payload
        // (`{ kind: 'motor', data: { chassis_no: '…', ... } }`), dual-write
        // into BOTH the top-level columns AND `risk_data.<kind>.*`. During
        // the shim window the flat top-level keys (motorTypeDriver, etc.)
        // still work as an alternative — see PolicyRiskShim + B2 §3.
        $shimColumns = [];
        $shimRiskData = null;
        $riskInput = $this->input('risk');
        if (is_array($riskInput) && isset($riskInput['kind'], $riskInput['data']) && is_array($riskInput['data'])) {
            $kind = (string) $riskInput['kind'];
            $existing = null;
            $policy = $this->route('policy');
            if ($policy && isset($policy->risk_data) && is_array($policy->risk_data)) {
                $existing = $policy->risk_data;
            }
            $dw = \App\Support\PolicyRiskShim::writerDualWrite($kind, $riskInput['data'], $existing);
            $shimColumns = $dw['columns'];
            $shimRiskData = $dw['risk_data'];
        }

        $map = [
            'quoteNo' => 'quote_no',
            'applicationNo' => 'application_no',
            'policyNo' => 'policy_no',
            'notionNo' => 'notion_no',
            'customerId' => 'customer_id',
            'productId' => 'product_id',
            'carrierId' => 'carrier_id',
            'writingAgentId' => 'writing_agent_id',
            'refAppToId' => 'ref_app_to_id',
            'coverage' => 'coverage',
            'annualPremium' => 'annual_premium',
            'mainPremium' => 'main_premium',
            'netPremium' => 'net_premium',
            'dutyStamp' => 'duty_stamp',
            'vat' => 'vat',
            'whtStatus' => 'wht_status',
            'whtAmt' => 'wht_amt',
            'totalPremiumPaid' => 'total_premium_paid',
            'netCustomerPaid' => 'net_customer_paid',
            'premiumMode' => 'premium_mode',
            'installmentTerm' => 'installment_term',
            'firstDueInst' => 'first_due_inst',
            'firstDueInstDate' => 'first_due_inst_date',
            'nextDueInst' => 'next_due_inst',
            'lastDueInstDate' => 'last_due_inst_date',
            'typeOfPaid' => 'type_of_paid',
            'typeOfPaidNote' => 'type_of_paid_note',
            'financeCompany' => 'finance_company',
            'frontEndFee' => 'front_end_fee',
            'discountAmount' => 'discount_amount',
            'subsidiseFromAgent' => 'subsidise_from_agent',
            'subsidiseToFinance' => 'subsidise_to_finance',
            'creditCardFee' => 'credit_card_fee',
            'commHubToAgentRate' => 'comm_hub_to_agent_rate',
            'commHubToAgentAmount' => 'comm_hub_to_agent_amount',
            'commCarrierToHubRate' => 'comm_carrier_to_hub_rate',
            'commCarrierToHubAmount' => 'comm_carrier_to_hub_amount',
            'commOverride' => 'comm_override',
            'quoteDate' => 'quote_date',
            'appDate' => 'app_date',
            'effectiveDate' => 'effective_date',
            'expiryDate' => 'expiry_date',
            'issueDate' => 'issue_date',
            'nextPremiumDue' => 'next_premium_due',
            'cancelDate' => 'cancel_date',
            'lapseDate' => 'lapse_date',
            'policyYear' => 'policy_year',
            'actYear' => 'act_year',
            'newOrRenew' => 'new_or_renew',
            'freelookActive' => 'freelook_active',
            'status' => 'status',
            'notes' => 'notes',
            'internalNote' => 'internal_note',
            'motorTypeDriver' => 'motor_type_driver',
            'motorTypeVehicle' => 'motor_type_vehicle',
            'motorVehicleBrand' => 'motor_vehicle_brand',
            'motorVehicleModel' => 'motor_vehicle_model',
            'motorLicenseNo' => 'motor_license_no',
            'motorEngineNo' => 'motor_engine_no',
            'motorChassisNo' => 'motor_chassis_no',
            'motorRegisterYear' => 'motor_register_year',
            'motorNoPassenger' => 'motor_no_passenger',
            'motorNotes' => 'motor_notes',
            'propertyInsuredName' => 'property_insured_name',
            'propertyInsuredAddress' => 'property_insured_address',
            'propertyBuildingCov' => 'property_building_cov',
            'propertyFurnitureCov' => 'property_furniture_cov',
            'propertyStockCov' => 'property_stock_cov',
            'propertyOtherCov' => 'property_other_cov',
            'propertyOtherDetail' => 'property_other_detail',
            'propertyNotes' => 'property_notes',
            'propertyPhone' => 'property_phone',
            'mailingAddByPolicy' => 'mailing_add_by_policy',
            'mailingDate' => 'mailing_date',
            'mailingNote' => 'mailing_note',
            // Phase B-2 context fields.
            'tripDestination' => 'trip_destination',
            'tripStart' => 'trip_start',
            'tripEnd' => 'trip_end',
            'travelerCount' => 'traveler_count',
            'travelerPassport' => 'traveler_passport',
            'insuredPersonName' => 'insured_person_name',
            'insuredPersonIdCard' => 'insured_person_id_card',
            'insuredPersonBirthDate' => 'insured_person_birth_date',
            'sumAssured' => 'sum_assured',
            'premiumPayingTerm' => 'premium_paying_term',
            'healthDeclaration' => 'health_declaration',
            'healthBeneficiaryName' => 'health_beneficiary_name',
            'healthBeneficiaryRelation' => 'health_beneficiary_relation',
        ];
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }

        // Merge shim writes AFTER the flat map so `risk` payload overrides
        // any legacy flat key sent alongside it. Also handles the case
        // where the caller only sent `risk` and none of the flat keys.
        foreach ($shimColumns as $col => $val) {
            $out[$col] = $val;
        }
        if ($shimRiskData !== null) {
            $out['risk_data'] = $shimRiskData;
        }

        return $out;
    }

    /**
     * Additional rule for the new `risk` payload shape (writer shim).
     * Kept OUT of the main rules() array to keep the diff minimal — Laravel
     * merges any missing keys through validated() anyway; validation is
     * loose here because the schema-driven wizard is authoritative for
     * per-field constraints. See B4.
     */
    protected function prepareForValidation(): void
    {
        // Nothing to do — kept as a stub so the shim's shape is documented
        // in one place. Add coerce/normalize logic here when the wizard's
        // Step-3 renderer starts producing `risk` payloads (C-14).
    }
}
