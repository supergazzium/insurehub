<script setup lang="ts">
// Agent portal — profile page (Phase 2 rewrite).
//
// Six sections, each with its own "Save this section" button. A global
// "Save All" button at the bottom submits every dirty section in one PATCH.
// Section state is tracked with a per-section `dirty` flag so the buttons
// enable/disable without extra bookkeeping.
//
// Sections (per spec):
//   1. Personal Info      — photo, name, phone, DOB, national ID, ID photo
//   2. Tax Invoice Address
//   3. Document Delivery Address (with "same as tax" toggle)
//   4. Non-life License   — has/doesn't have radio
//   5. Life License       — has/doesn't have radio
//   6. Bank Account       — bank dropdown, account number, bankbook photo
import { reactive, ref, computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  fetchMyAgent, patchProfile, patchIdDocument, patchLicense, patchBank,
  patchAddress, patchDelivery, patchAll, uploadPhoto,
  fetchBanks, fetchProvinces, fetchDistricts, fetchSubDistricts,
  type MyAgent, type BankOption, type SubDistrictOption,
} from '../../api/portal'
import { ApiError } from '../../api/client'
import { isThaiName, isThaiId13, isThaiMobile } from '../../utils/thaiValidation'
import DateInput from '../../components/DateInput.vue'
import { toIsoDate } from '../../util/dateFormat'

const { t, locale } = useI18n()

const me = ref<MyAgent | null>(null)
const loading = ref(true)
const savingSection = ref<string | null>(null)
const savingAll = ref(false)
const message = reactive<Record<string, { ok: boolean; text: string } | null>>({
  personal: null, tax: null, delivery: null, life: null, nonLife: null, bank: null,
})

// Section forms
const personal = reactive({
  firstName: '', lastName: '', phone: '', birthDate: '' as string | null, idCard: '',
})
const tax = reactive({ address: '', province: '', district: '', subDistrict: '', postcode: '' })
const delivery = reactive({
  sameAsTax: true,
  address: '', province: '', district: '', subDistrict: '', postcode: '',
})
const life = reactive({ has: false })
const nonLife = reactive({ has: false })
const bank = reactive({ bankId: '' as string, bankAccountNo: '', bankAccountName: '' })

// Section dirty flags
const dirty = reactive({
  personal: false, tax: false, delivery: false, life: false, nonLife: false, bank: false,
})

// Lookups
const banks = ref<BankOption[]>([])
const provinces = ref<string[]>([])
const taxDistricts = ref<string[]>([])
const taxSubDistricts = ref<SubDistrictOption[]>([])
const deliveryDistricts = ref<string[]>([])
const deliverySubDistricts = ref<SubDistrictOption[]>([])

async function loadLookups(): Promise<void> {
  const [b, p] = await Promise.all([fetchBanks(), fetchProvinces()])
  banks.value = b.data
  provinces.value = p.data
}

async function hydrate(m: MyAgent): Promise<void> {
  me.value = m
  personal.firstName = m.firstName
  personal.lastName = m.lastName
  personal.phone = m.phone
  personal.birthDate = m.birthDate
  personal.idCard = ''

  tax.address = m.address; tax.province = m.province
  tax.district = m.district; tax.subDistrict = m.subDistrict; tax.postcode = m.postcode
  delivery.sameAsTax = m.deliverySameAsTax
  delivery.address = m.deliveryAddress; delivery.province = m.deliveryProvince
  delivery.district = m.deliveryDistrict; delivery.subDistrict = m.deliverySubDistrict
  delivery.postcode = m.deliveryPostcode

  life.has = m.hasLifeLicense
  nonLife.has = m.hasNonLifeLicense

  bank.bankId = m.bankId ?? ''
  bank.bankAccountNo = ''; bank.bankAccountName = m.bankAccountName

  if (m.province) taxDistricts.value = (await fetchDistricts(m.province)).data
  if (m.province && m.district) taxSubDistricts.value = (await fetchSubDistricts(m.province, m.district)).data
  if (m.deliveryProvince) deliveryDistricts.value = (await fetchDistricts(m.deliveryProvince)).data
  if (m.deliveryProvince && m.deliveryDistrict)
    deliverySubDistricts.value = (await fetchSubDistricts(m.deliveryProvince, m.deliveryDistrict)).data

  // Reset dirty AFTER hydration so the initial fill doesn't mark them dirty.
  ;(['personal', 'tax', 'delivery', 'life', 'nonLife', 'bank'] as const)
    .forEach((k) => { dirty[k] = false })
}

