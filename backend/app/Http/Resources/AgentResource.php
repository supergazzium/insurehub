<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Agent
 */
class AgentResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'agentCode' => $this->agent_code,
            'agentType' => $this->agent_type,
            'firstName' => $this->first_name ?? '',
            'lastName' => $this->last_name ?? '',
            'firstNameEn' => $this->first_name_en ?? '',
            'lastNameEn' => $this->last_name_en ?? '',
            'nickname' => $this->nickname ?? '',
            'gender' => $this->gender ?? '',
            'email' => $this->email ?? '',
            'phone' => $this->phone ?? '',
            'lineId' => $this->line_id ?? '',
            'idCard' => $this->id_card ?? '',
            'birthDate' => $this->birth_date?->toDateString() ?? '',
            'address' => $this->address ?? '',
            'province' => $this->province ?? '',
            'district' => $this->district ?? '',
            'subDistrict' => $this->sub_district ?? '',
            'postcode' => $this->postcode ?? '',
            'kind' => $this->kind ?? 'individual',
            'juristicName' => $this->juristic_name ?? '',
            'taxId' => $this->tax_id ?? '',
            'vatType' => $this->vat_type ?? '',
            'bank' => [
                'bankName' => $this->bank_name_text ?? '',
                'accountNo' => $this->bank_account_no ?? '',
                'accountName' => $this->bank_account_name ?? '',
            ],
            'licenseNumber' => $this->license_number ?? '',
            'licenseIssuer' => $this->license_issuer ?? '',
            'licenseExpiry' => $this->license_expiry?->toDateString(),
            'licenseLifeNo' => $this->license_life_no ?? '',
            'licenseLifeExpiry' => $this->license_life_expiry?->toDateString(),
            'licenseNonLifeNo' => $this->license_non_life_no ?? '',
            'licenseNonLifeExpiry' => $this->license_non_life_expiry?->toDateString(),
            'parentAgentId' => $this->parent_agent_id !== null ? (string) $this->parent_agent_id : null,
            'level' => $this->level,
            'commissionPct' => (float) $this->commission_pct,
            'joinedAt' => $this->joined_at?->toDateString() ?? '',
            'headStatus' => $this->head_status ?? '',
            'headStartDateRaw' => $this->head_start_date_raw ?? '',
            'gracePeriodEndRaw' => $this->grace_period_end_raw ?? '',
            'source' => $this->source ?? '',
            'team' => $this->team ?? '',
            'teamLv1' => $this->team_lv1 ?? '',
            'teamLv2' => $this->team_lv2 ?? '',
            'teamNo' => $this->team_no ?? '',
            'notes' => $this->notes ?? '',
            'active' => (bool) $this->active,
        ];
    }
}
