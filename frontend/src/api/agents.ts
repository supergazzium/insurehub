// Typed clients for /api/v1/agents list endpoint.

import { api, buildQuery, type Paginated, type Single } from './client'
import type { Agent } from '../stores/agents'

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

/** Full detail fetch. */
export function fetchAgent(id: string) {
  return api.get<Single<Agent>>(`agents/${id}`)
}
