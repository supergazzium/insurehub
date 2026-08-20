<script setup lang="ts">
// New Customer modal — full rewrite covering individual + juristic
// customers, inline field validation modeled on AgentRegister.vue, and
// cascading province → amphoe → tambon dropdowns backed by the shared
// /public/lookup endpoints.
//
// Registered vs mailing address: the mailing block starts identical to
// the registered block (mailingSameAsRegistered=true, checkbox checked).
// Unchecking exposes a mirror block; on-submit we send the mailing
// fields only when the checkbox is off (backend defaults them to null).

import { computed, reactive, ref, watch } from 'vue'
import CreateModal from '../../components/CreateModal.vue'
import FormField from '../../components/FormField.vue'
import DateInput from '../../components/DateInput.vue'
import { toIsoDate } from '../../util/dateFormat'
import { api } from '../../api/client'
import { fetchProvinces, fetchDistricts, fetchSubDistricts, fetchNamePrefixes, fetchNationalities, type NamePrefixRow, type NationalityRow } from '../../api/portal'
import { isThaiName, isThaiJuristicName, isThaiId13, isThaiMobile, isThaiLandline, isLatinOrThaiName, isInternationalPhone, isPassportNumber } from '../../utils/thaiValidation'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'created', row: Record<string, unknown>): void
}>()

// ─── Reference data (loaded once) ─────────────────────────────────────────
const provinces = ref<string[]>([])
const registeredDistricts = ref<string[]>([])
const registeredSubDistricts = ref<{ name: string; postcode: string }[]>([])
const mailingDistricts = ref<string[]>([])
const mailingSubDistricts = ref<{ name: string; postcode: string }[]>([])
const namePrefixes = ref<NamePrefixRow[]>([])
const nationalities = ref<NationalityRow[]>([])

async function loadProvinces(): Promise<void> {
  if (provinces.value.length) return
  try { provinces.value = (await fetchProvinces()).data } catch { /* silent */ }
}

async function loadNamePrefixes(): Promise<void> {
  if (namePrefixes.value.length) return
  try { namePrefixes.value = (await fetchNamePrefixes()).data } catch { /* silent */ }
}
async function loadNationalities(): Promise<void> {
  if (nationalities.value.length) return
  try { nationalities.value = (await fetchNationalities()).data } catch { /* silent */ }
}

/** Prefix options for the currently-selected customerType. Individual
 *  customers get the "Individual" bucket (นาย/นาง/นางสาว/คุณ + ranks);
 *  corporate customers get the "Corporate" bucket (บริษัท/ร้าน/ห้าง/…). */
const availableTitles = computed(() => {
  // Foreign individuals reuse the Individual title bucket (นาย/นาง/…) —
  // the Thai UI still shows those titles as the standard set even for
  // non-Thai people. Only corporate rows use the Corporate bucket.
  const bucket = form.customerType === 'corporate' ? 'Corporate' : 'Individual'
  return namePrefixes.value.filter((p) => p.insuredType === bucket)
})

// Switching customer type resets every field whose meaning changes across
// types: title (Individual bucket vs Corporate), Thai ID vs passport, and
// the corporate-only juristicName/taxId. Prevents stale data from leaking
// through the payload after the operator flips between types.
watch(() => form.customerType, () => {
  form.titleTh = ''
  form.idCard = ''
  form.passport = ''
  form.nationality = ''
  form.juristicName = ''
  form.taxId = ''
})

// ─── Form state ───────────────────────────────────────────────────────────
const form = reactive({
  customerCode: '',
  customerType: 'individual' as 'individual' | 'foreign_individual' | 'corporate',
  // Individual + foreign_individual share these
  titleTh: '',
  firstName: '',
  lastName: '',
  nickname: '',
  gender: '' as '' | 'M' | 'F',
  birthDate: '',
  // Thai individual only
  idCard: '',
  // Foreign individual only
  passport: '',
  nationality: '',
  // Corporate only
  juristicName: '',
  taxId: '',
  // Contact (all types)
  phone: '',
  telPhone: '',
  email: '',
  // Registered address
  address: '',
  province: '',
  amphoe: '',
  subDistrict: '',
  postcode: '',
  // Mailing address
  mailingSameAsRegistered: true,
  mailingAddress: '',
  mailingProvince: '',
  mailingAmphoe: '',
  mailingSubDistrict: '',
  mailingPostcode: '',
})

