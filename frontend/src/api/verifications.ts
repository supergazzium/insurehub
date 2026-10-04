import { api, buildQuery } from './client'
import type { VerifyRow } from './followups'

export interface VerifyDiffRow {
  field: string
  label: string
  before: string | null
  after: string | null
  currentChanged: boolean
}

export interface VerifyDetail extends VerifyRow {
  makerId?: string | null
  diff: VerifyDiffRow[]
  summary?: Array<{ field: string; label: string; value: string }>
}

export function fetchVerifications(scope: 'pending' | 'mine' | 'all' = 'pending') {
  return api.get<{ data: VerifyRow[]; meta: { pendingCount: number; enabled: boolean; canVerify: boolean } }>(
    `verifications${buildQuery({ scope })}`,
  )
}

export function fetchVerification(id: string) {
  return api.get<{ data: VerifyDetail; meta: { canVerify: boolean; isMaker: boolean } }>(`verifications/${id}`)
}

export function approveVerification(id: string) {
  return api.post<{ data: VerifyDetail }>(`verifications/${id}/approve`, {})
}

export function rejectVerification(id: string, reason: string) {
  return api.post<{ data: VerifyDetail }>(`verifications/${id}/reject`, { reason })
}

export function cancelVerification(id: string) {
  return api.post<{ data: VerifyDetail }>(`verifications/${id}/cancel`, {})
}
