// Customers store — backed by the Laravel API.
//
// Mirrors the original public surface (state refs, getters, sync helpers,
// mutating actions). All mutating actions are now `async` and return Promises.
// Sync read helpers (`getCustomer`, `getCustomersByAssignedAgent`, `kycStatus`,
// `thaiAge`) still work directly off the cached `customers` array.

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, buildQuery, type Paginated, type Single } from '../api/client'
import {
  fetchCustomerList,
  type CustomerListRow,
  type CustomerListFilters,
} from '../api/customers'

export type { CustomerListRow, CustomerListFilters }

export type Gender = 'male' | 'female' | 'other'
export type MaritalStatus = 'single' | 'married' | 'divorced' | 'widowed'
export type KycDocType =
  | 'idCard'
  | 'houseReg'
  | 'bankBook'
  | 'income'
  | 'medical'
  | 'photo'
  | 'signature'
  | 'other'

export interface KycDoc {
  id: string
  type: KycDocType
  fileName: string
  uploadedAt: string
  uploadedByAgentId: string
  verified: boolean
}

export interface AssignmentHistoryEntry {
  id: string
  fromAgentId: string | null
  toAgentId: string | null
  reason: string
  byUserId: string
  at: string
}

export type CustomerType = 'individual' | 'corporate'

export interface MailingAddress {
  address: string
  subDistrict: string
  district: string
  province: string
  postcode: string
}

export interface CorporateContact {
  name: string
  phone: string
  email: string
  position: string
}

export interface Customer {
  id: string
  customerCode: string
  legacyId?: string
  customerType: CustomerType
  titleTh: string
  titleEn: string
  firstName: string
  lastName: string
  nickname: string
  firstNameEn: string
  lastNameEn: string
  juristicName: string
  taxId: string
  idCard: string
  nationalIdExpiry: string | null
  passport: string
  nationality: string
  religion: string
  birthDate: string
  gender: Gender
  maritalStatus: MaritalStatus
  occupation: string
  position: string
  employerName: string
  monthlyIncome: number
  email: string
  phone: string
  lineId: string
  address: string
  district: string
  amphoe: string
  province: string
  postcode: string
  mailingSameAsRegistered: boolean
  mailing: MailingAddress
  contactPerson: CorporateContact
  createdByAgentId: string | null
  assignedAgentId: string | null
  registeredAt: string
  lastContact: string | null
  notes: string
  activePolicyCount: number
  totalPolicyCount: number
  kycDocs: KycDoc[]
  assignmentHistory: AssignmentHistoryEntry[]
  active: boolean
}

export interface CustomerReferralLink {
  id: string
  agentId: string
  productId: string | null
  campaign: string
  token: string
  generatedAt: string
  clicks: number
  leads: number
  policies: number
  revoked: boolean
}