// ─── Auto-generated customer code ─────────────────────────────────────────
const codeLoading = ref(false)
const codeAutoFilled = ref(false)
async function suggestCode(): Promise<void> {
  codeLoading.value = true
  try {
    const res = await api.get<{ code: string; next: number }>('customers/next-code')
    form.customerCode = res.code
    codeAutoFilled.value = true
  } catch {
    // Fallback to a timestamp-based code so save isn't blocked when the
    // lookup fails; operator can edit before submit.
    const ts = Math.floor(Date.now() / 1000).toString().slice(-7)
    form.customerCode = `C${ts}`
  } finally {
    codeLoading.value = false
  }
}

// ─── Field validators (share the register-agent utilities so the same
//     rules run in both places — including the Thai national-ID MOD-11
//     checksum, so wrong IDs are rejected client-side too). ────────────
// Simple RFC-ish email; server does strict validation as well.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** Returns null when the field is valid, or an inline Thai error string. */
function validateFirstName(): string | null {
  if (form.customerType === 'corporate') return null
  if (form.firstName.trim() === '') return 'จำเป็น'
  // Thai individuals must use Thai chars; foreign individuals may use
  // Latin OR Thai. The three-way gate keeps a Thai form strict without
  // false-rejecting "John Smith" style entries for foreigners.
  if (form.customerType === 'individual') {
    if (!isThaiName(form.firstName)) return 'ใช้อักษรไทยเท่านั้น'
  } else if (form.customerType === 'foreign_individual') {
    if (!isLatinOrThaiName(form.firstName)) return 'ใช้อักษรไทยหรืออังกฤษเท่านั้น'
  }
  return null
}
function validateLastName(): string | null {
  if (form.customerType === 'corporate') return null
  if (form.lastName.trim() === '') return 'จำเป็น'
  if (form.customerType === 'individual') {
    if (!isThaiName(form.lastName)) return 'ใช้อักษรไทยเท่านั้น'
  } else if (form.customerType === 'foreign_individual') {
    if (!isLatinOrThaiName(form.lastName)) return 'ใช้อักษรไทยหรืออังกฤษเท่านั้น'
  }
  return null
}
function validateJuristicName(): string | null {
  if (form.customerType !== 'corporate') return null
  if (form.juristicName.trim() === '') return 'จำเป็น'
  // Same charset gate as individual names — must contain Thai letters and
  // may include Latin punctuation ("บริษัท เอ.บี.ซี. จำกัด (มหาชน)").
  if (!isThaiJuristicName(form.juristicName)) return 'ใช้อักษรไทย (และตัวเลข/เครื่องหมายที่จำเป็น)'
  return null
}
function validateGender(): string | null {
  // Gender required for both Thai and foreign individuals; corporate has none.
  if (form.customerType === 'corporate') return null
  return form.gender === '' ? 'จำเป็น' : null
}
function validateThaiNationalId(v: string): string | null {
  const t = v.trim()
  if (t === '') return null
  if (!/^\d{13}$/.test(t)) return 'ต้องเป็นตัวเลข 13 หลัก'
  if (!isThaiId13(t)) return 'เลขไม่ผ่านการตรวจสอบ (MOD-11 checksum)'
  return null
}
function validatePassport(v: string): string | null {
  // Passport required only when the operator picked foreign_individual.
  if (form.customerType !== 'foreign_individual') return null
  const t = v.trim()
  if (t === '') return 'จำเป็น'
  if (!isPassportNumber(t)) return 'รูปแบบไม่ถูกต้อง (ตัวอักษร A-Z / ตัวเลข 6-12 ตัว)'
  return null
}
function validateNationality(): string | null {
  if (form.customerType !== 'foreign_individual') return null
  return form.nationality === '' ? 'จำเป็น' : null
}
function validateMobile(v: string): string | null {
  const t = v.trim()
  if (t === '') return null
  // Foreign customers may enter home-country / international format;
  // Thai + corporate keep the strict domestic mobile rule.
  if (form.customerType === 'foreign_individual') {
    if (!isInternationalPhone(t)) return 'รูปแบบเบอร์โทรไม่ถูกต้อง (6-15 หลัก, ใส่ + ได้)'
  } else {
    if (!isThaiMobile(t)) return 'รูปแบบเบอร์มือถือไม่ถูกต้อง (ขึ้นต้น 06/08/09)'
  }
  return null
}
function validateLandline(v: string): string | null {
  const t = v.trim()
  if (t === '') return null
  if (form.customerType === 'foreign_individual') {
    if (!isInternationalPhone(t)) return 'รูปแบบเบอร์โทรไม่ถูกต้อง (6-15 หลัก, ใส่ + ได้)'
  } else {
    if (!isThaiLandline(t)) return 'รูปแบบเบอร์บ้านไม่ถูกต้อง (ขึ้นต้น 02/03/04/05)'
  }
  return null
}
function validateEmailFmt(v: string): string | null {
  const t = v.trim()
  if (t === '') return null
  if (!EMAIL_RE.test(t)) return 'รูปแบบอีเมลไม่ถูกต้อง'
  return null
}
function validateBirthDate(v: string): string | null {
  if (v === '') return null
  const today = toIsoDate(new Date())
  if (v >= today) return 'วันเกิดต้องเป็นวันในอดีต'
  return null
}