onMounted(async () => {
  loading.value = true
  try {
    const [meRes] = await Promise.all([fetchMyAgent(), loadLookups()])
    await hydrate(meRes.data)
  } catch (e) {
    console.warn('portal profile load failed', e)
  } finally {
    loading.value = false
  }
})

// Cascade handlers — reset downstream fields when a parent changes
async function onTaxProvinceChange(): Promise<void> {
  tax.district = ''; tax.subDistrict = ''; tax.postcode = ''
  taxDistricts.value = tax.province ? (await fetchDistricts(tax.province)).data : []
  taxSubDistricts.value = []
}
async function onTaxDistrictChange(): Promise<void> {
  tax.subDistrict = ''; tax.postcode = ''
  taxSubDistricts.value = tax.province && tax.district
    ? (await fetchSubDistricts(tax.province, tax.district)).data : []
}
function onTaxSubDistrictChange(): void {
  const match = taxSubDistricts.value.find((s) => s.name === tax.subDistrict)
  if (match) tax.postcode = match.postcode
}
async function onDeliveryProvinceChange(): Promise<void> {
  delivery.district = ''; delivery.subDistrict = ''; delivery.postcode = ''
  deliveryDistricts.value = delivery.province ? (await fetchDistricts(delivery.province)).data : []
  deliverySubDistricts.value = []
}
async function onDeliveryDistrictChange(): Promise<void> {
  delivery.subDistrict = ''; delivery.postcode = ''
  deliverySubDistricts.value = delivery.province && delivery.district
    ? (await fetchSubDistricts(delivery.province, delivery.district)).data : []
}
function onDeliverySubDistrictChange(): void {
  const match = deliverySubDistricts.value.find((s) => s.name === delivery.subDistrict)
  if (match) delivery.postcode = match.postcode
}

// Per-section validation
const personalErrors = computed(() => {
  const errs: string[] = []
  if (personal.firstName && !isThaiName(personal.firstName)) errs.push(t('portalProfile.err.firstNameThai'))
  if (personal.lastName && !isThaiName(personal.lastName)) errs.push(t('portalProfile.err.lastNameThai'))
  if (personal.phone && !isThaiMobile(personal.phone)) errs.push(t('portalProfile.err.phoneInvalid'))
  if (personal.idCard && !isThaiId13(personal.idCard)) errs.push(t('portalProfile.err.idCardInvalid'))
  return errs
})

// ── Section savers ─────────────────────────────────────────────────
async function savePersonal(): Promise<void> {
  if (personalErrors.value.length > 0) {
    message.personal = { ok: false, text: personalErrors.value[0] }
    return
  }
  savingSection.value = 'personal'
  try {
    const profileRes = await patchProfile({
      firstName: personal.firstName, lastName: personal.lastName,
      phone: personal.phone, birthDate: personal.birthDate,
    } as Partial<MyAgent>)
    let latest = profileRes.data
    if (personal.idCard.trim() !== '') {
      const idRes = await patchIdDocument({ idCard: personal.idCard.trim() })
      latest = idRes.data
    }
    await hydrate(latest)
    message.personal = { ok: true, text: t('portalProfile.saved') }
  } catch (e: unknown) {
    message.personal = { ok: false, text: e instanceof ApiError ? e.message : 'Save failed.' }
  } finally { savingSection.value = null }
}

async function saveTax(): Promise<void> {
  savingSection.value = 'tax'
  try {
    const res = await patchAddress({
      address: tax.address, province: tax.province, district: tax.district,
      subDistrict: tax.subDistrict, postcode: tax.postcode,
    } as Partial<MyAgent>)
    await hydrate(res.data)
    message.tax = { ok: true, text: t('portalProfile.saved') }
  } catch (e: unknown) {
    message.tax = { ok: false, text: e instanceof ApiError ? e.message : 'Save failed.' }
  } finally { savingSection.value = null }
}

