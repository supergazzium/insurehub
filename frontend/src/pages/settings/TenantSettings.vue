<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchTenant, updateCustomerTiers, updateFollowUpSettings, updatePayoutSettings, updateTenantProfile, type CustomerTierSetting, type TenantSettingsRow } from '../../api/tenant'
import { ApiError } from '../../api/client'

const { t } = useI18n()

type Tab = 'profile' | 'commission' | 'payout' | 'branding' | 'customers' | 'followups' | 'audit'
const tab = ref<Tab>('profile')

const tierRows = ref<Array<{ key: CustomerTierSetting['key']; label: string; min: string }>>([])
const tiersLoading = ref(false)
const tiersSaving = ref(false)
const tiersSaved = ref(false)
const tiersError = ref<string | null>(null)
let tiersLoaded = false

function toTierRows(rows: CustomerTierSetting[]): Array<{ key: CustomerTierSetting['key']; label: string; min: string }> {
  return rows.map((r) => ({ key: r.key, label: r.label, min: r.min === null ? '' : String(r.min) }))
}

async function loadTiers(): Promise<void> {
  tiersLoading.value = true
  tiersError.value = null
  try {
    tierRows.value = toTierRows((await fetchTenant()).data.customerTiers ?? [])
    tiersLoaded = true
  } catch (e: unknown) {
    tiersError.value = e instanceof ApiError ? (e.body?.message ?? `HTTP ${e.status}`) : 'โหลดเกณฑ์ไม่สำเร็จ'
  } finally {
    tiersLoading.value = false
  }
}

const tierValidation = computed<string | null>(() => {
  const mins = tierRows.value.map((r) => r.min.trim()).filter((m) => m !== '').map(Number)
  if (mins.some((n) => !Number.isFinite(n) || n < 0)) return 'กรุณากรอกตัวเลขตั้งแต่ 0 ขึ้นไป'
  if (new Set(mins).size !== mins.length) return 'เกณฑ์แต่ละเกรดต้องไม่ซ้ำกัน'
  return null
})

async function saveTiers(): Promise<void> {
  if (tierValidation.value) return
  tiersSaving.value = true
  tiersError.value = null
  try {
    const payload: CustomerTierSetting[] = tierRows.value.map((r) => ({
      key: r.key,
      label: r.label.trim(),
      min: r.min.trim() === '' ? null : Number(r.min),
    }))
    tierRows.value = toTierRows((await updateCustomerTiers(payload)).data.customerTiers ?? [])
    tiersSaved.value = true
    setTimeout(() => (tiersSaved.value = false), 2000)
  } catch (e: unknown) {
    tiersError.value = e instanceof ApiError ? (e.body?.message ?? `HTTP ${e.status}`) : 'บันทึกไม่สำเร็จ'
  } finally {
    tiersSaving.value = false
  }
}

const followUp = reactive({ policyVerifyRequired: false, freelookDaysStandard: 15, freelookDaysRemote: 30, noCommissionWindowDays: 180 })
const followUpLoading = ref(false)
const followUpSaving = ref(false)
const followUpSaved = ref(false)
const followUpError = ref<string | null>(null)
const confirmVerifyOff = ref(false)
let followUpLoaded = false
let followUpVerifyWas = false

function fillFollowUp(row: TenantSettingsRow): void {
  followUp.policyVerifyRequired = !!row.policyVerifyRequired
  followUp.freelookDaysStandard = Number(row.freelookDaysStandard ?? 15)
  followUp.freelookDaysRemote = Number(row.freelookDaysRemote ?? 30)
  followUp.noCommissionWindowDays = Number(row.noCommissionWindowDays ?? 180)
  followUpVerifyWas = followUp.policyVerifyRequired
}

async function loadFollowUp(): Promise<void> {
  followUpLoading.value = true
  followUpError.value = null
  try {
    fillFollowUp((await fetchTenant()).data)
    followUpLoaded = true
  } catch (e: unknown) {
    followUpError.value = e instanceof ApiError ? (e.body?.message ?? `HTTP ${e.status}`) : 'โหลดการตั้งค่าไม่สำเร็จ'
  } finally {
    followUpLoading.value = false
  }
}

async function saveFollowUp(): Promise<void> {
  if (followUpVerifyWas && !followUp.policyVerifyRequired && !confirmVerifyOff.value) {
    confirmVerifyOff.value = true
    return
  }
  confirmVerifyOff.value = false
  followUpSaving.value = true
  followUpError.value = null
  try {
    fillFollowUp((await updateFollowUpSettings({ ...followUp })).data)
    followUpSaved.value = true
    setTimeout(() => (followUpSaved.value = false), 2000)
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      followUpError.value = Object.values(errs)[0]?.[0] ?? e.body?.message ?? `HTTP ${e.status}`
    } else {
      followUpError.value = 'บันทึกไม่สำเร็จ'
    }
  } finally {
    followUpSaving.value = false
  }
}

watch(tab, (v) => {
  if (v === 'customers' && !tiersLoaded) void loadTiers()
  if (v === 'followups' && !followUpLoaded) void loadFollowUp()
  if (v === 'payout' && !payoutLoaded) void loadPayout()
})

// ── Profile ───────────────────────────────────────────────────────────────
const profile = reactive({
  name: '',
  nameEn: '',
  taxId: '',
  oicLicense: '',
  phone: '',
  email: '',
  website: '',
  address: '',
  district: '',
  amphoe: '',
  province: '',
  postcode: '',
  paymentChannels: '',
})
const profileLoading = ref(false)
const profileError = ref<string | null>(null)