const errFirstName = computed(() => validateFirstName())
const errLastName = computed(() => validateLastName())
const errJuristicName = computed(() => validateJuristicName())
const errGender = computed(() => validateGender())
const errIdCard = computed(() => validateThaiNationalId(form.idCard))
const errTaxId = computed(() => validateThaiNationalId(form.taxId))
const errPassport = computed(() => validatePassport(form.passport))
const errNationality = computed(() => validateNationality())
const errPhone = computed(() => validateMobile(form.phone))
const errTelPhone = computed(() => validateLandline(form.telPhone))
const errEmail = computed(() => validateEmailFmt(form.email))
const errBirth = computed(() => validateBirthDate(form.birthDate))

// ─── Touched + display gate ──────────────────────────────────────────────
// Errors only render after the operator has touched the field OR has
// clicked submit — same pattern as AgentRegister.vue.
type FieldName =
  | 'firstName' | 'lastName' | 'juristicName' | 'gender'
  | 'idCard' | 'taxId' | 'passport' | 'nationality'
  | 'phone' | 'telPhone' | 'email' | 'birthDate'
const touched = reactive<Record<FieldName, boolean>>({
  firstName: false, lastName: false, juristicName: false, gender: false,
  idCard: false, taxId: false, passport: false, nationality: false,
  phone: false, telPhone: false, email: false, birthDate: false,
})
const attemptedSubmit = ref(false)
function markTouched(field: FieldName): void { touched[field] = true }

function displayError(field: FieldName, localError: string | null): string | null {
  if (localError && (touched[field] || attemptedSubmit.value)) return localError
  return null
}
const showFirstName = computed(() => displayError('firstName', errFirstName.value))
const showLastName = computed(() => displayError('lastName', errLastName.value))
const showJuristicName = computed(() => displayError('juristicName', errJuristicName.value))
const showGender = computed(() => displayError('gender', errGender.value))
const showIdCard = computed(() => displayError('idCard', errIdCard.value))
const showTaxId = computed(() => displayError('taxId', errTaxId.value))
const showPassport = computed(() => displayError('passport', errPassport.value))
const showNationality = computed(() => displayError('nationality', errNationality.value))
const showPhone = computed(() => displayError('phone', errPhone.value))
const showTelPhone = computed(() => displayError('telPhone', errTelPhone.value))
const showEmail = computed(() => displayError('email', errEmail.value))
const showBirth = computed(() => displayError('birthDate', errBirth.value))

// ─── Green-check state — filled-in AND passing all rules ─────────────────
function isFieldValid(localError: string | null, filled: boolean): boolean {
  return filled && localError === null
}
// Person-typed fields validate for BOTH individual + foreign_individual;
// corporate hides them entirely so no green tick can leak through there.
const isPerson = computed(() => form.customerType === 'individual' || form.customerType === 'foreign_individual')
const firstNameValid = computed(() => isPerson.value && isFieldValid(errFirstName.value, form.firstName.trim() !== ''))
const lastNameValid = computed(() => isPerson.value && isFieldValid(errLastName.value, form.lastName.trim() !== ''))
const juristicNameValid = computed(() =>
  form.customerType === 'corporate' && isFieldValid(errJuristicName.value, form.juristicName.trim() !== ''),
)
const genderValid = computed(() => isPerson.value && isFieldValid(errGender.value, form.gender !== ''))
const idCardValid = computed(() => isFieldValid(errIdCard.value, form.idCard.trim() !== ''))
const taxIdValid = computed(() => isFieldValid(errTaxId.value, form.taxId.trim() !== ''))
const passportValid = computed(() =>
  form.customerType === 'foreign_individual' && isFieldValid(errPassport.value, form.passport.trim() !== ''),
)
const nationalityValid = computed(() =>
  form.customerType === 'foreign_individual' && isFieldValid(errNationality.value, form.nationality !== ''),
)
const phoneValid = computed(() => isFieldValid(errPhone.value, form.phone.trim() !== ''))
const telPhoneValid = computed(() => isFieldValid(errTelPhone.value, form.telPhone.trim() !== ''))
const emailValid = computed(() => isFieldValid(errEmail.value, form.email.trim() !== ''))
const birthValid = computed(() => isFieldValid(errBirth.value, form.birthDate !== ''))

