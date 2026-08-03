<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean list-row shape for /api/v1/policies index.
 * Wraps the raw DB row returned by PolicyController::index (with joined
 * customer/agent/carrier/product columns) so the frontend list table
 * doesn't need any extra fetches.
 *
 * The heavier full-detail shape (with rebate/riders/etc children) stays on
 * PolicyResource for /api/v1/policies/{id}.
 *
 * @mixin \stdClass
 */
class PolicyListResource extends JsonResource
{
    /** @return array<string, mixed> */
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
            'status' => $this->status,
            // Original Thai label from insurehub_legacy.lu_policy_status
            // (via policy_statuses.name_th). Preferred for display; `status`
            // is the frontend enum used for filtering/badge colors.
            'statusLabel' => $this->status_label ?? $this->status,
            'statusGroup' => $this->status_group,
            'newOrRenew' => $this->new_or_renew,
            'coverage' => (float) $this->coverage,
            'annualPremium' => (float) $this->annual_premium,
            'premiumMode' => $this->premium_mode,
            'appDate' => $this->app_date,
            'effectiveDate' => $this->effective_date,
            'expiryDate' => $this->expiry_date,
            'issueDate' => $this->issue_date,
            'cancelDate' => $this->cancel_date,
            'freelookActive' => (bool) $this->freelook_active,
            'premiumCheck' => $this->premium_check ?? '',
            // Joined display columns — saves the client from N+1 lookups.
            'customerCode' => $this->customer_code,
            'customerName' => trim(($this->customer_first_name ?? '') . ' ' . ($this->customer_last_name ?? '')),
            'agentCode' => $this->agent_code,
            'agentName' => trim(($this->agent_first_name ?? '') . ' ' . ($this->agent_last_name ?? '')),
            'carrierCode' => $this->carrier_code,
            'carrierName' => $this->carrier_name,
            'productCode' => $this->product_code,
            'productName' => $this->product_name,
            // Motor fields — non-motor policies leave these null. The UI
            // renders them in the "vehicle" column when present.
            'motorLicenseNo' => $this->motor_license_no,
            'motorVehicleBrand' => $this->motor_vehicle_brand,
            'motorVehicleModel' => $this->motor_vehicle_model,
        ];
    }
}
