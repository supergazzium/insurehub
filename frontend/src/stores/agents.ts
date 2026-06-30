// Agents store — backed by the Laravel API.
//
// Keeps the original public surface (state refs, getters, sync helpers,
// mutating actions) so existing pages don't have to change. Differences:
//   - `agents` and `links` start empty; call `load()` once on app boot or
//     when the agents module mounts.
//   - The mutating actions (`createAgent`, `updateAgent`, `setLevel`,
//     `setActive`, `transferUpline`, `generateLink`, `revokeLink`) are now
//     `async` and return Promises. Existing call sites that ignored the
//     return value keep working — they just now resolve when the server
//     finishes.
//   - `loading` / `error` refs are exposed for UI affordances.

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, buildQuery, type Paginated, type Single } from '../api/client'

export type AgentLevel = 'l1' | 'l2' | 'l3' | 'l4' | 'l5'
export type Gender = 'male' | 'female' | 'other' | ''
export type AgentKind = 'individual' | 'corporate'
export type VatType = '' | 'none' | 'vat7' | 'wht1' | 'wht3' | 'wht5'

export interface AgentBank {
  bankName: string
  accountNo: string
  accountName: string
}

export interface Agent {
  id: string
  agentCode: string

  firstName: string
  lastName: string
  nickname: string
  firstNameEn: string
  lastNameEn: string
  gender: Gender
  email: string
  phone: string
  lineId: string
  idCard: string
  birthDate: string

  address: string
  province: string
  district: string
  subDistrict: string
  postcode: string

  kind: AgentKind
  juristicName: string
  taxId: string
  vatType: VatType

  bank: AgentBank

  licenseNumber: string
  licenseIssuer: string
  licenseExpiry: string | null
  licenseLifeNo: string
  licenseLifeExpiry: string | null
  licenseNonLifeNo: string
  licenseNonLifeExpiry: string | null

  parentAgentId: string | null
  level: AgentLevel
  commissionPct: number
  joinedAt: string

  notes: string
  active: boolean
}

export interface RecruitmentLink {
  id: string
  agentId: string
  token: string
  generatedAt: string
  clicks: number
  signups: number
  pendingSignups: number
  revoked: boolean
}

const LEVEL_PCT: Record<AgentLevel, number> = {
  l1: 25,
  l2: 35,
  l3: 45,
  l4: 55,
  l5: 65,
}

