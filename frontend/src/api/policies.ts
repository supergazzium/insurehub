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

// ── C-11 Draft endpoints ─────────────────────────────────────────────────
// Back the wizard's auto-save + resume-from-draft flow. The permissive
// payload (any subset of PolicyRequest fields) is intentional — backend
// enforces state-machine gates on the transition endpoints below, not
// on the shape of the draft itself. See B3-wizard-ia.md §7.

/** POST /policies/draft — creates a status='draft' row. Does NOT mint
 *  quote_no or application_no. Emits draftCreated PolicyEvent. */
export function createDraftPolicy(payload: Record<string, unknown>) {
  return api.post<Single<Policy>>('policies/draft', payload)
}

/** PATCH /policies/{id}/draft — updates a draft in place. 409 with
 *  `code:not_draft` if the row has already been promoted. */
export function updateDraftPolicy(id: string, payload: Record<string, unknown>) {
  return api.patch<Single<Policy>>(`policies/${id}/draft`, payload)
}

/** PATCH /policies/{id} — general update for a NON-draft policy (issued /
 *  active / quotation / …). The wizard uses this when editing a policy that
 *  has moved past draft, since /draft rejects non-draft rows. */
export function updatePolicy(id: string, payload: Record<string, unknown>) {
  return api.patch<Single<Policy>>(`policies/${id}`, payload)
}

/** POST /policies/{id}/promote-to-quotation — mints quote_no and flips
 *  status to `quotation`. Backend rejects any source state other than
 *  draft with `code:invalid_transition`. */
export function promotePolicyToQuotation(id: string) {
  return api.post<Single<Policy>>(`policies/${id}/promote-to-quotation`)
}

/** POST /policies/{id}/promote-to-submitted — mints application_no and
 *  flips to `submitted`. Accepts source state = draft (short path) or
 *  quotation (two-step path). */
export function promotePolicyToSubmitted(id: string) {
  return api.post<Single<Policy>>(`policies/${id}/promote-to-submitted`)
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

/** C-8 — Issue Policy modal payload. All strings ISO. `force=true`
 *  bypasses the soft-duplicate policyNo check. */
export interface IssuePolicyPayload {
  policyNo: string
  issueDate: string
  periodPaidEnd?: string | null
  policyEnd?: string | null
  mailingAddByPolicy?: string | null
  mailingDate?: string | null
  mailingNote?: string | null
}

/** Response body for a soft-duplicate policyNo 409. Frontend surfaces the
 *  `existing` block as a "already used by … — proceed anyway?" banner. */
export interface DuplicatePolicyNoError {
  code: 'duplicate_policy_no'
  message: string
  existing: {
    id: string
    quoteNo: string | null
    applicationNo: string | null
    status: string
  }
}

/** POST /policies/{id}/issue. See B5-issue-modal.md.
 *  Guard: policy.status must be `approved` server-side (409 otherwise).
 *  Soft-duplicate on policyNo returns 409 with DuplicatePolicyNoError;
 *  pass { force: true } after operator confirmation to bypass. */
export function issuePolicy(id: string, payload: IssuePolicyPayload, opts?: { force?: boolean }) {
  const qs = opts?.force ? '?force=1' : ''
  return api.post<Single<Policy>>(`policies/${id}/issue${qs}`, payload)
}
