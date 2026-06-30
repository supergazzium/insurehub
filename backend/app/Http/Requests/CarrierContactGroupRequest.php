<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarrierContactGroupRequest extends FormRequest
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
            'carrierCode' => [
                $isCreate ? 'required' : 'sometimes',
                'string', 'max:8',
                Rule::exists('carriers', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:255'],
            'emails' => [$isCreate ? 'required' : 'sometimes', 'array', 'min:1'],
            'emails.*' => ['email'],
            'department' => [$isCreate ? 'required' : 'sometimes', 'string', 'in:new_business,underwriting,policy_issue,claims,other'],
            'insuranceTypes' => [$isCreate ? 'required' : 'sometimes', 'array'],
            'insuranceTypes.*' => ['string', 'in:life,group_life,ci,health,group_health,pa,motor,cmi,fire,marine,travel,liability,pet,other'],
            'isDefault' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
