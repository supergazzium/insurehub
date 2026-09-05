// Phase 9 — endorsements (event log per policy).
import { api } from './client'

export type EndorsementType =
  | 'endorsement.date_change'
  | 'endorsement.coverage_change'
  | 'endorsement.cancel_reissue'
  | 'endorsement.other'
  | 'endorsement.premium_change'
  | string

export interface Endorsement {
  id: string
  type: EndorsementType
  occurredAt: string | null
  byUserId: string | null
  payload: Record<string, unknown> | null
}

/** Payload shape for a premium-change (สลักหลังเบี้ยเพิ่ม) endorsement event. */
export interface PremiumEndorsementPayload {
  reason: string
  effectiveDate: string
  before: { annualPremium: number; mainPremium: number | null; coverage: number }
  after: { annualPremium: number; mainPremium: number | null; coverage: number }
  additionalPremium: number
  additionalDutyStamp: number
  additionalVat: number
  additionalTotal: number
  policyStatusAtEndorsement: string
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

/** สลักหลังเบี้ยเพิ่ม (v1) — records the premium increase, updates the policy,
 *  and captures the additional (pro-rata) premium for the remaining period. */
export function createPremiumEndorsement(policyId: string, payload: {
  reason: string
  effectiveDate: string
  newAnnualPremium: number
  newCoverage?: number | null
  additionalPremium: number
  additionalDutyStamp?: number | null
  additionalVat?: number | null
}) {
  return api.post<{ message: string; data: Endorsement }>(
    `policies/${policyId}/endorsements/premium`,
    payload,
  )
}

/** Edit an existing premium-change endorsement (keeps its `before` snapshot). */
export function updatePremiumEndorsement(policyId: string, eventId: string, payload: {
  reason: string
  effectiveDate: string
  newAnnualPremium: number
  newCoverage?: number | null
  additionalPremium: number
  additionalDutyStamp?: number | null
  additionalVat?: number | null
}) {
  return api.patch<{ message: string; data: Endorsement }>(
    `policies/${policyId}/endorsements/premium/${eventId}`,
    payload,
  )
}

/** Delete a premium-change endorsement; the policy premium re-syncs server-side. */
export function deletePremiumEndorsement(policyId: string, eventId: string) {
  return api.delete<{ message: string }>(
    `policies/${policyId}/endorsements/premium/${eventId}`,
  )
}
