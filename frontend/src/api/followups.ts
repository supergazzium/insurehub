// ติดตามงานค้าง (outstanding work follow-up) API client.
import { api, buildQuery } from './client'

export type FollowUpCategory =
  | 'approval' | 'no_policy_no' | 'not_delivered' | 'freelook' | 'unpaid' | 'premium_due' | 'no_commission' | 'cancelled' | 'verify'

export type AgingBucket = '0-7' | '8-30' | '30+'
export type CancelCheck = 'pending' | 'done' | 'na'

export interface FollowUpRow {
  policyId: string
  policyNo: string | null
  applicationNo: string | null
  status: string
  customerCode: string | null
  customerName: string
  customerPhone: string | null
  agentCode: string | null
  agentName: string
  carrierName: string | null
  productName: string | null
  effectiveDate: string | null
  issueDate: string | null
  receivedDate: string | null
  mailingDate: string | null
  shippedDate: string | null
  deliveredDate: string | null
  salesChannel: string | null
  freelookEndDate: string | null
  freelookDocSentAt: string | null
  freelookDocSignedAt: string | null
  freelookDocReturnedAt: string | null
  cancelDate: string | null
  cancelStatus: string | null
  cancellationReviewedAt: string | null
  cancelChecks: { refund: CancelCheck; clawback: CancelCheck; carrierDeduct: CancelCheck }
  oldestDueDate: string | null
  overdueCount: number
  overdueAmount: number
  // ทวงเบี้ยรายปี (premium_due category)
  nextPremiumDue: string | null
  nextPremiumYear: number | null
  premiumTerm: number | null
  agingDays: number | null
  alsoIn: string[]
}

export interface VerifyRow {
  id: string
  action: 'create' | 'update' | 'delete'
  actionLabel: string
  status: string
  policyId: string
  policyNo: string | null
  applicationNo: string | null
  policyStatus: string | null
  customerName: string | null
  makerName: string | null
  checkerName: string | null
  createdAt: string | null
  decidedAt: string | null
  reason: string | null
  note: string | null
  fieldCount: number
  agingDays?: number
}

export interface FollowUpMeta {
  category: string
  counts: Record<string, number>
  aging: Record<AgingBucket, number>
  stages: Record<string, number>
  verifyEnabled: boolean
  noCommissionWindowDays?: number
}

export interface FollowUpQuery {
  category?: FollowUpCategory
  q?: string
  aging?: AgingBucket | ''
  stage?: string
  reviewed?: boolean
}

export function fetchFollowUps(params: FollowUpQuery = {}) {
  return api.get<{ data: Array<FollowUpRow | VerifyRow>; meta: FollowUpMeta }>(
    `follow-ups${buildQuery({ ...params, reviewed: params.reviewed ? 1 : undefined } as Record<string, unknown>)}`,
  )
}

export function updateFreelookDocs(policyId: string, patch: { sentAt?: string | null; signedAt?: string | null; returnedAt?: string | null }) {
  return api.patch<{ data: Record<string, unknown> }>(`policies/${policyId}/freelook`, patch)
}

export interface CancellationReview {
  policyId: string
  status: string
  cancelDate: string | null
  cancelReason: string | null
  reviewedAt: string | null
  reviewedByName: string | null
  refundAmount: number | null
  clawbackAmount: number | null
  checks: { refund: CancelCheck; clawback: CancelCheck; carrierDeduct: CancelCheck }
  note: string | null
  suggestion: { paidCommission: number; netRefundAmount: number | null }
  uplineRatio?: number
  uplines?: Array<{ agentId: string; agentCode: string | null; agentName: string; paid: number; clawback: number }>
  clawbacks: Array<{ id: string; agentCode?: string | null; agentName?: string | null; isSeller?: boolean; amount: number; status: string; batchId: string | null; appliedAt: string | null }>
}

export function fetchCancellationReview(policyId: string) {
  return api.get<{ data: CancellationReview }>(`policies/${policyId}/cancellation-review`)
}

export function saveCancellationReview(policyId: string, payload: Record<string, unknown>) {
  return api.patch<{ data: CancellationReview }>(`policies/${policyId}/cancellation-review`, payload)
}
