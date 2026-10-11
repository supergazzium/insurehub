// รายงานภาษีหัก ณ ที่จ่าย — withholding-tax report for tax filing (ภ.ง.ด.53 / 50 ทวิ).
import { api, buildQuery, API_BASE_URL } from './client'

export interface WhtRow {
  agentId: string | null
  agentCode: string | null
  payeeName: string
  payeeType: 'individual' | 'corporate'
  taxId: string | null
  address: string | null
  branch: string | null
  payoutCount: number
  grossCommission: number
  whtAmount: number
  netPaid: number
}
export interface WhtTotals {
  payeeCount: number
  grossCommission: number
  whtAmount: number
  netPaid: number
}
export interface WhtReport {
  rows: WhtRow[]
  totals: WhtTotals
}

/** Per-agent WHT totals + summary for a period. */
export function fetchWhtReport(fromDate?: string, toDate?: string) {
  return api.get<WhtReport>(`wht-report${buildQuery({ fromDate, toDate })}`)
}

/** ภ.ง.ด.53-friendly CSV export URL (raw; needs a Bearer token). */
export function whtExportCsvUrl(fromDate?: string, toDate?: string): string {
  return `${API_BASE_URL}/wht-report/export.csv${buildQuery({ fromDate, toDate })}`
}
