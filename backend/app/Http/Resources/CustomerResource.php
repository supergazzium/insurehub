<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerCode' => $this->customer_code,
            'legacyId' => $this->legacy_id ?? '',
            'customerType' => $this->customer_type,
            'titleTh' => $this->title_th ?? '',
            'titleEn' => $this->title_en ?? '',
            'firstName' => $this->first_name ?? '',
            'lastName' => $this->last_name ?? '',
            'nickname' => $this->nickname ?? '',
            'firstNameEn' => $this->first_name_en ?? '',
            'lastNameEn' => $this->last_name_en ?? '',
            'juristicName' => $this->juristic_name ?? '',
            'taxId' => $this->tax_id ?? '',
            'idCard' => $this->id_card ?? '',
            'nationalIdExpiry' => $this->national_id_expiry?->toDateString(),
            'passport' => $this->passport ?? '',
            'nationality' => $this->nationality ?? '',
            'religion' => $this->religion ?? '',
            'birthDate' => $this->birth_date?->toDateString() ?? '',
            'gender' => $this->gender ?? '',
            'maritalStatus' => $this->marital_status ?? 'single',
            'occupation' => $this->occupation ?? '',
            'position' => $this->position ?? '',
            'employerName' => $this->employer_name ?? '',
            'monthlyIncome' => (float) $this->monthly_income,
            'email' => $this->email ?? '',
            'phone' => $this->phone ?? '',
            'lineId' => $this->line_id ?? '',
            'address' => $this->address ?? '',
            'district' => $this->sub_district ?? '',
            'amphoe' => $this->amphoe ?? '',
            'province' => $this->province ?? '',
            'postcode' => $this->postcode ?? '',
            'mailingSameAsRegistered' => (bool) $this->mailing_same_as_registered,
            'mailing' => [
                'address' => $this->mailing_address ?? '',
                'subDistrict' => $this->mailing_sub_district ?? '',
                'district' => $this->mailing_district ?? '',
                'province' => $this->mailing_province ?? '',
                'postcode' => $this->mailing_postcode ?? '',
            ],
            'contactPerson' => [
                'name' => $this->contact_name ?? '',
                'phone' => $this->contact_phone ?? '',
                'email' => $this->contact_email ?? '',
                'position' => $this->contact_position ?? '',
            ],
            'createdByAgentId' => $this->created_by_agent_id !== null ? (string) $this->created_by_agent_id : null,
            'assignedAgentId' => $this->assigned_agent_id !== null ? (string) $this->assigned_agent_id : null,
            // Denormalized agent code/name so the drawer can render a
            // clickable link without a second request.
            'assignedAgentCode' => $this->assignedAgent?->agent_code,
            'assignedAgentName' => $this->assignedAgent
                ? trim(($this->assignedAgent->first_name ?? '') . ' ' . ($this->assignedAgent->last_name ?? ''))
                : null,
            'registeredAt' => $this->registered_at?->toIso8601String() ?? '',
            'lastContact' => $this->last_contact?->toIso8601String(),
            'notes' => $this->notes ?? '',
            // Live policy counts — the denormalized columns on the
            // customers table drift out of sync, so we query straight from
            // the policies table via a model helper.
            'activePolicyCount' => $this->livePolicyCount('active'),
            'totalPolicyCount' => $this->livePolicyCount('all'),
            'kycDocs' => CustomerKycDocResource::collection($this->whenLoaded('kycDocs')),
            'assignmentHistory' => CustomerAssignmentHistoryResource::collection($this->whenLoaded('assignmentHistory')),
            'active' => (bool) $this->active,
        ];
    }
}
