<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean list-row shape for /api/v1/customers index.
 * Wraps raw DB rows with joined assigned-agent columns.
 *
 * @mixin \stdClass
 */
class CustomerListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerCode' => $this->customer_code,
            'customerType' => $this->customer_type,
            'titleTh' => $this->title_th ?? '',
            'firstName' => $this->first_name ?? '',
            'lastName' => $this->last_name ?? '',
            'nickname' => $this->nickname ?? '',
            'juristicName' => $this->juristic_name ?? '',
            'idCard' => $this->id_card ?? '',
            'taxId' => $this->tax_id ?? '',
            'passport' => $this->passport ?? '',
            'phone' => $this->phone ?? '',
            'email' => $this->email ?? '',
            'province' => $this->province ?? '',
            'assignedAgentId' => $this->assigned_agent_id !== null ? (string) $this->assigned_agent_id : null,
            'assignedAgentCode' => $this->assigned_agent_code,
            'assignedAgentName' => trim(($this->assigned_agent_first_name ?? '') . ' ' . ($this->assigned_agent_last_name ?? '')),
            'activePolicyCount' => (int) $this->active_policy_count,
            'totalPolicyCount' => (int) $this->total_policy_count,
            'active' => (bool) $this->active,
            'registeredAt' => $this->registered_at,
        ];
    }
}
