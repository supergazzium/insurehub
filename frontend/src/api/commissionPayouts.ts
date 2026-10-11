// ทำจ่ายค่าคอมประจำเดือน (ตัวแทน) — agent commission payout batch API client.
import { api, buildQuery, getToken, API_BASE_URL } from './client'

export type BatchStatus = 'DRAFT' | 'GENERATED' | 'APPROVED' | 'PAID' | 'CANCELLED' | 'REVERSED'
export type AdjustmentType = 'special_deduct' | 'bonus' | 'correction'

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
  periodLabel: string | null
  status: BatchStatus
  totalAgents: number
  totalItems: number
  totalAmount: number
  paymentDate: string | null
  paymentReference: string | null
  approvedAt?: string | null
  note: string | null
  paidAt: string | null
  reversedAt?: string | null
  reversalReason?: string | null
  reissuedFromBatchId?: string | null
  createdAt: string | null
}

export interface BatchItemRow {
  id?: string
  policyId: string
  policyNo: string | null
  applicationNo: string | null
  amount: number
  source: string | null
  status: string
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
  // Per-agent payment tracking (bulk run — each agent is a separate transfer).
  bankAccountNo?: string | null
  bankAccountName?: string | null
  whtAmount?: number
  netTransfer?: number
  paid?: boolean
  paidAt?: string | null
  paymentReference?: string | null
  reversed?: boolean
  pdfGeneratedAt?: string | null
  items: BatchItemRow[]
}
export interface BatchAdjustment {
  id: string
  agentId: string | null
  agentCode: string | null
  type: AdjustmentType | string
  amount: number
  reason: string
  createdAt: string | null
}
export interface BatchDetail extends BatchRow {
  agents: BatchAgentRow[]
  adjustments: BatchAdjustment[]
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
}
export interface AgentOutstandingResult {
  agents: AgentOutstandingRow[]
  totals: { totalAgents: number; totalItems: number; totalAmount: number }
}
export interface AgentDetailItem {
  policyId: number
  policyNo: string | null
  applicationNo: string | null
  basePremium: number
  agentCommission: number
  riderCommission: number
  amount: number
  amountSource: string
  policyDate: string | null
}
export interface AgentDetailResult {
  agent: { agentId: string; agentCode: string | null; agentName: string; vatType: string | null } | null
  items: AgentDetailItem[]
  totals: { itemCount: number; totalAmount: number }
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

// ── History / management ────────────────────────────────────────────────────
export interface HistoryFilters {
  fromDate?: string
  toDate?: string
  status?: BatchStatus | 'all'
  period?: string
  q?: string
  page?: number
  perPage?: number
}
export interface HistoryMeta { total: number; page: number; perPage: number }
export interface PayoutSummary {
  batchCount: number
  paidBatchCount: number
  pendingBatchCount: number
  reversedBatchCount: number
  paidAmount: number
  pendingAmount: number
  reversedAmount: number
  totalAgentsPaid: number
}
export interface AuditEntryRow {
  action: string
  at: string | null
  actor: string
  metadata: Record<string, unknown> | null
}
export interface ReferenceHit {
  batchId: string
  periodLabel: string | null
  status: BatchStatus
  paymentDate: string | null
  agentCode: string | null
  agentName: string | null
  reference: string | null
  amount: number
  level: 'agent' | 'batch'
}

/** Paginated batch history for the history page. */
export function fetchPayoutHistory(filters: HistoryFilters) {
  return api.get<{ data: BatchRow[]; meta: HistoryMeta }>(
    `commission-payout-batches/history${buildQuery(filters as Record<string, unknown>)}`,
  )
}
/** Reconciliation KPI summary. */
export function fetchPayoutSummary(fromDate?: string, toDate?: string) {
  return api.get<{ data: PayoutSummary }>(
    `commission-payout-batches/summary${buildQuery({ fromDate, toDate })}`,
  )
}
/** Per-batch audit timeline. */
export function fetchPayoutAuditLog(batchId: string) {
  return api.get<{ data: AuditEntryRow[] }>(`commission-payout-batches/${batchId}/audit-log`)
}
/** Resolve a bank-transfer reference to its batch/agent(s). */
export function findPayoutByReference(ref: string) {
  return api.get<{ data: ReferenceHit[] }>(
    `commission-payout-batches/find-by-reference${buildQuery({ ref })}`,
  )
}
/**
 * Create a DRAFT batch. Pass `agentIds` for a hand-picked selection (the
 * monthly bulk run pays 30–50 at once); omit it to include every eligible
 * agent (select-all). Dates optional (omit = all unpaid eligible).
 */
export function createPayoutBatch(payload: {
  fromDate?: string
  toDate?: string
  agentIds?: number[]
  periodLabel?: string
  note?: string
}) {
  return api.post<{ data: BatchRow }>('commission-payout-batches', payload)
}
export function fetchPayoutBatch(id: string) {
  return api.get<{ data: BatchDetail }>(`commission-payout-batches/${id}`)
}
export function addPayoutAdjustment(
  id: string,
  payload: { agentId?: string | null; agentCode?: string | null; amount: number; reason: string; type?: AdjustmentType },
) {
  return api.post<{ data: { id: string } }>(`commission-payout-batches/${id}/adjustments`, payload)
}
export function markPayoutBatchPaid(id: string, paymentDate: string, reference?: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/mark-paid`, { paymentDate, reference })
}
/** Pay ONE agent in the batch with that agent's own transfer reference. */
export function markAgentPaid(id: string, agentId: string, paymentDate: string, reference?: string) {
  return api.post<{ data: BatchRow }>(
    `commission-payout-batches/${id}/agents/${agentId}/mark-paid`, { paymentDate, reference },
  )
}
export function cancelPayoutBatch(id: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/cancel`, {})
}
/** Reverse (void) a PAID batch — wrong-payment handling. */
export function reversePayoutBatch(id: string, reason: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/reverse`, { reason })
}
/** Reverse one paid agent within a batch. */
export function reversePayoutAgent(id: string, agentId: string, reason: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/agents/${agentId}/reverse`, { reason })
}
/** Re-issue a corrected DRAFT batch after a reversal. Returns the new batch. */
export function reissuePayoutBatch(id: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/reissue`, {})
}
/** Exclude / re-include one line item before pay. */
export function setItemExcluded(id: string, itemId: string, excluded: boolean) {
  return api.post<{ data: BatchRow }>(
    `commission-payout-batches/${id}/items/${itemId}/${excluded ? 'exclude' : 'include'}`, {},
  )
}
export function approvePayoutBatch(id: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/approve`, {})
}
export function unapprovePayoutBatch(id: string) {
  return api.post<{ data: BatchRow }>(`commission-payout-batches/${id}/unapprove`, {})
}

/** Single-agent PDF download URL (raw; needs a Bearer token — not usable via window.open). */
export function agentPdfUrl(batchId: string, agentCode: string): string {
  return `${API_BASE_URL}/commission-payout-batches/${batchId}/agents/${encodeURIComponent(agentCode)}/pdf`
}

/**
 * Fetch one agent's PDF as a Blob WITH the auth header. The API routes require
 * a Bearer token, so a plain window.open navigation (which can't attach the
 * header) 500s. Callers save the returned blob.
 */
export async function downloadAgentPdf(batchId: string, agentCode: string): Promise<Blob> {
  const res = await fetch(agentPdfUrl(batchId, agentCode), {
    headers: { Authorization: `Bearer ${getToken() ?? ''}`, Accept: 'application/pdf' },
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error(body.message ?? `HTTP ${res.status}`)
  }
  return res.blob()
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