async function saveDelivery(): Promise<void> {
  savingSection.value = 'delivery'
  try {
    const res = await patchDelivery({
      deliverySameAsTax: delivery.sameAsTax,
      deliveryAddress: delivery.sameAsTax ? null : delivery.address,
      deliveryProvince: delivery.sameAsTax ? null : delivery.province,
      deliveryDistrict: delivery.sameAsTax ? null : delivery.district,
      deliverySubDistrict: delivery.sameAsTax ? null : delivery.subDistrict,
      deliveryPostcode: delivery.sameAsTax ? null : delivery.postcode,
    } as Partial<MyAgent>)
    await hydrate(res.data)
    message.delivery = { ok: true, text: t('portalProfile.saved') }
  } catch (e: unknown) {
    message.delivery = { ok: false, text: e instanceof ApiError ? e.message : 'Save failed.' }
  } finally { savingSection.value = null }
}

async function saveLife(): Promise<void> {
  savingSection.value = 'life'
  try {
    const res = await patchLicense({ hasLifeLicense: life.has } as Partial<MyAgent>)
    await hydrate(res.data)
    message.life = { ok: true, text: t('portalProfile.saved') }
  } catch (e: unknown) {
    message.life = { ok: false, text: e instanceof ApiError ? e.message : 'Save failed.' }
  } finally { savingSection.value = null }
}
async function saveNonLife(): Promise<void> {
  savingSection.value = 'nonLife'
  try {
    const res = await patchLicense({ hasNonLifeLicense: nonLife.has } as Partial<MyAgent>)
    await hydrate(res.data)
    message.nonLife = { ok: true, text: t('portalProfile.saved') }
  } catch (e: unknown) {
    message.nonLife = { ok: false, text: e instanceof ApiError ? e.message : 'Save failed.' }
  } finally { savingSection.value = null }
}

async function saveBank(): Promise<void> {
  savingSection.value = 'bank'
  try {
    const payload: Record<string, unknown> = {
      bankId: bank.bankId || null,
      bankAccountName: bank.bankAccountName || null,
    }
    if (bank.bankAccountNo.trim() !== '') payload.bankAccountNo = bank.bankAccountNo.trim()
    const res = await patchBank(payload as Partial<MyAgent>)
    await hydrate(res.data)
    message.bank = { ok: true, text: t('portalProfile.saved') }
  } catch (e: unknown) {
    message.bank = { ok: false, text: e instanceof ApiError ? e.message : 'Save failed.' }
  } finally { savingSection.value = null }
}

async function saveAll(): Promise<void> {
  if (personalErrors.value.length > 0) {
    message.personal = { ok: false, text: personalErrors.value[0] }
    return
  }
  savingAll.value = true
  try {
    const payload: Record<string, unknown> = {
      firstName: personal.firstName, lastName: personal.lastName,
      phone: personal.phone, birthDate: personal.birthDate,
      address: tax.address, province: tax.province, district: tax.district,
      subDistrict: tax.subDistrict, postcode: tax.postcode,
      deliverySameAsTax: delivery.sameAsTax,
      deliveryAddress: delivery.sameAsTax ? null : delivery.address,
      deliveryProvince: delivery.sameAsTax ? null : delivery.province,
      deliveryDistrict: delivery.sameAsTax ? null : delivery.district,
      deliverySubDistrict: delivery.sameAsTax ? null : delivery.subDistrict,
      deliveryPostcode: delivery.sameAsTax ? null : delivery.postcode,
      hasLifeLicense: life.has, hasNonLifeLicense: nonLife.has,
      bankId: bank.bankId || null,
      bankAccountName: bank.bankAccountName || null,
    }
    if (bank.bankAccountNo.trim() !== '') payload.bankAccountNo = bank.bankAccountNo.trim()
    const res = await patchAll(payload as Partial<MyAgent>)
    let latest = res.data
    if (personal.idCard.trim() !== '') {
      const idRes = await patchIdDocument({ idCard: personal.idCard.trim() })
      latest = idRes.data
    }
    await hydrate(latest)
    ;(['personal', 'tax', 'delivery', 'life', 'nonLife', 'bank'] as const)
      .forEach((k) => { message[k] = { ok: true, text: t('portalProfile.saved') } })
  } catch (e: unknown) {
    const text = e instanceof ApiError ? e.message : 'Save failed.'
    ;(['personal', 'tax', 'delivery', 'life', 'nonLife', 'bank'] as const)
      .forEach((k) => { message[k] = { ok: false, text } })
  } finally { savingAll.value = false }
}

