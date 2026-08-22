// Typed clients for the MGM commission admin surface.
//
// Endpoints:
//   • commission-tiers  — 3 tiers × 10 rank rates each
//   • product-types     — taxonomy (~26 rows), assignable to a tier
//
// The old (carrier × product-type) matrix is no longer part of the admin
// surface — commission rates are edited on the product itself. The backend
// route + controller still exist (frozen 410 on writes) pending physical
// teardown of the table.

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

/** One of the 6 wizard branches. Nullable when a custom product_type
 *  row hasn't been assigned a kind by the admin yet — the frontend then
 *  falls back to the ProductResource.productKind runtime derivation. */
export type ProductTypeKind = 'motor' | 'travel' | 'fire' | 'health' | 'life' | 'misc'

/** JSON schema authored per product_type. Consumed by the wizard's
 *  Step 3 dynamic risk renderer. Structural shape validated at the
 *  renderer boundary — see docs/audit-2026-08-21/B4-risk-schema.md. */
export type RiskSchema = Record<string, unknown>

export interface ProductType {
  id: string
  code: string
  nameTh: string
  nameEn: string
  subOf: string | null
  kind: ProductTypeKind | null
  riskSchema: RiskSchema | null
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
  kind?: ProductTypeKind | null
  riskSchema?: RiskSchema | null
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
  kind: ProductTypeKind | null
  riskSchema: RiskSchema | null
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

// ── Per-agent commission detail (read-only) ──────────────────────────────

export interface AgentCommissionAgent {
  id: string
  code: string
  name: string
  rankCode: string | null
  rankLevel: number | null
  active: boolean
  hasLicense: boolean
}

export interface AgentCommissionUplineLink {
  id: string
  code: string
  name: string
  rankCode: string | null
  active: boolean
}

export type PayoutType = 'DIRECT_COMMISSION' | 'REFERRAL_FEE' | 'MANAGEMENT_DIFFERENTIAL'

export interface AgentCommissionLedgerRow {
  id: string
  payoutType: PayoutType
  status: string
  basePremium: number
  rateApplied: number
  amount: number
  standardRate: number | null
  mgmtFeeRate: number | null
  createdAt: string | null
  paymentReference: string | null
  paymentDate: string | null
  policyNo: string | null
  policyId: string
  sourceAgentCode: string | null
  carrierCode: string | null
  carrierName: string | null
  productTypeCode: string | null
  productTypeNameTh: string | null
}

export interface AgentDownlineNode {
  code: string
  name: string
  rankCode: string | null
  rankLevel: number | null
  active: boolean
  children: AgentDownlineNode[]
}

export interface AgentCommissionResponse {
  agent: AgentCommissionAgent
  uplineChain: AgentCommissionUplineLink[]
  downlineTree: AgentDownlineNode[]
  ledger: AgentCommissionLedgerRow[]
  totals: {
    directCommission: number
    referralFee: number
    managementDifferential: number
    grandTotal: number
  }
}

export function fetchAgentCommissionDetail(agentCode: string) {
  return api.get<AgentCommissionResponse>(`agents/${encodeURIComponent(agentCode)}/commission-detail`)
}