// ─── Input class helper (rose/emerald/slate borders) ─────────────────────
function inputClass(hasErr: string | null, isValid: boolean): string {
  const base = 'w-full border rounded-lg px-3 py-1.5 text-sm focus:outline-none'
  if (hasErr) return `${base} border-rose-400 focus:border-rose-500`
  if (isValid) return `${base} border-emerald-400 focus:border-emerald-500`
  return `${base} border-slate-200 focus:border-brand-400`
}

// ─── Cascading address dropdowns ──────────────────────────────────────────
watch(() => form.province, async (province) => {
  form.amphoe = ''
  form.subDistrict = ''
  form.postcode = ''
  registeredDistricts.value = []
  registeredSubDistricts.value = []
  if (province) {
    registeredDistricts.value = (await fetchDistricts(province)).data
  }
})
watch(() => form.amphoe, async (amphoe) => {
  form.subDistrict = ''
  form.postcode = ''
  registeredSubDistricts.value = []
  if (form.province && amphoe) {
    registeredSubDistricts.value = (await fetchSubDistricts(form.province, amphoe)).data
  }
})
watch(() => form.subDistrict, (sub) => {
  const row = registeredSubDistricts.value.find((r) => r.name === sub)
  form.postcode = row?.postcode ?? ''
})

watch(() => form.mailingProvince, async (province) => {
  form.mailingAmphoe = ''
  form.mailingSubDistrict = ''
  form.mailingPostcode = ''
  mailingDistricts.value = []
  mailingSubDistricts.value = []
  if (province) mailingDistricts.value = (await fetchDistricts(province)).data
})
watch(() => form.mailingAmphoe, async (amphoe) => {
  form.mailingSubDistrict = ''
  form.mailingPostcode = ''
  mailingSubDistricts.value = []
  if (form.mailingProvince && amphoe) {
    mailingSubDistricts.value = (await fetchSubDistricts(form.mailingProvince, amphoe)).data
  }
})
watch(() => form.mailingSubDistrict, (sub) => {
  const row = mailingSubDistricts.value.find((r) => r.name === sub)
  form.mailingPostcode = row?.postcode ?? ''
})

// ─── Copy registered → mailing helper ─────────────────────────────────────
async function copyRegisteredToMailing(): Promise<void> {
  form.mailingAddress = form.address
  form.mailingProvince = form.province
  // Wait for cascaded fetches — set province, then next tick set amphoe, etc.
  // Simpler: fetch synchronously since options are the same.
  if (form.province) mailingDistricts.value = (await fetchDistricts(form.province)).data
  form.mailingAmphoe = form.amphoe
  if (form.province && form.amphoe) {
    mailingSubDistricts.value = (await fetchSubDistricts(form.province, form.amphoe)).data
  }
  form.mailingSubDistrict = form.subDistrict
  form.mailingPostcode = form.postcode
}

// ─── Submit gate. Any live validator error blocks; each field is
//     re-checked to reject a wrong ID (checksum) even if the shape is
//     right, since server also enforces the same rule. ──────────────────
const canSubmit = computed(() => {
  if (form.customerCode.trim() === '') return false
  if (errFirstName.value !== null) return false
  if (errLastName.value !== null) return false
  if (errJuristicName.value !== null) return false
  if (errGender.value !== null) return false
  if (errIdCard.value !== null) return false
  if (errTaxId.value !== null) return false
  if (errPassport.value !== null) return false
  if (errNationality.value !== null) return false
  if (errPhone.value !== null) return false
  if (errTelPhone.value !== null) return false
  if (errEmail.value !== null) return false
  if (errBirth.value !== null) return false
  return true
})

// Pre-submit gate for CreateModal — returning any messages triggers a
// browser alert that lists every failing field in Thai. Also flips
// `attemptedSubmit` on so the inline red errors light up in place.
function collectValidationProblems(): string[] {
  attemptedSubmit.value = true
  const problems: string[] = []
  const push = (label: string, err: string | null): void => {
    if (err) problems.push(`${label}: ${err}`)
  }
  if (form.customerCode.trim() === '') problems.push('รหัสลูกค้า: จำเป็น')
  push('ชื่อ', errFirstName.value)
  push('นามสกุล', errLastName.value)
  push('ชื่อนิติบุคคล', errJuristicName.value)
  push('เพศ', errGender.value)
  push('เลขบัตรประชาชน', errIdCard.value)
  push('เลขประจำตัวผู้เสียภาษี', errTaxId.value)
  push('เลขหนังสือเดินทาง', errPassport.value)
  push('สัญชาติ', errNationality.value)
  push('โทรศัพท์มือถือ', errPhone.value)
  push('โทรศัพท์บ้าน', errTelPhone.value)
  push('อีเมล', errEmail.value)
  push('วันเกิด', errBirth.value)
  return problems
}

