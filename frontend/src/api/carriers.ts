// Typed clients for /api/v1/carriers.

import { api, buildQuery, type Paginated } from './client'

/** Lean row returned by CarrierController::index — matches CarrierListResource. */
export interface CarrierListRow {
  id: string
  code: string
  name: string
  nameEn: string
  nicknameTh: string
  insureType: string
  subType: string
  oicInsureComCode: string
  compInsureCode: string
  taxId: string
  phone: string
  email: string
  website: string
  active: boolean
  productCount: number
  contractCount: number
}

export interface CarrierListFilters {
  q?: string
  activeOnly?: boolean
  insureType?: 'life' | 'non-life' | 'tax' | ''
  page?: number
  perPage?: number
}

export function fetchCarrierList(filters: CarrierListFilters = {}) {
  return api.get<Paginated<CarrierListRow>>(`carriers${buildQuery({ ...filters })}`)
}

/** One carrier bank account — matches CarrierBankAccountResource. */
export interface CarrierBankAccount {
  id: string
  carrierId: string
  bankId: string | null
  bankName: string
  branch: string
  accountNo: string
  accountName: string
  isPrimary: boolean
  sortOrder: number
  active: boolean
}

/** Full detail returned by CarrierController::show — extends the list row with nested collections. */
export interface CarrierDetail extends CarrierListRow {
  bankAccounts: CarrierBankAccount[]
}

export function fetchCarrier(id: string) {
  return api.get<{ data: CarrierDetail }>(`carriers/${id}`)
}

/** Sub-resource CRUD for /carriers/{id}/bank-accounts. */
export type CarrierBankAccountPayload = Partial<Omit<CarrierBankAccount, 'id' | 'carrierId'>>

export function createCarrierBankAccount(carrierId: string, payload: CarrierBankAccountPayload) {
  return api.post<{ data: CarrierBankAccount }>(`carriers/${carrierId}/bank-accounts`, payload)
}

export function updateCarrierBankAccount(carrierId: string, accountId: string, payload: CarrierBankAccountPayload) {
  return api.patch<{ data: CarrierBankAccount }>(`carriers/${carrierId}/bank-accounts/${accountId}`, payload)
}

export function deleteCarrierBankAccount(carrierId: string, accountId: string) {
  return api.delete<{ message: string }>(`carriers/${carrierId}/bank-accounts/${accountId}`)
}
