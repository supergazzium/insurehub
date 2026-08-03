<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $id = $this->route('product')?->id;
        $tenantId = $this->user()->tenant_id;

        return [
            'code' => [
                $isCreate ? 'required' : 'sometimes',
                'string', 'max:16',
                Rule::unique('products', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:255'],
            'nameEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'carrierId' => [
                $isCreate ? 'required' : 'sometimes',
                Rule::exists('carriers', 'id')->where('tenant_id', $tenantId),
            ],
            'type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'mainRider' => ['sometimes', 'nullable', 'string', 'max:32'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subCategory' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subCategory2' => ['sometimes', 'nullable', 'string', 'max:64'],
            'summary' => ['sometimes', 'nullable', 'string'],
            'coverage' => ['sometimes', 'numeric', 'min:0'],
            // Motor-specific — 1 / 2+ / 2 / 3+ / 3 tier.
            'coverageClass' => ['sometimes', 'nullable', 'string', 'in:1,2+,2,3+,3'],
            'vehicleAgeMin' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            'vehicleAgeMax' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            'minSumAssure' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'maxSumAssure' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'durationYears' => ['sometimes', 'integer', 'min:1'],
            'payYears' => ['sometimes', 'integer', 'min:1'],
            'premiumMode' => ['sometimes', 'string', 'in:monthly,quarterly,semiannual,annual,single'],
            'minPremium' => ['sometimes', 'numeric', 'min:0'],
            'maxPremium' => ['sometimes', 'numeric', 'min:0'],
            'minAge' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'maxAge' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'gender' => ['sometimes', 'string', 'in:all,male,female'],
            'requireMedical' => ['sometimes', 'boolean'],
            'smokerAccepted' => ['sometimes', 'boolean'],
            'preexistingExcluded' => ['sometimes', 'boolean'],
            'occupationClasses' => ['sometimes', 'array'],
            'occupationClasses.*' => ['string', 'in:class1,class2,class3,class4'],
            'notes' => ['sometimes', 'nullable', 'string'],
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
            'carrierId' => 'carrier_id',
            'type' => 'type',
            'mainRider' => 'main_rider',
            'category' => 'category',
            'subCategory' => 'sub_category',
            'subCategory2' => 'sub_category_2',
            'summary' => 'summary',
            'coverage' => 'coverage',
            'coverageClass' => 'coverage_class',
            'vehicleAgeMin' => 'vehicle_age_min',
            'vehicleAgeMax' => 'vehicle_age_max',
            'minSumAssure' => 'min_sum_assure',
            'maxSumAssure' => 'max_sum_assure',
            'durationYears' => 'duration_years',
            'payYears' => 'pay_years',
            'premiumMode' => 'premium_mode',
            'minPremium' => 'min_premium',
            'maxPremium' => 'max_premium',
            'minAge' => 'min_age',
            'maxAge' => 'max_age',
            'gender' => 'gender',
            'requireMedical' => 'require_medical',
            'smokerAccepted' => 'smoker_accepted',
            'preexistingExcluded' => 'preexisting_excluded',
            'occupationClasses' => 'occupation_classes',
            'notes' => 'notes',
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
