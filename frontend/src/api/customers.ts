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
