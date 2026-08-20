// Typed clients for /api/v1/me/agent — the agent portal (Phase 2).
//
// Every endpoint returns the same MyAgentResource shape so we can just replace
// the store's agent record on any partial save.

import { api } from './client'

export interface MyAgent {
  id: string
  agentCode: string
  signupType: 'personal' | 'corporate'
  juristicName: string
  firstName: string
  lastName: string
  firstNameEn: string
  lastNameEn: string
  nickname: string
  gender: string
  email: string
  phone: string
  lineId: string
  facebookName: string
  birthDate: string | null
  joinedAt: string | null

  approvalStatus: 'pending' | 'approved' | 'rejected'
  approvalNote: string | null
  approvedAt: string | null
  rejectedAt: string | null

  profilePhotoPath: string | null
  idCardPhotoPath: string | null
  bankBookPhotoPath: string | null

  idCardMasked: string
  bankAccountNoMasked: string
  bankAccountName: string
  bankId: string | null
  bankNameText: string

  licenseLifeNo: string
  licenseLifeExpiry: string | null
  licenseNonLifeNo: string
  licenseNonLifeExpiry: string | null
  licenseNumber: string
  licenseExpiry: string | null
  hasLifeLicense: boolean
  hasNonLifeLicense: boolean

  address: string
  subDistrict: string
  district: string
  province: string
  postcode: string

  deliverySameAsTax: boolean
  deliveryAddress: string
  deliverySubDistrict: string
  deliveryDistrict: string
  deliveryProvince: string
  deliveryPostcode: string

  active: boolean
}

// Reference/lookup shapes used by the profile form.
export interface BankOption {
  id: string
  nameTh: string
  nameEn: string | null
  code: string | null
}
export interface SubDistrictOption {
  name: string
  postcode: string
}

export interface ReferralLinkInfo {
  token: string
  url: string
  clicks: number
  signups: number
  pendingSignups: number
  generatedAt: string | null
}

export function fetchMyAgent() {
  return api.get<{ data: MyAgent }>('me/agent')
}

export function patchProfile(payload: Partial<MyAgent>) {
  return api.patch<{ data: MyAgent }>('me/agent/profile', payload)
}
export function patchIdDocument(payload: { idCard?: string }) {
  return api.patch<{ data: MyAgent }>('me/agent/id-document', payload)
}
export function patchLicense(payload: Partial<MyAgent>) {
  return api.patch<{ data: MyAgent }>('me/agent/license', payload)
}
export function patchBank(payload: Partial<MyAgent>) {
  return api.patch<{ data: MyAgent }>('me/agent/bank', payload)
}
export function patchAddress(payload: Partial<MyAgent>) {
  return api.patch<{ data: MyAgent }>('me/agent/address', payload)
}
export function patchDelivery(payload: Partial<MyAgent>) {
  return api.patch<{ data: MyAgent }>('me/agent/delivery', payload)
}
export function patchAll(payload: Partial<MyAgent>) {
  return api.patch<{ data: MyAgent }>('me/agent', payload)
}

// Public reference lookups
export function fetchBanks() {
  return api.get<{ data: BankOption[] }>('public/lookup/banks')
}
export function fetchProvinces() {
  return api.get<{ data: string[] }>('public/lookup/provinces')
}
export function fetchDistricts(province: string) {
  return api.get<{ data: string[] }>(`public/lookup/districts?province=${encodeURIComponent(province)}`)
}
export function fetchSubDistricts(province: string, district: string) {
  return api.get<{ data: SubDistrictOption[] }>(
    `public/lookup/sub-districts?province=${encodeURIComponent(province)}&district=${encodeURIComponent(district)}`,
  )
}

export function unmaskIdCard() {
  return api.get<{ idCard: string }>('me/agent/id-card-unmask')
}

/** One row from lookups/name-prefixes — the LookupController::prefixes shape. */
export interface NamePrefixRow {
  id: string
  insuredType: 'Individual' | 'Corporate'
  descriptionTh: string
  descriptionEn: string | null
}

/** Full list of name prefixes (คำนำหน้าชื่อ). Both individual and corporate
 *  rows come back; caller filters by insuredType. Not cached — the list is
 *  small (~200 rows) and the lookup runs behind auth so browser caching
 *  wouldn't help across sessions anyway. */
export function fetchNamePrefixes() {
  return api.get<{ data: NamePrefixRow[] }>('lookups/name-prefixes')
}

/** One row from lookups/nationalities — LookupController::nationalities
 *  shape. Storage on customers uses the Thai name (nameTh) as the
 *  persisted value, matching the existing `customers.nationality`
 *  varchar column. */
export interface NationalityRow {
  id: string
  iso2: string | null
  iso3: string | null
  nameTh: string
  nameEn: string | null
}

export function fetchNationalities() {
  return api.get<{ data: NationalityRow[] }>('lookups/nationalities')
}

export function fetchReferralLink() {
  return api.get<ReferralLinkInfo>('me/agent/referral-link')
}

export function fetchDownline() {
  return api.get<{ data: MyAgent[] }>('me/agent/downline')
}

/** Phase 7a — earnings ledger. */
export interface EarningsSummary {
  accrued: number
  paid: number
  total: number
  txnCount: number
}
export interface MonthlyEarning {
  month: string        // YYYY-MM
  unsettled: number
  settled: number
  count: number
}
export interface EarningsTxn {
  id: string
  type: 'agent' | 'override' | 'inh' | string
  status: 'unsettled' | 'settled' | string
  policyId: string
  basePremium: number
  diffPct: number
  amount: number       // can be negative (reversal)
  isReversal: boolean
  createdAt: string
}
export interface EarningsResponse {
  summary: EarningsSummary
  byMonth: MonthlyEarning[]
  recent: EarningsTxn[]
}

export function fetchEarnings() {
  return api.get<EarningsResponse>('me/agent/earnings')
}

export function changePassword(currentPassword: string, newPassword: string) {
  return api.post<{ message: string }>('auth/change-password', {
    currentPassword,
    newPassword,
    newPassword_confirmation: newPassword,
  })
}

/**
 * Upload a photo via multipart. The API client uses fetch under the hood and
 * automatically handles FormData (does NOT set Content-Type — browser fills it
 * with the correct boundary).
 */
export async function uploadPhoto(
  endpoint: 'profile-photo' | 'id-photo' | 'bank-book-photo',
  file: File,
): Promise<{ data: MyAgent }> {
  const form = new FormData()
  form.append('photo', file)
  return api.post<{ data: MyAgent }>(`me/agent/${endpoint}`, form)
}
