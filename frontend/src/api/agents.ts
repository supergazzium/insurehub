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
  phone?: string
  kind?: 'individual' | 'corporate'
  juristicName?: string
  level: string
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
  approvalStatus?: string
  lastNoteAt?: string | null
  noteCount?: number
}

export interface AgentListFilters {
  q?: string
  activeOnly?: boolean
  parentAgentId?: string
  level?: string
  agentType?: string
  licenseStatus?: 'valid' | 'expired' | 'expiring60d' | 'none' | ''
  licenseType?: 'life' | 'non_life' | ''
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

// ── สายงาน (upline), ranks, hierarchy editing, promotion approval ──

export interface RankRow {
  id: string
  level: number
  levelKey: string        // 'l1'..'l10'
  code: string
  nameTh: string
  nameEn: string
  threeMonthAccumTarget: number
  licenseRequired: boolean
}
export function fetchRanks() {
  return api.get<{ data: RankRow[] }>('ranks')
}
export interface RankPatch {
  nameTh?: string
  nameEn?: string
  threeMonthAccumTarget?: number
  licenseRequired?: boolean
}
export function updateRank(id: string, patch: RankPatch) {
  return api.patch<{ data: RankRow }>(`ranks/${id}`, patch)
}

/** PATCH an agent's ต้นสาย (upline). */
export interface AgentHierarchyPatch {
  parentAgentId?: string | number | null
}
export interface HierarchyRollupEntry {
  ownPremium: number
  ownPolicyCount: number
  subtreePremium: number
  subtreePolicyCount: number
}
/** Per-agent own/subtree premium rollup for the hierarchy tree. */
export function fetchHierarchyRollup() {
  return api.get<{ data: Record<string, HierarchyRollupEntry> }>('agents/hierarchy-rollup')
}

export function updateAgentHierarchy(agentId: string, patch: AgentHierarchyPatch) {
  return api.patch<{ data: { id: string; parentAgentId: string | null; level: string | null; rankId: string | null } }>(
    `agents/${agentId}/hierarchy`, patch,
  )
}

// ── Promotion approval queue ────────────────────────────────────────────────
export type PromotionStatus = 'pending' | 'approved' | 'rejected' | 'superseded'
export interface RankPromotionRow {
  id: string
  status: PromotionStatus
  trigger: 'auto' | 'manual' | string
  agentId: string
  agentCode: string | null
  agentName: string
  currentLevel: number | null
  licenseValid: boolean
  fromLevel: number | null
  fromRankLabel: string | null
  toLevel: number | null
  toRankLabel: string | null
  toLicenseRequired: boolean
  qualifyingVolume: number
  qualifyingPeriod: string | null
  requestedAt: string | null
  requestedByName: string | null
  requestNote: string | null
  decidedAt: string | null
  decidedByName: string | null
  promotedAt: string | null
  notes: string | null
}
export function fetchRankPromotions(status: PromotionStatus | 'all' = 'pending', agentId?: string) {
  return api.get<{ data: RankPromotionRow[]; meta: { pendingCount: number } }>(
    `rank-promotions${buildQuery({ status, agentId })}`,
  )
}
export function approveRankPromotion(id: string) {
  return api.post<{ data: RankPromotionRow }>(`rank-promotions/${id}/approve`, {})
}
export function rejectRankPromotion(id: string, note: string) {
  return api.post<{ data: RankPromotionRow }>(`rank-promotions/${id}/reject`, { note })
}
export function createRankRequest(agentId: string, toLevel: number, note: string) {
  return api.post<{ data: RankPromotionRow }>(`agents/${agentId}/rank-requests`, { toLevel, note })
}

// ── Level progress (Phase 4) ────────────────────────────────────────────────
export interface LevelProgress {
  month?: string
  currentLevel: number
  currentRankLabel: string | null
  nextLevel: number | null
  nextRankLabel: string | null
  currentVolume: number
  target: number
  progressPct: number
  remaining: number
  nextRequiresLicense: boolean
  licenseBlocked: boolean
}
export function fetchLevelProgress(agentId: string) {
  return api.get<{ data: LevelProgress }>(`agents/${agentId}/level-progress`)
}

export interface LevelProgressBoardRow extends LevelProgress {
  agentId: string
  agentCode: string | null
  agentName: string
}
export function fetchLevelProgressBoard() {
  return api.get<{ data: LevelProgressBoardRow[]; meta: { month: string; agentCount: number } }>(
    'agents/level-progress-board',
  )
}


// ── Agent management: approve / reject / deactivate / pending (admin) ────────
export function fetchPendingAgents() {
  return api.get<{ data: AgentListRow[] }>('admin/agents/pending')
}
export function approveAgent(id: string) {
  return api.post<{ message: string }>(`admin/agents/${id}/approve`, {})
}
export function rejectAgent(id: string, note: string) {
  return api.post<{ message: string }>(`admin/agents/${id}/reject`, { note })
}
/** Activate / deactivate via the standard agent PATCH. */
export function setAgentActive(id: string, active: boolean) {
  return api.patch<{ data: Record<string, unknown> }>(`agents/${id}`, { active })
}
/** Full create — POST /agents (agentCode required). */
export function createAgentFull(payload: Record<string, unknown>) {
  return api.post<{ data: Record<string, unknown> }>('agents', payload)
}
/** Full update — PATCH /agents/{id}. */
export function updateAgentFull(id: string, payload: Record<string, unknown>) {
  return api.patch<{ data: Record<string, unknown> }>(`agents/${id}`, payload)
}

// ── Agent VAT + notes ───────────────────────────────────────────────────────
export interface AgentNoteRow {
  id: string
  note: string
  kind: string
  createdAt: string | null
  createdByName?: string | null
}

export type AgentDocKind = 'id' | 'bank' | 'license_life' | 'license_non_life' | 'profile'
export interface AgentDocumentPresence {
  id: boolean
  bank: boolean
  licenseLife: boolean
  licenseNonLife: boolean
  profile: boolean
}
export function uploadAgentDocument(agentId: string, kind: AgentDocKind, file: File) {
  const fd = new FormData()
  fd.append('file', file)
  return api.post<{ data: Record<string, unknown> }>(`agents/${agentId}/documents/${kind}`, fd)
}
export function deleteAgentDocument(agentId: string, kind: AgentDocKind) {
  return api.delete<{ data: Record<string, unknown> }>(`agents/${agentId}/documents/${kind}`)
}
export async function fetchAgentDocumentBlob(agentId: string, kind: AgentDocKind): Promise<Blob | null> {
  const res = await api.get<{ data: { mime: string; name: string; content: string } }>(
    `agents/${agentId}/documents/${kind}?as=base64&t=${Date.now()}`,
  )
  const bin = atob(res.data.content)
  const bytes = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i)
  return new Blob([bytes], { type: res.data.mime })
}
export function fetchAgentNotes(agentId: string) {
  return api.get<{ data: AgentNoteRow[] }>(`agents/${agentId}/notes`)
}
export function createAgentNote(agentId: string, note: string, kind = 'general') {
  return api.post<{ data: { id: string } }>(`agents/${agentId}/notes`, { note, kind })
}

export interface AgentRelation {
  id: string
  agentCode: string | null
  name: string
  level: string | null
  active: boolean
}
export interface AgentRelations {
  self: AgentRelation
  upline: AgentRelation[]    // nearest first → top last
  downline: AgentRelation[]
}
export function fetchAgentRelations(agentId: string) {
  return api.get<AgentRelations>(`agents/${agentId}/relations`)
}

