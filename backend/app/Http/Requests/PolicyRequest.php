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
        $tenantId = $this->user()->tenant_id;

        return [
            'quoteNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'applicationNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'policyNo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customerId' => [$isCreate ? 'required' : 'sometimes', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'productId' => [$isCreate ? 'required' : 'sometimes', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'carrierId' => [$isCreate ? 'required' : 'sometimes', Rule::exists('carriers', 'id')->where('tenant_id', $tenantId)],
            'writingAgentId' => [$isCreate ? 'required' : 'sometimes', Rule::exists('agents', 'id')->where('tenant_id', $tenantId)],
            'coverage' => ['sometimes', 'numeric', 'min:0'],
            'annualPremium' => ['sometimes', 'numeric', 'min:0'],
            'premiumMode' => ['sometimes', 'string', 'in:monthly,quarterly,semiannual,annual,single'],
            'quoteDate' => ['sometimes', 'nullable', 'date'],
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
            'status' => ['sometimes', 'string', 'in:quote,application,submitted,issued,active,lapsed,cancelled,reinstated,expired'],
            'notes' => ['sometimes', 'nullable', 'string'],
            // Children — accepted but persisted by the controller.
            'riders' => ['sometimes', 'array'],
            'riders.*.name' => ['required_with:riders', 'string', 'max:255'],
            'riders.*.premium' => ['required_with:riders', 'numeric', 'min:0'],
            'riders.*.notes' => ['nullable', 'string'],
            'beneficiaries' => ['sometimes', 'array'],
            'beneficiaries.*.name' => ['required_with:beneficiaries', 'string', 'max:255'],
            'beneficiaries.*.relation' => ['nullable', 'string', 'max:32'],
            'beneficiaries.*.share' => ['required_with:beneficiaries', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function toModel(): array
    {
        $v = $this->validated();
        $map = [
            'quoteNo' => 'quote_no',
            'applicationNo' => 'application_no',
            'policyNo' => 'policy_no',
            'customerId' => 'customer_id',
            'productId' => 'product_id',
            'carrierId' => 'carrier_id',
            'writingAgentId' => 'writing_agent_id',
            'coverage' => 'coverage',
            'annualPremium' => 'annual_premium',
            'premiumMode' => 'premium_mode',
            'quoteDate' => 'quote_date',
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
        ];
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }
        return $out;
    }
}
