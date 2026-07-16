// Policies store — backed by the Laravel API.
//
// Lifecycle methods (convertToApplication, submitToCarrier, issuePolicy,
// renewPolicy, cancelPolicy, lapsePolicy, reinstatePolicy) all funnel through
// `POST /policies/:id/events`, which atomically updates the policy status +
// fields and emits a `policy_events` row. payment/document recording uses the
// dedicated child endpoints (`POST /policies/:id/payments`, `…/documents`).
//
// All sync lookup helpers continue to read from the cached `policies` array.

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, type Single } from '../api/client'
import {
  fetchPolicyList,
  type PolicyListRow,
  type PolicyListFilters,
} from '../api/policies'

// Re-export list types so pages can import both the detail Policy and the
// lean PolicyListRow from a single store module.
export type { PolicyListRow, PolicyListFilters }

export type PolicyStatus =
  | 'quote'
  | 'application'
  | 'submitted'
  | 'issued'
  | 'active'
  | 'lapsed'
  | 'cancelled'
  | 'reinstated'
  | 'expired'

export type PolicyEventType =
  | 'created'
  | 'convertedToApplication'
  | 'submittedToCarrier'
  | 'issued'
  | 'premiumPaid'
  | 'renewed'
  | 'lapsed'
  | 'cancelled'
  | 'reinstated'
  | 'detailsUpdated'
  | 'documentUploaded'

export type PaymentMethod = 'bankTransfer' | 'creditCard' | 'cash' | 'cheque' | 'directDebit'
export type PolicyDocType =
  | 'application'
  | 'policy'
  | 'receipt'
  | 'medical'
  | 'endorsement'
  | 'cancellation'
  | 'other'

export interface PolicyEvent {
  id: string
  policyId: string
  type: PolicyEventType
  at: string
  byUserId: string
  payload: Record<string, string | number | null>
}

export interface PolicyPayment {
  id: string
  policyId: string
  paymentDate: string
  amount: number
  method: PaymentMethod
  reference: string
  recordedByUserId: string
}

export interface PolicyDocument {
  id: string
  policyId: string
  type: PolicyDocType
  fileName: string
  uploadedAt: string
  uploadedByUserId: string
}

export interface MotorDetails {
  vehicleBrand: string
  vehicleModel: string
  licenseNo: string
  engineNo: string
  chassisNo: string
  registerYear: string
  noPassenger: number
  typeDriver: string
  typeVehicle: string
  notes: string
}

export interface PropertyDetails {
  insuredName: string
  insuredAddress: string
  buildingCoverage: number
  furnitureCoverage: number
  stockCoverage: number
  otherCoverage: number
  otherDetail: string
  notes: string
}

export interface Beneficiary {
  name: string
  relation: string
  share: number
}

export interface Rider {
  name: string
  premium: number
  notes: string
}

export type NewOrRenew = 'new' | 'renew'

export interface Policy {
  id: string
  quoteNo: string
  applicationNo: string | null
  policyNo: string | null
  customerId: string
  productId: string
  carrierId: string
  writingAgentId: string
  coverage: number
  annualPremium: number
  premiumMode: 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single'
  quoteDate: string
  effectiveDate: string | null
  expiryDate: string | null
  issueDate: string | null
  nextPremiumDue: string | null
  cancelDate: string | null
  lapseDate: string | null
  policyYear: number
  actYear: number
  newOrRenew: NewOrRenew
  freelookActive: boolean
  riders: Rider[]
  beneficiaries: Beneficiary[]
  motor: MotorDetails | null
  property: PropertyDetails | null
  status: PolicyStatus
  /** Original Thai label from lu_policy_status. Prefer for display. */
  statusLabel?: string
  statusGroup?: string | null
  notes: string
  events: PolicyEvent[]
  payments: PolicyPayment[]
  documents: PolicyDocument[]
}

const TODAY_BE = '2569-06-06'