function fillProfile(row: TenantSettingsRow): void {
  profile.name = row.name ?? ''
  profile.nameEn = row.nameEn ?? ''
  profile.taxId = row.taxId ?? ''
  profile.oicLicense = row.oicLicense ?? ''
  profile.phone = row.phone ?? ''
  profile.email = row.email ?? ''
  profile.website = row.website ?? ''
  profile.address = row.address ?? ''
  profile.district = row.district ?? ''
  profile.amphoe = row.amphoe ?? ''
  profile.province = row.province ?? ''
  profile.postcode = row.postcode ?? ''
  profile.paymentChannels = row.paymentChannels ?? ''
}

async function loadProfile(): Promise<void> {
  profileLoading.value = true
  profileError.value = null
  try {
    fillProfile((await fetchTenant()).data)
  } catch (e: unknown) {
    profileError.value = e instanceof ApiError ? (e.body?.message ?? `HTTP ${e.status}`) : 'โหลดข้อมูลหน่วยงานไม่สำเร็จ'
  } finally {
    profileLoading.value = false
  }
}
onMounted(loadProfile)
const logoPreview = ref<string | null>(null)
const logoInput = ref<HTMLInputElement | null>(null)
const profileSaving = ref(false)
const profileSaved = ref(false)

function pickLogo() {
  logoInput.value?.click()
}
function onLogoChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const reader = new FileReader()
  reader.onload = () => {
    logoPreview.value = reader.result as string
  }
  reader.readAsDataURL(file)
}
function removeLogo() {
  logoPreview.value = null
  if (logoInput.value) logoInput.value.value = ''
}
async function saveProfile() {
  profileSaving.value = true
  profileError.value = null
  try {
    const nullable = (v: string) => (v.trim() === '' ? null : v.trim())
    const res = await updateTenantProfile({
      name: profile.name.trim(),
      nameEn: nullable(profile.nameEn),
      taxId: nullable(profile.taxId),
      oicLicense: nullable(profile.oicLicense),
      phone: nullable(profile.phone),
      email: nullable(profile.email),
      website: nullable(profile.website),
      address: nullable(profile.address),
      district: nullable(profile.district),
      amphoe: nullable(profile.amphoe),
      province: nullable(profile.province),
      postcode: nullable(profile.postcode),
      paymentChannels: nullable(profile.paymentChannels),
    })
    fillProfile(res.data)
    profileSaved.value = true
    setTimeout(() => (profileSaved.value = false), 2000)
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const errs = e.body?.errors ?? {}
      profileError.value = Object.values(errs)[0]?.[0] ?? e.body?.message ?? `HTTP ${e.status}`
    } else {
      profileError.value = 'บันทึกไม่สำเร็จ'
    }
  } finally {
    profileSaving.value = false
  }
}

// ── Commission mode ───────────────────────────────────────────────────────
const commissionMode = ref<'asEarned' | 'advance'>('asEarned')

// ── Payout cycle ──────────────────────────────────────────────────────────
const payout = reactive({
  cycle: 'monthly' as 'weekly' | 'biweekly' | 'monthly',
  minBalance: 500,
  autoApprove: false,
})
const payoutRules = reactive({
  payoutRequireFreelook: true,
  payoutRequireInsurerReceived: true,
  payoutRequireCustomerPaid: true,
  whtRateIndividual: 3,
  whtRateCorporate: 3,
})
const payoutLoading = ref(false)
const payoutSaving = ref(false)
const payoutSaved = ref(false)
const payoutError = ref<string | null>(null)
let payoutLoaded = false

function fillPayout(row: TenantSettingsRow): void {
  payoutRules.payoutRequireFreelook = row.payoutRequireFreelook !== false
  payoutRules.payoutRequireInsurerReceived = row.payoutRequireInsurerReceived !== false
  payoutRules.payoutRequireCustomerPaid = row.payoutRequireCustomerPaid !== false
  payoutRules.whtRateIndividual = Number(row.whtRateIndividual ?? 3)
  payoutRules.whtRateCorporate = Number(row.whtRateCorporate ?? 3)
  if (row.payout) {
    payout.cycle = row.payout.cycle ?? 'monthly'
    payout.minBalance = Number(row.payout.minBalance ?? 0)
    payout.autoApprove = row.payout.autoApprove === true
  }
}

async function loadPayout(): Promise<void> {
  payoutLoading.value = true
  payoutError.value = null
  try {
    fillPayout((await fetchTenant()).data)
    payoutLoaded = true
  } catch (e: unknown) {
    payoutError.value = e instanceof ApiError ? (e.body?.message ?? `HTTP ${e.status}`) : 'โหลดการตั้งค่าไม่สำเร็จ'
  } finally {
    payoutLoading.value = false
  }
}

async function savePayout(): Promise<void> {
  payoutSaving.value = true
  payoutError.value = null
  try {
    fillPayout((await updatePayoutSettings({ ...payoutRules, payout: { ...payout } })).data)
    payoutSaved.value = true
    setTimeout(() => (payoutSaved.value = false), 2000)
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      payoutError.value = Object.values(errs)[0]?.[0] ?? e.body?.message ?? `HTTP ${e.status}`
    } else {
      payoutError.value = 'บันทึกไม่สำเร็จ'
    }
  } finally {
    payoutSaving.value = false
  }
}

