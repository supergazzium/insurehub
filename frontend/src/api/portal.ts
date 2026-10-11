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

/** One row from lookups/policy-statuses — LookupController::policyStatuses.
 *  `code` is the machine value stored on policies.status (null for follow-up
 *  sub-statuses that aren't a settable policy status). */
export interface PolicyStatusRow {
  id: string
  nameTh: string
  groupNameTh: string | null
  code: string | null
}

export function fetchPolicyStatuses() {
  return api.get<{ data: PolicyStatusRow[] }>('lookups/policy-statuses')
}

export function fetchReferralLink() {
  return api.get<ReferralLinkInfo>('me/agent/referral-link')
}

export function fetchDownline() {
  return api.get<{ data: MyAgent[] }>('me/agent/downline')
}

export interface EarningsLine {
  key: string
  lineType: 'base' | 'self_mgmt' | 'referral' | 'override'
  lineLabel: string
  policyId: number
  policyNo: string | null
  applicationNo: string | null
  customerName: string | null
  policyDate: string | null
  sourceAgent: string | null
  amount: number
  entitlement: number
  ratioLabel: string | null
  reasons: Array<{ code: string; label: string }>
}
export interface EarningsPayout {
  batchId: string
  version: number
  paymentDate: string | null
  base: number
  selfMgmt: number
  referral: number
  override: number
  gross: number
  deduction: number
  vat: number
  wht: number
  net: number
  certificate: string | null
}
export interface EarningsResponse {
  summary: {
    readyAmount: number
    readyNet: number
    waitingAmount: number
    pendingDeduction: number
    paidThisYear: number
    whtThisYear: number
    paidTotal: number
  }
  agent: { agentCode: string | null; agentName: string; vatType: string | null; whtRate?: number; whtFormLabel?: string | null } | null
  ready: EarningsLine[]
  waiting: EarningsLine[]
  deductions: Array<{ id: string; typeLabel?: string; type: string; amount: number; policyNo: string | null }>
  totals: { byType: Record<string, number>; taxableAmount: number; vatAmount: number; whtAmount: number; netAmount: number; appliedDeduction: number }
  history: EarningsPayout[]
  byMonth: Array<{ month: string; gross: number; wht: number; net: number; count: number }>
}

export function fetchEarnings() {
  return api.get<EarningsResponse>('me/agent/earnings')
}

export function myStatementPath(batchId: string): string {
  return `me/agent/payouts/${batchId}/statement`
}

export function myCertificatePath(batchId: string): string {
  return `me/agent/payouts/${batchId}/certificate`
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

// สายงาน — the signed-in agent's own upline chain (self → top) and direct
// downline. Mirrors AgentRelations from the back-office agents API.
export type { AgentRelation, AgentRelations } from './agents'

export function fetchMyRelations() {
  return api.get<import('./agents').AgentRelations>('me/agent/relations')
}
