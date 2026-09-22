// รับค่าคอมจากบริษัทประกัน — insurer commission receipt API client.
import { api, buildQuery, getToken, API_BASE_URL } from './client'

export type CommissionType = 'MAIN' | 'OV'
export type ReceivableStatus = 'Pending' | 'Matched' | 'Mismatch' | 'Received' | 'No Commission'

export interface ReceivableRow {
  id: string
  commissionType: CommissionType
  policyId: string
  policyNo: string | null
  applicationNo: string | null
  policyYear: number | null
  customerName: string
  insurerName: string | null
  agentCode: string | null
  expectedAmount: number
  statementAmount: number | null
  receivedAmount: number | null
  differenceAmount: number | null
  status: ReceivableStatus
  receivedDate: string | null
  version: number
}

export interface ReceivableDetail {
  id: string
  commissionType: CommissionType
  policyId: string
  policyNo: string | null
  applicationNo: string | null
  policyYear: number | null
  insurerName: string | null
  expectedAmount: number
  statementAmount: number | null
  receivedAmount: number | null
  differenceAmount: number | null
  status: ReceivableStatus
  receivedDate: string | null
  note: string | null
  version: number
}

export interface ReceivableFilters {
  policyYear?: number
  insurerId?: number
  type?: CommissionType | 'ALL'
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
}

export interface ReceiptBatch {
  id: string
  batchNo: string | null
  insurerId: string
  insurerName: string | null
  policyYear: number | null
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

export function fetchReceivables(filters: ReceivableFilters) {
  return api.get<{ data: ReceivableRow[] }>(`commission-receivables${buildQuery(filters as Record<string, unknown>)}`)
}
export function fetchReceivable(id: string) {
  return api.get<{ data: ReceivableDetail; sibling: ReceivableDetail | null }>(`commission-receivables/${id}`)
}
export function reviewReceivable(id: string, statementAmount: number, note: string | undefined, version: number) {
  return api.patch<{ data: ReceivableDetail }>(`commission-receivables/${id}/review`, { statementAmount, note, version })
}
export function confirmReceived(id: string, receivedAmount: number, receivedDate: string, version: number, receiptBatchId?: number) {
  return api.post<{ data: ReceivableDetail }>(`commission-receivables/${id}/confirm-received`, { receivedAmount, receivedDate, version, receiptBatchId })
}
export function markNoCommission(id: string, reason: string, version: number) {
  return api.post<{ data: ReceivableDetail }>(`commission-receivables/${id}/mark-no-commission`, { reason, version })
}
export function reopenReceivable(id: string, reason: string, version: number) {
  return api.post<{ data: ReceivableDetail }>(`commission-receivables/${id}/reopen`, { reason, version })
}
export function fetchReceivableAudit(id: string) {
  return api.get<{ data: AuditRow[] }>(`commission-receivables/${id}/audit-log`)
}
export function fetchReconSummary(policyYear?: number, insurerId?: number) {
  return api.get<{ data: ReconSummary }>(`commission-reconciliation/summary${buildQuery({ policyYear, insurerId })}`)
}

// Receipt batches + files
export function fetchReceiptBatches(insurerId?: number, policyYear?: number) {
  return api.get<{ data: ReceiptBatch[] }>(`commission-receipt-batches${buildQuery({ insurerId, policyYear })}`)
}
export function createReceiptBatch(payload: {
  insurerId: number; policyYear?: number; statementDate?: string; receivedFileDate?: string; remark?: string
}) {
  return api.post<{ data: ReceiptBatch }>('commission-receipt-batches', payload)
}
export function fetchReceiptBatch(id: string) {
  return api.get<{ data: ReceiptBatch & { files: ReceiptFile[] } }>(`commission-receipt-batches/${id}`)
}
/** File upload — raw fetch (multipart), since the api wrapper is JSON-only. */
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
export function receiptFileDownloadUrl(fileId: string): string {
  return `${API_BASE_URL}/commission-receipt-files/${fileId}/download`
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
  agentPayable: number
  agentPaid: number
  expectedMargin: number
  realizedMargin: number
}
export interface MonthlyPoint { month: string; expected: number; received: number }
export interface InsurerOutstanding { insurer: string; amount: number }
export interface PolicyReconRow {
  policyId: string
  policyNo: string | null
  insurerExpected: number
  insurerReceived: number
  mainStatus: ReceivableStatus | null
  ovStatus: ReceivableStatus | null
  agentPayable: number
  agentPaid: number
  expectedMargin: number
  cashMargin: number
}
export interface ReconDashboard {
  kpi: DashboardKpi
  monthly: MonthlyPoint[]
  outstandingByInsurer: InsurerOutstanding[]
  policyLevel: PolicyReconRow[]
}

export function fetchReconDashboard(policyYear?: number, insurerId?: number) {
  return api.get<{ data: ReconDashboard }>(`commission-reconciliation/dashboard${buildQuery({ policyYear, insurerId })}`)
}

