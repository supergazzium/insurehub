// ทำจ่ายค่าคอมประจำเดือน (ตัวแทน) — agent commission payout batch API client.
import { api, buildQuery, getToken, API_BASE_URL } from './client'

export type BatchStatus = 'DRAFT' | 'GENERATED' | 'APPROVED' | 'PAID' | 'CANCELLED'

export interface PreviewAgentRow {
  agentId: string | null
  agentCode: string | null
  agentName: string
  vatType: string | null
  itemCount: number
  amount: number
}
export interface PreviewResult {
  agents: PreviewAgentRow[]
  totals: { totalAgents: number; totalItems: number; totalAmount: number }
  warnings: string[]
}

export interface BatchRow {
  id: string
  fromDate: string | null
  toDate: string | null
  status: BatchStatus
  totalAgents: number
  totalItems: number
  totalAmount: number
  paymentDate: string | null
  paymentReference: string | null
  approvedAt?: string | null
  calcVersion?: number
  netTotal?: number | null
  whtTotal?: number | null
  note: string | null
  paidAt: string | null
  createdAt: string | null
}

export type LineType = 'base' | 'self_mgmt' | 'referral' | 'override'

export interface BatchItemRow {
  policyId: string
  policyNo: string | null
  applicationNo: string | null
  lineType?: LineType
  lineLabel?: string
  sourceAgent?: string | null
  ratioLabel?: string | null
  override?: boolean
  amount: number
  source: string | null
  status: string
}
export interface PayeeTax {
  base: number
  selfMgmt: number
  referral: number
  override: number
  taxable: number
  vat: number
  total: number
  whtRate: number
  whtForm: string | null
  wht: number
  net: number
  certificate: string | null
  bankName: string | null
  hasBankAccount: boolean
}
export interface BatchAgentRow {
  agentId: string | null
  agentCode: string | null
  agentName: string
  vatType: string | null
  itemCount: number
  commission: number
  deduct: number
  net: number
  payeeId?: string | null
  payeeKind?: string | null
  tax?: PayeeTax
  items: BatchItemRow[]
}
export interface BatchAdjustment {
  id: string
  agentId: string | null
  agentCode: string | null
  type: string
  typeLabel?: string
  policyNo?: string | null
  amount: number
  reason: string
  createdAt: string | null
}
export interface BatchDetail extends BatchRow {
  agents: BatchAgentRow[]
  adjustments: BatchAdjustment[]
}
export interface BatchMeta {
  canApprove: boolean
  canPay: boolean
}

export function previewPayout(fromDate: string, toDate: string) {
  return api.post<PreviewResult>('commission-payout-batches/preview', { fromDate, toDate })
}

// ── Agent-first payout ─────────────────────────────────────────────────────
export interface AgentOutstandingRow {
  agentId: string | null
  agentCode: string | null
  agentName: string
  vatType: string | null
  itemCount: number
  amount: number
  whtRate?: number
  byType?: Record<LineType, number>
  waitingCount?: number
  waitingAmount?: number
  netAmount?: number
  pendingDeduction?: number
}
export interface AgentOutstandingResult {
  agents: AgentOutstandingRow[]
  totals: { totalAgents: number; totalItems: number; totalAmount: number; totalWaiting?: number; totalNet?: number }
  warnings?: string[]
}
export interface LineReason {
  code: string
  label: string
}
export interface LineOverride {
  id: string
  reason: string
  by: string | null
  at: string | null
  bypassed: LineReason[]
}
export interface AgentDetailItem {
  key: string
  lineType: LineType
  lineLabel: string
  policyId: number
  policyNo: string | null
  applicationNo: string | null
  customerName: string | null
  policyDate: string | null
  sourceAgent: string | null
  basePremium: number
  agentCommission: number
  riderCommission: number
  amount: number
  entitlement: number
  ratioLabel: string | null
  amountSource: string
  override: LineOverride | null
  reasons: LineReason[]
}
export interface PendingDeduction {
  id: string
  type: string
  typeLabel?: string
  amount: number
  reason: string | null
  policyId: string | null
  policyNo: string | null
  createdAt: string | null
}
export interface AgentDetailTotals {
  itemCount: number
  totalAmount: number
  byType: Record<LineType, number>
  pendingDeduction: number
  appliedDeduction: number
  carryForward: number
  taxableAmount: number
  vatAmount: number
  grossWithVat: number
  whtAmount: number
  netAmount: number
  waitingCount: number
  waitingAmount: number
}
export interface AgentDetailResult {
  agent: {
    agentId: string
    agentCode: string | null
    agentName: string
    vatType: string | null
    kind?: string
    whtRate?: number
    whtForm?: string
    whtFormLabel?: string | null
    bankName?: string | null
    hasBankAccount?: boolean
  } | null
  items: AgentDetailItem[]
  waiting: AgentDetailItem[]
  pendingDeductions?: PendingDeduction[]
  warnings?: string[]
  totals: AgentDetailTotals
  meta?: { canPay: boolean; canOverride: boolean }
}

