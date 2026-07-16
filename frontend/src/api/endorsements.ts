// Phase 9 — endorsements (event log per policy).
import { api } from './client'

export interface Endorsement {
  id: string
  type: 'endorsement.date_change' | 'endorsement.coverage_change' | 'endorsement.cancel_reissue' | 'endorsement.other' | string
  occurredAt: string | null
  byUserId: string | null
  payload: Record<string, unknown> | null
}

export function fetchEndorsements(policyId: string) {
  return api.get<{ data: Endorsement[] }>(`policies/${policyId}/endorsements`)
}

export function createEndorsement(policyId: string, payload: {
  type: string
  reason: string
  effectiveDate?: string
  changes?: Record<string, unknown>
}) {
  return api.post<{ message: string; data: Endorsement }>(`policies/${policyId}/endorsements`, payload)
}
