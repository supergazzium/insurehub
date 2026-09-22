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
  note: string | null
  paidAt: string | null
  createdAt: string | null
}

export interface BatchItemRow {
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
  items: BatchItemRow[]
}
export interface BatchAdjustment {
  id: string
  agentId: string | null
  agentCode: string | null
  type: string
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
export function fetchPayoutBatches(status?: BatchStatus | 'all') {
  return api.get<{ data: BatchRow[] }>(`commission-payout-batches${buildQuery({ status })}`)
}
export function createPayoutBatch(fromDate: string, toDate: string, note?: string) {
  return api.post<{ data: BatchRow }>('commission-payout-batches', { fromDate, toDate, note })
}
export function fetchPayoutBatch(id: string) {
  return api.get<{ data: BatchDetail }>(`commission-payout-batches/${id}`)
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