export const useAgentStore = defineStore('agents', () => {
  // ── State ────────────────────────────────────────────────────────────────
  const agents = ref<Agent[]>([])
  const links = ref<RecruitmentLink[]>([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)

  // ── Lookup helpers (read from the cached `agents` array) ─────────────────
  function getAgent(id: string | null): Agent | null {
    if (!id) return null
    return agents.value.find((a) => a.id === id) ?? null
  }

  function getDirectDownline(parentId: string): Agent[] {
    return agents.value.filter((a) => a.parentAgentId === parentId)
  }

  function getAllDownline(parentId: string): Agent[] {
    const result: Agent[] = []
    const stack = [parentId]
    while (stack.length) {
      const cur = stack.pop() as string
      const children = getDirectDownline(cur)
      for (const c of children) {
        result.push(c)
        stack.push(c.id)
      }
    }
    return result
  }

  function getUplineChain(agentId: string): Agent[] {
    const chain: Agent[] = []
    let cur = getAgent(agentId)
    while (cur?.parentAgentId) {
      const parent = getAgent(cur.parentAgentId)
      if (!parent) break
      chain.push(parent)
      cur = parent
    }
    return chain
  }

  function getMaxDownlineDepth(parentId: string): number {
    const queue: { id: string; depth: number }[] = [{ id: parentId, depth: 0 }]
    let max = 0
    while (queue.length) {
      const cur = queue.shift() as { id: string; depth: number }
      max = Math.max(max, cur.depth)
      for (const child of getDirectDownline(cur.id)) {
        queue.push({ id: child.id, depth: cur.depth + 1 })
      }
    }
    return max
  }

  const topLevelAgents = computed(() => agents.value.filter((a) => !a.parentAgentId))

  function eligibleUplines(forAgentId: string | null): Agent[] {
    if (!forAgentId) return [...agents.value]
    const excluded = new Set([forAgentId, ...getAllDownline(forAgentId).map((a) => a.id)])
    return agents.value.filter((a) => !excluded.has(a.id))
  }

  // ── Loaders ──────────────────────────────────────────────────────────────

  /**
   * Fetch all agents + active recruitment links. The agents endpoint paginates
   * (default 25/page) so we walk pages until we've collected everything.
   */
  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const all: Agent[] = []
      let page = 1
      // The API caps perPage at 100 — pull the largest pages we can.
      // 1000 agents = 10 round-trips at worst, still well under a second.
      // eslint-disable-next-line no-constant-condition
      while (true) {
        const response = await api.get<Paginated<Agent>>(
          `agents${buildQuery({ page, perPage: 100 })}`,
        )
        all.push(...response.data)
        const meta = response.meta
        if (!meta || page >= meta.last_page) break
        page += 1
      }
      agents.value = all
      const linksResponse = await api.get<Paginated<RecruitmentLink>>(
        `recruitment-links${buildQuery({ activeOnly: false, perPage: 100 })}`,
      )
      links.value = linksResponse.data
      loaded.value = true
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load agents.'
      throw err
    } finally {
      loading.value = false
    }
  }

  // ── Mutations (server-backed) ────────────────────────────────────────────

  function nextAgentCode(): string {
    // Mirrors the legacy `AG-NNNNN` pattern. The backend doesn't auto-generate,
    // so the client supplies a code and the unique constraint catches collisions.
    const max = agents.value.reduce((acc, a) => {
      const n = parseInt(a.agentCode.replace(/\D/g, ''), 10)
      return Number.isFinite(n) && n > acc ? n : acc
    }, 0)
    return 'AG-' + String(max + 1).padStart(5, '0')
  }

  async function createAgent(payload: Omit<Agent, 'id' | 'agentCode'>): Promise<Agent> {
    const body = { ...payload, agentCode: nextAgentCode() }
    const response = await api.post<Single<Agent>>('agents', body)
    const created = response.data
    agents.value = [created, ...agents.value]
    return created
  }

  async function updateAgent(id: string, patch: Partial<Agent>): Promise<void> {
    const response = await api.patch<Single<Agent>>(`agents/${id}`, patch)
    const updated = response.data
    agents.value = agents.value.map((a) => (a.id === id ? updated : a))
  }

  async function setLevel(id: string, level: AgentLevel): Promise<void> {
    await updateAgent(id, { level, commissionPct: LEVEL_PCT[level] })
  }

  async function setActive(id: string, active: boolean): Promise<void> {
    await updateAgent(id, { active })
  }

  async function transferUpline(id: string, newUplineId: string | null): Promise<void> {
    if (newUplineId === id) return
    const descendantIds = new Set(getAllDownline(id).map((a) => a.id))
    if (newUplineId && descendantIds.has(newUplineId)) return // would create a cycle
    await updateAgent(id, { parentAgentId: newUplineId })
  }

  // ── Recruitment links ────────────────────────────────────────────────────

  function getLinkForAgent(agentId: string): RecruitmentLink | null {
    return links.value.find((l) => l.agentId === agentId && !l.revoked) ?? null
  }

  async function generateLink(agentId: string): Promise<RecruitmentLink> {
    const response = await api.post<Single<RecruitmentLink>>('recruitment-links', { agentId })
    const fresh = response.data
    // The server revoked the previous link on its side; reflect that locally.
    links.value = [
      ...links.value.map((l) =>
        l.agentId === agentId && !l.revoked ? { ...l, revoked: true } : l,
      ),
      fresh,
    ]
    return fresh
  }

  async function revokeLink(linkId: string): Promise<void> {
    await api.delete(`recruitment-links/${linkId}`)
    links.value = links.value.map((l) => (l.id === linkId ? { ...l, revoked: true } : l))
  }

  return {
    // state
    agents,
    links,
    loading,
    loaded,
    error,
    // helpers
    getAgent,
    getDirectDownline,
    getAllDownline,
    getUplineChain,
    getMaxDownlineDepth,
    topLevelAgents,
    eligibleUplines,
    // loaders
    load,
    // mutations
    createAgent,
    updateAgent,
    setLevel,
    setActive,
    transferUpline,
    // recruitment
    getLinkForAgent,
    generateLink,
    revokeLink,
    // constants
    LEVEL_PCT,
  }
})
