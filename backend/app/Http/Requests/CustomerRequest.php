<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\ThaiIdentifier;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    /** Thai character range plus space + . + - for common name conventions. */
    private const THAI_NAME_REGEX = '/^[\x{0E00}-\x{0E7F} .\-]+$/u';

    /** Thai phone: 10 digits starting with 0 (mobile 06/08/09/07, landline 02/03/04/05). */
    private const THAI_PHONE_REGEX = '/^0\d{8,9}$/';

    /**
     * International phone: leading + or digit, 6-20 chars total, tolerant
     * of spaces / dashes / parentheses so a paste of "+66 81 234 5678"
     * works. Used only for foreign_individual — Thai customers keep the
     * strict `^0\d{8,9}$` rule so we don't silently accept malformed
     * numbers on domestic accounts.
     */
    private const INTL_PHONE_REGEX = '/^\+?[\d\s\-().]{6,20}$/';

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
        // Three customer types: Thai individual, foreign individual (passport
        // instead of Thai national ID), or corporate. `is*` flags below drive
        // the required-vs-optional decisions on each field.
        $customerType = $this->input('customerType', 'individual');
        $isIndividual = $customerType === 'individual';
        $isForeign = $customerType === 'foreign_individual';
        $isPerson = $isIndividual || $isForeign;
        $isCorporate = $customerType === 'corporate';

        return [
            'customerCode' => [
                $isCreate ? 'required' : 'sometimes',
                'string', 'max:16',
                Rule::unique('customers', 'customer_code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'customerType' => ['sometimes', 'string', 'in:individual,foreign_individual,corporate'],
            'titleTh' => ['sometimes', 'nullable', 'string', 'max:255'],
            'titleEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'firstName' => [
                $isCreate && $isPerson ? 'required' : 'sometimes',
                'nullable', 'string', 'max:255',
                // Thai charset only for Thai individuals; foreigners get to
                // use Latin letters + Thai + . - space.
                Rule::when($isIndividual, ['regex:'.self::THAI_NAME_REGEX]),
            ],
            'lastName' => [
                $isCreate && $isPerson ? 'required' : 'sometimes',
                'nullable', 'string', 'max:255',
                Rule::when($isIndividual, ['regex:'.self::THAI_NAME_REGEX]),
            ],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'firstNameEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lastNameEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'juristicName' => [
                $isCreate && $isCorporate ? 'required' : 'sometimes',
                'nullable', 'string', 'max:255',
            ],
            'taxId' => ['sometimes', 'nullable', 'string', 'regex:/^\d{13}$/'],
            // Thai national ID: only meaningful for Thai individuals + corporate
            // (juristic reg number). Foreign individuals must leave it blank —
            // Thai's mod-11 checksum wouldn't apply to their home-country ID.
            'idCard' => [
                'sometimes', 'nullable', 'string',
                Rule::when($isForeign, ['prohibited']),
                Rule::when(! $isForeign, ['regex:/^\d{13}$/']),
            ],
            'nationalIdExpiry' => ['sometimes', 'nullable', 'date'],
            'passport' => [
                // Passport is the ID mechanism for foreigners: required + a
                // reasonable format check (uppercase alnum, 6..12 chars).
                $isCreate && $isForeign ? 'required' : 'sometimes',
                'nullable', 'string', 'max:32',
                Rule::when($isForeign, ['regex:/^[A-Z0-9]{6,12}$/']),
            ],
            'nationality' => [
                // Non-Thai nationality expected on foreign customers so the
                // form isn't silently defaulted to Thai. Required to submit.
                $isCreate && $isForeign ? 'required' : 'sometimes',
                'nullable', 'string', 'max:255',
            ],
            'religion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birthDate' => ['sometimes', 'nullable', 'date', 'before:today'],
            // Storage is single-letter codes (M/F) after the 2027_02_11
            // normalization migration; the create modal renders Thai labels.
            'gender' => ['sometimes', 'nullable', 'string', 'in:M,F'],
            'maritalStatus' => ['sometimes', 'nullable', 'string', 'in:single,married,divorced,widowed,'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employerName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'monthlyIncome' => ['sometimes', 'numeric', 'min:0'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => [
                'sometimes', 'nullable', 'string',
                'regex:'.($isForeign ? self::INTL_PHONE_REGEX : self::THAI_PHONE_REGEX),
            ],
            'telPhone' => [
                'sometimes', 'nullable', 'string',
                'regex:'.($isForeign ? self::INTL_PHONE_REGEX : self::THAI_PHONE_REGEX),
            ],
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

    /**
     * Enforce Thai MOD-11 checksum on idCard + taxId. Both fields already
     * pass a /^\d{13}$/ shape rule at the `rules()` layer; we only run the
     * checksum here so callers get a clearer error message than "regex
     * failed". Sharing the same ThaiIdentifier::isValid as AgentRegister
     * keeps the two entry points in lock-step.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $idCard = (string) ($this->input('idCard') ?? '');
            if (preg_match('/^\d{13}$/', $idCard) === 1 && ! ThaiIdentifier::isValid($idCard)) {
                $v->errors()->add('idCard', 'เลขบัตรประชาชนไม่ถูกต้อง (MOD-11 checksum)');
            }
            $taxId = (string) ($this->input('taxId') ?? '');
            if (preg_match('/^\d{13}$/', $taxId) === 1 && ! ThaiIdentifier::isValid($taxId)) {
                $v->errors()->add('taxId', 'เลขประจำตัวผู้เสียภาษีไม่ถูกต้อง (MOD-11 checksum)');
            }
        });
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
            'telPhone' => 'tel_phone',
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
