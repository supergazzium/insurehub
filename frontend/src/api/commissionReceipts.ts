import { api, buildQuery, getToken, API_BASE_URL } from './client'

export type CommissionType = 'MAIN' | 'OV' | 'ADJ'
export type ReceivableStatus = 'Pending' | 'Matched' | 'Mismatch' | 'Received' | 'No Commission'
export type AdjustmentKind = 'endorsement' | 'cancellation' | 'other'
export type DiffReason = 'rate' | 'endorsement' | 'cancellation' | 'no_policy_commission' | 'other'

export interface ReceivableHint {
  code: string
  label: string
  estimate?: number | null
}

export interface ReceivableRow {
  id: string
  commissionType: CommissionType
  lineNo: number
  adjustmentKind: AdjustmentKind | null
  adjustmentKindLabel: string | null
  policyEventId: string | null
  policyId: string
  policyNo: string | null
  applicationNo: string | null
  policyYear: number | null
  policyStatus: string | null
  cancelDate: string | null
  importedFromLegacy: boolean
  customerName: string | null
  customerCode: string | null
  insurerId: string | null
  insurerName: string | null
  agentCode: string | null
  agentName: string | null
  productName: string | null
  effectiveDate: string | null
  mainPremium: number | null
  netPremium: number | null
  policyCarrierAmount: number | null
  policyCarrierRate: number | null
  rate: number | null
  expectedAmount: number
  statementAmount: number | null
  receivedAmount: number | null
  differenceAmount: number | null
  status: ReceivableStatus
  receivedDate: string | null
  receivedByName: string | null
  batchId: string | null
  batchNo: string | null
  note: string | null
  diffReason: DiffReason | null
  diffReasonLabel: string | null
  diffNote: string | null
  source: 'system' | 'legacy'
  version: number
  hints: ReceivableHint[]
  suggestedReason: DiffReason | null
}

export interface ReceivableFilters {
  insurerId?: number
  monthFrom?: string
  monthTo?: string
  includeOutstanding?: boolean
  policyYear?: number
  type?: CommissionType | 'ALL' | 'MAIN_ADJ'
  status?: ReceivableStatus
  q?: string
  all?: boolean
}

export interface AuditRow {
  action: string
  at: string | null
  actor: string
  reason: string | null
  old: Record<string, unknown> | null
  new: Record<string, unknown> | null
}

export interface ReconSummary {
  expected: number
  received: number
  outstanding: number
  mismatchCount: number
  mismatchAmount: number
  noCommissionCount: number
  adjustmentReceived: number
  adjustmentOpen: number
  receivedWithDiff: number
}

export interface ReceiptBatch {
  id: string
  batchNo: string | null
  insurerId: string
  insurerName: string | null
  policyYear: number | null
  statementMonth: string | null
  statementTotal: number | null
  receivedCount: number
  receivedTotal: number
  difference: number | null
  statementDate: string | null
  receivedFileDate: string | null
  remark: string | null
  fileCount: number
  createdAt: string | null
}

export interface ReceiptFile {
  id: string
  originalFilename: string
  mimeType: string | null
  fileSize: number
  uploadedAt: string | null
}

export interface BulkRowError { id: string; message: string }

export interface StatementLine { ref: string; amount: number | null }

export interface StatementMatchEntry {
  line: number
  ref: string
  amount: number | null
  receivableId: string
  version: number
  policyId: string
  policyNo: string | null
  expectedAmount: number
  difference: number | null
  status: ReceivableStatus
}

export interface StatementMatchResult {
  matched: StatementMatchEntry[]
  notFound: { line: number; ref: string; amount: number | null }[]
  otherInsurer: { line: number; ref: string; amount: number | null; policyNo: string | null; insurerName: string | null }[]
  alreadyClosed: StatementMatchEntry[]
  duplicates: { line: number; ref: string; amount: number | null }[]
}

export interface AdjustmentSuggestion {
  kind: AdjustmentKind
  rate: number | null
  amount: number | null
  basis: string
  events: {
    id: string
    seq: number | null
    reason: string | null
    effectiveDate: string | null
    additionalPremium: number
    estimatedCommission: number | null
    linked: boolean
  }[]
}

export function fetchReceivables(filters: ReceivableFilters) {
  return api.get<{ data: ReceivableRow[]; meta: { truncated: boolean; limit: number } }>(
    `commission-receivables${buildQuery(filters as Record<string, unknown>)}`,
  )
}

export function fetchReceivable(id: string) {
  return api.get<{ data: ReceivableRow; rows: ReceivableRow[] }>(`commission-receivables/${id}`)
}

export function reviewReceivable(id: string, statementAmount: number, version: number, note?: string) {
  return api.patch<{ data: ReceivableRow }>(`commission-receivables/${id}/review`, { statementAmount, note, version })
}

export function bulkReview(items: { id: string; statementAmount: number; version: number }[]) {
  return api.post<{ data: ReceivableRow[] }>('commission-receivables/bulk-review', { items })
}

export interface ReceivePayload {
  receivedAmount?: number | null
  receivedDate: string
  receiptBatchId?: number | null
  diffReason?: DiffReason | null
  diffNote?: string | null
  version: number
}

