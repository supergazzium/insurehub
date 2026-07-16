// Phase 8a — operational reports (freelook / cancellation / mailing / payment).
import { api, buildQuery } from './client'

export interface FreelookRow {
  policyId: string
  applicationNo: string | null
  policyNo: string | null
  effectiveDate: string | null
  expiryDate: string | null
  daysSinceEffective: number
  annualPremium: number
  customerCode: string | null
  customerName: string | null
  agentCode: string | null
  agentName: string | null
  carrierCode: string | null
  carrierName: string | null
  productCode: string | null
  productName: string | null
}

export function fetchFreelook(days = 30) {
  return api.get<{ data: FreelookRow[]; meta: { days: number; total: number } }>(
    `reports/freelook${buildQuery({ days })}`,
  )
}

export interface MailingRow {
  policyId: string
  applicationNo: string | null
  policyNo: string | null
  mailingDate: string | null
  mailingAddress: string | null
  mailingNote: string | null
  customerCode: string | null
  customerName: string | null
  agentCode: string | null
  agentName: string | null
  carrierCode: string | null
  carrierName: string | null
}

export function fetchMailingPipeline(from: string, to: string) {
  return api.get<{ data: MailingRow[]; meta: { from: string; to: string; total: number } }>(
    `reports/mailing-pipeline${buildQuery({ from, to })}`,
  )
}

export interface PaymentRow {
  paymentId: string
  paymentDate: string | null
  amount: number
  method: string
  reference: string | null
  policyId: string
  applicationNo: string | null
  policyNo: string | null
  customerCode: string | null
  customerName: string | null
  agentCode: string | null
  agentName: string | null
  carrierCode: string | null
}

export function fetchPaymentHistory(from: string, to: string) {
  return api.get<{
    data: PaymentRow[]
    meta: { from: string; to: string; total: number; totalAmount: number }
  }>(`reports/payment-history${buildQuery({ from, to })}`)
}

export interface CancellationRow {
  policyId: string
  applicationNo: string | null
  policyNo: string | null
  cancelStatus: string | null
  cancelDate: string | null
  annualPremium: number
  refundPremium: number | null
  refundTotalPremium: number | null
  netRefundAmount: number | null
  customerName: string | null
  agentCode: string | null
}

/** cancellation-ledger already exists on backend; shape matches ReportController */
export function fetchCancellations(days = 90) {
  return api.get<{ data: CancellationRow[]; meta: Record<string, unknown> }>(
    `reports/cancellation-ledger${buildQuery({ days })}`,
  )
}
