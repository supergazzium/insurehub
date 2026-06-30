<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Policy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Policy
 */
class PolicyResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'quoteNo' => $this->quote_no,
            'applicationNo' => $this->application_no,
            'policyNo' => $this->policy_no,
            'customerId' => (string) $this->customer_id,
            'productId' => (string) $this->product_id,
            'carrierId' => (string) $this->carrier_id,
            'writingAgentId' => (string) $this->writing_agent_id,
            'coverage' => (float) $this->coverage,
            'annualPremium' => (float) $this->annual_premium,
            'premiumMode' => $this->premium_mode,
            'quoteDate' => $this->quote_date?->toDateString() ?? '',
            'effectiveDate' => $this->effective_date?->toDateString(),
            'expiryDate' => $this->expiry_date?->toDateString(),
            'issueDate' => $this->issue_date?->toDateString(),
            'nextPremiumDue' => $this->next_premium_due?->toDateString(),
            'cancelDate' => $this->cancel_date?->toDateString(),
            'lapseDate' => $this->lapse_date?->toDateString(),
            'policyYear' => (int) $this->policy_year,
            'actYear' => (int) $this->act_year,
            'newOrRenew' => $this->new_or_renew,
            'freelookActive' => (bool) $this->freelook_active,
            'status' => $this->status,
            'notes' => $this->notes ?? '',
            'riders' => $this->whenLoaded(
                'riders',
                fn () => $this->riders->map(fn ($r) => [
                    'id' => (string) $r->id,
                    'name' => $r->name,
                    'premium' => (float) $r->premium,
                    'notes' => $r->notes ?? '',
                ]),
                fn () => [],
            ),
            'beneficiaries' => $this->whenLoaded(
                'beneficiaries',
                fn () => $this->beneficiaries->map(fn ($b) => [
                    'id' => (string) $b->id,
                    'name' => $b->name,
                    'relation' => $b->relation ?? '',
                    'share' => (float) $b->share,
                ]),
                fn () => [],
            ),
            'motor' => $this->motor_vehicle_brand !== null ? [
                'vehicleBrand' => $this->motor_vehicle_brand ?? '',
                'vehicleModel' => $this->motor_vehicle_model ?? '',
                'licenseNo' => $this->motor_license_no ?? '',
                'engineNo' => $this->motor_engine_no ?? '',
                'chassisNo' => $this->motor_chassis_no ?? '',
                'registerYear' => $this->motor_register_year ?? '',
                'noPassenger' => (int) ($this->motor_no_passenger ?? 0),
                'typeDriver' => $this->motor_type_driver ?? '',
                'typeVehicle' => $this->motor_type_vehicle ?? '',
                'notes' => $this->motor_notes ?? '',
            ] : null,
            'property' => $this->property_insured_name !== null ? [
                'insuredName' => $this->property_insured_name ?? '',
                'insuredAddress' => $this->property_insured_address ?? '',
                'buildingCoverage' => (float) ($this->property_building_cov ?? 0),
                'furnitureCoverage' => (float) ($this->property_furniture_cov ?? 0),
                'stockCoverage' => (float) ($this->property_stock_cov ?? 0),
                'otherCoverage' => (float) ($this->property_other_cov ?? 0),
                'otherDetail' => $this->property_other_detail ?? '',
                'notes' => $this->property_notes ?? '',
            ] : null,
            'events' => PolicyEventResource::collection($this->whenLoaded('events')),
            'payments' => PolicyPaymentResource::collection($this->whenLoaded('payments')),
            'documents' => PolicyDocumentResource::collection($this->whenLoaded('documents')),
        ];
    }
}