// Photo uploads
const uploading = ref<string | null>(null)
async function upload(kind: 'profile-photo' | 'id-photo' | 'bank-book-photo', file: File | undefined | null): Promise<void> {
  if (!file) return
  uploading.value = kind
  try {
    const res = await uploadPhoto(kind, file)
    await hydrate(res.data)
    // Belt-and-suspenders: if the watcher didn't fire (Vue reactivity edge
    // case with the array getter), explicitly refresh just this kind.
    const short: 'profile' | 'id' | 'bank' =
      kind === 'profile-photo' ? 'profile' : kind === 'id-photo' ? 'id' : 'bank'
    const path =
      short === 'profile' ? res.data.profilePhotoPath
      : short === 'id' ? res.data.idCardPhotoPath
      : res.data.bankBookPhotoPath
    await refreshPhoto(short, path)
  } catch (e) { console.warn('upload failed', e) } finally { uploading.value = null }
}

const bankLabel = (b: BankOption): string => (locale.value === 'th' ? b.nameTh : (b.nameEn || b.nameTh))

// ── Photo rendering ─────────────────────────────────────────────────
// Photos are stored on the backend's private `local` disk (never at a
// public /storage URL). They're fetched through the bearer-authenticated
// GET /me/agent/photo/{kind} endpoint, wrapped in a blob URL so <img src>
// can render them. Re-fetch runs on hydrate whenever the stored path
// changes so uploads render immediately after save.
const photoBlobUrls = reactive<Record<'profile' | 'id' | 'bank', string>>({
  profile: '', id: '', bank: '',
})
async function refreshPhoto(kind: 'profile' | 'id' | 'bank', path: string | null | undefined): Promise<void> {
  // Revoke the previous blob URL so we don't leak memory across re-renders.
  if (photoBlobUrls[kind]) {
    URL.revokeObjectURL(photoBlobUrls[kind])
    photoBlobUrls[kind] = ''
  }
  if (!path) return
  const { getToken } = await import('../../api/client')
  const token = getToken()
  if (!token) return
  const base = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.replace(/\/+$/, '')
    ?? 'http://127.0.0.1:8000/api/v1'
  try {
    // Cache-bust with the file path so the browser doesn't reuse a stale
    // response after a re-upload with the same kind. Path includes a
    // random suffix so it changes on every upload.
    const bust = encodeURIComponent(path)
    const res = await fetch(`${base}/me/agent/photo/${kind}?v=${bust}`, {
      headers: { Authorization: `Bearer ${token}` },
      cache: 'no-store',
    })
    if (!res.ok) {
      console.warn(`[portal-profile] photo fetch failed: ${kind} → HTTP ${res.status}`)
      return
    }
    const blob = await res.blob()
    if (blob.size === 0) {
      console.warn(`[portal-profile] photo fetch returned empty blob: ${kind}`)
      return
    }
    photoBlobUrls[kind] = URL.createObjectURL(blob)
  } catch (e) {
    console.warn(`[portal-profile] photo fetch error: ${kind}`, e)
  }
}
function photoUrlFor(kind: 'profile' | 'id' | 'bank'): string {
  return photoBlobUrls[kind]
}
onBeforeUnmount(() => {
  for (const k of ['profile', 'id', 'bank'] as const) {
    if (photoBlobUrls[k]) URL.revokeObjectURL(photoBlobUrls[k])
  }
})
// Refresh all three photo blobs whenever the agent record changes.
watch(
  () => me.value ? [me.value.profilePhotoPath, me.value.idCardPhotoPath, me.value.bankBookPhotoPath] as const : null,
  (paths) => {
    if (!paths) return
    void refreshPhoto('profile', paths[0])
    void refreshPhoto('id', paths[1])
    void refreshPhoto('bank', paths[2])
  },
  { immediate: true, deep: false },
)