// ─── Payload ──────────────────────────────────────────────────────────────
const payload = computed(() => {
  const base: Record<string, unknown> = {
    customerCode: form.customerCode.trim(),
    customerType: form.customerType,
    phone: form.phone.trim() || null,
    telPhone: form.telPhone.trim() || null,
    email: form.email.trim() || null,
    address: form.address.trim() || null,
    province: form.province || null,
    amphoe: form.amphoe || null,
    // Backend maps `district` → sub_district (see CustomerRequest::toModel).
    district: form.subDistrict || null,
    postcode: form.postcode || null,
    mailingSameAsRegistered: form.mailingSameAsRegistered,
    active: true,
  }
  if (form.customerType === 'individual') {
    base.titleTh = form.titleTh.trim() || null
    base.firstName = form.firstName.trim() || null
    base.lastName = form.lastName.trim() || null
    base.nickname = form.nickname.trim() || null
    base.idCard = form.idCard.trim() || null
    base.gender = form.gender || null
    base.birthDate = form.birthDate || null
  } else if (form.customerType === 'foreign_individual') {
    base.titleTh = form.titleTh.trim() || null
    base.firstName = form.firstName.trim() || null
    base.lastName = form.lastName.trim() || null
    base.nickname = form.nickname.trim() || null
    // Passport uppercase-normalized so storage matches the isPassportNumber
    // canonical form; server also enforces ^[A-Z0-9]{6,12}$.
    base.passport = form.passport.trim().toUpperCase() || null
    base.nationality = form.nationality || null
    base.gender = form.gender || null
    base.birthDate = form.birthDate || null
  } else {
    base.juristicName = form.juristicName.trim() || null
    base.taxId = form.taxId.trim() || null
  }
  if (!form.mailingSameAsRegistered) {
    base.mailing = {
      address: form.mailingAddress.trim() || null,
      province: form.mailingProvince || null,
      district: form.mailingAmphoe || null,
      subDistrict: form.mailingSubDistrict || null,
      postcode: form.mailingPostcode || null,
    }
  }
  return base
})

// ─── Modal lifecycle ──────────────────────────────────────────────────────
watch(
  () => props.open,
  (v) => {
    if (v) {
      Object.assign(form, {
        customerCode: '',
        customerType: 'individual',
        titleTh: '', firstName: '', lastName: '', nickname: '',
        idCard: '', gender: '', birthDate: '',
        passport: '', nationality: '',
        juristicName: '', taxId: '',
        phone: '', telPhone: '', email: '',
        address: '', province: '', amphoe: '', subDistrict: '', postcode: '',
        mailingSameAsRegistered: true,
        mailingAddress: '', mailingProvince: '', mailingAmphoe: '',
        mailingSubDistrict: '', mailingPostcode: '',
      })
      registeredDistricts.value = []
      registeredSubDistricts.value = []
      mailingDistricts.value = []
      mailingSubDistricts.value = []
      codeAutoFilled.value = false
      // Reset touched state so red errors don't flash immediately on reopen.
      Object.keys(touched).forEach((k) => { touched[k as FieldName] = false })
      attemptedSubmit.value = false
      void loadProvinces()
      void loadNamePrefixes()
      void loadNationalities()
      void suggestCode()
    }
  },
)
</script>