const next3Payouts = computed(() => {
  const dates: { cutoff: string; pay: string }[] = []
  const now = new Date()
  for (let i = 1; i <= 3; i++) {
    let cutoff: Date
    let pay: Date
    if (payout.cycle === 'weekly') {
      const next = new Date(now)
      const daysToMon = (1 - now.getDay() + 7) % 7 || 7
      next.setDate(now.getDate() + daysToMon + (i - 1) * 7)
      pay = next
      cutoff = new Date(next.getTime() - 86400000)
    } else if (payout.cycle === 'biweekly') {
      const next = new Date(now)
      const daysToMon = (1 - now.getDay() + 7) % 7 || 7
      next.setDate(now.getDate() + daysToMon + (i - 1) * 14)
      pay = next
      cutoff = new Date(next.getTime() - 86400000)
    } else {
      pay = new Date(now.getFullYear(), now.getMonth() + i, 1)
      cutoff = new Date(now.getFullYear(), now.getMonth() + i, 0)
    }
    dates.push({
      cutoff: cutoff.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }),
      pay: pay.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }),
    })
  }
  return dates
})

// ── Branding ──────────────────────────────────────────────────────────────
const brandColor = ref('#1f54f5')
const colorPresets = ['#1f54f5', '#0ea5e9', '#10b981', '#f97316', '#ef4444', '#8b5cf6', '#ec4899', '#0f172a']
const emailSignature = ref('ขอแสดงความนับถือ\nทีมงาน บริษัท เอบีซี อินชัวรันส์ จำกัด\nโทร 02-555-0100')

function resetBranding() {
  brandColor.value = '#1f54f5'
  emailSignature.value = 'ขอแสดงความนับถือ\nทีมงาน บริษัท เอบีซี อินชัวรันส์ จำกัด\nโทร 02-555-0100'
}

// ── Audit log ─────────────────────────────────────────────────────────────
interface AuditEntry {
  id: string
  time: string
  user: string
  action: string
  target: string
  ip: string
  result: 'success' | 'failed'
}

const auditEntries = ref<AuditEntry[]>([
  { id: 'a1', time: '2026-06-05 10:42:18', user: 'สมชาย แก้วประเสริฐ', action: 'สร้างผู้ใช้', target: 'newagent1@abc-insure.co.th', ip: '203.150.12.45', result: 'success' },
  { id: 'a2', time: '2026-06-05 09:15:02', user: 'จิราภรณ์ พงษ์ศิริ', action: 'ออกกรมธรรม์', target: 'POL-2026-0412', ip: '203.150.12.78', result: 'success' },
  { id: 'a3', time: '2026-06-05 08:30:55', user: 'ระบบ', action: 'สร้างชุดจ่ายเงิน', target: 'BATCH-2026-06A', ip: '–', result: 'success' },
  { id: 'a4', time: '2026-06-04 17:48:22', user: 'อนุชา ใจดี', action: 'เข้าสู่ระบบล้มเหลว', target: '–', ip: '180.183.45.12', result: 'failed' },
  { id: 'a5', time: '2026-06-04 16:20:10', user: 'สมชาย แก้วประเสริฐ', action: 'แก้ไขบทบาท', target: 'porntip@abc-insure.co.th', ip: '203.150.12.45', result: 'success' },
  { id: 'a6', time: '2026-06-04 14:05:30', user: 'ณัฐวุฒิ รัตนา', action: 'อนุมัติการจ่ายเงิน', target: 'BATCH-2026-05B', ip: '203.150.12.91', result: 'success' },
  { id: 'a7', time: '2026-06-04 11:33:18', user: 'จิราภรณ์ พงษ์ศิริ', action: 'มอบหมายลูกค้า', target: 'CUST-00845', ip: '203.150.12.78', result: 'success' },
  { id: 'a8', time: '2026-06-03 15:12:44', user: 'สมชาย แก้วประเสริฐ', action: 'เปลี่ยนรอบการจ่าย', target: 'monthly → biweekly', ip: '203.150.12.45', result: 'success' },
  { id: 'a9', time: '2026-06-03 09:08:22', user: 'อนุชา ใจดี', action: 'ออกใบเสนอราคา', target: 'QUO-2026-1023', ip: '180.183.45.12', result: 'success' },
  { id: 'a10', time: '2026-06-02 14:40:15', user: 'วรรณา สุขใจ', action: 'ระงับการเข้าถึง', target: 'oldstaff@abc-insure.co.th', ip: '203.150.12.45', result: 'success' },
])

const auditSearch = ref('')
const auditFilter = ref<'all' | 'last24h' | 'last7d' | 'last30d'>('all')

