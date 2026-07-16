<script setup lang="ts">
// Sectioned profile page. Each section saves independently via its own PATCH
// endpoint; a "Save all" button walks through them in sequence.
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  fetchMyAgent, patchProfile, patchIdDocument, patchLicense, patchBank, patchAddress,
  unmaskIdCard, uploadPhoto, type MyAgent,
} from '../../api/portal'
import { ApiError } from '../../api/client'

const { t } = useI18n()

const me = ref<MyAgent | null>(null)
const loading = ref(false)
const savingSection = ref<string | null>(null)
const savingAll = ref(false)
const sectionMessage = ref<Record<string, { ok: boolean; text: string } | null>>({
  profile: null, id: null, license: null, bank: null, address: null,
})

// Per-section local edit state, populated from `me`.
const profile = reactive({
  firstName: '', lastName: '', firstNameEn: '', lastNameEn: '',
  nickname: '', gender: '', phone: '', lineId: '', facebookName: '',
  birthDate: '' as string | null,
})
const idDoc = reactive({ idCard: '' })
const license = reactive({
  licenseLifeNo: '', licenseLifeExpiry: '' as string | null,
  licenseNonLifeNo: '', licenseNonLifeExpiry: '' as string | null,
})
const bank = reactive({
  bankNameText: '', bankAccountNo: '', bankAccountName: '',
})
const address = reactive({
  address: '', subDistrict: '', district: '', province: '', postcode: '',
})
const showIdUnmask = ref(false)
const unmaskedId = ref<string | null>(null)

function hydrateFromMe(m: MyAgent): void {
  profile.firstName = m.firstName
  profile.lastName = m.lastName
  profile.firstNameEn = m.firstNameEn
  profile.lastNameEn = m.lastNameEn
  profile.nickname = m.nickname
  profile.gender = m.gender
  profile.phone = m.phone
  profile.lineId = m.lineId
  profile.facebookName = m.facebookName
  profile.birthDate = m.birthDate
  // idDoc.idCard stays blank — user must retype to change (masked view only).
  idDoc.idCard = ''
  license.licenseLifeNo = m.licenseLifeNo
  license.licenseLifeExpiry = m.licenseLifeExpiry
  license.licenseNonLifeNo = m.licenseNonLifeNo
  license.licenseNonLifeExpiry = m.licenseNonLifeExpiry
  bank.bankNameText = m.bankNameText
  bank.bankAccountNo = ''  // masked; user retypes to change
  bank.bankAccountName = m.bankAccountName
  address.address = m.address
  address.subDistrict = m.subDistrict
  address.district = m.district
  address.province = m.province
  address.postcode = m.postcode
}

onMounted(async () => {
  loading.value = true
  try {
    const res = await fetchMyAgent()
    me.value = res.data
    hydrateFromMe(res.data)
  } finally {
    loading.value = false
  }
})

async function save(section: string): Promise<void> {
  savingSection.value = section
  sectionMessage.value[section] = null
  try {
    let res: { data: MyAgent }
    if (section === 'profile') res = await patchProfile(profile)
    else if (section === 'id') {
      if (!idDoc.idCard.trim()) throw new Error('Enter a national ID to update.')
      res = await patchIdDocument({ idCard: idDoc.idCard.trim() })
    }
    else if (section === 'license') res = await patchLicense(license)
    else if (section === 'bank') {
      const payload: Record<string, unknown> = { bankNameText: bank.bankNameText, bankAccountName: bank.bankAccountName }
      if (bank.bankAccountNo.trim()) payload.bankAccountNo = bank.bankAccountNo.trim()
      res = await patchBank(payload)
    }
    else if (section === 'address') res = await patchAddress(address)
    else return
    me.value = res.data
    hydrateFromMe(res.data)
    sectionMessage.value[section] = { ok: true, text: t('portal.profile.saved') }
  } catch (e: unknown) {
    const msg = e instanceof ApiError ? e.message : e instanceof Error ? e.message : 'Save failed'
    sectionMessage.value[section] = { ok: false, text: msg }
  } finally {
    savingSection.value = null
    setTimeout(() => { sectionMessage.value[section] = null }, 3000)
  }
}

