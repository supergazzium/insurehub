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
import { api, buildQuery, type Paginated, type Single } from '../api/client'

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
  notes: string
  events: PolicyEvent[]
  payments: PolicyPayment[]
  documents: PolicyDocument[]
}

const TODAY_BE = '2569-06-06'

export const usePolicyStore = defineStore('policies', () => {
  // ── State ────────────────────────────────────────────────────────────────
  const policies = ref<Policy[]>([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)

  // ── Helpers ──────────────────────────────────────────────────────────────
  function getPolicy(id: string): Policy | null {
    return policies.value.find((p) => p.id === id) ?? null
  }

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
    const sameMonth = policies.value.filter((p) => p.quoteNo.startsWith(`Q-${today}`)).length
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

  // ── Loaders ──────────────────────────────────────────────────────────────
  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const all: Policy[] = []
      let page = 1
      // eslint-disable-next-line no-constant-condition
      while (true) {
        const response = await api.get<Paginated<Policy>>(
          `policies${buildQuery({ page, perPage: 100 })}`,
        )
        all.push(...response.data)
        const meta = response.meta
        if (!meta || page >= meta.last_page) break
        page += 1
      }
      // List endpoint may not include child arrays — normalise.
      policies.value = all.map(normalize)
      loaded.value = true
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load policies.'
      throw err
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
    // state
    policies,
    loading,
    loaded,
    error,
    // helpers
    getPolicy,
    policiesForCustomer,
    policiesForAgent,
    totalsByStatus,
    // loaders
    load,
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
