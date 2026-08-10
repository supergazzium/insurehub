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
            // Shorthand: seed a product_commission_rates row where every
            // year (com_rate_yr_1..10 + _11up) is set to this percent.
            // Consumed by ProductController::store — NOT persisted onto
            // the products table itself. Kept for backward compat; new
            // callers should send `commissionRates` instead.
            'commissionPercent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],

            // Structured rate payload — supersedes `commissionPercent` when
            // both are sent. Consumed by ProductRateSeeder::seed().
            //   shape: 'flat'         → arbitrary installment map. Writes rows
            //                             to product_commission_rate_installments
            //                             with band = unbounded.
            //   shape: 'per-year'     → wide product_commission_rates row. Year 6
            //                             seeds every column from yr_6..yr_11up
            //                             (matches PDF "Y6+" convention).
            //   shape: 'installment'  → fixed grid main/3/6/12. Same target as
            //                             flat but UI-distinct.
            //   shape: 'band'         → repeatable band rows on
            //                             product_commission_rate_installments
            //                             with min/max_sum_assure filled. Each
            //                             row carries its own installment term.
            //   shape: 'skip'         → no-op; caller wants to add rates later.
            // All rate values are percents (0..100). Nulls mean "leave this
            // party at the previous value on update; store nothing on create".
            'commissionRates' => ['sometimes', 'nullable', 'array'],
            'commissionRates.shape' => ['required_with:commissionRates', 'string', 'in:flat,per-year,installment,band,skip'],

            // flat + installment share this validation branch.
            'commissionRates.installments' => ['sometimes', 'array'],
            'commissionRates.installments.*' => ['array'],
            'commissionRates.installments.*.inh' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'commissionRates.installments.*.ag' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'commissionRates.installments.*.ov' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],

            // per-year branch.
            'commissionRates.years' => ['sometimes', 'array'],
            'commissionRates.years.*' => ['array'],
            'commissionRates.years.*.inh' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'commissionRates.years.*.ag' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'commissionRates.years.*.ov' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],

            // band branch — repeatable rows. UI enforces min <= max where
            // both are present; we defer to the seeder for cross-row validation.
            'commissionRates.bands' => ['sometimes', 'array'],
            'commissionRates.bands.*' => ['array'],
            'commissionRates.bands.*.minSumAssure' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'commissionRates.bands.*.maxSumAssure' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'commissionRates.bands.*.installmentTerm' => ['sometimes', 'nullable', 'string', 'max:32'],
            'commissionRates.bands.*.inh' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'commissionRates.bands.*.ag' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'commissionRates.bands.*.ov' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
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