async function saveAll(): Promise<void> {
  savingAll.value = true
  for (const s of ['profile', 'license', 'bank', 'address']) {
    await save(s)
  }
  if (idDoc.idCard.trim()) await save('id')
  savingAll.value = false
}

async function unmask(): Promise<void> {
  try {
    const res = await unmaskIdCard()
    unmaskedId.value = res.idCard
    showIdUnmask.value = true
  } catch { /* silent */ }
}

async function onPhotoChange(kind: 'profile-photo' | 'id-photo' | 'bank-book-photo', e: Event): Promise<void> {
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  if (!file) return
  try {
    const res = await uploadPhoto(kind, file)
    me.value = res.data
  } catch (err: unknown) {
    alert(err instanceof ApiError ? err.message : 'Upload failed')
  } finally {
    target.value = ''
  }
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('portal.profile.title') }}</h1>
        <p class="text-sm text-slate-500 mt-1">{{ t('portal.profile.subtitle') }}</p>
      </div>
      <button type="button"
        class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
        :disabled="savingAll || !me" @click="saveAll">
        <i v-if="savingAll" class="pi pi-spin pi-spinner mr-2" />
        {{ t('portal.profile.saveAll') }}
      </button>
    </header>

    <div v-if="loading" class="card p-6 text-slate-500 text-sm">Loading…</div>

    <template v-else-if="me">
      <!-- Profile photo -->
      <section class="card p-5">
        <div class="flex items-center gap-4">
          <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center text-slate-400">
            <i v-if="!me.profilePhotoPath" class="pi pi-user text-3xl" />
            <img v-else src="" alt="profile" class="w-full h-full object-cover rounded-full" />
          </div>
          <div>
            <div class="font-medium text-slate-900">{{ me.firstName }} {{ me.lastName }}</div>
            <label class="mt-2 inline-flex items-center gap-2 text-xs text-brand-600 hover:text-brand-700 cursor-pointer">
              <i class="pi pi-camera" />
              <span>{{ t('portal.profile.uploadPhoto') }}</span>
              <input type="file" accept="image/*" class="hidden"
                @change="(e) => onPhotoChange('profile-photo', e)" />
            </label>
          </div>
        </div>
      </section>

      <!-- 1. Basic info -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('portal.section.profile') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'profile'" @click="save('profile')">
            <i v-if="savingSection === 'profile'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('portal.profile.save') }}
          </button>
        </div>
        <div v-if="sectionMessage.profile" :class="sectionMessage.profile.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-3">
          {{ sectionMessage.profile.text }}
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.firstName') }}</label>
            <input v-model.trim="profile.firstName" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.lastName') }}</label>
            <input v-model.trim="profile.lastName" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.nickname') }}</label>
            <input v-model.trim="profile.nickname" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.gender') }}</label>
            <select v-model="profile.gender" class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white">
              <option value="">—</option>
              <option value="male">male</option><option value="female">female</option><option value="other">other</option>
            </select>
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.phone') }}</label>
            <input v-model.trim="profile.phone" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.lineId') }}</label>
            <input v-model.trim="profile.lineId" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.birthDate') }}</label>
            <input v-model="profile.birthDate" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
        </div>
      </section>

      <!-- 2. ID card -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('portal.section.id') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'id' || !idDoc.idCard.trim()" @click="save('id')">
            <i v-if="savingSection === 'id'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('portal.profile.save') }}
          </button>
        </div>
        <div v-if="sectionMessage.id" :class="sectionMessage.id.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-3">
          {{ sectionMessage.id.text }}
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.idCardCurrent') }}</label>
            <div class="flex items-center gap-2">
              <input :value="showIdUnmask ? (unmaskedId ?? me.idCardMasked) : me.idCardMasked" disabled
                class="flex-1 border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 text-slate-500 font-mono text-xs" />
              <button type="button" class="text-xs text-brand-600 hover:text-brand-700 px-2 py-2" @click="showIdUnmask ? (showIdUnmask = false) : unmask()">
                <i :class="showIdUnmask ? 'pi pi-eye-slash' : 'pi pi-eye'" />
              </button>
            </div>
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.idCardNew') }}</label>
            <input v-model.trim="idDoc.idCard" placeholder="Enter to change"
              class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div class="col-span-2">
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.idCardPhoto') }}</label>
            <label class="inline-flex items-center gap-2 text-sm text-brand-600 hover:text-brand-700 cursor-pointer">
              <i class="pi pi-upload" />
              <span>{{ me.idCardPhotoPath ? t('portal.profile.replacePhoto') : t('portal.profile.uploadPhoto') }}</span>
              <input type="file" accept="image/*" class="hidden" @change="(e) => onPhotoChange('id-photo', e)" />
            </label>
            <div v-if="me.idCardPhotoPath" class="text-xs text-slate-500 mt-1 truncate">{{ me.idCardPhotoPath }}</div>
          </div>
        </div>
      </section>

      <!-- 3. License -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('portal.section.license') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'license'" @click="save('license')">
            <i v-if="savingSection === 'license'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('portal.profile.save') }}
          </button>
        </div>
        <div v-if="sectionMessage.license" :class="sectionMessage.license.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-3">
          {{ sectionMessage.license.text }}
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.licenseLifeNo') }}</label>
            <input v-model.trim="license.licenseLifeNo" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.licenseLifeExpiry') }}</label>
            <input v-model="license.licenseLifeExpiry" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.licenseNonLifeNo') }}</label>
            <input v-model.trim="license.licenseNonLifeNo" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.licenseNonLifeExpiry') }}</label>
            <input v-model="license.licenseNonLifeExpiry" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
        </div>
      </section>

      <!-- 4. Bank -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('portal.section.bank') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'bank'" @click="save('bank')">
            <i v-if="savingSection === 'bank'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('portal.profile.save') }}
          </button>
        </div>
        <div v-if="sectionMessage.bank" :class="sectionMessage.bank.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-3">
          {{ sectionMessage.bank.text }}
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.bankNameText') }}</label>
            <input v-model.trim="bank.bankNameText" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.bankAccountName') }}</label>
            <input v-model.trim="bank.bankAccountName" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.bankAccountCurrent') }}</label>
            <input :value="me.bankAccountNoMasked" disabled
              class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 text-slate-500 font-mono text-xs" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.bankAccountNew') }}</label>
            <input v-model.trim="bank.bankAccountNo" placeholder="Enter to change"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono" />
          </div>
          <div class="col-span-2">
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.bankBookPhoto') }}</label>
            <label class="inline-flex items-center gap-2 text-sm text-brand-600 hover:text-brand-700 cursor-pointer">
              <i class="pi pi-upload" />
              <span>{{ me.bankBookPhotoPath ? t('portal.profile.replacePhoto') : t('portal.profile.uploadPhoto') }}</span>
              <input type="file" accept="image/*" class="hidden" @change="(e) => onPhotoChange('bank-book-photo', e)" />
            </label>
            <div v-if="me.bankBookPhotoPath" class="text-xs text-slate-500 mt-1 truncate">{{ me.bankBookPhotoPath }}</div>
          </div>
        </div>
      </section>

      <!-- 5. Address -->
      <section class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-slate-900">{{ t('portal.section.address') }}</h2>
          <button type="button" class="text-sm text-brand-600 hover:text-brand-700 disabled:opacity-50"
            :disabled="savingSection === 'address'" @click="save('address')">
            <i v-if="savingSection === 'address'" class="pi pi-spin pi-spinner mr-1" />
            {{ t('portal.profile.save') }}
          </button>
        </div>
        <div v-if="sectionMessage.address" :class="sectionMessage.address.ok ? 'text-emerald-700' : 'text-rose-700'" class="text-xs mb-3">
          {{ sectionMessage.address.text }}
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div class="md:col-span-2">
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.address') }}</label>
            <textarea v-model.trim="address.address" rows="2" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.subDistrict') }}</label>
            <input v-model.trim="address.subDistrict" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.district') }}</label>
            <input v-model.trim="address.district" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.province') }}</label>
            <input v-model.trim="address.province" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.field.postcode') }}</label>
            <input v-model.trim="address.postcode" class="w-full border border-slate-200 rounded-lg px-3 py-2" />
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
