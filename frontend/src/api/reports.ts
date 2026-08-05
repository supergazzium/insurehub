// Typed clients for /api/v1/reports/* endpoints (added in Phase 4).
// These are direct-query analytics — no writes, no validation. Errors bubble
// up as ApiError from the request wrapper.

import { api, buildQuery } from './client'

export interface ApiEnvelope<T> {
  data: T
  meta?: Record<string, unknown>
}

export interface ApiList<T> {
  data: T[]
  meta?: Record<string, unknown>
}

// ── Dashboard KPIs ───────────────────────────────────────────────────────

export interface DashboardKpis {
  totalPolicies: number
  activePolicies: number
  cancelledPolicies: number
  expiring60d: number
  inForcePremium: number
  totalCustomers: number
  totalAgents: number
}

export function fetchDashboardKpis(): Promise<ApiEnvelope<DashboardKpis>> {
  return api.get<ApiEnvelope<DashboardKpis>>('reports/dashboard-kpis')
}

// ── Expiring soon ─────────────────────────────────────────────────────────

export interface ExpiringPolicy {
  policyId: string
  applicationNo: string | null
  policyNo: string | null
  expiryDate: string
  daysRemaining: number
  annualPremium: number
  customerCode: string | null
  customerName: string
  customerEmail?: string | null
  customerPhone?: string | null
  customerType?: string | null
  agentCode: string | null
  agentName: string
  agentEmail?: string | null
  carrierId?: string | null
  carrierCode: string | null
  carrierName: string | null
  carrierInsureType?: string | null
  productId?: string | null
  productCode: string | null
  productName: string | null
  productType?: string | null
  productMainRider?: string | null
  motorLicenseNo?: string | null
  // Phase 8b — renewal action timestamps (null if never done)
  lastContactedAt?: string | null
  lastNoticeSentAt?: string | null
  renewalStartedAt?: string | null
}

// Phase 8b — renewal actions
export interface RenewalContactedPayload {
  channel?: 'phone' | 'line' | 'email' | 'inperson' | 'other'
  note?: string
}
export interface QuoteHint {
  customerId: string | null
  productId: string | null
  carrierId: string | null
  writingAgentId: string | null
  refAppToId: string
  newOrRenew: 'renew'
}

export function markRenewalContacted(policyId: string, payload: RenewalContactedPayload = {}) {
  return api.post<{ message: string; event: { id: string; type: string; occurredAt: string; payload: unknown } }>(
    `policies/${policyId}/renewal/contacted`, payload,
  )
}

export function markRenewalStarted(policyId: string) {
  return api.post<{ message: string; quoteHint: QuoteHint }>(
    `policies/${policyId}/renewal/started`,
  )
}

export function sendRenewalNotice(policyId: string) {
  return api.post<{ message: string; sentTo: string; sentToAgent: boolean }>(
    `policies/${policyId}/renewal/send-notice`,
  )
}

export interface ExpiringSoonMeta {
  days: number
  from: string
  to: string
  currentPage: number
  perPage: number
  total: number
  lastPage: number
}

export interface ExpiringSoonSummary {
  totalInWindow: number
  urgentCount: number
}

export interface ExpiringSoonQuery {
  from?: string
  to?: string
  days?: number
  q?: string
  carrierId?: string
  productId?: string
  productType?: string
  insureType?: string
  page?: number
  perPage?: number
  sortBy?: 'expiryDate' | 'annualPremium' | 'customerName'
  sortDir?: 'asc' | 'desc'
}

export function fetchExpiringSoon(
  params: ExpiringSoonQuery = {},
): Promise<ApiList<ExpiringPolicy> & { meta: ExpiringSoonMeta; summary: ExpiringSoonSummary }> {
  return api.get<ApiList<ExpiringPolicy> & { meta: ExpiringSoonMeta; summary: ExpiringSoonSummary }>(
    `reports/expiring-soon${buildQuery(params as Record<string, unknown>)}`,
  )
}

// ── Active policies ───────────────────────────────────────────────────────

export interface ActivePolicyRow {
  policyId: string
  applicationNo: string | null
  policyNo: string | null
  customerCode: string | null
  customerName: string
  agentCode: string | null
  productCode: string | null
  productName: string | null
  effectiveDate: string | null
  expiryDate: string | null
  annualPremium: number
  coverage: number
}

export function fetchActivePolicies(params: { page?: number; perPage?: number } = {}) {
  return api.get<ApiList<ActivePolicyRow>>(`reports/active-policies${buildQuery(params)}`)
}

// ── Agent commission ledger ───────────────────────────────────────────────

export type LedgerParty = 'inh' | 'ag'

