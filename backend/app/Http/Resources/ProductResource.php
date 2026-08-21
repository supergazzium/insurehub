<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductCommissionBand;
use App\Models\ProductCommissionRate;
use App\Support\ProductKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'commissionCode' => $this->commission_code ?? '',
            'name' => $this->name,
            'nameEn' => $this->name_en ?? '',
            'carrierId' => (string) $this->carrier_id,
            'carrierCode' => $this->carrier?->code,
            'carrierName' => $this->carrier?->name,
            'carrierInsureType' => $this->carrier?->insure_type ?? '',
            'type' => $this->type ?? '',
            // MGM product-type FK — descriptive/reporting only after the
            // commission tier moved directly onto the product.
            'productTypeId' => $this->product_type_id !== null ? (string) $this->product_type_id : null,
            // Denormalized product-type block so the wizard can render Step 3
            // + resolve the risk_schema in a single fetch. `kind` and
            // `riskSchema` come from the 2027_02_15_000200 migration.
            'productType' => $this->productType ? [
                'id' => (string) $this->productType->id,
                'code' => $this->productType->code,
                'nameTh' => $this->productType->name_th,
                'kind' => $this->productType->kind,
                'riskSchema' => $this->productType->risk_schema,
            ] : null,
            // ระดับค่าคอม — the tier the MGM engine reads for
            // referral_fee_rate + mgmt_fee_rate lookups.
            'commissionTierId' => $this->commission_tier_id !== null ? (string) $this->commission_tier_id : null,
            'category' => $this->category ?? '',
            'subCategory' => $this->sub_category ?? '',
            'subCategory2' => $this->sub_category_2 ?? '',
            'mainRider' => $this->main_rider ?? '',
            // Prefer the stored productType.kind (authored per taxonomy row)
            // over the runtime derivation. The derivation stays as fallback
            // for tenants whose product_types.kind hasn't been populated.
            'productKind' => $this->productType?->kind
                ?? ProductKind::derive($this->type ?? '', $this->category ?? '', $this->sub_category_2 ?? '', $this->sub_category ?? ''),
            'validStart' => $this->valid_start?->toDateString(),
            'validEnd' => $this->valid_end?->toDateString(),
            'summary' => $this->summary ?? '',
            'coverage' => (float) $this->coverage,
            'coverageClass' => $this->coverage_class,
            'vehicleAgeMin' => $this->vehicle_age_min !== null ? (int) $this->vehicle_age_min : null,
            'vehicleAgeMax' => $this->vehicle_age_max !== null ? (int) $this->vehicle_age_max : null,
            'durationYears' => (int) $this->duration_years,
            'payYears' => (int) $this->pay_years,
            'premiumMode' => $this->premium_mode,
            'minPremium' => (float) $this->min_premium,
            'maxPremium' => (float) $this->max_premium,
            'minAge' => (int) $this->min_age,
            'maxAge' => (int) $this->max_age,
            'minSumAssure' => $this->min_sum_assure !== null ? (float) $this->min_sum_assure : null,
            'maxSumAssure' => $this->max_sum_assure !== null ? (float) $this->max_sum_assure : null,
            'gender' => $this->gender,
            'requireMedical' => (bool) $this->require_medical,
            'smokerAccepted' => (bool) $this->smoker_accepted,
            'preexistingExcluded' => (bool) $this->preexisting_excluded,
            'occupationClasses' => $this->occupation_classes ?? [],
            'notes' => $this->notes ?? '',
            'active' => (bool) $this->active,
            'commissionRates' => $this->serializeCommissionRates(),
            'commissionBands' => $this->serializeCommissionBands(),
        ];
    }

    /**
     * Return banded rates grouped by direction. Only meaningful for Life /
     * Rider products; other groups get empty arrays. Rows are ordered by
     * band_seq so the frontend renders them in author-defined order.
     *
     * @return array{
     *   carrierToHub: list<array<string,mixed>>,
     *   hubToAgent: list<array<string,mixed>>,
     * }
     */
    private function serializeCommissionBands(): array
    {
        if (! $this->relationLoaded('commissionBands')) {
            return ['carrierToHub' => [], 'hubToAgent' => []];
        }

        $grouped = ['carrierToHub' => [], 'hubToAgent' => []];
        foreach ($this->commissionBands->sortBy('band_seq') as $band) {
            $key = $band->direction === ProductCommissionBand::DIRECTION_CARRIER_TO_HUB
                ? 'carrierToHub'
                : 'hubToAgent';
            $grouped[$key][] = [
                'sumAssuredMin' => $band->sum_assured_min !== null ? (float) $band->sum_assured_min : null,
                'sumAssuredMax' => $band->sum_assured_max !== null ? (float) $band->sum_assured_max : null,
                'entryAgeMin' => $band->entry_age_min !== null ? (int) $band->entry_age_min : null,
                'entryAgeMax' => $band->entry_age_max !== null ? (int) $band->entry_age_max : null,
                'yr1' => $band->yr_1 !== null ? (float) $band->yr_1 : null,
                'yr2' => $band->yr_2 !== null ? (float) $band->yr_2 : null,
                'yr3' => $band->yr_3 !== null ? (float) $band->yr_3 : null,
                'yr4' => $band->yr_4 !== null ? (float) $band->yr_4 : null,
                'yr5' => $band->yr_5 !== null ? (float) $band->yr_5 : null,
                'yr6Up' => $band->yr_6_up !== null ? (float) $band->yr_6_up : null,
            ];
        }

        return $grouped;
    }

    /**
     * Return the two-panel commission-rate shape the frontend expects.
     * Missing rows serialize as null (frontend uses that to show empty inputs
     * rather than 0 %). Only reads pre-loaded `commissionRates` — controllers
     * eager-load; other callers get {carrierToHub:null, hubToAgent:null}.
     *
     * @return array{
     *   scheme: string,
     *   carrierToHub: array<string,float|null>|null,
     *   hubToAgent: array<string,float|null>|null,
     * }
     */
    private function serializeCommissionRates(): array
    {
        $scheme = in_array($this->type, ['Life', 'Rider'], true)
            ? ProductCommissionRate::SCHEME_LIFE_YEARS
            : ProductCommissionRate::SCHEME_FLAT;

        if (! $this->relationLoaded('commissionRates')) {
            return ['scheme' => $scheme, 'carrierToHub' => null, 'hubToAgent' => null];
        }

        $rows = $this->commissionRates->keyBy('direction');

        return [
            'scheme' => $scheme,
            'carrierToHub' => $this->rowToPanel($rows->get(ProductCommissionRate::DIRECTION_CARRIER_TO_HUB)),
            'hubToAgent' => $this->rowToPanel($rows->get(ProductCommissionRate::DIRECTION_HUB_TO_AGENT)),
        ];
    }

    /**
     * @return array<string,float|null>|null
     */
    private function rowToPanel(?ProductCommissionRate $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'flatRate' => $row->flat_rate !== null ? (float) $row->flat_rate : null,
            'yr1' => $row->yr_1 !== null ? (float) $row->yr_1 : null,
            'yr2' => $row->yr_2 !== null ? (float) $row->yr_2 : null,
            'yr3' => $row->yr_3 !== null ? (float) $row->yr_3 : null,
            'yr4' => $row->yr_4 !== null ? (float) $row->yr_4 : null,
            'yr5' => $row->yr_5 !== null ? (float) $row->yr_5 : null,
            'yr6_10' => $row->yr_6_10 !== null ? (float) $row->yr_6_10 : null,
            'yr11Up' => $row->yr_11_up !== null ? (float) $row->yr_11_up : null,
        ];
    }
}
