// Typed clients for /api/v1/policies list endpoint.
// Detail view (show / store / update) still uses the Policy type from stores/policies.ts.

import { api, buildQuery, type Paginated, type Single } from './client'
import type { Policy, PolicyStatus, NewOrRenew } from '../stores/policies'

/** Lean row returned by PolicyController::index — matches PolicyListResource. */
export interface PolicyListRow {
  id: string
  quoteNo: string | null
  applicationNo: string | null
  policyNo: string | null
  customerId: string
  productId: string
  carrierId: string
  writingAgentId: string
  status: PolicyStatus
  /** Original Thai label from lu_policy_status (e.g. อนุมัติแล้ว). Prefer for display. */
  statusLabel: string
  /** Group label (Approved / Pending / Cancelled / etc.). */
  statusGroup: string | null
  newOrRenew: NewOrRenew
  coverage: number
  annualPremium: number
  premiumMode: string
  appDate: string | null
  effectiveDate: string | null
  expiryDate: string | null
  issueDate: string | null
  cancelDate: string | null
  freelookActive: boolean
  premiumCheck: string
  customerCode: string | null
  customerName: string
  agentCode: string | null
  agentName: string
  carrierCode: string | null
  carrierName: string | null
  productCode: string | null
  productName: string | null
  motorLicenseNo: string | null
  motorVehicleBrand: string | null
  motorVehicleModel: string | null
}

export interface PolicyListFilters {
  q?: string
  status?: PolicyStatus | ''
  customerId?: string
  customerType?: 'individual' | 'corporate' | 'foreign' | ''
  /** Insurance type filter — matches carrier.insure_type. */
  insureType?: 'life' | 'non-life' | 'tax' | ''
  writingAgentId?: string
  carrierId?: string
  productId?: string
  newOrRenew?: NewOrRenew | ''
  /** effective_date range (existing) */
  fromDate?: string
  toDate?: string
  /** created_at range (new — "Create date" per spec) */
  createdFrom?: string
  createdTo?: string
  page?: number
  perPage?: number
}

export function fetchPolicyList(filters: PolicyListFilters = {}) {
  return api.get<Paginated<PolicyListRow>>(`policies${buildQuery({ ...filters })}`)
}

/** Fetch one policy with its full detail shape (riders/beneficiaries/etc). */
export function fetchPolicy(id: string) {
  return api.get<Single<Policy>>(`policies/${id}`)
}

/** Phase 6/9b — sectioned PATCH. Section = dates|premium|payment|notes|identifiers|motor|commission. */
export type PolicySection = 'dates' | 'premium' | 'payment' | 'notes' | 'identifiers' | 'motor' | 'commission'

export function patchPolicySection(id: string, section: PolicySection, payload: Record<string, unknown>) {
  return api.patch<Single<Policy>>(`policies/${id}/section/${section}`, payload)
}

/** Phase 6b — riders + beneficiaries + docs. */
export interface RiderInput {
  name: string
  premium: number
  slot?: number | null
  productId?: number | null
  comRateInh?: number | null
  comRateAg?: number | null
  comAmtInh?: number | null
  comAmtAg?: number | null
  notes?: string | null
}
export interface BeneficiaryInput {
  name: string
  relation?: string | null
  share: number
  slot?: number | null
}

export function syncPolicyRiders(id: string, riders: RiderInput[]) {
  return api.put<Single<Policy>>(`policies/${id}/riders`, { riders })
}
export function syncPolicyBeneficiaries(id: string, beneficiaries: BeneficiaryInput[]) {
  return api.put<Single<Policy>>(`policies/${id}/beneficiaries`, { beneficiaries })
}

export async function uploadPolicyDocument(id: string, type: string, file: File) {
  const form = new FormData()
  form.append('type', type)
  form.append('file', file)
  return api.post<{ data: { id: string; type: string; fileName: string; uploadedAt: string } }>(
    `policies/${id}/documents/upload`, form,
  )
}
export function deletePolicyDocument(policyId: string, docId: string) {
  return api.delete<{ message: string }>(`policies/${policyId}/documents/${docId}`)
}
/** Build the URL for a stored policy document. Backend streams the file
 *  inline with the correct Content-Type after tenant/session checks. */
export function policyDocumentDownloadUrl(policyId: string, docId: string): string {
  const base = (import.meta.env.VITE_API_BASE_URL as string | undefined)
    ?? 'http://127.0.0.1:8000/api/v1'
  return `${base.replace(/\/+$/, '')}/policies/${policyId}/documents/${docId}/download`
}

/** Phase 9c — recompute all commission accrual for a policy at current rates. */
export function recomputeCommission(policyId: string) {
  return api.post<{ message: string; reversed: number; created: number; keyVersion: string }>(
    `policies/${policyId}/commission/recompute`,
  )
}
