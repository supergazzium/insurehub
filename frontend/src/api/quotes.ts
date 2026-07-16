// Typed clients for /api/v1/quotes — Phase 5 (Quotation module).
import { api, buildQuery, type Paginated } from './client'

export interface Quote {
  id: string
  quoteNo: string
  applicationNo: string | null
  policyNo: string | null
  status: string
  customerId: string | null
  productId: string | null
  carrierId: string | null
  writingAgentId: string | null
  quoteDate: string | null
  appDate: string | null
  effectiveDate: string | null
  expiryDate: string | null
  coverage: number
  annualPremium: number
  mainPremium: string | number | null
  netPremium: string | number | null
  dutyStamp: string | number | null
  vat: string | number | null
  totalPremiumPaid: string | number | null
  premiumMode: string
  newOrRenew: string
  internalNote: string | null
}

export interface ActTariff {
  id: string
  vehicleTypeCode: string
  labelTh: string
  labelEn: string | null
  premium: number
}

export type PremiumMode = 'iterativeVat' | 'vatInclusive' | 'fixedDuty' | 'bare'

export interface PremiumPreview {
  netPremium: number
  dutyStamp: number
  vat: number
  totalPremium: number
}

export function fetchQuoteList(filters: { q?: string; agentId?: string; page?: number; perPage?: number } = {}) {
  return api.get<Paginated<Quote>>(`quotes${buildQuery(filters)}`)
}

export function fetchQuote(id: string) {
  return api.get<{ data: Quote }>(`quotes/${id}`)
}

// FK fields on the wire accept either string (from the picker) or number
// (from `Number(id)`). Laravel casts to int on the way in. The Quote type
// itself keeps `string | null` because that's what the server returns.
export type QuoteWritePayload = Omit<Partial<Quote>,
  'customerId' | 'productId' | 'carrierId' | 'writingAgentId'
> & {
  customerId?: string | number | null
  productId?: string | number | null
  carrierId?: string | number | null
  writingAgentId?: string | number | null
}

export function createQuote(payload: QuoteWritePayload) {
  return api.post<{ data: Quote }>('quotes', payload)
}

export function updateQuote(id: string, payload: QuoteWritePayload) {
  return api.patch<{ data: Quote }>(`quotes/${id}`, payload)
}

export function convertQuoteToApplication(id: string) {
  return api.post<{ data: Quote }>(`quotes/${id}/convert`)
}

export function fetchActTariffs() {
  return api.get<{ data: ActTariff[] }>('quotes/act-tariffs')
}

export function previewPremium(payload: {
  mode: PremiumMode
  netPremium?: number
  totalPaid?: number
  fixedDuty?: number
}) {
  return api.post<PremiumPreview>('quotes/premium/preview', payload)
}
