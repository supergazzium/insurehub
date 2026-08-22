// Typed clients for /api/v1/customers list endpoint.

import { api, buildQuery, type Paginated } from './client'

/** Lean row returned by CustomerController::index — matches CustomerListResource.
 *
 *  The identity block (titleTh, juristicName, taxId, passport) is included
 *  so the list row can render a correct display name and identity number
 *  for individual / foreign_individual / corporate customers without a
 *  round-trip to /customers/{id}. */
export interface CustomerListRow {
  id: string
  customerCode: string
  customerType: string
  titleTh: string
  firstName: string
  lastName: string
  nickname: string
  juristicName: string
  idCard: string
  taxId: string
  passport: string
  phone: string
  email: string
  province: string
  assignedAgentId: string | null
  assignedAgentCode: string | null
  assignedAgentName: string
  activePolicyCount: number
  totalPolicyCount: number
  active: boolean
  registeredAt: string | null
}

export interface CustomerListFilters {
  q?: string
  assignedAgentId?: string
  unassigned?: boolean
  active?: boolean
  withPolicies?: boolean
  customerType?: string
  page?: number
  perPage?: number
  sortBy?: 'customerCode' | 'firstName' | 'lastName' | 'province' | 'registeredAt' | 'newest'
  sortDir?: 'asc' | 'desc'
}

export function fetchCustomerList(filters: CustomerListFilters = {}) {
  return api.get<Paginated<CustomerListRow>>(`customers${buildQuery({ ...filters })}`)
}

/** Full customer record via GET /customers/{id}. Used by the wizard's
 *  resume-from-draft flow (C-15) to hydrate the EntityPicker label
 *  after page reload. Typed loosely — CustomerResource has ~40 fields
 *  and callers here only need a handful. */
export function fetchCustomer(id: string) {
  return api.get<{ data: Record<string, unknown> }>(`customers/${id}`)
}

// ── C-12 Prior assets ─────────────────────────────────────────────────
//
// Feeds the wizard's "Reuse from prior policy" dropdown (B3 §4). The
// endpoint scopes results to the customer's own history + the requested
// kind, and returns records deduplicated by the schema-declared
// `dedupe_keys` (motor: license_no+chassis_no; travel: passport;
// health/life: id_card; fire: insured_address).

export interface PriorAsset {
  dedupeKey: string
  lastUsedPolicyNo: string | null
  lastUsedApplicationNo: string | null
  lastUsedAt: string | null
  fields: Record<string, unknown>
}

export interface PriorAssetsResponse {
  kind: string
  dedupeKeys: string[]
  assets: PriorAsset[]
}

/** GET /customers/{id}/prior-assets?kind=motor
 *  Kinds: motor | travel | fire | health | life | misc.
 *  Returns empty `assets` when the customer has no prior policy of
 *  that kind. */
export function fetchPriorAssets(customerId: string, kind: string) {
  return api.get<PriorAssetsResponse>(`customers/${customerId}/prior-assets?kind=${encodeURIComponent(kind)}`)
}
