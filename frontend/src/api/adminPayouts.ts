// Typed clients for /api/v1/admin/payouts — Phase 7b.
import { api, getToken } from './client'

export interface PayoutPreviewGroup {
  agentId: number
  agentCode: string
  agentName: string
  gross: number
  txnIds: number[]
  txnCount: number
  vatType: string
}

export interface PayoutPreview {
  periodFrom: string
  periodTo: string
  agentCount: number
  totalGross: number
  groups: PayoutPreviewGroup[]
}

export interface Payout {
  id: string
  agentId: string
  agentCode: string
  agentName: string
  periodFrom: string
  periodTo: string
  status: 'draft' | 'issued' | 'paid' | 'void'
  grossAmount: number
  whtRate: number
  whtAmount: number
  netAmount: number
  bankRef: string | null
  issuedAt: string | null
  paidAt: string | null
  createdAt: string
  transactions?: Array<{
    id: string; type: string; policyId: string
    policyNo: string | null; applicationNo: string | null
    basePremium: number; diffPct: number; amount: number; isReversal: boolean
  }>
}

export function previewPayouts(periodFrom: string, periodTo: string, agentIds?: number[]) {
  return api.post<PayoutPreview>('admin/payouts/preview', { periodFrom, periodTo, agentIds })
}

export function createPayouts(periodFrom: string, periodTo: string, agentIds?: number[]) {
  return api.post<{ created: number; payouts: Payout[] }>('admin/payouts', {
    periodFrom, periodTo, agentIds,
  })
}

export function fetchPayoutList(filters: { status?: string; agentId?: string; perPage?: number } = {}) {
  const q = new URLSearchParams()
  if (filters.status) q.set('status', filters.status)
  if (filters.agentId) q.set('agentId', filters.agentId)
  if (filters.perPage) q.set('perPage', String(filters.perPage))
  const suffix = q.toString() ? `?${q.toString()}` : ''
  return api.get<{ data: Payout[] }>(`admin/payouts${suffix}`)
}

export function fetchPayout(id: string) {
  return api.get<{ data: Payout }>(`admin/payouts/${id}`)
}

export function issuePayout(id: string) {
  return api.post<{ data: Payout }>(`admin/payouts/${id}/issue`)
}
export function payPayout(id: string, bankRef: string) {
  return api.post<{ data: Payout }>(`admin/payouts/${id}/pay`, { bankRef })
}
export function voidPayout(id: string, reason: string) {
  return api.post<{ data: Payout }>(`admin/payouts/${id}/void`, { reason })
}

/**
 * Trigger a browser download of the PDF. Uses window.open with a bearer-tokened
 * URL is awkward — we do a fetch → blob → object-URL → simulated click instead.
 */
export async function downloadPayoutPdf(id: string, filename: string): Promise<void> {
  const base = (import.meta.env.VITE_API_BASE_URL as string) || 'http://127.0.0.1:8000/api/v1'
  const res = await fetch(`${base}/admin/payouts/${id}/pdf`, {
    headers: { Authorization: `Bearer ${getToken() ?? ''}` },
  })
  if (!res.ok) throw new Error(`PDF download failed: HTTP ${res.status}`)
  const blob = await res.blob()
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
