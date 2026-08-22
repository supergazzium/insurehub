// Typed clients for /api/v1/agents list endpoint.

import { api, buildQuery, type Paginated } from './client'

/** Lean row returned by AgentController::index — matches AgentListResource. */
export interface AgentListRow {
  id: string
  agentCode: string
  agentType: string
  firstName: string
  lastName: string
  nickname: string
  email: string
  phone: string
  level: string
  team: string
  teamNo: string
  headStatus: string
  licenseLifeNo: string
  licenseLifeExpiry: string | null
  licenseNonLifeNo: string
  licenseNonLifeExpiry: string | null
  parentAgentId: string | null
  parentAgentCode: string | null
  parentAgentName: string
  joinedAt: string | null
  active: boolean
}

export interface AgentListFilters {
  q?: string
  activeOnly?: boolean
  parentAgentId?: string
  level?: string
  agentType?: string
  licenseStatus?: 'valid' | 'expired' | 'expiring60d' | ''
  page?: number
  perPage?: number
}

export function fetchAgentList(filters: AgentListFilters = {}) {
  return api.get<Paginated<AgentListRow>>(`agents${buildQuery({ ...filters })}`)
}

/** Full agent record via GET /agents/{id}. Used by the wizard's
 *  resume-from-draft flow (C-15) to hydrate the EntityPicker label. */
export function fetchAgent(id: string) {
  return api.get<{ data: Record<string, unknown> }>(`agents/${id}`)
}
