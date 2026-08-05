// Typed clients for /api/v1/customers list endpoint.

import { api, buildQuery, type Paginated, type Single } from './client'
import type { Customer } from '../stores/customers'

/** Lean row returned by CustomerController::index — matches CustomerListResource. */
export interface CustomerListRow {
  id: string
  customerCode: string
  customerType: string
  firstName: string
  lastName: string
  nickname: string
  idCard: string
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

/** Full detail fetch. */
export function fetchCustomer(id: string) {
  return api.get<Single<Customer>>(`customers/${id}`)
}
