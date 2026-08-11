// Typed clients for the MGM commission admin surface.
//
// Three groups of endpoints (backend PR-A2..PR-A4):
//   • commission-tiers          — 3 tiers × 10 rank rates each
//   • product-types             — taxonomy (~26 rows), assignable to a tier
//   • carrier-product-type-rates — 24 × ~21 matrix (standard commission)

import { api } from './client'

// ── Commission tiers ─────────────────────────────────────────────────────

export interface TierRankRate {
  id: string
  rankId: string
  rankLevel: number
  mgmtFeeRate: number
  referralFeeRate: number
  validStart: string | null
  validEnd: string | null
}

export interface CommissionTier {
  id: string
  code: string
  nameTh: string
  nameEn: string
  colorHex: string | null
  sortOrder: number
  notes: string | null
  rankRates: TierRankRate[] | null
}

export function fetchCommissionTiers() {
  return api.get<{ data: CommissionTier[] }>('commission-tiers')
}

export function updateCommissionTier(id: string, payload: {
  nameTh?: string
  nameEn?: string
  colorHex?: string | null
  notes?: string | null
}) {
  return api.patch<{ data: CommissionTier }>(`commission-tiers/${id}`, payload)
}

export function updateTierRankRate(tierId: string, rateId: string, payload: {
  mgmtFeeRate?: number
  referralFeeRate?: number
}) {
  return api.patch<TierRankRate & { tierId: string }>(
    `commission-tiers/${tierId}/rates/${rateId}`,
    payload,
  )
}

// ── Product types ────────────────────────────────────────────────────────

export interface ProductType {
  id: string
  code: string
  nameTh: string
  nameEn: string
  subOf: string | null
  tierId: string
  tierCode: string | null
  tierNameTh: string | null
  sortOrder: number
  active: boolean
  notes: string | null
}

export function fetchProductTypes(activeOnly = false) {
  const qs = activeOnly ? '?activeOnly=1' : ''
  return api.get<{ data: ProductType[] }>(`product-types${qs}`)
}

export function createProductType(payload: {
  code: string
  nameTh: string
  nameEn: string
  subOf?: string | null
  tierId: number
  sortOrder?: number
  active?: boolean
  notes?: string | null
}) {
  return api.post<{ data: ProductType }>('product-types', payload)
}

export function updateProductType(id: string, payload: Partial<{
  code: string
  nameTh: string
  nameEn: string
  subOf: string | null
  tierId: number
  sortOrder: number
  active: boolean
  notes: string | null
}>) {
  return api.patch<{ data: ProductType }>(`product-types/${id}`, payload)
}

export function deleteProductType(id: string) {
  return api.delete<{ message: string }>(`product-types/${id}`)
}

// ── Carrier × product-type matrix ────────────────────────────────────────

export interface MatrixCarrier {
  id: string
  code: string
  name: string
  insureType: string
}

export interface MatrixProductType {
  id: string
  code: string
  nameTh: string
  nameEn: string
  subOf: string | null
  tierId: string
  sortOrder: number
}

export interface MatrixRate {
  id: string
  carrierId: string
  productTypeId: string
  standardRate: number | null
  validStart: string | null
}

export interface MatrixResponse {
  carriers: MatrixCarrier[]
  productTypes: MatrixProductType[]
  rates: MatrixRate[]
}

export function fetchCarrierProductTypeRates() {
  return api.get<MatrixResponse>('carrier-product-type-rates')
}

export function createCarrierProductTypeRate(payload: {
  carrierId: number
  productTypeId: number
  standardRate?: number | null
  notes?: string | null
}) {
  return api.post<MatrixRate>('carrier-product-type-rates', payload)
}

export function updateCarrierProductTypeRate(id: string, payload: {
  standardRate?: number | null
  notes?: string | null
}) {
  return api.patch<MatrixRate>(`carrier-product-type-rates/${id}`, payload)
}

export function deleteCarrierProductTypeRate(id: string) {
  return api.delete<{ message: string }>(`carrier-product-type-rates/${id}`)
}
