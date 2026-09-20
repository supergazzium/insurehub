// ติดตามงานค้าง (outstanding work follow-up) API client.
import { api, buildQuery } from './client'

export type FollowUpCategory =
  | 'approval' | 'no_policy_no' | 'not_delivered' | 'freelook' | 'no_commission' | 'cancelled'

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
  freelookEndDate: string | null
  cancelDate: string | null
  cancelStatus: string | null
}

export function fetchFollowUps(params: { category?: FollowUpCategory; q?: string } = {}) {
  return api.get<{ data: FollowUpRow[]; meta: { category: string; counts: Record<string, number> } }>(
    `follow-ups${buildQuery(params as Record<string, unknown>)}`,
  )
}