export interface CommissionLedgerRow {
  source: 'main' | 'rider'
  policyId: string
  applicationNo: string | null
  appDate: string | null
  agentCode: string | null
  party: LedgerParty
  commissionRate: number | null
  commissionAmount: number | null
  basePremium: number | null
}

export interface CommissionLedgerFilters {
  agentCode?: string
  party?: LedgerParty | ''
  fromDate?: string
  toDate?: string
  perPage?: number
}

export function fetchCommissionLedger(filters: CommissionLedgerFilters = {}) {
  return api.get<ApiList<CommissionLedgerRow> & { meta: { total: number; filter: Record<string, string | null> } }>(
    `reports/agent-commission-ledger${buildQuery({ ...filters })}`,
  )
}

// ── Agent performance ─────────────────────────────────────────────────────

export interface AgentPerformanceRow {
  month: string
  agentCode: string | null
  agentName: string
  appsWritten: number
  appsApproved: number
  premiumTotal: number
  commissionAgTotal: number
}

export function fetchAgentPerformance(fromMonth?: string, toMonth?: string) {
  return api.get<ApiList<AgentPerformanceRow>>(
    `reports/agent-performance${buildQuery({ fromMonth, toMonth })}`,
  )
}

// ── Product performance ───────────────────────────────────────────────────

export interface ProductPerformanceRow {
  productCode: string
  productName: string
  carrierCode: string | null
  productType: string | null
  policiesSold: number
  avgPremium: number
  cancelledCount: number
  cancellationRate: number
  totalPremium: number
}

export function fetchProductPerformance() {
  return api.get<ApiList<ProductPerformanceRow>>('reports/product-performance')
}

// ── New vs Renew by month ────────────────────────────────────────────────

export interface NewVsRenewRow {
  month: string
  kind: 'new' | 'renew' | 'other'
  policies: number
  premiumTotal: number
}

export function fetchNewVsRenewByMonth() {
  return api.get<ApiList<NewVsRenewRow>>('reports/new-vs-renew-by-month')
}

// ── Cancellation ledger ──────────────────────────────────────────────────

export interface CancellationRow {
  policyId: string
  applicationNo: string | null
  policyNo: string | null
  cancelStatus: string
  cancelDate: string | null
  annualPremium: number
  refundPremium: number | null
  refundTotalPremium: number | null
  netRefundAmount: number | null
  customerName: string
  agentCode: string | null
}

export function fetchCancellationLedger(params: { page?: number; perPage?: number } = {}) {
  return api.get<ApiList<CancellationRow>>(`reports/cancellation-ledger${buildQuery(params)}`)
}

// ── Rebate reconciliation ────────────────────────────────────────────────

export interface RebateLeg {
  calculated: number | null
  actual: number | null
  delta: number | null
}

export interface RebateReconciliationRow {
  rebateId: string
  policyId: string
  applicationNo: string | null
  rebateStatus: string | null
  earnDate: string | null
  agentCode: string | null
  inh: RebateLeg
  ov: RebateLeg
  ag: RebateLeg
}

export function fetchRebateReconciliation(params: { page?: number; perPage?: number } = {}) {
  return api.get<ApiList<RebateReconciliationRow>>(`reports/rebate-reconciliation${buildQuery(params)}`)
}

/**
 * PATCH a single rebate row — used by the inline editor on /commissions/rebates.
 */
export interface RebateUpdatePayload {
  calculatedAmount?: number | null
  actualAmount?: number | null
  calculatedOv?: number | null
  actualOv?: number | null
  calculatedAgentAmount?: number | null
  actualAgentAmount?: number | null
  rebateStatus?: string | null
  ovStatus?: string | null
  agentRebateStatus?: string | null
  validateAmount?: string | null
  validateOv?: string | null
  agentCheckStatus?: string | null
  earnDate?: string | null
  ovDate?: string | null
  agentReceiveDate?: string | null
}

export interface RebateRowResponse {
  id: string
  policyId: string
  rebateStatus: string | null
  earnDate: string | null
  ovStatus: string | null
  ovDate: string | null
  calculatedAmount: number | null
  actualAmount: number | null
  calculatedOv: number | null
  actualOv: number | null
  validateAmount: string | null
  validateOv: string | null
  agentRebateStatus: string | null
  agentReceiveDate: string | null
  calculatedAgentAmount: number | null
  actualAgentAmount: number | null
  agentCheckStatus: string | null
}

export function updateRebate(id: string, patch: RebateUpdatePayload) {
  return api.patch<{ data: RebateRowResponse }>(`policy-rebates/${id}`, patch)
}