/** Agent-first outstanding list — every agent with unpaid commission. */
export function fetchOutstandingByAgent(fromDate?: string, toDate?: string) {
  return api.get<AgentOutstandingResult>(
    `commission-payout-batches/by-agent${buildQuery({ fromDate, toDate })}`,
  )
}
/** One agent's outstanding line items. */
export function fetchAgentOutstandingDetail(agentId: string, fromDate?: string, toDate?: string) {
  return api.get<AgentDetailResult>(
    `commission-payout-batches/by-agent/${agentId}${buildQuery({ fromDate, toDate })}`,
  )
}
/** Pay one agent — creates a single-agent batch, approves, marks paid. */
export function payAgent(
  agentId: string,
  payload: { paymentDate: string; reference?: string; fromDate?: string; toDate?: string; note?: string },
) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/by-agent/${agentId}/pay`, payload)
}
export function fetchPayoutBatches(status?: BatchStatus | 'all') {
  return api.get<{ data: BatchRow[] }>(`commission-payout-batches${buildQuery({ status })}`)
}
export function createPayoutBatch(fromDate: string, toDate: string, note?: string) {
  return api.post<{ data: BatchRow }>('commission-payout-batches', { fromDate, toDate, note })
}
export function fetchPayoutBatch(id: string) {
  return api.get<{ data: BatchDetail; meta?: BatchMeta }>(`commission-payout-batches/${id}`)
}
export function overridePolicyConditions(policyId: number | string, reason: string) {
  return api.post<{ data: { id: string } }>(`policies/${policyId}/payout-override`, { reason })
}
export function revokePolicyOverride(policyId: number | string) {
  return api.delete<{ data: { revoked: number } }>(`policies/${policyId}/payout-override`)
}
export function agentStatementPath(batchId: string, agentCode: string): string {
  return `commission-payout-batches/${batchId}/agents/${encodeURIComponent(agentCode)}/pdf`
}
export function certificatePath(batchId: string, payeeId: string): string {
  return `commission-payout-batches/${batchId}/payees/${payeeId}/certificate`
}
export function bankCsvPath(batchId: string): string {
  return `commission-payout-batches/${batchId}/bank.csv`
}
export interface WhtReportRow {
  seq: number
  payeeId: string
  batchId: string
  taxId: string | null
  name: string | null
  agentCode: string | null
  address: string | null
  date: string | null
  incomeType: string
  rate: number
  amount: number
  wht: number
  condition: number
  certificate: string | null
}
export interface WhtReport {
  month: string
  form: 'pnd3' | 'pnd53'
  formLabel: string
  rows: WhtReportRow[]
  totals: { count: number; amount: number; wht: number }
}
export function fetchWhtReport(month: string, form: 'pnd3' | 'pnd53') {
  return api.get<{ data: WhtReport }>(`commission-payout-batches/wht-report${buildQuery({ month, form })}`)
}
export function whtReportCsvPath(month: string, form: 'pnd3' | 'pnd53'): string {
  return `commission-payout-batches/wht-report${buildQuery({ month, form, format: 'csv' })}`
}
export function addPayoutAdjustment(
  id: string, payload: { agentId?: string | null; agentCode?: string | null; amount: number; reason: string },
) {
  return api.post<{ data: { id: string } }>(`commission-payout-batches/${id}/adjustments`, payload)
}
export function markPayoutBatchPaid(id: string, paymentDate: string, reference?: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/mark-paid`, { paymentDate, reference })
}
export function cancelPayoutBatch(id: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/cancel`, {})
}
export function approvePayoutBatch(id: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/approve`, {})
}
export function unapprovePayoutBatch(id: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/unapprove`, {})
}

/** Single-agent PDF download URL (opens/downloads directly). */
export function agentPdfUrl(batchId: string, agentCode: string): string {
  return `${API_BASE_URL}/commission-payout-batches/${batchId}/agents/${encodeURIComponent(agentCode)}/pdf`
}

/** Generate a ZIP of all (or selected) agent PDFs — returns a Blob to save. */
export async function generatePayoutPdfs(batchId: string, agentCodes?: string[]): Promise<Blob> {
  const res = await fetch(`${API_BASE_URL}/commission-payout-batches/${batchId}/generate-pdfs`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${getToken() ?? ''}`,
      'Content-Type': 'application/json',
      Accept: 'application/zip',
    },
    body: JSON.stringify({ agentCodes }),
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error(body.message ?? `HTTP ${res.status}`)
  }
  return res.blob()
}

/** Reconciliation CSV export URL (opens/downloads directly). */
export function exportCsvUrl(batchId: string): string {
  return `${API_BASE_URL}/commission-payout-batches/${batchId}/export.csv`
}

