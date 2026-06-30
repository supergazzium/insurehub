<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $id = $this->route('agent')?->id;
        $tenantId = $this->user()->tenant_id;

        return [
            'agentCode' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'max:16',
                Rule::unique('agents', 'agent_code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($id),
            ],
            'agentType' => ['sometimes', 'string', 'in:AG,IN'],
            'firstName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'firstNameEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lastNameEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female,other,'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'lineId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'idCard' => ['sometimes', 'nullable', 'string', 'max:20'],
            'birthDate' => ['sometimes', 'nullable', 'date'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subDistrict' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postcode' => ['sometimes', 'nullable', 'string', 'max:16'],
            'kind' => ['sometimes', 'string', 'in:individual,corporate'],
            'juristicName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'taxId' => ['sometimes', 'nullable', 'string', 'max:20'],
            'vatType' => ['sometimes', 'string', 'in:,none,vat7,wht1,wht3,wht5'],
            'bank.bankName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank.accountNo' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bank.accountName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'licenseNumber' => ['sometimes', 'nullable', 'string', 'max:32'],
            'licenseIssuer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'licenseExpiry' => ['sometimes', 'nullable', 'date'],
            'licenseLifeNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'licenseLifeExpiry' => ['sometimes', 'nullable', 'date'],
            'licenseNonLifeNo' => ['sometimes', 'nullable', 'string', 'max:32'],
            'licenseNonLifeExpiry' => ['sometimes', 'nullable', 'date'],
            'parentAgentId' => [
                'sometimes', 'nullable',
                Rule::exists('agents', 'id')->where('tenant_id', $tenantId),
            ],
            'level' => ['sometimes', 'string', 'in:l1,l2,l3,l4,l5'],
            'commissionPct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'joinedAt' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Map camelCase request keys → snake_case DB columns, only for keys actually present.
     *
     * @return array<string, mixed>
     */
    public function toModel(): array
    {
        $v = $this->validated();
        $map = [
            'agentCode' => 'agent_code',
            'agentType' => 'agent_type',
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'firstNameEn' => 'first_name_en',
            'lastNameEn' => 'last_name_en',
            'nickname' => 'nickname',
            'gender' => 'gender',
            'email' => 'email',
            'phone' => 'phone',
            'lineId' => 'line_id',
            'idCard' => 'id_card',
            'birthDate' => 'birth_date',
            'address' => 'address',
            'province' => 'province',
            'district' => 'district',
            'subDistrict' => 'sub_district',
            'postcode' => 'postcode',
            'kind' => 'kind',
            'juristicName' => 'juristic_name',
            'taxId' => 'tax_id',
            'vatType' => 'vat_type',
            'licenseNumber' => 'license_number',
            'licenseIssuer' => 'license_issuer',
            'licenseExpiry' => 'license_expiry',
            'licenseLifeNo' => 'license_life_no',
            'licenseLifeExpiry' => 'license_life_expiry',
            'licenseNonLifeNo' => 'license_non_life_no',
            'licenseNonLifeExpiry' => 'license_non_life_expiry',
            'parentAgentId' => 'parent_agent_id',
            'level' => 'level',
            'commissionPct' => 'commission_pct',
            'joinedAt' => 'joined_at',
            'notes' => 'notes',
            'active' => 'active',
        ];
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }
        if (array_key_exists('bank', $v)) {
            $bank = $v['bank'] ?? [];
            if (array_key_exists('bankName', $bank)) {
                $out['bank_name_text'] = $bank['bankName'];
            }
            if (array_key_exists('accountNo', $bank)) {
                $out['bank_account_no'] = $bank['accountNo'];
            }
            if (array_key_exists('accountName', $bank)) {
                $out['bank_account_name'] = $bank['accountName'];
            }
        }
        return $out;
    }
}