<template>
  <CreateModal
    :open="open" entity="customers" title="New Customer"
    :payload="payload" :can-submit="canSubmit" :validate="collectValidationProblems"
    @close="emit('close')" @created="(row) => emit('created', row)"
  >
    <template #default="{ fieldErrors }">
      <div class="grid grid-cols-2 gap-4">
        <!-- Customer type — 3-way: Thai individual, foreign individual
             (passport instead of Thai national ID + international phone
             formats), or juristic. -->
        <FormField label="ประเภทลูกค้า" required class="col-span-2" error-key="customerType" :errors="fieldErrors">
          <div class="flex gap-2">
            <label v-for="opt in [
              { value: 'individual', label: 'บุคคลธรรมดา' },
              { value: 'foreign_individual', label: 'ชาวต่างชาติ' },
              { value: 'corporate', label: 'นิติบุคคล' },
            ]" :key="opt.value"
              :class="[
                'flex-1 flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer text-sm transition-colors',
                form.customerType === opt.value
                  ? 'border-brand-500 bg-brand-50 text-brand-700'
                  : 'border-slate-200 hover:bg-slate-50 text-slate-700',
              ]">
              <input type="radio" :value="opt.value" v-model="form.customerType" class="accent-brand-500" />
              <span class="font-medium">{{ opt.label }}</span>
            </label>
          </div>
        </FormField>

        <!-- Customer code -->
        <FormField label="รหัสลูกค้า" required class="col-span-2" error-key="customerCode" :errors="fieldErrors"
          hint="ระบบเติมให้อัตโนมัติ (C0000001) แก้ไขได้ก่อนบันทึก">
          <div class="flex gap-2 items-center">
            <input v-model.trim="form.customerCode" :disabled="codeLoading"
              class="flex-1 border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
            <button type="button" @click="suggestCode" :disabled="codeLoading"
              class="px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50 text-xs">
              <i class="pi pi-refresh" /> Regenerate
            </button>
          </div>
        </FormField>

        <!-- Individual block -->
        <!-- Person block — shared between Thai + foreign individuals.
             Charset expectations and ID mechanism (national ID vs passport)
             differ per type; the fields common to both stay here. -->
        <template v-if="isPerson">
          <FormField label="คำนำหน้า" error-key="titleTh" :errors="fieldErrors"
            hint="รายการดึงมาจากตาราง name_prefixes">
            <select v-model="form.titleTh"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
              <option value="">—</option>
              <option v-for="p in availableTitles" :key="p.id" :value="p.descriptionTh">
                {{ p.descriptionTh }}<span v-if="p.descriptionEn" class="text-slate-400"> · {{ p.descriptionEn }}</span>
              </option>
            </select>
          </FormField>
          <FormField label="ชื่อเล่น" error-key="nickname" :errors="fieldErrors">
            <input v-model.trim="form.nickname"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
          </FormField>
          <FormField
            :label="form.customerType === 'foreign_individual' ? 'ชื่อ (First name)' : 'ชื่อ (ภาษาไทย)'"
            required error-key="firstName" :errors="fieldErrors">
            <div class="relative">
              <input v-model.trim="form.firstName"
                :lang="form.customerType === 'foreign_individual' ? 'en' : 'th'"
                :placeholder="form.customerType === 'foreign_individual' ? 'John' : 'สมชาย'"
                :class="inputClass(showFirstName, firstNameValid)"
                @blur="markTouched('firstName')" />
              <i v-if="firstNameValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2 text-xs" />
            </div>
            <p v-if="showFirstName" class="text-xs text-rose-600 mt-0.5">{{ showFirstName }}</p>
          </FormField>
          <FormField
            :label="form.customerType === 'foreign_individual' ? 'นามสกุล (Last name)' : 'นามสกุล (ภาษาไทย)'"
            required error-key="lastName" :errors="fieldErrors">
            <div class="relative">
              <input v-model.trim="form.lastName"
                :lang="form.customerType === 'foreign_individual' ? 'en' : 'th'"
                :placeholder="form.customerType === 'foreign_individual' ? 'Smith' : 'ใจดี'"
                :class="inputClass(showLastName, lastNameValid)"
                @blur="markTouched('lastName')" />
              <i v-if="lastNameValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2 text-xs" />
            </div>
            <p v-if="showLastName" class="text-xs text-rose-600 mt-0.5">{{ showLastName }}</p>
          </FormField>

          <!-- Thai national ID — Thai individual only. Foreigners never
               see this field (server also rejects idCard on foreign
               types via `prohibited`). -->
          <FormField v-if="form.customerType === 'individual'"
            label="เลขบัตรประชาชน" error-key="idCard" :errors="fieldErrors">
            <div class="relative">
              <input v-model.trim="form.idCard" maxlength="13" inputmode="numeric" placeholder="1234567890123"
                :class="[inputClass(showIdCard, idCardValid), 'font-mono']"
                @blur="markTouched('idCard')" />
              <i v-if="idCardValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2 text-xs" />
            </div>
            <p v-if="showIdCard" class="text-xs text-rose-600 mt-0.5">{{ showIdCard }}</p>
          </FormField>

          <!-- Passport + nationality — foreign individual only. -->
          <FormField v-if="form.customerType === 'foreign_individual'"
            label="เลขที่หนังสือเดินทาง (Passport)" required error-key="passport" :errors="fieldErrors"
            hint="ตัวอักษรพิมพ์ใหญ่ A-Z และตัวเลข 6-12 ตัว">
            <div class="relative">
              <input v-model.trim="form.passport" maxlength="12" placeholder="AB1234567"
                :class="[inputClass(showPassport, passportValid), 'font-mono uppercase']"
                @blur="markTouched('passport'); form.passport = form.passport.toUpperCase()"
                @input="form.passport = form.passport.toUpperCase()" />
              <i v-if="passportValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2 text-xs" />
            </div>
            <p v-if="showPassport" class="text-xs text-rose-600 mt-0.5">{{ showPassport }}</p>
          </FormField>
          <FormField v-if="form.customerType === 'foreign_individual'"
            label="สัญชาติ (Nationality)" required error-key="nationality" :errors="fieldErrors">
            <div class="relative">
              <select v-model="form.nationality" @blur="markTouched('nationality')"
                :class="[inputClass(showNationality, nationalityValid), 'bg-white']">
                <option value="">— เลือก —</option>
                <option v-for="n in nationalities" :key="n.id" :value="n.nameTh">
                  {{ n.nameTh }}<span v-if="n.nameEn" class="text-slate-400"> · {{ n.nameEn }}</span>
                </option>
              </select>
            </div>
            <p v-if="showNationality" class="text-xs text-rose-600 mt-0.5">{{ showNationality }}</p>
          </FormField>

          <FormField label="เพศ" required error-key="gender" :errors="fieldErrors">
            <div class="relative">
              <select v-model="form.gender" @blur="markTouched('gender')"
                :class="[inputClass(showGender, genderValid), 'bg-white']">
                <option value="">— เลือก —</option>
                <option value="M">ชาย</option>
                <option value="F">หญิง</option>
              </select>
            </div>
            <p v-if="showGender" class="text-xs text-rose-600 mt-0.5">{{ showGender }}</p>
          </FormField>
          <FormField label="วันเกิด" error-key="birthDate" :errors="fieldErrors">
            <div class="relative">
              <DateInput v-model="form.birthDate" :max="toIsoDate(new Date())" @blur="markTouched('birthDate')" />
              <i v-if="birthValid" class="pi pi-check text-emerald-500 absolute right-8 top-1/2 -translate-y-1/2 text-xs pointer-events-none" />
            </div>
            <p v-if="showBirth" class="text-xs text-rose-600 mt-0.5">{{ showBirth }}</p>
          </FormField>
        </template>

        <!-- Juristic block -->
        <template v-if="form.customerType === 'corporate'">
          <FormField label="คำนำหน้า (นิติบุคคล)" class="col-span-2" error-key="titleTh" :errors="fieldErrors"
            hint="เลือกประเภทของนิติบุคคล (บริษัท / ห้างหุ้นส่วน / ร้าน …)">
            <select v-model="form.titleTh"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
              <option value="">—</option>
              <option v-for="p in availableTitles" :key="p.id" :value="p.descriptionTh">
                {{ p.descriptionTh }}<span v-if="p.descriptionEn" class="text-slate-400"> · {{ p.descriptionEn }}</span>
              </option>
            </select>
          </FormField>
          <FormField label="ชื่อนิติบุคคล" required class="col-span-2" error-key="juristicName" :errors="fieldErrors">
            <div class="relative">
              <input v-model.trim="form.juristicName" placeholder="ระบุชื่อกิจการ"
                :class="inputClass(showJuristicName, juristicNameValid)"
                @blur="markTouched('juristicName')" />
              <i v-if="juristicNameValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2 text-xs" />
            </div>
            <p v-if="showJuristicName" class="text-xs text-rose-600 mt-0.5">{{ showJuristicName }}</p>
          </FormField>
          <FormField label="เลขประจำตัวผู้เสียภาษี" class="col-span-2" error-key="taxId" :errors="fieldErrors">
            <div class="relative">
              <input v-model.trim="form.taxId" maxlength="13" inputmode="numeric" placeholder="1234567890123"
                :class="[inputClass(showTaxId, taxIdValid), 'font-mono']"
                @blur="markTouched('taxId')" />
              <i v-if="taxIdValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2 text-xs" />
            </div>
            <p v-if="showTaxId" class="text-xs text-rose-600 mt-0.5">{{ showTaxId }}</p>
          </FormField>
        </template>

        <!-- Contact (both types) -->
        <FormField label="โทรศัพท์มือถือ" error-key="phone" :errors="fieldErrors">
          <div class="relative">
            <input v-model.trim="form.phone" inputmode="tel" placeholder="0812345678"
              :class="inputClass(showPhone, phoneValid)"
              @blur="markTouched('phone')" />
            <i v-if="phoneValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2 text-xs" />
          </div>
          <p v-if="showPhone" class="text-xs text-rose-600 mt-0.5">{{ showPhone }}</p>
        </FormField>
        <FormField label="โทรศัพท์บ้าน" error-key="telPhone" :errors="fieldErrors">
          <div class="relative">
            <input v-model.trim="form.telPhone" inputmode="tel" placeholder="021234567"
              :class="inputClass(showTelPhone, telPhoneValid)"
              @blur="markTouched('telPhone')" />
            <i v-if="telPhoneValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2 text-xs" />
          </div>
          <p v-if="showTelPhone" class="text-xs text-rose-600 mt-0.5">{{ showTelPhone }}</p>
        </FormField>
        <FormField label="อีเมล" class="col-span-2" error-key="email" :errors="fieldErrors">
          <div class="relative">
            <input v-model.trim="form.email" type="email" placeholder="name@example.com"
              :class="inputClass(showEmail, emailValid)"
              @blur="markTouched('email')" />
            <i v-if="emailValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2 text-xs" />
          </div>
          <p v-if="showEmail" class="text-xs text-rose-600 mt-0.5">{{ showEmail }}</p>
        </FormField>

        <!-- Registered address -->
        <div class="col-span-2 border-t border-slate-200 pt-3 mt-2">
          <div class="text-sm font-semibold text-slate-700 mb-2">ที่อยู่ตามทะเบียนบ้าน</div>
        </div>
        <FormField label="ที่อยู่ (บ้านเลขที่, หมู่, ซอย, ถนน)" class="col-span-2" error-key="address" :errors="fieldErrors">
          <input v-model.trim="form.address"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="จังหวัด" error-key="province" :errors="fieldErrors">
          <select v-model="form.province"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">— เลือก —</option>
            <option v-for="p in provinces" :key="p" :value="p">{{ p }}</option>
          </select>
        </FormField>
        <FormField label="อำเภอ / เขต" error-key="amphoe" :errors="fieldErrors">
          <select v-model="form.amphoe" :disabled="!form.province"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400 disabled:bg-slate-50">
            <option value="">— เลือก —</option>
            <option v-for="d in registeredDistricts" :key="d" :value="d">{{ d }}</option>
          </select>
        </FormField>
        <FormField label="ตำบล / แขวง" error-key="district" :errors="fieldErrors">
          <select v-model="form.subDistrict" :disabled="!form.amphoe"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400 disabled:bg-slate-50">
            <option value="">— เลือก —</option>
            <option v-for="s in registeredSubDistricts" :key="s.name" :value="s.name">{{ s.name }}</option>
          </select>
        </FormField>
        <FormField label="รหัสไปรษณีย์" error-key="postcode" :errors="fieldErrors">
          <input v-model.trim="form.postcode" maxlength="5" readonly
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 text-slate-600 focus:outline-none font-mono" />
        </FormField>

        <!-- Mailing address -->
        <div class="col-span-2 border-t border-slate-200 pt-3 mt-2">
          <div class="flex items-center justify-between mb-2">
            <div class="text-sm font-semibold text-slate-700">ที่อยู่จัดส่งเอกสาร</div>
            <label class="flex items-center gap-2 text-xs text-slate-600 cursor-pointer">
              <input type="checkbox" v-model="form.mailingSameAsRegistered" class="accent-brand-500" />
              เหมือนที่อยู่ตามทะเบียนบ้าน
            </label>
          </div>
        </div>
        <template v-if="!form.mailingSameAsRegistered">
          <div class="col-span-2">
            <button type="button" @click="copyRegisteredToMailing"
              class="text-xs px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50">
              <i class="pi pi-copy" /> คัดลอกจากที่อยู่ตามทะเบียนบ้าน
            </button>
          </div>
          <FormField label="ที่อยู่ (จัดส่ง)" class="col-span-2" error-key="mailing.address" :errors="fieldErrors">
            <input v-model.trim="form.mailingAddress"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
          </FormField>
          <FormField label="จังหวัด (จัดส่ง)" error-key="mailing.province" :errors="fieldErrors">
            <select v-model="form.mailingProvince"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
              <option value="">— เลือก —</option>
              <option v-for="p in provinces" :key="p" :value="p">{{ p }}</option>
            </select>
          </FormField>
          <FormField label="อำเภอ / เขต (จัดส่ง)" error-key="mailing.district" :errors="fieldErrors">
            <select v-model="form.mailingAmphoe" :disabled="!form.mailingProvince"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400 disabled:bg-slate-50">
              <option value="">— เลือก —</option>
              <option v-for="d in mailingDistricts" :key="d" :value="d">{{ d }}</option>
            </select>
          </FormField>
          <FormField label="ตำบล / แขวง (จัดส่ง)" error-key="mailing.subDistrict" :errors="fieldErrors">
            <select v-model="form.mailingSubDistrict" :disabled="!form.mailingAmphoe"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400 disabled:bg-slate-50">
              <option value="">— เลือก —</option>
              <option v-for="s in mailingSubDistricts" :key="s.name" :value="s.name">{{ s.name }}</option>
            </select>
          </FormField>
          <FormField label="รหัสไปรษณีย์ (จัดส่ง)" error-key="mailing.postcode" :errors="fieldErrors">
            <input v-model.trim="form.mailingPostcode" maxlength="5" readonly
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 text-slate-600 focus:outline-none font-mono" />
          </FormField>
        </template>
      </div>
    </template>
  </CreateModal>
</template>
