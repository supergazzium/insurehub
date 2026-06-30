<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $id = $this->route('carrier')?->id;
        $tenantId = $this->user()->tenant_id;

        return [
            'code' => [
                $isCreate ? 'required' : 'sometimes',
                'string', 'max:8',
                Rule::unique('carriers', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:255'],
            'nameEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nicknameTh' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:16'],
            'subType' => ['sometimes', 'nullable', 'string', 'max:64'],
            'compInsureCode' => ['sometimes', 'nullable', 'string'],
            'oicInsureComCode' => ['sometimes', 'nullable', 'string', 'max:16'],
            'oicLicense' => ['sometimes', 'nullable', 'string', 'max:32'],
            'taxId' => ['sometimes', 'nullable', 'string', 'max:20'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'logoUrl' => ['sometimes', 'nullable', 'string', 'max:512'],
            'since' => ['sometimes', 'nullable', 'string', 'max:8'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function toModel(): array
    {
        $v = $this->validated();
        $map = [
            'code' => 'code',
            'name' => 'name',
            'nameEn' => 'name_en',
            'nicknameTh' => 'nickname_th',
            'type' => 'insure_type',
            'subType' => 'sub_type',
            'compInsureCode' => 'comp_insure_code',
            'oicInsureComCode' => 'oic_insure_com_code',
            'oicLicense' => 'oic_license',
            'taxId' => 'tax_id',
            'phone' => 'phone',
            'email' => 'email',
            'website' => 'website',
            'address' => 'address',
            'logoUrl' => 'logo_url',
            'since' => 'since',
            'active' => 'active',
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