export function receiveReceivable(id: string, payload: ReceivePayload) {
  return api.post<{ data: ReceivableRow }>(`commission-receivables/${id}/receive`, payload)
}

export function bulkReceive(payload: {
  receivedDate: string
  receiptBatchId?: number | null
  items: { id: string; version: number; receivedAmount?: number | null; diffReason?: DiffReason | null; diffNote?: string | null }[]
}) {
  return api.post<{ data: ReceivableRow[] }>('commission-receivables/bulk-receive', payload)
}

export function statementMatch(insurerId: number, lines: StatementLine[], type: 'MAIN' | 'OV' = 'MAIN') {
  return api.post<{ data: StatementMatchResult }>('commission-receivables/statement-match', { insurerId, type, lines })
}

export function markNoCommission(id: string, reason: string, version: number) {
  return api.post<{ data: ReceivableRow }>(`commission-receivables/${id}/mark-no-commission`, { reason, version })
}

export function reopenReceivable(id: string, reason: string, version: number) {
  return api.post<{ data: ReceivableRow; meta: { warning: string | null } }>(`commission-receivables/${id}/reopen`, { reason, version })
}

export function resyncExpected(id: string) {
  return api.post<{ data: ReceivableRow }>(`commission-receivables/${id}/resync-expected`, {})
}

export function createAdjustment(payload: { policyId: string; kind: AdjustmentKind; expectedAmount: number; policyEventId?: string | null; note?: string | null }) {
  return api.post<{ data: ReceivableRow }>('commission-receivables/adjustments', payload)
}

export function suggestAdjustment(policyId: string, kind: AdjustmentKind) {
  return api.get<{ data: AdjustmentSuggestion }>(`commission-receivables/adjustments/suggest${buildQuery({ policyId, kind })}`)
}

export function deleteAdjustment(id: string) {
  return api.delete<{ data: { deleted: boolean } }>(`commission-receivables/${id}`)
}

export function fetchReceivableAudit(id: string) {
  return api.get<{ data: AuditRow[] }>(`commission-receivables/${id}/audit-log`)
}

export function fetchReconSummary(filters: ReceivableFilters) {
  return api.get<{ data: ReconSummary }>(`commission-reconciliation/summary${buildQuery(filters as Record<string, unknown>)}`)
}

export function receivablesExportPath(filters: ReceivableFilters): string {
  return `commission-receivables/export${buildQuery(filters as Record<string, unknown>)}`
}

export function fetchReceiptBatches(insurerId?: number, policyYear?: number) {
  return api.get<{ data: ReceiptBatch[] }>(`commission-receipt-batches${buildQuery({ insurerId, policyYear })}`)
}

export function createReceiptBatch(payload: {
  insurerId: number
  policyYear?: number
  statementMonth?: string
  statementTotal?: number
  statementDate?: string
  receivedFileDate?: string
  remark?: string
}) {
  return api.post<{ data: ReceiptBatch }>('commission-receipt-batches', payload)
}

export function fetchReceiptBatch(id: string) {
  return api.get<{ data: ReceiptBatch & { files: ReceiptFile[] } }>(`commission-receipt-batches/${id}`)
}

export async function uploadReceiptFile(batchId: string, file: File): Promise<ReceiptFile> {
  const fd = new FormData()
  fd.append('file', file)
  const res = await fetch(`${API_BASE_URL}/commission-receipt-batches/${batchId}/files`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${getToken() ?? ''}`, Accept: 'application/json' },
    body: fd,
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error(body.message ?? `HTTP ${res.status}`)
  }
  return (await res.json()).data
}

export function receiptFilePath(fileId: string): string {
  return `commission-receipt-files/${fileId}/download`
}

export interface DashboardKpi {
  expectedInsurer: number
  potentialExpected: number
  receivablesMaterialised: number
  receivedInsurer: number
  outstanding: number
  mismatchCount: number
  mismatchAmount: number
  noCommissionCount: number
  adjustmentReceived: number
  adjustmentOpen: number
  agentPayable: number
  agentPaid: number
  agentMgmPaid: number
  expectedMargin: number
  realizedMargin: number
}
export interface MonthlyPoint { month: string; expected: number; received: number }
export interface InsurerOutstanding { insurer: string; amount: number }
export interface ReasonSummary { reason: DiffReason; label: string; count: number; difference: number }
export interface PolicyReconRow {
  policyId: string
  policyNo: string | null
  insurerExpected: number
  insurerReceived: number
  mainStatus: ReceivableStatus | null
  ovStatus: ReceivableStatus | null
  adjustments: number
  agentPayable: number
  agentPaid: number
  expectedMargin: number
  cashMargin: number
}
export interface ReconDashboard {
  kpi: DashboardKpi
  monthly: MonthlyPoint[]
  outstandingByInsurer: InsurerOutstanding[]
  mismatchByReason: ReasonSummary[]
  unresolvedMismatch: { count: number; difference: number }
  policyLevel: PolicyReconRow[]
}

export function fetchReconDashboard(filters: ReceivableFilters) {
  return api.get<{ data: ReconDashboard }>(`commission-reconciliation/dashboard${buildQuery(filters as Record<string, unknown>)}`)
}
