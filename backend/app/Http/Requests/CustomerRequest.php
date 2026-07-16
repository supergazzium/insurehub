<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $id = $this->route('customer')?->id;
        $tenantId = $this->user()->tenant_id;

        return [
            'customerCode' => [
                $isCreate ? 'required' : 'sometimes',
                'string', 'max:16',
                Rule::unique('customers', 'customer_code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'customerType' => ['sometimes', 'string', 'in:individual,corporate'],
            'titleTh' => ['sometimes', 'nullable', 'string', 'max:255'],
            'titleEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'firstName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'firstNameEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lastNameEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'juristicName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'taxId' => ['sometimes', 'nullable', 'string', 'max:20'],
            'idCard' => ['sometimes', 'nullable', 'string', 'max:20'],
            'nationalIdExpiry' => ['sometimes', 'nullable', 'date'],
            'passport' => ['sometimes', 'nullable', 'string', 'max:32'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:255'],
            'religion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birthDate' => ['sometimes', 'nullable', 'date'],
            // Accept both English enum values (male/female/other) and the Thai
            // strings used by the imported Access data (ชาย/หญิง). Empty string
            // is treated as null via `nullable`.
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female,other,ชาย,หญิง,'],
            'maritalStatus' => ['sometimes', 'nullable', 'string', 'in:single,married,divorced,widowed,'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employerName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'monthlyIncome' => ['sometimes', 'numeric', 'min:0'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'lineId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amphoe' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postcode' => ['sometimes', 'nullable', 'string', 'max:16'],
            'mailingSameAsRegistered' => ['sometimes', 'boolean'],
            'mailing.address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mailing.subDistrict' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mailing.district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mailing.province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mailing.postcode' => ['sometimes', 'nullable', 'string', 'max:16'],
            'contactPerson.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contactPerson.phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'contactPerson.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contactPerson.position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'createdByAgentId' => ['sometimes', 'nullable', Rule::exists('agents', 'id')->where('tenant_id', $tenantId)],
            'assignedAgentId' => ['sometimes', 'nullable', Rule::exists('agents', 'id')->where('tenant_id', $tenantId)],
            'notes' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function toModel(): array
    {
        $v = $this->validated();
        $flat = [
            'customerCode' => 'customer_code',
            'customerType' => 'customer_type',
            'titleTh' => 'title_th',
            'titleEn' => 'title_en',
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'nickname' => 'nickname',
            'firstNameEn' => 'first_name_en',
            'lastNameEn' => 'last_name_en',
            'juristicName' => 'juristic_name',
            'taxId' => 'tax_id',
            'idCard' => 'id_card',
            'nationalIdExpiry' => 'national_id_expiry',
            'passport' => 'passport',
            'nationality' => 'nationality',
            'religion' => 'religion',
            'birthDate' => 'birth_date',
            'gender' => 'gender',
            'maritalStatus' => 'marital_status',
            'occupation' => 'occupation',
            'position' => 'position',
            'employerName' => 'employer_name',
            'monthlyIncome' => 'monthly_income',
            'email' => 'email',
            'phone' => 'phone',
            'lineId' => 'line_id',
            'address' => 'address',
            // Frontend `district` is sub-district (tambon); frontend `amphoe` is district (khet).
            'district' => 'sub_district',
            'amphoe' => 'amphoe',
            'province' => 'province',
            'postcode' => 'postcode',
            'mailingSameAsRegistered' => 'mailing_same_as_registered',
            'createdByAgentId' => 'created_by_agent_id',
            'assignedAgentId' => 'assigned_agent_id',
            'notes' => 'notes',
            'active' => 'active',
        ];
        $out = [];
        foreach ($flat as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }

        $nested = [
            'mailing' => [
                'address' => 'mailing_address',
                'subDistrict' => 'mailing_sub_district',
                'district' => 'mailing_district',
                'province' => 'mailing_province',
                'postcode' => 'mailing_postcode',
            ],
            'contactPerson' => [
                'name' => 'contact_name',
                'phone' => 'contact_phone',
                'email' => 'contact_email',
                'position' => 'contact_position',
            ],
        ];
        foreach ($nested as $group => $cols) {
            if (! array_key_exists($group, $v)) {
                continue;
            }
            foreach ($cols as $camel => $snake) {
                if (array_key_exists($camel, $v[$group])) {
                    $out[$snake] = $v[$group][$camel];
                }
            }
        }
        return $out;
    }
}