const filteredAudit = computed(() => {
  return auditEntries.value.filter((a) => {
    if (auditSearch.value) {
      const q = auditSearch.value.toLowerCase()
      const hay = `${a.user} ${a.action} ${a.target}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    // Time filter (against the fixed mock dates)
    if (auditFilter.value !== 'all') {
      const limitDays = auditFilter.value === 'last24h' ? 1 : auditFilter.value === 'last7d' ? 7 : 30
      const entryTime = new Date(a.time).getTime()
      const now = new Date('2026-06-05 12:00:00').getTime()
      if (now - entryTime > limitDays * 86_400_000) return false
    }
    return true
  })
})

function exportAuditCsv() {
  const header = ['เวลา', 'ผู้ใช้', 'การดำเนินการ', 'เป้าหมาย', 'IP', 'ผลลัพธ์']
  const rows = filteredAudit.value.map((a) => [a.time, a.user, a.action, a.target, a.ip, a.result === 'success' ? 'สำเร็จ' : 'ล้มเหลว'])
  const csv = [header, ...rows].map((r) => r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `audit-log-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.tenantSettings.name') }}</h1>
      <p class="text-slate-500 text-sm mt-1">{{ t('modules.tenantSettings.description') }}</p>
    </header>

    <!-- Tabs -->
    <div class="border-b border-slate-200 flex items-center gap-1 overflow-x-auto">
      <button
        v-for="tk in (['profile', 'commission', 'payout', 'branding', 'customers', 'followups', 'audit'] as Tab[])"
        :key="tk"
        type="button"
        @click="tab = tk"
        :class="[
          'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition',
          tab === tk
            ? 'border-brand-600 text-brand-700'
            : 'border-transparent text-slate-500 hover:text-slate-900',
        ]"
      >
        {{ t(`settings.tabs.${tk}`) }}
      </button>
    </div>

    <!-- Profile tab -->
    <section v-if="tab === 'profile'" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-1">
        <div class="card p-5">
          <h3 class="font-semibold text-slate-900">{{ t('settings.profile.logo') }}</h3>
          <p class="text-xs text-slate-500 mt-1">{{ t('settings.profile.logoHint') }}</p>

          <div class="mt-4 aspect-square w-full bg-slate-50 border border-dashed border-slate-300 rounded-xl flex items-center justify-center overflow-hidden">
            <img v-if="logoPreview" :src="logoPreview" alt="logo" class="max-w-full max-h-full object-contain" />
            <div v-else class="text-center text-slate-400">
              <i class="pi pi-image text-3xl block mb-2" />
              <span class="text-xs">ยังไม่มีโลโก้</span>
            </div>
          </div>

          <input ref="logoInput" type="file" accept="image/*" class="hidden" @change="onLogoChange" />
          <div class="grid grid-cols-2 gap-2 mt-3">
            <button
              type="button"
              @click="pickLogo"
              class="py-2 text-sm border border-slate-300 rounded-lg hover:bg-slate-50 transition"
            >
              {{ t('settings.profile.changeLogo') }}
            </button>
            <button
              type="button"
              :disabled="!logoPreview"
              @click="removeLogo"
              class="py-2 text-sm border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition disabled:opacity-50"
            >
              {{ t('settings.profile.removeLogo') }}
            </button>
          </div>
        </div>
      </div>

      <div class="lg:col-span-2 card p-6">
        <h3 class="font-semibold text-slate-900">{{ t('settings.profile.title') }}</h3>
        <p class="text-xs text-slate-500 mt-1">{{ t('settings.profile.subtitle') }}</p>

        <form class="mt-5 space-y-4" @submit.prevent="saveProfile">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('settings.profile.name') }}</label>
              <input v-model="profile.name" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('settings.profile.nameEn') }}</label>
              <input v-model="profile.nameEn" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('settings.profile.taxId') }}</label>
              <input v-model="profile.taxId" type="text" maxlength="13" inputmode="numeric" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('settings.profile.oicLicense') }}</label>
              <input v-model="profile.oicLicense" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('settings.profile.phone') }}</label>
              <input v-model="profile.phone" type="tel" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('settings.profile.email') }}</label>
              <input v-model="profile.email" type="email" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('settings.profile.website') }}</label>
              <input v-model="profile.website" type="url" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
          </div>

          <div class="border-t border-slate-100 pt-4">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('settings.profile.address') }}</label>
            <textarea v-model="profile.address" rows="2" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none" />

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('settings.profile.district') }}</label>
                <input v-model="profile.district" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('settings.profile.amphoe') }}</label>
                <input v-model="profile.amphoe" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('settings.profile.province') }}</label>
                <input v-model="profile.province" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('settings.profile.postcode') }}</label>
                <input v-model="profile.postcode" type="text" maxlength="5" inputmode="numeric" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
            </div>
          </div>

          <div class="border-t border-slate-100 pt-4">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">ช่องทางชำระเงิน (แสดงบนใบเตือนต่ออายุ)</label>
            <textarea v-model="profile.paymentChannels" rows="4" maxlength="2000" data-testid="tenant-payment-channels"
              placeholder="เช่น&#10;โอนเข้าบัญชี ธนาคารกสิกรไทย เลขที่ 123-4-56789-0 ชื่อบัญชี บริษัท ... จำกัด&#10;พร้อมเพย์ 0105561234567"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            <p class="text-xs text-slate-500 mt-1">ข้อความนี้ รวมถึงชื่อ ที่อยู่ เบอร์โทร เลขผู้เสียภาษี และใบอนุญาต คปภ. ด้านบน จะพิมพ์บนใบเตือนต่ออายุที่สร้างจากระบบ</p>
          </div>

          <p v-if="profileError" class="text-sm text-rose-600">{{ profileError }}</p>
          <div class="flex items-center justify-end gap-3 pt-2 border-t border-slate-100">
            <span v-if="profileLoading" class="text-slate-400 text-sm">กำลังโหลด…</span>
            <span v-if="profileSaved" class="text-emerald-600 text-sm flex items-center gap-1">
              <i class="pi pi-check-circle" /> {{ t('settings.profile.saved') }}
            </span>
            <button
              type="submit"
              :disabled="profileSaving || profileLoading || !profile.name.trim()"
              data-testid="tenant-profile-save"
              class="px-5 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition disabled:opacity-50 flex items-center gap-2"
            >
              <i v-if="profileSaving" class="pi pi-spin pi-spinner" />
              <span>{{ t('settings.profile.saveChanges') }}</span>
            </button>
          </div>
        </form>
      </div>
    </section>

    <!-- Commission mode tab -->
    <section v-if="tab === 'commission'" class="space-y-5">
      <div>
        <h3 class="font-semibold text-slate-900">{{ t('settings.commission.title') }}</h3>
        <p class="text-sm text-slate-500 mt-1">{{ t('settings.commission.subtitle') }}</p>
      </div>

      <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3 flex items-start gap-2">
        <i class="pi pi-info-circle mt-0.5" />
        <span>{{ t('settings.commission.warningChange') }}</span>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <label
          v-for="mode in (['asEarned', 'advance'] as const)"
          :key="mode"
          :class="[
            'card p-5 cursor-pointer transition relative',
            commissionMode === mode ? 'ring-2 ring-brand-500 border-brand-200' : 'hover:border-slate-300',
            mode === 'advance' ? 'opacity-60 cursor-not-allowed' : '',
          ]"
        >
          <input
            type="radio"
            v-model="commissionMode"
            :value="mode"
            :disabled="mode === 'advance'"
            class="sr-only"
          />
          <div class="flex items-start justify-between mb-2">
            <div class="flex items-center gap-2">
              <div
                :class="[
                  'w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0',
                  commissionMode === mode ? 'border-brand-600' : 'border-slate-300',
                ]"
              >
                <div v-if="commissionMode === mode" class="w-2.5 h-2.5 rounded-full bg-brand-600" />
              </div>
              <h4 class="font-semibold text-slate-900">{{ t(`settings.commission.modes.${mode}.name`) }}</h4>
            </div>
            <span
              :class="[
                'inline-flex px-2 py-0.5 rounded-md text-xs font-medium',
                mode === 'asEarned' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500',
              ]"
            >
              {{ t(`settings.commission.modes.${mode}.badge`) }}
            </span>
          </div>
          <p class="text-sm text-slate-500 leading-relaxed">
            {{ t(`settings.commission.modes.${mode}.desc`) }}
          </p>
          <div
            v-if="commissionMode === mode"
            class="mt-3 pt-3 border-t border-brand-100 text-xs text-brand-700 font-medium flex items-center gap-1"
          >
            <i class="pi pi-check" /> {{ t('settings.commission.currentLabel') }}
          </div>
        </label>
      </div>
    </section>

    <!-- Payout cycle tab -->
    <section v-if="tab === 'payout'" class="space-y-6" data-testid="tenant-payout">
      <div v-if="payoutLoading" class="card p-6 text-sm text-slate-500">กำลังโหลด…</div>
      <div class="card p-6" data-testid="payout-conditions">
        <h3 class="font-semibold text-slate-900">เงื่อนไขก่อนจ่ายค่าคอม</h3>
        <p class="text-xs text-slate-500 mt-1 max-w-3xl">
          รายการที่ไม่ผ่านเงื่อนไขจะขึ้นเป็น "รอเงื่อนไข" พร้อมเหตุผลและไม่เข้ารอบจ่าย · ผู้มีสิทธิ์ "ปรับแต่งค่าคอมมิชชัน" ยกเว้นรายกรมธรรม์ได้โดยต้องใส่เหตุผล (บันทึกใน audit)
        </p>
        <div class="mt-4 space-y-3">
          <label class="flex items-start gap-3 cursor-pointer">
            <input v-model="payoutRules.payoutRequireFreelook" type="checkbox" data-testid="cond-freelook" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600" />
            <span><span class="text-sm font-medium text-slate-700">พ้นระยะ Freelook แล้ว</span><span class="block text-xs text-slate-500">เฉพาะประกันชีวิต ต้องมีวันที่ลูกค้าได้รับกรมธรรม์และพ้นวันสิ้นสุด Freelook</span></span>
          </label>
          <label class="flex items-start gap-3 cursor-pointer">
            <input v-model="payoutRules.payoutRequireInsurerReceived" type="checkbox" data-testid="cond-insurer" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600" />
            <span><span class="text-sm font-medium text-slate-700">ได้รับค่าคอมจากบริษัทประกันแล้ว</span><span class="block text-xs text-slate-500">รายการค่าคอมหลักของกรมธรรม์ในหน้า "รับค่าคอม" ต้องเป็นสถานะ "รับแล้ว"</span></span>
          </label>
          <label class="flex items-start gap-3 cursor-pointer">
            <input v-model="payoutRules.payoutRequireCustomerPaid" type="checkbox" data-testid="cond-customer" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600" />
            <span><span class="text-sm font-medium text-slate-700">ลูกค้าชำระเบี้ยแล้ว</span><span class="block text-xs text-slate-500">กรมธรรม์ผ่อนจ่ายค่าคอมพื้นฐานตามสัดส่วนงวดที่ชำระครบ (เช่น 3/10 งวด = 30%) ปิดข้อนี้ = จ่ายเต็มทันที</span></span>
          </label>
        </div>
      </div>

      <div class="card p-6 grid grid-cols-1 md:grid-cols-2 gap-4" data-testid="payout-wht">
        <div class="md:col-span-2">
          <h3 class="font-semibold text-slate-900">ภาษีหัก ณ ที่จ่าย (ค่าเริ่มต้น)</h3>
          <p class="text-xs text-slate-500 mt-1">คิดจากยอดก่อนภาษีมูลค่าเพิ่ม หลังหักรายการหัก · กำหนดอัตรารายตัวแทนได้ที่หน้าแก้ไขตัวแทน · ควรยืนยันอัตราและแบบที่ยื่นกับฝ่ายบัญชี/ผู้สอบบัญชี</p>
        </div>
        <label class="text-sm text-slate-700">บุคคลธรรมดา (ยื่น ภ.ง.ด.3) %
          <input v-model.number="payoutRules.whtRateIndividual" type="number" min="0" max="15" step="0.01" data-testid="wht-individual" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm text-right" />
        </label>
        <label class="text-sm text-slate-700">นิติบุคคล (ยื่น ภ.ง.ด.53) %
          <input v-model.number="payoutRules.whtRateCorporate" type="number" min="0" max="15" step="0.01" data-testid="wht-corporate" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm text-right" />
        </label>
      </div>

      <div>
        <h3 class="font-semibold text-slate-900">{{ t('settings.payout.title') }}</h3>
        <p class="text-sm text-slate-500 mt-1">{{ t('settings.payout.subtitle') }}</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <label
          v-for="c in (['weekly', 'biweekly', 'monthly'] as const)"
          :key="c"
          :class="[
            'card p-4 cursor-pointer transition',
            payout.cycle === c ? 'ring-2 ring-brand-500 border-brand-200' : 'hover:border-slate-300',
          ]"
        >
          <input type="radio" v-model="payout.cycle" :value="c" class="sr-only" />
          <div class="flex items-start gap-3">
            <div
              :class="[
                'w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 mt-0.5',
                payout.cycle === c ? 'border-brand-600' : 'border-slate-300',
              ]"
            >
              <div v-if="payout.cycle === c" class="w-2.5 h-2.5 rounded-full bg-brand-600" />
            </div>
            <div class="min-w-0">
              <div class="font-medium text-slate-900">{{ t(`settings.payout.cycles.${c}`) }}</div>
              <div class="text-xs text-slate-500 mt-1">{{ t(`settings.payout.cycleDesc.${c}`) }}</div>
            </div>
          </div>
        </label>
      </div>

      <div class="card p-5">
        <h4 class="font-semibold text-slate-900 mb-4">ตัวเลือกเพิ่มเติม</h4>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('settings.payout.minBalance') }}</label>
            <input
              v-model.number="payout.minBalance"
              type="number"
              min="0"
              class="w-full md:w-64 px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
            <p class="text-xs text-slate-500 mt-1">{{ t('settings.payout.minBalanceHint') }}</p>
          </div>

          <label class="flex items-start gap-3 cursor-pointer py-2 border-t border-slate-100 mt-3 pt-4">
            <input
              v-model="payout.autoApprove"
              type="checkbox"
              class="mt-0.5 w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            />
            <div>
              <div class="text-sm font-medium text-slate-700">{{ t('settings.payout.autoApprove') }}</div>
              <div class="text-xs text-slate-500 mt-0.5">{{ t('settings.payout.autoApproveHint') }}</div>
            </div>
          </label>
        </div>
      </div>

      <div class="card p-5">
        <h4 class="font-semibold text-slate-900">{{ t('settings.payout.preview') }}</h4>
        <p class="text-xs text-slate-500 mt-1">{{ t('settings.payout.next3') }}</p>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
          <div v-for="(p, i) in next3Payouts" :key="i" class="border border-slate-200 rounded-lg p-3">
            <div class="text-xs text-slate-500">รอบที่ {{ i + 1 }}</div>
            <div class="text-sm font-semibold text-slate-900 mt-1">{{ p.pay }}</div>
            <div class="text-xs text-slate-500 mt-2">
              <span class="text-slate-400">ตัดยอด:</span> {{ p.cutoff }}
            </div>
          </div>
        </div>
      </div>

      <p v-if="payoutError" class="text-xs text-rose-600">{{ payoutError }}</p>
      <div class="flex items-center justify-end gap-3">
        <span v-if="payoutSaved" class="text-xs text-emerald-600"><i class="pi pi-check" /> บันทึกแล้ว</span>
        <button type="button" data-testid="payout-save" class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
          :disabled="payoutSaving || payoutLoading" @click="savePayout">บันทึก</button>
      </div>
    </section>

    <!-- Branding tab -->
    <section v-if="tab === 'branding'" class="space-y-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="font-semibold text-slate-900">{{ t('settings.branding.title') }}</h3>
          <p class="text-sm text-slate-500 mt-1">{{ t('settings.branding.subtitle') }}</p>
        </div>
        <button
          type="button"
          @click="resetBranding"
          class="px-3 py-2 text-sm border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition"
        >
          {{ t('settings.branding.reset') }}
        </button>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card p-5">
          <h4 class="font-semibold text-slate-900">{{ t('settings.branding.primaryColor') }}</h4>
          <p class="text-xs text-slate-500 mt-1">{{ t('settings.branding.primaryColorHint') }}</p>

          <div class="mt-4 flex items-center gap-3">
            <div class="relative">
              <input
                v-model="brandColor"
                type="color"
                class="w-14 h-14 rounded-lg border-2 border-slate-200 cursor-pointer"
              />
            </div>
            <input
              v-model="brandColor"
              type="text"
              maxlength="7"
              class="w-32 px-3 py-2 font-mono text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500"
            />
          </div>

          <div class="mt-5">
            <div class="text-xs font-medium text-slate-600 mb-2">{{ t('settings.branding.colorPresets') }}</div>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="c in colorPresets"
                :key="c"
                type="button"
                @click="brandColor = c"
                :style="{ background: c }"
                :class="[
                  'w-8 h-8 rounded-full border-2 transition',
                  brandColor.toLowerCase() === c.toLowerCase() ? 'border-slate-900 ring-2 ring-offset-2 ring-slate-300' : 'border-white shadow-sm',
                ]"
                :title="c"
              />
            </div>
          </div>

          <div class="mt-6 pt-4 border-t border-slate-100">
            <div class="text-xs font-medium text-slate-600 mb-3">{{ t('settings.branding.preview') }}</div>
            <div class="flex flex-wrap items-center gap-3">
              <button
                type="button"
                :style="{ background: brandColor }"
                class="px-4 py-2 text-white text-sm font-medium rounded-lg"
              >
                {{ t('settings.branding.previewButton') }}
              </button>
              <a
                :style="{ color: brandColor }"
                class="text-sm font-medium underline"
                href="#"
                @click.prevent
              >
                {{ t('settings.branding.previewLink') }}
              </a>
              <span
                :style="{ background: brandColor + '20', color: brandColor }"
                class="px-2.5 py-1 rounded-md text-xs font-medium"
              >
                แบดจ์
              </span>
            </div>
          </div>
        </div>

        <div class="card p-5">
          <h4 class="font-semibold text-slate-900">{{ t('settings.branding.emailSignature') }}</h4>
          <p class="text-xs text-slate-500 mt-1">{{ t('settings.branding.emailSignatureHint') }}</p>

          <textarea
            v-model="emailSignature"
            rows="6"
            class="mt-3 w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none font-sans"
          />

          <div class="mt-4 p-4 bg-slate-50 border border-slate-200 rounded-lg">
            <div class="text-xs text-slate-500 mb-2">พรีวิวอีเมล</div>
            <div class="text-sm text-slate-800 mb-3">
              เรียน คุณลูกค้า,<br />
              ขอบคุณที่ไว้วางใจบริการของเรา ใบเสร็จกรมธรรม์แนบมาด้วย
            </div>
            <div class="text-sm text-slate-700 whitespace-pre-line border-t border-slate-200 pt-3">{{ emailSignature }}</div>
          </div>
        </div>
      </div>
    </section>

    <section v-if="tab === 'customers'" class="space-y-4" data-testid="tenant-customer-tiers">
      <div class="card p-6">
        <h3 class="font-semibold text-slate-900">{{ t('settings.customerTiers.title') }}</h3>
        <p class="text-xs text-slate-500 mt-1">{{ t('settings.customerTiers.subtitle') }}</p>
        <div v-if="tiersLoading" class="text-sm text-slate-500 mt-4">กำลังโหลด…</div>
        <table v-else class="w-full text-sm mt-4">
          <thead class="text-xs text-slate-500">
            <tr>
              <th class="text-left font-medium py-2 w-24">Key</th>
              <th class="text-left font-medium py-2 pr-3">{{ t('settings.customerTiers.label') }}</th>
              <th class="text-left font-medium py-2">{{ t('settings.customerTiers.min') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in tierRows" :key="r.key">
              <td class="py-2 font-mono text-xs text-slate-500 uppercase">{{ r.key }}</td>
              <td class="py-2 pr-3">
                <input v-model="r.label" maxlength="32"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400" />
              </td>
              <td class="py-2">
                <input v-model="r.min" inputmode="decimal" :placeholder="t('settings.customerTiers.disabledHint')"
                  :data-testid="`tier-min-${r.key}`"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white text-right tabular-nums focus:outline-none focus:border-brand-400" />
              </td>
            </tr>
          </tbody>
        </table>
        <p class="text-[11px] text-slate-400 mt-2">{{ t('settings.customerTiers.disabledHint') }}</p>
        <p v-if="tierValidation" class="text-xs text-rose-600 mt-2">{{ tierValidation }}</p>
        <p v-if="tiersError" class="text-xs text-rose-600 mt-2">{{ tiersError }}</p>
        <div class="flex items-center justify-end gap-3 mt-4">
          <span v-if="tiersSaved" class="text-xs text-emerald-600"><i class="pi pi-check" /> {{ t('settings.customerTiers.saved') }}</span>
          <button type="button" data-testid="tier-save"
            class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
            :disabled="tiersSaving || tiersLoading || !!tierValidation" @click="saveTiers">
            {{ t('settings.customerTiers.save') }}
          </button>
        </div>
      </div>
    </section>

    <section v-if="tab === 'followups'" class="space-y-4" data-testid="tenant-followups">
      <div v-if="followUpLoading" class="card p-6 text-sm text-slate-500">กำลังโหลด…</div>
      <template v-else>
        <div class="card p-6">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h3 class="font-semibold text-slate-900">ตรวจสอบ 2 ชั้น (Maker-Checker / Verify)</h3>
              <p class="text-xs text-slate-500 mt-1 max-w-2xl">
                เมื่อเปิด การสร้างกรมธรรม์ การแก้ข้อมูลสำคัญ (ลูกค้า ตัวแทน บริษัท สินค้า เลขกรมธรรม์ วันที่ ทุน เบี้ย ค่าคอม งวดชำระ สถานะ) และการลบ
                จะเป็น "คำขอ" ที่ต้องให้ผู้ใช้อีกคนที่มีสิทธิ์ "ตรวจสอบ (Verify) กรมธรรม์" อนุมัติก่อน ข้อมูลจริงจะไม่เปลี่ยนจนกว่าจะอนุมัติ · ผู้ส่งคำขออนุมัติเองไม่ได้
              </p>
            </div>
            <label class="inline-flex shrink-0 cursor-pointer items-center gap-2">
              <input v-model="followUp.policyVerifyRequired" type="checkbox" data-testid="verify-toggle"
                class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-400" />
              <span :class="['text-sm font-medium', followUp.policyVerifyRequired ? 'text-emerald-700' : 'text-slate-500']">
                {{ followUp.policyVerifyRequired ? 'เปิดใช้งาน' : 'ปิดอยู่' }}
              </span>
            </label>
          </div>
          <p class="mt-3 text-[11px] text-slate-400">กำหนดผู้มีสิทธิ์ตรวจได้ที่ ผู้ใช้และสิทธิ์ › บทบาท › กรมธรรม์ › ตรวจสอบ (Verify) กรมธรรม์</p>
        </div>

        <div class="card p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="md:col-span-3">
            <h3 class="font-semibold text-slate-900">กติกาการติดตามงานค้าง</h3>
          </div>
          <label class="text-sm text-slate-700">Freelook ขายตรง (วัน)
            <input v-model.number="followUp.freelookDaysStandard" type="number" min="1" max="120" data-testid="freelook-days-standard"
              class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm text-right tabular-nums" />
            <span class="mt-0.5 block text-[11px] text-slate-400">นับจากวันที่ลูกค้าได้รับกรมธรรม์</span>
          </label>
          <label class="text-sm text-slate-700">Freelook ขายทางโทรศัพท์/ออนไลน์ (วัน)
            <input v-model.number="followUp.freelookDaysRemote" type="number" min="1" max="120" data-testid="freelook-days-remote"
              class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm text-right tabular-nums" />
          </label>
          <label class="text-sm text-slate-700">ติดตาม "ยังไม่บันทึกค่าคอม" ย้อนหลัง (วัน)
            <input v-model.number="followUp.noCommissionWindowDays" type="number" min="7" max="3650" data-testid="no-commission-days"
              class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm text-right tabular-nums" />
            <span class="mt-0.5 block text-[11px] text-slate-400">นับจากวันเริ่มคุ้มครอง</span>
          </label>
        </div>

        <div v-if="confirmVerifyOff" class="card p-4 bg-amber-50 border border-amber-300 text-sm text-amber-900" data-testid="verify-off-confirm">
          ปิดการตรวจสอบ 2 ชั้น? คำขอที่ค้างอยู่จะยังรอตรวจตามเดิม แต่การแก้ไขต่อจากนี้จะบันทึกทันทีโดยไม่มีคนตรวจ · กด "บันทึก" อีกครั้งเพื่อยืนยัน
        </div>
        <p v-if="followUpError" class="text-xs text-rose-600">{{ followUpError }}</p>
        <div class="flex items-center justify-end gap-3">
          <span v-if="followUpSaved" class="text-xs text-emerald-600"><i class="pi pi-check" /> บันทึกแล้ว</span>
          <button type="button" data-testid="followups-save"
            class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
            :disabled="followUpSaving" @click="saveFollowUp">บันทึก</button>
        </div>
      </template>
    </section>

    <!-- Audit log tab -->
    <section v-if="tab === 'audit'" class="space-y-4">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="font-semibold text-slate-900">{{ t('settings.audit.title') }}</h3>
          <p class="text-sm text-slate-500 mt-1">{{ t('settings.audit.subtitle') }}</p>
        </div>
        <button
          type="button"
          @click="exportAuditCsv"
          class="px-3 py-2 text-sm border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition flex items-center gap-2"
        >
          <i class="pi pi-download" />
          {{ t('common.export') }} CSV
        </button>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <div class="relative flex-1 min-w-[240px]">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input
            v-model="auditSearch"
            type="search"
            :placeholder="t('settings.audit.searchPlaceholder')"
            class="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-400"
          />
        </div>
        <div class="flex items-center gap-1 bg-white border border-slate-200 rounded-lg p-0.5">
          <button
            v-for="f in (['all', 'last24h', 'last7d', 'last30d'] as const)"
            :key="f"
            type="button"
            @click="auditFilter = f"
            :class="[
              'px-3 py-1.5 text-xs font-medium rounded transition',
              auditFilter === f ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-900',
            ]"
          >
            {{ t(`settings.audit.filters.${f}`) }}
          </button>
        </div>
      </div>

      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
              <tr>
                <th class="text-left px-4 py-3 font-medium">{{ t('settings.audit.cols.time') }}</th>
                <th class="text-left px-4 py-3 font-medium">{{ t('settings.audit.cols.user') }}</th>
                <th class="text-left px-4 py-3 font-medium">{{ t('settings.audit.cols.action') }}</th>
                <th class="text-left px-4 py-3 font-medium">{{ t('settings.audit.cols.target') }}</th>
                <th class="text-left px-4 py-3 font-medium">{{ t('settings.audit.cols.ip') }}</th>
                <th class="text-left px-4 py-3 font-medium">{{ t('settings.audit.cols.result') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="a in filteredAudit" :key="a.id" class="hover:bg-slate-50/50">
                <td class="px-4 py-3 text-slate-500 text-xs whitespace-nowrap font-mono">{{ a.time }}</td>
                <td class="px-4 py-3 text-slate-900">{{ a.user }}</td>
                <td class="px-4 py-3 text-slate-700">{{ a.action }}</td>
                <td class="px-4 py-3 text-slate-700 font-mono text-xs">{{ a.target }}</td>
                <td class="px-4 py-3 text-slate-500 text-xs font-mono">{{ a.ip }}</td>
                <td class="px-4 py-3">
                  <span
                    :class="[
                      'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium',
                      a.result === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700',
                    ]"
                  >
                    <i :class="a.result === 'success' ? 'pi pi-check' : 'pi pi-times'" />
                    {{ t(`settings.audit.result.${a.result}`) }}
                  </span>
                </td>
              </tr>
              <tr v-if="!filteredAudit.length">
                <td colspan="6" class="px-4 py-10 text-center text-slate-400 text-sm">
                  {{ t('common.noData') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</template>
