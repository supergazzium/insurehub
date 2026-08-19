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
            // MGM product-type assignment — descriptive / reporting only.
            // The engine no longer resolves the commission tier through
            // product_types; it reads products.commission_tier_id directly
            // (see commissionTierId below). product_type_id kept nullable
            // so operators can add products without picking a type.
            'productTypeId' => ['sometimes', 'nullable', 'integer', 'exists:product_types,id'],
            // ระดับค่าคอม — required for the MGM engine to compute
            // REFERRAL_FEE and MANAGEMENT_DIFFERENTIAL. Nullable at the
            // schema layer (legacy products still exist without one) but
            // the create modal enforces it as required.
            'commissionTierId' => ['sometimes', 'nullable', 'integer', 'exists:commission_tiers,id'],
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

            // Per-product standard commission rates. Two directions:
            //   carrierToHub — what the carrier pays InsureHub
            //   hubToAgent   — what InsureHub pays the selling agent
            //     (MGM engine DIRECT base rate)
            // Scheme per direction is inferred from the product group at
            // persist time (Life/Rider → life_years, else flat). Fields are
            // decimals in the "rate" scale — 0.10 = 10%. Nullable.
            'commissionRates' => ['sometimes', 'array'],
            'commissionRates.carrierToHub' => ['sometimes', 'array'],
            'commissionRates.hubToAgent' => ['sometimes', 'array'],
            'commissionRates.carrierToHub.flatRate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.carrierToHub.yr1' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.carrierToHub.yr2' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.carrierToHub.yr3' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.carrierToHub.yr4' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.carrierToHub.yr5' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.carrierToHub.yr6_10' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.carrierToHub.yr11Up' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.hubToAgent.flatRate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.hubToAgent.yr1' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.hubToAgent.yr2' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.hubToAgent.yr3' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.hubToAgent.yr4' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.hubToAgent.yr5' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.hubToAgent.yr6_10' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionRates.hubToAgent.yr11Up' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],

            // Banded rates. One entry per band; used for Life products where
            // the rate depends on sum-assured range + entry-age range +
            // policy year. Absent = don't touch existing bands. Empty array
            // = wipe all bands for that direction.
            'commissionBands' => ['sometimes', 'array'],
            'commissionBands.carrierToHub' => ['sometimes', 'array'],
            'commissionBands.hubToAgent' => ['sometimes', 'array'],
            'commissionBands.carrierToHub.*' => ['array'],
            'commissionBands.hubToAgent.*' => ['array'],
            'commissionBands.carrierToHub.*.sumAssuredMin' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'commissionBands.carrierToHub.*.sumAssuredMax' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'commissionBands.carrierToHub.*.entryAgeMin' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            'commissionBands.carrierToHub.*.entryAgeMax' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            'commissionBands.carrierToHub.*.yr1' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.carrierToHub.*.yr2' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.carrierToHub.*.yr3' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.carrierToHub.*.yr4' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.carrierToHub.*.yr5' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.carrierToHub.*.yr6Up' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.hubToAgent.*.sumAssuredMin' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'commissionBands.hubToAgent.*.sumAssuredMax' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'commissionBands.hubToAgent.*.entryAgeMin' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            'commissionBands.hubToAgent.*.entryAgeMax' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            'commissionBands.hubToAgent.*.yr1' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.hubToAgent.*.yr2' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.hubToAgent.*.yr3' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.hubToAgent.*.yr4' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.hubToAgent.*.yr5' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'commissionBands.hubToAgent.*.yr6Up' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
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
            'productTypeId' => 'product_type_id',
            'commissionTierId' => 'commission_tier_id',
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