export const usePolicyStore = defineStore('policies', () => {
  // ── Paginated list state ─────────────────────────────────────────────────
  // The list view uses server-side pagination + filters. `list` is the
  // current page of lean rows returned by GET /api/v1/policies.
  const list = ref<PolicyListRow[]>([])
  const listMeta = ref<{ currentPage: number; lastPage: number; perPage: number; total: number } | null>(null)
  const listFilters = ref<PolicyListFilters>({ page: 1, perPage: 25 })
  const listLoading = ref(false)
  const listError = ref<string | null>(null)

  // ── Detail cache ─────────────────────────────────────────────────────────
  // `policies` holds full-detail Policy objects loaded on demand (by id).
  // Downstream code (detail panels, mutations) continues to read from this
  // cache; nothing is preloaded upfront.
  const policies = ref<Policy[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  // ── Helpers ──────────────────────────────────────────────────────────────
  function getPolicy(id: string): Policy | null {
    return policies.value.find((p) => p.id === id) ?? null
  }

  /**
   * Deprecated: policies are no longer preloaded. Callers should query the
   * server (`fetchPolicyList({ customerId })`) or use the child endpoint.
   * Kept for compat with pages that only need the cached subset.
   */
  function policiesForCustomer(customerId: string): Policy[] {
    return policies.value.filter((p) => p.customerId === customerId)
  }

  function policiesForAgent(agentId: string): Policy[] {
    return policies.value.filter((p) => p.writingAgentId === agentId)
  }

  const totalsByStatus = computed(() => {
    const out: Record<PolicyStatus, number> = {
      quote: 0,
      application: 0,
      submitted: 0,
      issued: 0,
      active: 0,
      lapsed: 0,
      cancelled: 0,
      reinstated: 0,
      expired: 0,
    }
    for (const p of policies.value) out[p.status]++
    return out
  })

  // ── Identifier generators (client-side; backend will be authoritative later) ─
  function nextQuoteNo(): string {
    const today = TODAY_BE.slice(0, 7)
    const sameMonth = policies.value.filter((p) => (p.quoteNo ?? '').startsWith(`Q-${today}`)).length
    return `Q-${today}-${String(sameMonth + 1).padStart(3, '0')}`
  }
  function nextApplicationNo(quoteNo: string): string {
    return 'APP-' + quoteNo.slice(2)
  }

  // ── Cache surgery ────────────────────────────────────────────────────────
  function upsertPolicy(updated: Policy): void {
    const idx = policies.value.findIndex((p) => p.id === updated.id)
    if (idx === -1) {
      policies.value = [updated, ...policies.value]
    } else {
      policies.value = policies.value.map((p) => (p.id === updated.id ? updated : p))
    }
  }

  // ── Server-paginated list loader ─────────────────────────────────────────

  /**
   * Fetch a page of policies from the server. `filters` overrides the store's
   * current filter state; omitted keys keep their existing value.
   * Never preloads everything — the total dataset is ~20k rows.
   */
  async function loadPage(filters: PolicyListFilters = {}): Promise<void> {
    listFilters.value = { ...listFilters.value, ...filters }
    listLoading.value = true
    listError.value = null
    try {
      const res = await fetchPolicyList(listFilters.value)
      list.value = res.data
      const m = res.meta
      listMeta.value = m
        ? { currentPage: m.current_page, lastPage: m.last_page, perPage: m.per_page, total: m.total }
        : null
    } catch (err) {
      listError.value = err instanceof Error ? err.message : 'Failed to load policies.'
      throw err
    } finally {
      listLoading.value = false
    }
  }

  /**
   * Legacy full-load shim — first page only. Used by pages that still expect
   * `policies.value` populated (CommissionEngine, old PolicyList). New code
   * should use loadPage() / ensureDetail(id).
   */
  const loaded = ref(false)
  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const res = await fetchPolicyList({ page: 1, perPage: 100 })
      // list rows are lean; upgrade to normalized Policy shape via minimal cast.
      policies.value = res.data.map((r) => normalize({
        id: r.id,
        quoteNo: r.quoteNo ?? '',
        applicationNo: r.applicationNo,
        policyNo: r.policyNo,
        customerId: r.customerId,
        productId: r.productId,
        carrierId: r.carrierId,
        writingAgentId: r.writingAgentId,
        coverage: r.coverage,
        annualPremium: r.annualPremium,
        premiumMode: r.premiumMode as Policy['premiumMode'],
        quoteDate: '',
        effectiveDate: r.effectiveDate,
        expiryDate: r.expiryDate,
        issueDate: r.issueDate,
        nextPremiumDue: null,
        cancelDate: r.cancelDate,
        lapseDate: null,
        policyYear: 1,
        actYear: 1,
        newOrRenew: r.newOrRenew,
        freelookActive: r.freelookActive,
        riders: [],
        beneficiaries: [],
        motor: null,
        property: null,
        status: r.status,
        notes: '',
        events: [],
        payments: [],
        documents: [],
      }))
      loaded.value = true
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load policies.'
    } finally {
      loading.value = false
    }
  }

  /**
   * Ensure the full-detail Policy for `id` is in the cache. Fetches from
   * /policies/{id} if missing. Returns the cached record.
   */
  async function ensureDetail(id: string, force = false): Promise<Policy | null> {
    if (!force) {
      const cached = getPolicy(id)
      if (cached && cached.events !== undefined) return cached
    }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<Single<Policy>>(`policies/${id}`)
      const p = normalize(res.data)
      upsertPolicy(p)
      return p
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load policy.'
      return null
    } finally {
      loading.value = false
    }
  }

  function normalize(p: Policy): Policy {
    return {
      ...p,
      // Legacy imports often have a null quoteNo; consumers concat it as a string.
      quoteNo: p.quoteNo ?? '',
      notes: p.notes ?? '',
      riders: p.riders ?? [],
      beneficiaries: p.beneficiaries ?? [],
      events: p.events ?? [],
      payments: p.payments ?? [],
      documents: p.documents ?? [],
    }
  }

  // ── Mutations / lifecycle ────────────────────────────────────────────────

  async function createQuote(payload: {
    customerId: string
    productId: string
    carrierId: string
    writingAgentId: string
    coverage: number
    annualPremium: number
    premiumMode: Policy['premiumMode']
    notes?: string
  }): Promise<Policy> {
    const quoteNo = nextQuoteNo()
    const response = await api.post<Single<Policy>>('policies', {
      quoteNo,
      customerId: payload.customerId,
      productId: payload.productId,
      carrierId: payload.carrierId,
      writingAgentId: payload.writingAgentId,
      coverage: payload.coverage,
      annualPremium: payload.annualPremium,
      premiumMode: payload.premiumMode,
      status: 'quote',
      notes: payload.notes ?? '',
    })
    const created = normalize(response.data)
    upsertPolicy(created)
    return created
  }

  async function createPolicyDirect(payload: {
    customerId: string
    productId: string
    carrierId: string
    writingAgentId: string
    coverage: number
    annualPremium: number
    premiumMode: Policy['premiumMode']
    policyNo: string | null
    applicationNo: string | null
    effectiveDate: string | null
    expiryDate: string | null
    issueDate: string | null
    nextPremiumDue: string | null
    status: PolicyStatus
    newOrRenew: NewOrRenew
    policyYear: number
    actYear: number
    notes?: string
  }): Promise<Policy> {
    const quoteNo = nextQuoteNo()
    const response = await api.post<Single<Policy>>('policies', {
      quoteNo,
      applicationNo: payload.applicationNo ?? nextApplicationNo(quoteNo),
      policyNo: payload.policyNo,
      customerId: payload.customerId,
      productId: payload.productId,
      carrierId: payload.carrierId,
      writingAgentId: payload.writingAgentId,
      coverage: payload.coverage,
      annualPremium: payload.annualPremium,
      premiumMode: payload.premiumMode,
      effectiveDate: payload.effectiveDate,
      expiryDate: payload.expiryDate,
      issueDate: payload.issueDate,
      nextPremiumDue: payload.nextPremiumDue,
      status: payload.status,
      newOrRenew: payload.newOrRenew,
      policyYear: payload.policyYear,
      actYear: payload.actYear,
      notes: payload.notes ?? '',
    })
    const created = normalize(response.data)
    upsertPolicy(created)
    return created
  }

  /** Internal: POST a lifecycle event and refresh the cached policy with the
   *  server's authoritative state + event list. */
  async function emitEvent(
    policyId: string,
    type: PolicyEventType,
    payload: Record<string, string | number | null> = {},
  ): Promise<Policy | null> {
    const response = await api.post<Single<Policy>>(`policies/${policyId}/events`, {
      type,
      payload,
    })
    const updated = normalize(response.data)
    upsertPolicy(updated)
    return updated
  }

  async function convertToApplication(policyId: string): Promise<void> {
    const current = getPolicy(policyId)
    if (!current || current.status !== 'quote') return
    await emitEvent(policyId, 'convertedToApplication', {
      applicationNo: nextApplicationNo(current.quoteNo),
    })
  }

  async function submitToCarrier(policyId: string): Promise<void> {
    const current = getPolicy(policyId)
    if (!current || current.status !== 'application') return
    await emitEvent(policyId, 'submittedToCarrier')
  }

  async function issuePolicy(
    policyId: string,
    policyNo: string,
    effectiveDate: string,
  ): Promise<void> {
    const current = getPolicy(policyId)
    if (!current || (current.status !== 'submitted' && current.status !== 'application')) return
    await emitEvent(policyId, 'issued', { policyNo, effectiveDate })
  }

  async function recordPremiumPayment(payload: {
    policyId: string
    paymentDate: string
    amount: number
    method: PaymentMethod
    reference: string
  }): Promise<void> {
    const current = getPolicy(payload.policyId)
    if (!current) return
    await api.post<Single<PolicyPayment>>(`policies/${payload.policyId}/payments`, {
      paymentDate: payload.paymentDate,
      amount: payload.amount,
      method: payload.method,
      reference: payload.reference,
    })
    // Re-fetch the policy so events + payments + status are all fresh.
    const refreshed = await api.get<Single<Policy>>(`policies/${payload.policyId}`)
    upsertPolicy(normalize(refreshed.data))
  }

  async function renewPolicy(
    policyId: string,
    newExpiry: string,
    newPremium: number,
  ): Promise<void> {
    const current = getPolicy(policyId)
    if (!current || (current.status !== 'active' && current.status !== 'issued')) return
    await emitEvent(policyId, 'renewed', { newExpiry, newPremium })
  }

  async function cancelPolicy(
    policyId: string,
    reason: string,
    cancelDate: string,
  ): Promise<void> {
    const current = getPolicy(policyId)
    if (!current) return
    await emitEvent(policyId, 'cancelled', { reason, cancelDate })
  }

  async function lapsePolicy(policyId: string, lapseDate: string): Promise<void> {
    const current = getPolicy(policyId)
    if (!current || current.status !== 'active') return
    await emitEvent(policyId, 'lapsed', { lapseDate })
  }

  async function reinstatePolicy(policyId: string, reinstateDate: string): Promise<void> {
    const current = getPolicy(policyId)
    if (!current || current.status !== 'lapsed') return
    await emitEvent(policyId, 'reinstated', { reinstateDate })
  }

  async function updatePolicyDetails(policyId: string, patch: Partial<Policy>): Promise<void> {
    const response = await api.patch<Single<Policy>>(`policies/${policyId}`, patch)
    upsertPolicy(normalize(response.data))
  }

  async function uploadDocument(
    policyId: string,
    doc: Omit<PolicyDocument, 'id' | 'policyId' | 'uploadedAt' | 'uploadedByUserId'>,
  ): Promise<void> {
    await api.post<Single<PolicyDocument>>(`policies/${policyId}/documents`, {
      type: doc.type,
      fileName: doc.fileName,
    })
    const refreshed = await api.get<Single<Policy>>(`policies/${policyId}`)
    upsertPolicy(normalize(refreshed.data))
  }

  async function removeDocument(policyId: string, docId: string): Promise<void> {
    await api.delete(`policies/${policyId}/documents/${docId}`)
    const current = getPolicy(policyId)
    if (current) {
      upsertPolicy({ ...current, documents: current.documents.filter((d) => d.id !== docId) })
    }
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
    policies,
    loading,
    loaded,
    error,
    load,
    ensureDetail,
    // helpers
    getPolicy,
    policiesForCustomer,
    policiesForAgent,
    totalsByStatus,
    // lifecycle
    createQuote,
    createPolicyDirect,
    convertToApplication,
    submitToCarrier,
    issuePolicy,
    recordPremiumPayment,
    renewPolicy,
    cancelPolicy,
    lapsePolicy,
    reinstatePolicy,
    updatePolicyDetails,
    uploadDocument,
    removeDocument,
  }
})
