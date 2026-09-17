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
  teamId: string | null
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

// ── สายงาน (teams), ranks, hierarchy editing, promotion approval (Phase 2) ──

export interface TeamRow {
  id: string
  code: string
  name: string | null
  parentTeamId: string | null
  leaderAgentId: string | null
  active: boolean
  memberCount: number
}
export function fetchTeams() {
  return api.get<{ data: TeamRow[] }>('teams')
}
export function createTeam(payload: { code: string; name?: string; parentTeamId?: string | number | null }) {
  return api.post<{ data: { id: string } }>('teams', payload)
}

export interface RankRow {
  id: string
  level: number
  levelKey: string        // 'l1'..'l10'
  code: string
  nameTh: string
  nameEn: string
  monthlyAvgTarget: number
  threeMonthAccumTarget: number
  licenseRequired: boolean
}
export function fetchRanks() {
  return api.get<{ data: RankRow[] }>('ranks')
}

/** PATCH an agent's สายงาน (team + upline) and level. All fields optional. */
export interface AgentHierarchyPatch {
  teamId?: string | number | null
  parentAgentId?: string | number | null
  level?: string | null   // 'l1'..'l10'
}
export interface HierarchyRollupEntry {
  teamCode: string | null
  ownPremium: number
  ownPolicyCount: number
  subtreePremium: number
  subtreePolicyCount: number
}
/** Per-agent team + own/subtree premium rollup for the hierarchy tree. */
export function fetchHierarchyRollup() {
  return api.get<{ data: Record<string, HierarchyRollupEntry> }>('agents/hierarchy-rollup')
}

export function updateAgentHierarchy(agentId: string, patch: AgentHierarchyPatch) {
  return api.patch<{ data: { id: string; teamId: string | null; parentAgentId: string | null; level: string | null; rankId: string | null } }>(
    `agents/${agentId}/hierarchy`, patch,
  )
}

// ── Promotion approval queue ────────────────────────────────────────────────
export type PromotionStatus = 'pending' | 'approved' | 'rejected'
export interface RankPromotionRow {
  id: string
  status: PromotionStatus
  trigger: string
  agentId: string
  agentCode: string | null
  agentName: string
  fromLevel: number | null
  fromRankLabel: string | null
  toLevel: number | null
  toRankLabel: string | null
  qualifyingVolume: number
  qualifyingPeriod: string | null
  requestedAt: string | null
  decidedAt: string | null
  promotedAt: string | null
  notes: string | null
}
export function fetchRankPromotions(status: PromotionStatus | 'all' = 'pending') {
  return api.get<{ data: RankPromotionRow[]; meta: { pendingCount: number } }>(
    `rank-promotions${buildQuery({ status })}`,
  )
}
export function approveRankPromotion(id: string) {
  return api.post<{ data: { id: string; status: PromotionStatus } }>(`rank-promotions/${id}/approve`, {})
}
export function rejectRankPromotion(id: string, note?: string) {
  return api.post<{ data: { id: string; status: PromotionStatus } }>(`rank-promotions/${id}/reject`, { note })
}
