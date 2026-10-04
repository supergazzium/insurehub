import { api, type Single } from './client'

export interface CustomerTierSetting {
  key: 'vip' | 'gold' | 'silver'
  label: string
  min: number | null
}

export interface TenantSettingsRow {
  id: string
  name: string
  nameEn: string | null
  taxId: string | null
  oicLicense: string | null
  phone: string | null
  email: string | null
  website: string | null
  address: string | null
  district: string | null
  amphoe: string | null
  province: string | null
  postcode: string | null
  paymentChannels: string | null
  customerTiers: CustomerTierSetting[]
  policyVerifyRequired?: boolean
  freelookDaysStandard?: number
  freelookDaysRemote?: number
  noCommissionWindowDays?: number
  payoutRequireFreelook?: boolean
  payoutRequireInsurerReceived?: boolean
  payoutRequireCustomerPaid?: boolean
  whtRateIndividual?: number
  whtRateCorporate?: number
  payout?: { cycle: 'weekly' | 'biweekly' | 'monthly'; minBalance: number; autoApprove: boolean }
  [key: string]: unknown
}

export interface PayoutSettingsPatch {
  payoutRequireFreelook: boolean
  payoutRequireInsurerReceived: boolean
  payoutRequireCustomerPaid: boolean
  whtRateIndividual: number
  whtRateCorporate: number
  payout: { cycle: 'weekly' | 'biweekly' | 'monthly'; minBalance: number; autoApprove: boolean }
}

export function updatePayoutSettings(patch: PayoutSettingsPatch) {
  return api.patch<Single<TenantSettingsRow>>('tenant', patch)
}

export interface FollowUpSettingsPatch {
  policyVerifyRequired?: boolean
  freelookDaysStandard?: number
  freelookDaysRemote?: number
  noCommissionWindowDays?: number
}

export function updateFollowUpSettings(patch: FollowUpSettingsPatch) {
  return api.patch<Single<TenantSettingsRow>>('tenant', patch)
}

export type TenantProfilePatch = Partial<Pick<TenantSettingsRow,
  'name' | 'nameEn' | 'taxId' | 'oicLicense' | 'phone' | 'email' | 'website' |
  'address' | 'district' | 'amphoe' | 'province' | 'postcode' | 'paymentChannels'>>

export function updateTenantProfile(patch: TenantProfilePatch) {
  return api.patch<Single<TenantSettingsRow>>('tenant', patch)
}

export function fetchTenant() {
  return api.get<Single<TenantSettingsRow>>('tenant')
}

export function updateCustomerTiers(customerTiers: CustomerTierSetting[]) {
  return api.patch<Single<TenantSettingsRow>>('tenant', { customerTiers })
}