export const useCustomerStore = defineStore('customers', () => {
  // ── Paginated list state (server-side) ───────────────────────────────────
  const list = ref<CustomerListRow[]>([])
  const listMeta = ref<{ currentPage: number; lastPage: number; perPage: number; total: number } | null>(null)
  const listFilters = ref<CustomerListFilters>({ page: 1, perPage: 25 })
  const listLoading = ref(false)
  const listError = ref<string | null>(null)

  // ── Detail cache ─────────────────────────────────────────────────────────
  const customers = ref<Customer[]>([])
  const links = ref<CustomerReferralLink[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  // ── Lookup helpers (sync, read from cache) ───────────────────────────────
  function getCustomer(id: string): Customer | null {
    return customers.value.find((c) => c.id === id) ?? null
  }

  function getCustomersByAssignedAgent(agentId: string | null): Customer[] {
    return customers.value.filter((c) => c.assignedAgentId === agentId)
  }

  const unassignedCustomers = computed(() =>
    customers.value.filter((c) => c.assignedAgentId === null),
  )

  function thaiAge(birthDate: string): number {
    if (!birthDate) return 0
    const [y, m, d] = birthDate.split('-').map(Number)
    if (!y || !m || !d) return 0
    // birthDate is stored as BE in seed data; ISO dates from API are CE. Handle both.
    const ce = y > 2300 ? y - 543 : y
    const today = new Date(2026, 5, 5) // 2026-06-05 (kept stable for snapshot-style displays)
    let age = today.getFullYear() - ce
    const birthThisYear = new Date(today.getFullYear(), m - 1, d)
    if (today < birthThisYear) age--
    return age
  }

  function kycStatus(c: Customer): 'missing' | 'partial' | 'complete' {
    const docs = c.kycDocs ?? []
    if (!docs.length) return 'missing'
    const hasIdCard = docs.some((d) => d.type === 'idCard' && d.verified)
    if (!hasIdCard) return 'partial'
    if (docs.length >= 3) return 'complete'
    return 'partial'
  }

  /** The list endpoint omits `kycDocs` and `assignmentHistory` (they're only
   *  included on the detail endpoint via `whenLoaded`). Normalise so consumers
   *  can always iterate without a null-check. */
  function normalize(c: Customer): Customer {
    return {
      ...c,
      kycDocs: c.kycDocs ?? [],
      assignmentHistory: c.assignmentHistory ?? [],
    }
  }

  // ── Server-paginated list loader ─────────────────────────────────────────

  async function loadPage(filters: CustomerListFilters = {}): Promise<void> {
    listFilters.value = { ...listFilters.value, ...filters }
    listLoading.value = true
    listError.value = null
    try {
      const res = await fetchCustomerList(listFilters.value)
      list.value = res.data
      const m = res.meta
      listMeta.value = m
        ? { currentPage: m.current_page, lastPage: m.last_page, perPage: m.per_page, total: m.total }
        : null
    } catch (err) {
      listError.value = err instanceof Error ? err.message : 'Failed to load customers.'
      throw err
    } finally {
      listLoading.value = false
    }
  }

  /**
   * Legacy full-load shim. Some pages (CustomerReferral, CommissionEngine)
   * still expect `customers.value` to be pre-populated. Rather than break
   * them mid-refactor, this fetches the first page (25 rows) into the
   * detail cache as normalized Customer objects — enough for demos, and
   * cheaper than the old `while(true)` loop.
   *
   * New code should use loadPage() (server-paginated list rows) or
   * ensureDetail(id) (single-record detail cache) instead.
   */
  const loaded = ref(false)
  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const res = await api.get<Paginated<Customer>>(
        `customers${buildQuery({ page: 1, perPage: 100 })}`,
      )
      customers.value = res.data.map(normalize)
      await loadReferralLinks()
      loaded.value = true
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load customers.'
    } finally {
      loading.value = false
    }
  }

  /** Ensure the full-detail Customer for `id` is in cache, fetching if needed. */
  async function ensureDetail(id: string, force = false): Promise<Customer | null> {
    if (!force) {
      const cached = getCustomer(id)
      if (cached) return cached
    }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<Single<Customer>>(`customers/${id}`)
      const c = normalize(res.data)
      customers.value = [c, ...customers.value.filter((x) => x.id !== id)]
      return c
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load customer.'
      return null
    } finally {
      loading.value = false
    }
  }

  /** Fetch the referral links (small dataset — one request). */
  async function loadReferralLinks(): Promise<void> {
    try {
      const linksResponse = await api.get<Paginated<CustomerReferralLink>>(
        `customer-referral-links${buildQuery({ perPage: 100 })}`,
      )
      links.value = linksResponse.data
    } catch {
      /* referral links are non-critical; silence errors */
    }
  }

  // ── Mutations (server-backed) ────────────────────────────────────────────

  function nextCustomerCode(): string {
    const max = customers.value.reduce((acc, c) => {
      const n = parseInt(c.customerCode.replace(/\D/g, ''), 10)
      return Number.isFinite(n) && n > acc ? n : acc
    }, 0)
    return 'CUST-' + String(max + 1).padStart(5, '0')
  }

  async function createCustomer(payload: Omit<Customer, 'id' | 'customerCode'>): Promise<Customer> {
    const body = { ...payload, customerCode: nextCustomerCode() }
    const response = await api.post<Single<Customer>>('customers', body)
    const created = normalize(response.data)
    customers.value = [created, ...customers.value]
    return created
  }

  async function updateCustomer(id: string, patch: Partial<Customer>): Promise<void> {
    const response = await api.patch<Single<Customer>>(`customers/${id}`, patch)
    const updated = normalize(response.data)
    customers.value = customers.value.map((c) => (c.id === id ? updated : c))
  }

  async function setActive(id: string, active: boolean): Promise<void> {
    await updateCustomer(id, { active })
  }

  async function assignCustomer(
    customerId: string,
    toAgentId: string | null,
    reason: string,
    /** Retained for compatibility with the legacy sync signature; ignored.
     *  The server records the user id from the auth token. */
    _byUserId: string = 'u1',
  ): Promise<void> {
    void _byUserId
    // The reassign endpoint requires a non-null toAgentId; for null (unassign) fall back to PATCH.
    if (toAgentId === null) {
      await updateCustomer(customerId, { assignedAgentId: null })
      return
    }
    const response = await api.post<Single<Customer>>(`customers/${customerId}/assignments`, {
      toAgentId,
      reason,
    })
    const updated = normalize(response.data)
    customers.value = customers.value.map((c) => (c.id === customerId ? updated : c))
  }

  async function uploadKycDoc(
    customerId: string,
    doc: Omit<KycDoc, 'id' | 'uploadedAt'>,
  ): Promise<void> {
    const response = await api.post<Single<KycDoc>>(`customers/${customerId}/kyc-docs`, {
      type: doc.type,
      fileName: doc.fileName,
      uploadedByAgentId: doc.uploadedByAgentId || null,
      verified: doc.verified,
    })
    const created = response.data
    customers.value = customers.value.map((c) =>
      c.id === customerId ? { ...c, kycDocs: [...c.kycDocs, created] } : c,
    )
  }

  async function removeKycDoc(customerId: string, docId: string): Promise<void> {
    await api.delete(`customers/${customerId}/kyc-docs/${docId}`)
    customers.value = customers.value.map((c) =>
      c.id === customerId ? { ...c, kycDocs: c.kycDocs.filter((d) => d.id !== docId) } : c,
    )
  }

  async function verifyKycDoc(customerId: string, docId: string): Promise<void> {
    const response = await api.patch<Single<KycDoc>>(
      `customers/${customerId}/kyc-docs/${docId}/verify`,
    )
    const updated = response.data
    customers.value = customers.value.map((c) =>
      c.id === customerId
        ? {
            ...c,
            kycDocs: c.kycDocs.map((d) => (d.id === docId ? updated : d)),
          }
        : c,
    )
  }

  async function mergeCustomers(primaryId: string, duplicateId: string): Promise<void> {
    const response = await api.post<Single<Customer>>(`customers/${primaryId}/merge`, {
      duplicateId,
    })
    const merged = normalize(response.data)
    customers.value = customers.value
      .filter((c) => c.id !== duplicateId)
      .map((c) => (c.id === primaryId ? merged : c))
  }

  // ── Referral links ───────────────────────────────────────────────────────
  function getActiveLinksForAgent(agentId: string): CustomerReferralLink[] {
    return links.value.filter((l) => l.agentId === agentId && !l.revoked)
  }

  async function createLink(
    payload: Omit<
      CustomerReferralLink,
      'id' | 'token' | 'generatedAt' | 'clicks' | 'leads' | 'policies' | 'revoked'
    >,
  ): Promise<CustomerReferralLink> {
    const response = await api.post<Single<CustomerReferralLink>>('customer-referral-links', {
      agentId: payload.agentId,
      productId: payload.productId,
      campaign: payload.campaign,
    })
    const fresh = response.data
    links.value = [...links.value, fresh]
    return fresh
  }

  async function revokeLink(linkId: string): Promise<void> {
    await api.delete(`customer-referral-links/${linkId}`)
    links.value = links.value.map((l) => (l.id === linkId ? { ...l, revoked: true } : l))
  }

  return {
    // list state (server-paginated)
    list,
    listMeta,
    listFilters,
    listLoading,
    listError,
    loadPage,
    // detail-cache state
    customers,
    links,
    loading,
    loaded,
    error,
    load,
    ensureDetail,
    loadReferralLinks,
    // helpers
    getCustomer,
    getCustomersByAssignedAgent,
    unassignedCustomers,
    thaiAge,
    kycStatus,
    // mutations
    createCustomer,
    updateCustomer,
    setActive,
    assignCustomer,
    uploadKycDoc,
    removeKycDoc,
    verifyKycDoc,
    mergeCustomers,
    // referral
    getActiveLinksForAgent,
    createLink,
    revokeLink,
  }
})