const anyDirty = computed(() =>
  dirty.personal || dirty.tax || dirty.delivery || dirty.life || dirty.nonLife || dirty.bank,
)

// Dirty watchers — deep so cascade handlers reset children and still flip dirty.
watch(personal, () => { dirty.personal = true }, { deep: true })
watch(tax, () => { dirty.tax = true }, { deep: true })
watch(delivery, () => { dirty.delivery = true }, { deep: true })
watch(life, () => { dirty.life = true }, { deep: true })
watch(nonLife, () => { dirty.nonLife = true }, { deep: true })
watch(bank, () => { dirty.bank = true }, { deep: true })
</script>

<template>
  <div v-if="loading" class="py-16 text-center text-slate-500">
    <i class="pi pi-spin pi-spinner mr-1" /> {{ t('portalProfile.loading') }}
  </div>

  <div v-else-if="me" class="space-y-6">
    <!-- Header card -->
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200 flex items-center gap-4">
      <div class="shrink-0">
        <div v-if="me.profilePhotoPath" class="w-16 h-16 rounded-full overflow-hidden bg-slate-100">
          <img :src="photoUrlFor('profile')" class="w-full h-full object-cover" />
        </div>
        <div v-else class="w-16 h-16 rounded-full bg-brand-600 text-white flex items-center justify-center font-bold text-xl">
          {{ (me.firstName?.[0] ?? '') + (me.lastName?.[0] ?? '') }}
        </div>
      </div>
      <div class="flex-1 min-w-0">
        <div class="text-lg font-semibold text-slate-900 truncate">
          {{ me.firstName }} {{ me.lastName }}
        </div>
        <div class="mt-1 flex items-center gap-2">
          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-brand-50 border border-brand-200">
            <span class="text-[10px] uppercase text-brand-600 font-semibold tracking-wider">{{ t('portalProfile.agentCode') }}</span>
            <span class="text-sm font-mono font-semibold text-brand-800">{{ me.agentCode }}</span>
          </span>
          <span v-if="me.approvalStatus === 'pending'" class="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-800">{{ t('portalProfile.pending') }}</span>
        </div>
      </div>
    </div>

    <!-- Personal Info -->
    <section class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h2 class="text-base font-semibold text-slate-900 mb-4">{{ t('portalProfile.personal.title') }}</h2>

      <div class="mb-4">
        <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.personal.profilePhoto') }}</label>
        <div class="flex items-center gap-3">
          <div class="w-20 h-20 rounded-lg overflow-hidden bg-slate-100 flex items-center justify-center">
            <img v-if="me.profilePhotoPath" :src="photoUrlFor('profile')" class="w-full h-full object-cover" />
            <i v-else class="pi pi-user text-3xl text-slate-400" />
          </div>
          <label class="cursor-pointer px-3 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">
            <i class="pi pi-upload mr-1" /> {{ t('portalProfile.upload') }}
            <input type="file" accept="image/*" class="hidden"
              @change="(e) => upload('profile-photo', (e.target as HTMLInputElement).files?.[0])" />
          </label>
          <span v-if="uploading === 'profile-photo'" class="text-xs text-slate-500"><i class="pi pi-spin pi-spinner" /> {{ t('portalProfile.uploading') }}</span>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.personal.firstName') }} *</label>
          <input v-model.trim="personal.firstName" lang="th"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.personal.lastName') }} *</label>
          <input v-model.trim="personal.lastName" lang="th"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.personal.phone') }} *</label>
          <input v-model.trim="personal.phone" type="tel" inputmode="tel" maxlength="14" placeholder="08x-xxx-xxxx"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.personal.birthDate') }} *</label>
          <DateInput v-model="personal.birthDate" :max="toIsoDate(new Date())" />
        </div>
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('portalProfile.personal.idCard') }} *
            <span class="text-xs text-slate-500 font-normal ml-1">{{ t('portalProfile.personal.idCardCurrent') }}: <code class="font-mono">{{ me.idCardMasked || '—' }}</code></span>
          </label>
          <input v-model.trim="personal.idCard" inputmode="numeric" maxlength="13"
            :placeholder="t('portalProfile.personal.idCardPlaceholder')"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
        </div>
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.personal.idPhoto') }} *</label>
          <div class="flex items-center gap-3">
            <div class="w-32 h-20 rounded-lg overflow-hidden bg-slate-100 flex items-center justify-center border border-slate-200">
              <img v-if="me.idCardPhotoPath" :src="photoUrlFor('id')" class="w-full h-full object-cover" />
              <i v-else class="pi pi-id-card text-3xl text-slate-400" />
            </div>
            <label class="cursor-pointer px-3 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">
              <i class="pi pi-upload mr-1" /> {{ t('portalProfile.upload') }}
              <input type="file" accept="image/*" class="hidden"
                @change="(e) => upload('id-photo', (e.target as HTMLInputElement).files?.[0])" />
            </label>
            <span v-if="uploading === 'id-photo'" class="text-xs text-slate-500"><i class="pi pi-spin pi-spinner" /> {{ t('portalProfile.uploading') }}</span>
          </div>
        </div>
      </div>

      <p v-for="err in personalErrors" :key="err" class="mt-3 text-xs text-rose-600">{{ err }}</p>
      <p v-if="message.personal" :class="message.personal.ok ? 'mt-3 text-xs text-emerald-600' : 'mt-3 text-xs text-rose-600'">
        {{ message.personal.text }}
      </p>

      <div class="mt-4 flex justify-end">
        <button @click="savePersonal" :disabled="!dirty.personal || savingSection === 'personal' || personalErrors.length > 0"
          class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 disabled:opacity-50">
          <i v-if="savingSection === 'personal'" class="pi pi-spin pi-spinner mr-1" />
          {{ t('portalProfile.saveSection') }}
        </button>
      </div>
    </section>

    <!-- Tax Invoice Address -->
    <section class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h2 class="text-base font-semibold text-slate-900 mb-4">{{ t('portalProfile.tax.title') }}</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.line') }}</label>
          <input v-model.trim="tax.address"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.province') }}</label>
          <select v-model="tax.province" @change="onTaxProvinceChange"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500">
            <option value="">{{ t('portalProfile.address.selectProvince') }}</option>
            <option v-for="p in provinces" :key="p" :value="p">{{ p }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.district') }}</label>
          <select v-model="tax.district" @change="onTaxDistrictChange" :disabled="!tax.province"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 disabled:bg-slate-50">
            <option value="">{{ t('portalProfile.address.selectDistrict') }}</option>
            <option v-for="d in taxDistricts" :key="d" :value="d">{{ d }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.subDistrict') }}</label>
          <select v-model="tax.subDistrict" @change="onTaxSubDistrictChange" :disabled="!tax.district"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 disabled:bg-slate-50">
            <option value="">{{ t('portalProfile.address.selectSubDistrict') }}</option>
            <option v-for="s in taxSubDistricts" :key="s.name" :value="s.name">{{ s.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.postcode') }}</label>
          <input v-model.trim="tax.postcode" readonly
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-slate-50 text-slate-600" />
        </div>
      </div>
      <p v-if="message.tax" :class="message.tax.ok ? 'mt-3 text-xs text-emerald-600' : 'mt-3 text-xs text-rose-600'">
        {{ message.tax.text }}
      </p>
      <div class="mt-4 flex justify-end">
        <button @click="saveTax" :disabled="!dirty.tax || savingSection === 'tax'"
          class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 disabled:opacity-50">
          <i v-if="savingSection === 'tax'" class="pi pi-spin pi-spinner mr-1" />
          {{ t('portalProfile.saveSection') }}
        </button>
      </div>
    </section>

    <!-- Delivery Address -->
    <section class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h2 class="text-base font-semibold text-slate-900 mb-4">{{ t('portalProfile.delivery.title') }}</h2>

      <div class="mb-4">
        <label class="text-sm font-medium text-slate-700 mb-2 block">{{ t('portalProfile.delivery.sameAsTax') }}</label>
        <div class="flex items-center gap-4 text-sm">
          <label class="inline-flex items-center gap-2">
            <input type="radio" :value="true" v-model="delivery.sameAsTax" />
            {{ t('portalProfile.yes') }}
          </label>
          <label class="inline-flex items-center gap-2">
            <input type="radio" :value="false" v-model="delivery.sameAsTax" />
            {{ t('portalProfile.no') }}
          </label>
        </div>
      </div>

      <div v-if="!delivery.sameAsTax" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.line') }}</label>
          <input v-model.trim="delivery.address"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.province') }}</label>
          <select v-model="delivery.province" @change="onDeliveryProvinceChange"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500">
            <option value="">{{ t('portalProfile.address.selectProvince') }}</option>
            <option v-for="p in provinces" :key="p" :value="p">{{ p }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.district') }}</label>
          <select v-model="delivery.district" @change="onDeliveryDistrictChange" :disabled="!delivery.province"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 disabled:bg-slate-50">
            <option value="">{{ t('portalProfile.address.selectDistrict') }}</option>
            <option v-for="d in deliveryDistricts" :key="d" :value="d">{{ d }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.subDistrict') }}</label>
          <select v-model="delivery.subDistrict" @change="onDeliverySubDistrictChange" :disabled="!delivery.district"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 disabled:bg-slate-50">
            <option value="">{{ t('portalProfile.address.selectSubDistrict') }}</option>
            <option v-for="s in deliverySubDistricts" :key="s.name" :value="s.name">{{ s.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.address.postcode') }}</label>
          <input v-model.trim="delivery.postcode" readonly
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-slate-50 text-slate-600" />
        </div>
      </div>
      <p v-if="message.delivery" :class="message.delivery.ok ? 'mt-3 text-xs text-emerald-600' : 'mt-3 text-xs text-rose-600'">
        {{ message.delivery.text }}
      </p>
      <div class="mt-4 flex justify-end">
        <button @click="saveDelivery" :disabled="!dirty.delivery || savingSection === 'delivery'"
          class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 disabled:opacity-50">
          <i v-if="savingSection === 'delivery'" class="pi pi-spin pi-spinner mr-1" />
          {{ t('portalProfile.saveSection') }}
        </button>
      </div>
    </section>

    <!-- Non-life License -->
    <section class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h2 class="text-base font-semibold text-slate-900 mb-4">{{ t('portalProfile.nonLife.title') }}</h2>
      <div class="flex items-center gap-4 text-sm">
        <label class="inline-flex items-center gap-2">
          <input type="radio" :value="true" v-model="nonLife.has" />
          {{ t('portalProfile.license.has') }}
        </label>
        <label class="inline-flex items-center gap-2">
          <input type="radio" :value="false" v-model="nonLife.has" />
          {{ t('portalProfile.license.hasNot') }}
        </label>
      </div>
      <p v-if="message.nonLife" :class="message.nonLife.ok ? 'mt-3 text-xs text-emerald-600' : 'mt-3 text-xs text-rose-600'">
        {{ message.nonLife.text }}
      </p>
      <div class="mt-4 flex justify-end">
        <button @click="saveNonLife" :disabled="!dirty.nonLife || savingSection === 'nonLife'"
          class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 disabled:opacity-50">
          <i v-if="savingSection === 'nonLife'" class="pi pi-spin pi-spinner mr-1" />
          {{ t('portalProfile.saveSection') }}
        </button>
      </div>
    </section>

    <!-- Life License -->
    <section class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h2 class="text-base font-semibold text-slate-900 mb-4">{{ t('portalProfile.life.title') }}</h2>
      <div class="flex items-center gap-4 text-sm">
        <label class="inline-flex items-center gap-2">
          <input type="radio" :value="true" v-model="life.has" />
          {{ t('portalProfile.license.has') }}
        </label>
        <label class="inline-flex items-center gap-2">
          <input type="radio" :value="false" v-model="life.has" />
          {{ t('portalProfile.license.hasNot') }}
        </label>
      </div>
      <p v-if="message.life" :class="message.life.ok ? 'mt-3 text-xs text-emerald-600' : 'mt-3 text-xs text-rose-600'">
        {{ message.life.text }}
      </p>
      <div class="mt-4 flex justify-end">
        <button @click="saveLife" :disabled="!dirty.life || savingSection === 'life'"
          class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 disabled:opacity-50">
          <i v-if="savingSection === 'life'" class="pi pi-spin pi-spinner mr-1" />
          {{ t('portalProfile.saveSection') }}
        </button>
      </div>
    </section>

    <!-- Bank Account -->
    <section class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
      <h2 class="text-base font-semibold text-slate-900 mb-4">{{ t('portalProfile.bank.title') }}</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.bank.bank') }}</label>
          <select v-model="bank.bankId"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500">
            <option value="">{{ t('portalProfile.bank.selectBank') }}</option>
            <option v-for="b in banks" :key="b.id" :value="b.id">{{ bankLabel(b) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('portalProfile.bank.accountNo') }}
            <span class="text-xs text-slate-500 font-normal ml-1">{{ t('portalProfile.bank.accountNoCurrent') }}: <code class="font-mono">{{ me.bankAccountNoMasked || '—' }}</code></span>
          </label>
          <input v-model.trim="bank.bankAccountNo"
            :placeholder="t('portalProfile.bank.accountNoPlaceholder')"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
        </div>
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('portalProfile.bank.bankBookPhoto') }}</label>
          <div class="flex items-center gap-3">
            <div class="w-32 h-20 rounded-lg overflow-hidden bg-slate-100 flex items-center justify-center border border-slate-200">
              <img v-if="me.bankBookPhotoPath" :src="photoUrlFor('bank')" class="w-full h-full object-cover" />
              <i v-else class="pi pi-book text-3xl text-slate-400" />
            </div>
            <label class="cursor-pointer px-3 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">
              <i class="pi pi-upload mr-1" /> {{ t('portalProfile.upload') }}
              <input type="file" accept="image/*" class="hidden"
                @change="(e) => upload('bank-book-photo', (e.target as HTMLInputElement).files?.[0])" />
            </label>
            <span v-if="uploading === 'bank-book-photo'" class="text-xs text-slate-500"><i class="pi pi-spin pi-spinner" /> {{ t('portalProfile.uploading') }}</span>
          </div>
        </div>
      </div>
      <p v-if="message.bank" :class="message.bank.ok ? 'mt-3 text-xs text-emerald-600' : 'mt-3 text-xs text-rose-600'">
        {{ message.bank.text }}
      </p>
      <div class="mt-4 flex justify-end">
        <button @click="saveBank" :disabled="!dirty.bank || savingSection === 'bank'"
          class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 disabled:opacity-50">
          <i v-if="savingSection === 'bank'" class="pi pi-spin pi-spinner mr-1" />
          {{ t('portalProfile.saveSection') }}
        </button>
      </div>
    </section>

    <!-- Sticky Save All -->
    <div class="sticky bottom-4 z-20">
      <div class="bg-white/95 backdrop-blur border border-slate-200 rounded-xl p-3 shadow-lg flex items-center justify-between">
        <div class="text-sm text-slate-600">
          <span v-if="anyDirty">
            <i class="pi pi-exclamation-circle text-amber-500 mr-1" />
            {{ t('portalProfile.unsavedChanges') }}
          </span>
          <span v-else>{{ t('portalProfile.allSaved') }}</span>
        </div>
        <button @click="saveAll" :disabled="!anyDirty || savingAll"
          class="px-5 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50">
          <i v-if="savingAll" class="pi pi-spin pi-spinner mr-1" />
          {{ t('portalProfile.saveAll') }}
        </button>
      </div>
    </div>
  </div>
</template>
