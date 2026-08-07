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
  address: string
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

/** One carrier contact — matches CarrierContactResource. */
export interface CarrierContact {
  id: string
  carrierId: string
  firstName: string
  lastName: string
  phone: string
  email: string
  isPrimary: boolean
  sortOrder: number
  active: boolean
}

/** Full detail returned by CarrierController::show — extends the list row with nested collections. */
export interface CarrierDetail extends CarrierListRow {
  bankAccounts: CarrierBankAccount[]
  contacts: CarrierContact[]
}

export function fetchCarrier(id: string) {
  return api.get<{ data: CarrierDetail }>(`carriers/${id}`)
}

/** Partial update — the backend accepts `active`, `name`, `code`, etc. */
export function updateCarrier(id: string, payload: Partial<Pick<CarrierListRow, 'active'>>) {
  return api.patch<{ data: CarrierDetail }>(`carriers/${id}`, payload)
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

/** One carrier portal credential — matches CarrierCredentialResource. */
export interface CarrierCredential {
  id: string
  carrierId: string
  url: string
  username: string
  password: string
  label: string
  sortOrder: number
}
export type CarrierCredentialPayload = Partial<Omit<CarrierCredential, 'id' | 'carrierId'>>

export function fetchCarrierCredentials(carrierId: string) {
  return api.get<{ data: CarrierCredential[] }>(`carriers/${carrierId}/credentials`)
}
export function createCarrierCredential(carrierId: string, payload: CarrierCredentialPayload) {
  return api.post<{ data: CarrierCredential }>(`carriers/${carrierId}/credentials`, payload)
}
export function updateCarrierCredential(carrierId: string, credentialId: string, payload: CarrierCredentialPayload) {
  return api.patch<{ data: CarrierCredential }>(`carriers/${carrierId}/credentials/${credentialId}`, payload)
}
export function deleteCarrierCredential(carrierId: string, credentialId: string) {
  return api.delete<{ message: string }>(`carriers/${carrierId}/credentials/${credentialId}`)
}

/** Tenant-wide label suggestions across every carrier's credentials.
 *  Powers the sticky-note picker so labels can be reused between carriers. */
export interface CredentialLabelStat { label: string; count: number }
export function fetchCredentialLabels() {
  return api.get<{ data: CredentialLabelStat[] }>('carrier-credentials/labels')
}

/** Sub-resource CRUD for /carriers/{id}/contacts. */
export type CarrierContactPayload = Partial<Omit<CarrierContact, 'id' | 'carrierId'>>

export function createCarrierContact(carrierId: string, payload: CarrierContactPayload) {
  return api.post<{ data: CarrierContact }>(`carriers/${carrierId}/contacts`, payload)
}
export function updateCarrierContact(carrierId: string, contactId: string, payload: CarrierContactPayload) {
  return api.patch<{ data: CarrierContact }>(`carriers/${carrierId}/contacts/${contactId}`, payload)
}
export function deleteCarrierContact(carrierId: string, contactId: string) {
  return api.delete<{ message: string }>(`carriers/${carrierId}/contacts/${contactId}`)
}
