<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useCustomerStore, type Customer } from '../../stores/customers'
import { useAuthStore } from '../../stores/auth'
import { fetchPolicyList, type PolicyListRow } from '../../api/policies'
import { fetchCustomerAudit, type CustomerAuditEntry } from '../../api/customers'
import EditableField, { type EditableType, type SelectOption } from '../../components/EditableField.vue'
import AddressPicker from '../../components/AddressPicker.vue'
import DeleteConfirmDialog from '../../components/DeleteConfirmDialog.vue'
import ConfirmChangesDialog, { type ConfirmChangeRow } from '../../components/ConfirmChangesDialog.vue'
import CopyButton from '../../components/CopyButton.vue'
import AgentPicker from '../../components/AgentPicker.vue'
import { api, ApiError } from '../../api/client'
import { fmtDate, fmtDateTime } from '../../util/dateFormat'
import { customerDisplayName, customerTypeLabel } from '../../utils/customerDisplay'
import { genderFromTitle } from '../../utils/genderFromTitle'
import { useDraftEdit } from '../../composables/useDraftEdit'

const CUSTOMER_TYPE_OPTIONS = [
  { value: 'individual', label: 'บุคคลธรรมดา' },
  { value: 'foreign_individual', label: 'ชาวต่างชาติ' },
  { value: 'corporate', label: 'นิติบุคคล' },
]
const GENDER_OPTIONS = [
  { value: 'M', label: 'ชาย' },
  { value: 'F', label: 'หญิง' },
]
const MARITAL_OPTIONS = [
  { value: 'single', label: 'โสด' },
  { value: 'married', label: 'สมรส' },
  { value: 'divorced', label: 'หย่า' },
  { value: 'widowed', label: 'หม้าย' },
]
const YES_NO_OPTIONS = [
  { value: 'yes', label: 'ใช่' },
  { value: 'no', label: 'ไม่ใช่' },
]

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const customerId = computed<string | null>(() => (route.params.id as string) ?? null)
function goBack(): void { router.push({ name: 'customers' }) }

const customerStore = useCustomerStore()
const auth = useAuthStore()
const canEdit = computed(() => auth.hasPermission('customers.update'))
const canDelete = computed(() => auth.hasPermission('customers.delete'))

const loading = ref(false)
const errorMsg = ref<string | null>(null)
const policies = ref<PolicyListRow[]>([])
const policiesLoading = ref(false)

const customer = computed<Customer | null>(() => (customerId.value ? customerStore.getCustomer(customerId.value) : null))
const d = useDraftEdit<Customer>(() => customer.value)
const editing = computed(() => d.editing.value)

const currentType = computed(() => d.get<string>('customerType'))
const isIndividual = computed(() => currentType.value === 'individual')
const isForeign = computed(() => currentType.value === 'foreign_individual')
const isPerson = computed(() => isIndividual.value || isForeign.value)
const isCorporate = computed(() => currentType.value === 'corporate')

interface FieldDef {
  path: string
  label: string | (() => string)
  type?: EditableType
  options?: SelectOption[]
  span?: 2 | 3 | 4
  copy?: boolean
  show?: () => boolean
  placeholder?: string
}

const NAME_FIELDS: FieldDef[] = [
  { path: 'customerType', label: 'ประเภทลูกค้า', type: 'select', options: CUSTOMER_TYPE_OPTIONS },
  { path: 'titleTh', label: () => (isCorporate.value ? 'คำนำหน้า (นิติบุคคล)' : 'คำนำหน้า') },
  { path: 'juristicName', label: 'ชื่อนิติบุคคล', span: 2, show: () => isCorporate.value },
  { path: 'firstName', label: () => (isForeign.value ? 'ชื่อ (First name)' : 'ชื่อ (ภาษาไทย)'), show: () => isPerson.value },
  { path: 'lastName', label: () => (isForeign.value ? 'นามสกุล (Last name)' : 'นามสกุล (ภาษาไทย)'), show: () => isPerson.value },
  { path: 'nickname', label: 'ชื่อเล่น', show: () => isPerson.value },
  { path: 'titleEn', label: 'คำนำหน้า (อังกฤษ)', show: () => isPerson.value },
  { path: 'firstNameEn', label: 'ชื่อ (อังกฤษ)', show: () => isPerson.value },
  { path: 'lastNameEn', label: 'นามสกุล (อังกฤษ)', show: () => isPerson.value },
]

const IDENTITY_FIELDS: FieldDef[] = [
  { path: 'taxId', label: 'เลขประจำตัวผู้เสียภาษี', copy: true, show: () => isCorporate.value },
  { path: 'idCard', label: 'เลขบัตรประชาชน', copy: true, show: () => isIndividual.value },
  { path: 'passport', label: 'เลขที่หนังสือเดินทาง (Passport)', copy: true, show: () => isForeign.value },
  { path: 'nationalIdExpiry', label: 'วันหมดอายุบัตร', type: 'date', show: () => isPerson.value },
  { path: 'birthDate', label: 'วันเกิด', type: 'date', show: () => isPerson.value },
  { path: 'gender', label: 'เพศ', type: 'select', options: GENDER_OPTIONS, show: () => isPerson.value },
  { path: 'maritalStatus', label: 'สถานภาพสมรส', type: 'select', options: MARITAL_OPTIONS, show: () => isPerson.value },
  { path: 'nationality', label: () => (isForeign.value ? 'สัญชาติ (Nationality)' : 'สัญชาติ'), show: () => isPerson.value },
  { path: 'race', label: 'เชื้อชาติ', show: () => isPerson.value },
  { path: 'religion', label: 'ศาสนา', show: () => isPerson.value },
]

const OCCUPATION_FIELDS: FieldDef[] = [
  { path: 'occupation', label: 'อาชีพ' },
  { path: 'position', label: 'ตำแหน่ง' },
  { path: 'employerName', label: 'สถานที่ทำงาน', span: 2 },
  { path: 'monthlyIncome', label: 'รายได้ต่อเดือน', type: 'currency' },
]

const CONTACT_FIELDS: FieldDef[] = [
  { path: 'phone', label: 'โทรศัพท์มือถือ', copy: true, placeholder: '0812345678' },
  { path: 'telPhone', label: () => t('customers.fields.telPhone'), copy: true, placeholder: '0812345678 หรือ 021234567' },
  { path: 'lineId', label: 'LINE ID', copy: true },
  { path: 'facebookName', label: 'Facebook' },
  { path: 'email', label: 'อีเมล', copy: true },
  { path: 'email2', label: 'อีเมล (สำรอง)', copy: true },
]

const ADDRESS_FIELDS: FieldDef[] = [
  { path: 'address', label: 'ที่อยู่', span: 4 },
]

const MAILING_FIELDS: FieldDef[] = [
  { path: 'mailingSameAsRegistered', label: 'ใช้ที่อยู่เดียวกับทะเบียนบ้าน', type: 'select', options: YES_NO_OPTIONS },
  { path: 'mailing.address', label: 'ที่อยู่จัดส่ง', span: 3 },
]

const CONTACT_PERSON_FIELDS: FieldDef[] = [
  { path: 'contactPerson.name', label: 'ชื่อผู้ติดต่อแทน' },
  { path: 'contactPerson.position', label: 'ตำแหน่ง / ความสัมพันธ์' },
  { path: 'contactPerson.phone', label: 'เบอร์โทรผู้ติดต่อแทน', copy: true },
  { path: 'contactPerson.email', label: 'อีเมลผู้ติดต่อแทน', copy: true },
]

const NOTES_FIELDS: FieldDef[] = [
  { path: 'notes', label: 'บันทึก', type: 'textarea', span: 4, placeholder: 'ยังไม่มีบันทึก' },
]

const EXTRA_LABELS: Record<string, string> = {
  province: 'จังหวัด',
  amphoe: 'อำเภอ / เขต',
  district: 'ตำบล / แขวง',
  postcode: 'รหัสไปรษณีย์',
  'mailing.province': 'จังหวัด (ที่อยู่จัดส่ง)',
  'mailing.district': 'อำเภอ / เขต (ที่อยู่จัดส่ง)',
  'mailing.subDistrict': 'ตำบล / แขวง (ที่อยู่จัดส่ง)',
  'mailing.postcode': 'รหัสไปรษณีย์ (ที่อยู่จัดส่ง)',
  assignedAgentId: 'ตัวแทนผู้ดูแล',
  active: 'สถานะการใช้งาน',
  customerCode: 'รหัสลูกค้า',
}

const ALL_FIELDS: FieldDef[] = [
  ...NAME_FIELDS, ...IDENTITY_FIELDS, ...OCCUPATION_FIELDS, ...CONTACT_FIELDS,
  ...ADDRESS_FIELDS, ...MAILING_FIELDS, ...CONTACT_PERSON_FIELDS, ...NOTES_FIELDS,
]
const FIELD_BY_PATH = new Map(ALL_FIELDS.map((f) => [f.path, f]))

const PATH_BY_COLUMN: Record<string, string> = {
  customer_code: 'customerCode', customer_type: 'customerType', title_th: 'titleTh', title_en: 'titleEn',
  first_name: 'firstName', last_name: 'lastName', nickname: 'nickname', first_name_en: 'firstNameEn',
  last_name_en: 'lastNameEn', juristic_name: 'juristicName', tax_id: 'taxId', id_card: 'idCard',
  national_id_expiry: 'nationalIdExpiry', passport: 'passport', nationality: 'nationality', race: 'race',
  religion: 'religion', birth_date: 'birthDate', gender: 'gender', marital_status: 'maritalStatus',
  occupation: 'occupation', position: 'position', employer_name: 'employerName', monthly_income: 'monthlyIncome',
  email: 'email', email2: 'email2', phone: 'phone', tel_phone: 'telPhone', line_id: 'lineId',
  facebook_name: 'facebookName', address: 'address', sub_district: 'district', amphoe: 'amphoe',
  province: 'province', postcode: 'postcode', mailing_same_as_registered: 'mailingSameAsRegistered',
  mailing_address: 'mailing.address', mailing_sub_district: 'mailing.subDistrict', mailing_district: 'mailing.district',
  mailing_province: 'mailing.province', mailing_postcode: 'mailing.postcode', contact_name: 'contactPerson.name',
  contact_phone: 'contactPerson.phone', contact_email: 'contactPerson.email', contact_position: 'contactPerson.position',
  assigned_agent_id: 'assignedAgentId', notes: 'notes', active: 'active',
}

function labelOf(f: FieldDef): string {
  return typeof f.label === 'function' ? f.label() : f.label
}

function labelForPath(path: string): string {
  const f = FIELD_BY_PATH.get(path)
  if (f) return labelOf(f)
  return EXTRA_LABELS[path] ?? path
}

function visible(fields: FieldDef[]): FieldDef[] {
  return fields.filter((f) => (f.show ? f.show() : true))
}

function spanClass(f: FieldDef): string {
  if (f.span === 4) return 'col-span-2 md:col-span-4'
  if (f.span === 3) return 'col-span-2 md:col-span-3'
  if (f.span === 2) return 'col-span-2'
  return ''
}

function fieldValue(f: FieldDef): string | number | null {
  const v = d.get<unknown>(f.path)
  if (f.path === 'mailingSameAsRegistered') return v ? 'yes' : 'no'
  if (v === undefined || v === null) return null
  if (typeof v === 'number' || typeof v === 'string') return v
  return String(v)
}

function setField(f: FieldDef, v: string | number | null): void {
  if (f.path === 'mailingSameAsRegistered') {
    d.set(f.path, v === 'yes')
    return
  }
  d.set(f.path, v)
  if (f.path === 'titleTh' && !isCorporate.value && !d.get('gender')) {
    const g = genderFromTitle(typeof v === 'string' ? v : '')
    if (g) d.set('gender', g)
  }
}

function formatValue(path: string, v: unknown): string {
  if (v === null || v === undefined || v === '') return ''
  if (path === 'mailingSameAsRegistered' || path === 'active') {
    const on = v === true || v === 1 || v === '1' || v === 'true'
    return path === 'active' ? (on ? 'ใช้งาน' : 'ไม่ใช้งาน') : (on ? 'ใช่' : 'ไม่ใช่')
  }
  const f = FIELD_BY_PATH.get(path)
  if (f?.type === 'select' && f.options) {
    return f.options.find((o) => o.value === String(v))?.label ?? String(v)
  }
  if (f?.type === 'date') return fmtDate(String(v))
  if (f?.type === 'currency') {
    const n = Number(v)
    return Number.isFinite(n) ? n.toLocaleString('th-TH', { maximumFractionDigits: 2 }) : String(v)
  }
  return String(v)
}

const confirmRows = computed<ConfirmChangeRow[]>(() =>
  d.changes.value.map((c) => ({
    key: c.path,
    label: labelForPath(c.path),
    from: formatValue(c.path, c.from),
    to: formatValue(c.path, c.to),
  })),
)

const fieldErrors = ref<Record<string, string>>({})
const showConfirm = ref(false)
const saving = ref(false)
const saveError = ref<string | null>(null)
const saveErrorList = ref<string[]>([])
const savedFlash = ref(false)

function beginEdit(): void {
  if (!canEdit.value) return
  fieldErrors.value = {}
  d.begin()
}

function cancelEdit(): void {
  fieldErrors.value = {}
  d.cancel()
}

function requestSave(): void {
  saveError.value = null
  saveErrorList.value = []
  if (!d.changes.value.length) {
    d.cancel()
    return
  }
  showConfirm.value = true
}

async function confirmSave(): Promise<void> {
  if (!customer.value) return
  saving.value = true
  saveError.value = null
  saveErrorList.value = []
  try {
    await customerStore.updateCustomer(customer.value.id, d.payload({ customerType: currentType.value }) as Partial<Customer>)
    fieldErrors.value = {}
    d.cancel()
    showConfirm.value = false
    savedFlash.value = true
    window.setTimeout(() => (savedFlash.value = false), 2500)
    void loadAudit()
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      saveError.value = e.status === 403 ? t('customers.ui.noEditPermission') : (e.body?.message ?? `HTTP ${e.status}`)
      const errs = e.body?.errors ?? {}
      const mapped: Record<string, string> = {}
      for (const [k, msgs] of Object.entries(errs)) mapped[k] = msgs[0] ?? ''
      fieldErrors.value = mapped
      saveErrorList.value = Object.entries(mapped).map(([k, m]) => `${labelForPath(k)}: ${m}`)
    } else {
      saveError.value = e instanceof Error ? e.message : 'บันทึกไม่สำเร็จ'
    }
  } finally {
    saving.value = false
  }
}

function str(path: string): string | null {
  const v = d.get<unknown>(path)
  return v === undefined || v === null ? null : String(v)
}

function onAddressUpdate(field: string, value: string | null): void {
  d.set(field, value)
}

onBeforeRouteLeave(() => {
  if (d.dirty.value && !window.confirm(t('customers.ui.unsavedLeave'))) return false
  return true
})

function onBeforeUnload(e: BeforeUnloadEvent): void {
  if (!d.dirty.value) return
  e.preventDefault()
  e.returnValue = ''
}
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload))

const audit = ref<CustomerAuditEntry[]>([])
const auditLoading = ref(false)

async function loadAudit(): Promise<void> {
  if (!customerId.value) return
  auditLoading.value = true
  try {
    audit.value = (await fetchCustomerAudit(customerId.value)).data
  } catch {
    audit.value = []
  } finally {
    auditLoading.value = false
  }
}

function auditActionLabel(action: string): string {
  if (action === 'customer.updated') return t('customers.ui.auditActionUpdated')
  if (action === 'customer.gender_backfilled') return t('customers.ui.auditActionBackfill')
  return action
}

function auditRows(entry: CustomerAuditEntry): ConfirmChangeRow[] {
  return Object.entries(entry.changes ?? {}).map(([col, c]) => {
    const path = PATH_BY_COLUMN[col] ?? col
    return { key: col, label: labelForPath(path), from: formatValue(path, c.from), to: formatValue(path, c.to) }
  })
}

watch(
  () => customerId.value,
  async (id) => {
    d.cancel()
    if (!id) {
      policies.value = []
      return
    }
    loading.value = true
    errorMsg.value = null
    policies.value = []
    try {
      await customerStore.ensureDetail(id, true)
      void loadAudit()
      policiesLoading.value = true
      const res = await fetchPolicyList({ customerId: id, perPage: 50 })
      policies.value = res.data
    } catch (e: unknown) {
      errorMsg.value = e instanceof Error ? e.message : 'โหลดข้อมูลลูกค้าไม่สำเร็จ'
    } finally {
      loading.value = false
      policiesLoading.value = false
    }
  },
  { immediate: true },
)

const policyCounts = computed(() => {
  const c = customer.value
  if (!c) return { inForce: 0, inProgress: 0, ended: 0, total: 0 }
  return c.policyCounts ?? { inForce: c.activePolicyCount, inProgress: 0, ended: 0, total: c.totalPolicyCount }
})

const TIER_CLASS: Record<string, string> = {
  vip: 'bg-violet-50 text-violet-700 ring-1 ring-violet-200',
  gold: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
  silver: 'bg-slate-100 text-slate-700 ring-1 ring-slate-200',
}

const sortedAssignmentHistory = computed(() =>
  [...(customer.value?.assignmentHistory ?? [])].sort((a, b) => String(b.at ?? '').localeCompare(String(a.at ?? ''))),
)

function agentText(code: string | null | undefined, name: string | null | undefined, id: string | null): string {
  if (code) return name ? `${code} · ${name}` : code
  return id ? `#${id}` : '—'
}


const showAssign = ref(false)
const assignForm = ref({ agentId: '', reason: '' })
const assignSaving = ref(false)
const assignError = ref<string | null>(null)

function openAssign(): void {
  assignForm.value = { agentId: '', reason: '' }
  assignError.value = null
  showAssign.value = true
}

async function submitAssign(): Promise<void> {
  if (!customer.value) return
  if (!assignForm.value.agentId) {
    assignError.value = t('customers.ui.agentRequired')
    return
  }
  if (assignForm.value.agentId === customer.value.assignedAgentId) {
    assignError.value = t('customers.ui.sameAgent')
    return
  }
  assignSaving.value = true
  assignError.value = null
  try {
    await customerStore.assignCustomer(customer.value.id, assignForm.value.agentId, assignForm.value.reason.trim())
    showAssign.value = false
    void loadAudit()
  } catch (e: unknown) {
    assignError.value = e instanceof ApiError
      ? (e.status === 403 ? t('customers.ui.noEditPermission') : (e.body?.message ?? `HTTP ${e.status}`))
      : 'บันทึกไม่สำเร็จ'
  } finally {
    assignSaving.value = false
  }
}

const showDelete = ref(false)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

async function doDelete(): Promise<void> {
  if (!customerId.value) return
  deleting.value = true
  deleteError.value = null
  try {
    await api.delete(`customers/${customerId.value}`)
    await customerStore.loadPage({})
    showDelete.value = false
    goBack()
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 409) deleteError.value = t('customers.ui.deleteBlocked')
    else if (e instanceof ApiError && e.status === 403) deleteError.value = t('customers.ui.noEditPermission')
    else deleteError.value = e instanceof ApiError ? e.message : 'ลบไม่สำเร็จ'
  } finally {
    deleting.value = false
  }
}

function fmtBaht(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 0 }).format(n)
}

function statusBadge(s: string): string {
  return {
    quote: 'bg-slate-100 text-slate-600',
    application: 'bg-amber-50 text-amber-700',
    submitted: 'bg-amber-50 text-amber-700',
    issued: 'bg-sky-50 text-sky-700',
    active: 'bg-emerald-50 text-emerald-700',
    lapsed: 'bg-rose-50 text-rose-700',
    cancelled: 'bg-slate-100 text-slate-500',
    reinstated: 'bg-violet-50 text-violet-700',
    expired: 'bg-slate-100 text-slate-500',
  }[s] ?? 'bg-slate-100 text-slate-600'
}
</script>

<template>
  <div class="space-y-6 max-w-4xl pb-20">
    <header class="flex items-start justify-between gap-3 flex-wrap">
      <div v-if="customer">
        <div class="flex items-center gap-2 text-xs uppercase text-slate-400">
          <RouterLink :to="{ name: 'customers' }" class="hover:text-brand-600 normal-case">ลูกค้า</RouterLink>
          <span>/</span>
          <span class="font-mono select-text">{{ customer.customerCode }}</span>
          <CopyButton :value="customer.customerCode" />
          <span>·</span>
          <span>{{ customerTypeLabel(customer.customerType) }}</span>
          <span v-if="customer.legacyId">· รหัสเดิม {{ customer.legacyId }}</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900 mt-1 flex items-center gap-2 flex-wrap">
          <span class="select-text">{{ customerDisplayName(customer) || '—' }}</span>
          <span v-if="customer.nickname" class="text-sm text-slate-500">({{ customer.nickname }})</span>
          <span v-if="customer.salesSummary?.tier" data-testid="customer-tier-badge"
            :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-semibold', TIER_CLASS[customer.salesSummary.tier.key] ?? TIER_CLASS.silver]">
            {{ customer.salesSummary.tier.label }}
          </span>
        </h1>
        <div v-if="customer.firstNameEn || customer.lastNameEn" class="text-xs text-slate-500 mt-0.5">
          {{ customer.titleEn }} {{ customer.firstNameEn }} {{ customer.lastNameEn }}
        </div>
      </div>
      <div v-else class="text-slate-500 text-sm">กำลังโหลด…</div>
      <div class="flex items-center gap-2">
        <button type="button"
          class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100 flex items-center gap-1.5"
          @click="goBack">
          <i class="pi pi-arrow-left text-xs" /> กลับ
        </button>
        <template v-if="customer">
          <button v-if="!editing && canEdit" type="button" data-testid="customer-edit-btn"
            class="px-3 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm flex items-center gap-1.5"
            @click="beginEdit">
            <i class="pi pi-pencil text-xs" /> {{ t('customers.ui.edit') }}
          </button>
        </template>
      </div>
    </header>

    <div v-if="errorMsg" class="p-4 bg-rose-50 border border-rose-200 rounded-lg text-rose-700 text-sm">
      {{ errorMsg }}
    </div>

    <div v-if="loading && !customer" class="card p-6 text-slate-500 text-sm">กำลังโหลด…</div>

    <template v-else-if="customer">
      <div v-if="savedFlash" class="p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-2"
        data-testid="customer-saved-flash">
        <i class="pi pi-check-circle" /> {{ t('customers.ui.saved') }}
      </div>
      <div v-if="editing"
        class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-2"
        data-testid="customer-edit-banner">
        <i class="pi pi-pencil" /> {{ t('customers.ui.editModeHint') }}
      </div>
      <div v-else class="text-xs text-slate-500 flex items-center gap-2">
        <i class="pi pi-eye" />
        {{ canEdit ? t('customers.ui.viewModeHint') : t('customers.ui.noEditPermission') }}
      </div>

      <section v-if="customer.salesSummary" class="card p-5" data-testid="customer-sales-card">
        <div class="flex items-center justify-between gap-2 mb-3">
          <h2 class="font-semibold text-slate-900">{{ t('customers.ui.salesTitle') }}</h2>
          <span class="text-[11px] text-slate-400" :title="t('customers.ui.salesHint')"><i class="pi pi-info-circle" /> {{ t('customers.ui.salesHint') }}</span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div class="rounded-lg bg-slate-50 p-3">
            <div class="text-xs text-slate-500">{{ t('customers.ui.salesTotal') }}</div>
            <div class="text-lg font-semibold text-slate-900 tabular-nums">{{ fmtBaht(customer.salesSummary.totalPremium) }}</div>
          </div>
          <div class="rounded-lg bg-slate-50 p-3">
            <div class="text-xs text-slate-500">{{ t('customers.ui.salesThisYear', { year: customer.salesSummary.year + 543 }) }}</div>
            <div class="text-lg font-semibold text-slate-900 tabular-nums">{{ fmtBaht(customer.salesSummary.premiumThisYear) }}</div>
          </div>
          <div class="rounded-lg bg-slate-50 p-3">
            <div class="text-xs text-slate-500">{{ t('customers.ui.salesPolicies') }}</div>
            <div class="text-lg font-semibold text-slate-900 tabular-nums">{{ customer.salesSummary.policyCount }}</div>
          </div>
          <div class="rounded-lg bg-slate-50 p-3">
            <div class="text-xs text-slate-500">{{ t('customers.ui.salesTier') }}</div>
            <div class="mt-1">
              <span v-if="customer.salesSummary.tier"
                :class="['inline-flex px-2 py-0.5 rounded-md text-sm font-semibold', TIER_CLASS[customer.salesSummary.tier.key] ?? TIER_CLASS.silver]">
                {{ customer.salesSummary.tier.label }}
              </span>
              <span v-else class="text-sm text-slate-400">{{ t('customers.ui.salesNoTier') }}</span>
            </div>
          </div>
        </div>
      </section>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ประเภทและชื่อลูกค้า</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div v-for="f in visible(NAME_FIELDS)" :key="f.path" :class="spanClass(f)">
            <div class="text-xs text-slate-400">{{ labelOf(f) }}</div>
            <div class="flex items-start gap-1">
              <EditableField class="flex-1" entity="customers" :id="customer.id" :field="f.path" :type="f.type ?? 'text'"
                :options="f.options ?? []" :value="fieldValue(f)" :readonly="!editing" :controlled="editing"
                :placeholder="editing ? f.placeholder : undefined" :error-text="fieldErrors[f.path] ?? null" @update="v => setField(f, v)" />
              <CopyButton v-if="f.copy && !editing" :value="fieldValue(f)" />
            </div>
          </div>
        </div>
      </section>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ข้อมูลตัวตน</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div v-for="f in visible(IDENTITY_FIELDS)" :key="f.path" :class="spanClass(f)">
            <div class="text-xs text-slate-400">{{ labelOf(f) }}</div>
            <div class="flex items-start gap-1">
              <EditableField class="flex-1" entity="customers" :id="customer.id" :field="f.path" :type="f.type ?? 'text'"
                :options="f.options ?? []" :value="fieldValue(f)" :readonly="!editing" :controlled="editing"
                :placeholder="editing ? f.placeholder : undefined" :error-text="fieldErrors[f.path] ?? null" @update="v => setField(f, v)" />
              <CopyButton v-if="f.copy && !editing" :value="fieldValue(f)" />
            </div>
          </div>
        </div>
      </section>

      <section v-if="isPerson" class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">อาชีพและรายได้</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div v-for="f in visible(OCCUPATION_FIELDS)" :key="f.path" :class="spanClass(f)">
            <div class="text-xs text-slate-400">{{ labelOf(f) }}</div>
            <EditableField entity="customers" :id="customer.id" :field="f.path" :type="f.type ?? 'text'"
              :options="f.options ?? []" :value="fieldValue(f)" :readonly="!editing" :controlled="editing"
              :placeholder="editing ? f.placeholder : undefined" :error-text="fieldErrors[f.path] ?? null" @update="v => setField(f, v)" />
          </div>
        </div>
      </section>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ช่องทางติดต่อ</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div v-for="f in visible(CONTACT_FIELDS)" :key="f.path" :class="spanClass(f)" :data-testid="`customer-field-${f.path}`">
            <div class="text-xs text-slate-400">{{ labelOf(f) }}</div>
            <div class="flex items-start gap-1">
              <EditableField class="flex-1" entity="customers" :id="customer.id" :field="f.path" :type="f.type ?? 'text'"
                :options="f.options ?? []" :value="fieldValue(f)" :readonly="!editing" :controlled="editing"
                :placeholder="editing ? f.placeholder : undefined" :error-text="fieldErrors[f.path] ?? null" @update="v => setField(f, v)" />
              <CopyButton v-if="f.copy && !editing" :value="fieldValue(f)" />
            </div>
          </div>
        </div>
      </section>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ที่อยู่ตามทะเบียนบ้าน</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm mb-3">
          <div v-for="f in ADDRESS_FIELDS" :key="f.path" :class="spanClass(f)">
            <div class="text-xs text-slate-400">{{ labelOf(f) }}</div>
            <EditableField entity="customers" :id="customer.id" :field="f.path" :value="fieldValue(f)"
              :readonly="!editing" :controlled="editing" :error-text="fieldErrors[f.path] ?? null" @update="v => setField(f, v)" />
          </div>
        </div>
        <AddressPicker v-if="editing"
          :key="`reg-${customer.id}`"
          entity="customers" :id="customer.id" deferred
          :fields="{ province: 'province', district: 'amphoe', subDistrict: 'district', postcode: 'postcode' }"
          :province="str('province')" :district="str('amphoe')"
          :sub-district="str('district')" :postcode="str('postcode')"
          @update="onAddressUpdate" />
        <div v-else class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div><div class="text-xs text-slate-400">จังหวัด</div><div class="px-1 py-0.5 select-text text-slate-900">{{ customer.province || '—' }}</div></div>
          <div><div class="text-xs text-slate-400">อำเภอ / เขต</div><div class="px-1 py-0.5 select-text text-slate-900">{{ customer.amphoe || '—' }}</div></div>
          <div><div class="text-xs text-slate-400">ตำบล / แขวง</div><div class="px-1 py-0.5 select-text text-slate-900">{{ customer.district || '—' }}</div></div>
          <div><div class="text-xs text-slate-400">รหัสไปรษณีย์</div><div class="px-1 py-0.5 select-text text-slate-900">{{ customer.postcode || '—' }}</div></div>
        </div>
      </section>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">ที่อยู่จัดส่ง</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm mb-3">
          <div v-for="f in MAILING_FIELDS" :key="f.path" :class="spanClass(f)">
            <div class="text-xs text-slate-400">{{ labelOf(f) }}</div>
            <EditableField entity="customers" :id="customer.id" :field="f.path" :type="f.type ?? 'text'"
              :options="f.options ?? []" :value="fieldValue(f)" :readonly="!editing" :controlled="editing"
              :error-text="fieldErrors[f.path] ?? null" @update="v => setField(f, v)" />
          </div>
        </div>
        <template v-if="!d.get('mailingSameAsRegistered')">
          <AddressPicker v-if="editing"
            :key="`mail-${customer.id}`"
            entity="customers" :id="customer.id" deferred
            :fields="{ province: 'mailing.province', district: 'mailing.district', subDistrict: 'mailing.subDistrict', postcode: 'mailing.postcode' }"
            :province="str('mailing.province')" :district="str('mailing.district')"
            :sub-district="str('mailing.subDistrict')" :postcode="str('mailing.postcode')"
            @update="onAddressUpdate" />
          <div v-else class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div><div class="text-xs text-slate-400">จังหวัด</div><div class="px-1 py-0.5 select-text text-slate-900">{{ customer.mailing?.province || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">อำเภอ / เขต</div><div class="px-1 py-0.5 select-text text-slate-900">{{ customer.mailing?.district || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">ตำบล / แขวง</div><div class="px-1 py-0.5 select-text text-slate-900">{{ customer.mailing?.subDistrict || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">รหัสไปรษณีย์</div><div class="px-1 py-0.5 select-text text-slate-900">{{ customer.mailing?.postcode || '—' }}</div></div>
          </div>
        </template>
        <p v-else class="text-xs text-slate-400">ใช้ที่อยู่ตามทะเบียนบ้าน — เปลี่ยน “ใช้ที่อยู่เดียวกับทะเบียนบ้าน” เป็น “ไม่ใช่” เพื่อระบุที่อยู่จัดส่งอื่น</p>
      </section>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900">{{ t('customers.ui.contactPersonTitle') }}</h2>
        <p class="text-xs text-slate-500 mt-0.5 mb-3">{{ t('customers.ui.contactPersonHint') }}</p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div v-for="f in CONTACT_PERSON_FIELDS" :key="f.path" :class="spanClass(f)">
            <div class="text-xs text-slate-400">{{ labelOf(f) }}</div>
            <div class="flex items-start gap-1">
              <EditableField class="flex-1" entity="customers" :id="customer.id" :field="f.path" :value="fieldValue(f)"
                :readonly="!editing" :controlled="editing" :error-text="fieldErrors[f.path] ?? null" @update="v => setField(f, v)" />
              <CopyButton v-if="f.copy && !editing" :value="fieldValue(f)" />
            </div>
          </div>
        </div>
      </section>

      <section class="card p-5" data-testid="customer-assignment-card">
        <div class="flex items-center justify-between gap-2 mb-3">
          <h2 class="font-semibold text-slate-900">การมอบหมาย</h2>
          <button v-if="canEdit && !editing" type="button" data-testid="customer-change-agent-btn"
            class="px-2.5 py-1 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-xs flex items-center gap-1"
            @click="openAssign">
            <i class="pi pi-user-edit text-xs" /> {{ t('customers.ui.changeAgent') }}
          </button>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div>
            <div class="text-xs text-slate-400">ตัวแทนที่ดูแล</div>
            <router-link v-if="customer.assignedAgentCode"
              :to="{ name: 'agents', query: { q: customer.assignedAgentCode } }"
              class="text-brand-600 hover:text-brand-800 hover:underline">
              {{ customer.assignedAgentCode }}<span v-if="customer.assignedAgentName" class="text-slate-500"> · {{ customer.assignedAgentName }}</span>
            </router-link>
            <span v-else class="text-slate-400">{{ t('customers.ui.unassigned') }}</span>
          </div>
          <div><div class="text-xs text-slate-400">สร้างโดย</div><div class="text-slate-900">{{ customer.createdByName || '—' }}</div></div>
          <div><div class="text-xs text-slate-400">วันที่ลงทะเบียน</div><div class="text-slate-900">{{ fmtDate(customer.registeredAt) || '—' }}</div></div>
          <div><div class="text-xs text-slate-400">ติดต่อล่าสุด</div><div class="text-slate-900">{{ fmtDate(customer.lastContact) || '—' }}</div></div>
          <div :title="t('customers.ui.inForceHint')" data-testid="policy-count-inforce">
            <div class="text-xs text-slate-400 flex items-center gap-1">{{ t('customers.ui.inForce') }} <i class="pi pi-info-circle text-[10px]" /></div>
            <div class="font-semibold text-emerald-700">{{ policyCounts.inForce }}</div>
          </div>
          <div :title="t('customers.ui.inProgressHint')" data-testid="policy-count-inprogress">
            <div class="text-xs text-slate-400 flex items-center gap-1">{{ t('customers.ui.inProgress') }} <i class="pi pi-info-circle text-[10px]" /></div>
            <div class="font-semibold text-amber-700">{{ policyCounts.inProgress }}</div>
          </div>
          <div :title="t('customers.ui.endedHint')" data-testid="policy-count-ended">
            <div class="text-xs text-slate-400 flex items-center gap-1">{{ t('customers.ui.ended') }} <i class="pi pi-info-circle text-[10px]" /></div>
            <div class="font-semibold text-slate-600">{{ policyCounts.ended }}</div>
          </div>
          <div data-testid="policy-count-total">
            <div class="text-xs text-slate-400">{{ t('customers.ui.total') }}</div>
            <div class="font-semibold text-slate-900">{{ policyCounts.total }}</div>
          </div>
          <div><div class="text-xs text-slate-400">สถานะ</div>
            <span v-if="customer.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">ใช้งาน</span>
            <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">ไม่ใช้งาน</span>
          </div>
        </div>
        <p class="text-[11px] text-slate-400 mt-3"><i class="pi pi-info-circle text-[10px]" /> {{ t('customers.ui.changeAgentNote') }}</p>
      </section>

      <section class="card p-5" v-if="customer.kycDocs && customer.kycDocs.length">
        <h2 class="font-semibold text-slate-900 mb-3">เอกสาร KYC <span class="text-slate-500 normal-case">({{ customer.kycDocs.length }})</span></h2>
        <div class="overflow-hidden rounded-lg border border-slate-200">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
              <tr>
                <th class="px-4 py-2 text-left">ประเภท</th>
                <th class="px-4 py-2 text-left">ไฟล์</th>
                <th class="px-4 py-2 text-left">วันที่อัปโหลด</th>
                <th class="px-4 py-2 text-left">การยืนยัน</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="kd in customer.kycDocs" :key="kd.id">
                <td class="px-4 py-2 text-slate-700">{{ kd.type }}</td>
                <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ kd.fileName }}</td>
                <td class="px-4 py-2 text-slate-700">{{ fmtDate(kd.uploadedAt) || '—' }}</td>
                <td class="px-4 py-2">
                  <span v-if="kd.verified" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">ยืนยันแล้ว</span>
                  <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">รอตรวจสอบ</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card p-5" v-if="sortedAssignmentHistory.length" data-testid="customer-assignment-history">
        <h2 class="font-semibold text-slate-900 mb-3">ประวัติการมอบหมาย <span class="text-slate-500 normal-case">({{ sortedAssignmentHistory.length }})</span></h2>
        <div class="overflow-hidden rounded-lg border border-slate-200">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
              <tr>
                <th class="px-4 py-2 text-left">เมื่อ</th>
                <th class="px-4 py-2 text-left">จาก</th>
                <th class="px-4 py-2 text-left">ถึง</th>
                <th class="px-4 py-2 text-left">เหตุผล</th>
                <th class="px-4 py-2 text-left">ผู้ทำรายการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="h in sortedAssignmentHistory" :key="h.id">
                <td class="px-4 py-2 text-slate-700 whitespace-nowrap">{{ fmtDateTime(h.at) }}</td>
                <td class="px-4 py-2 text-xs text-slate-700">{{ agentText(h.fromAgentCode, h.fromAgentName, h.fromAgentId) }}</td>
                <td class="px-4 py-2 text-xs text-slate-900 font-medium">{{ agentText(h.toAgentCode, h.toAgentName, h.toAgentId) }}</td>
                <td class="px-4 py-2 text-slate-700">{{ h.reason || '—' }}</td>
                <td class="px-4 py-2 text-xs text-slate-500">{{ h.byUserName || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">กรมธรรม์ของลูกค้ารายนี้ <span class="text-slate-500 font-normal">({{ policies.length }})</span></h2>
        <div v-if="policiesLoading" class="text-sm text-slate-500">กำลังโหลดกรมธรรม์…</div>
        <div v-else-if="!policies.length" class="text-sm text-slate-500">ไม่พบกรมธรรม์</div>
        <div v-else class="overflow-hidden rounded-lg border border-slate-200">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
              <tr>
                <th class="px-4 py-2 text-left">เลขที่ใบคำขอ</th>
                <th class="px-4 py-2 text-left">เลขกรมธรรม์</th>
                <th class="px-4 py-2 text-left">แบบประกัน / บริษัท</th>
                <th class="px-4 py-2 text-left">ตัวแทน</th>
                <th class="px-4 py-2 text-right">เบี้ย / ปี</th>
                <th class="px-4 py-2 text-left">วันเริ่มคุ้มครอง</th>
                <th class="px-4 py-2 text-left">สถานะ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="p in policies" :key="p.id" class="hover:bg-slate-50">
                <td class="px-4 py-2 font-mono text-xs">
                  <a v-if="p.applicationNo"
                    :href="`/insurehub/policies?open=${p.id}`"
                    target="_blank" rel="noopener"
                    class="text-brand-600 hover:text-brand-800 hover:underline inline-flex items-center gap-1">
                    {{ p.applicationNo }}
                    <i class="pi pi-external-link text-[10px] opacity-60" />
                  </a>
                  <span v-else class="text-slate-400">—</span>
                </td>
                <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ p.policyNo ?? '—' }}</td>
                <td class="px-4 py-2">
                  <router-link v-if="p.productId"
                    :to="{ name: 'products', query: { open: p.productId } }"
                    class="block text-brand-600 hover:text-brand-800 hover:underline truncate max-w-[200px]">
                    {{ p.productName ?? p.productCode }}
                  </router-link>
                  <span v-else class="block text-slate-900 truncate max-w-[200px]">{{ p.productName ?? p.productCode }}</span>
                  <router-link v-if="p.carrierId"
                    :to="{ name: 'carriers', query: { open: p.carrierId } }"
                    class="text-xs text-brand-600 hover:text-brand-800 hover:underline">
                    {{ p.carrierCode }}
                  </router-link>
                  <span v-else class="text-xs text-slate-500">{{ p.carrierCode }}</span>
                </td>
                <td class="px-4 py-2">
                  <router-link v-if="p.agentCode"
                    :to="{ name: 'agents', query: { q: p.agentCode } }"
                    class="text-brand-600 hover:text-brand-800 hover:underline">
                    {{ p.agentCode }}
                  </router-link>
                  <span v-else class="text-slate-400">—</span>
                </td>
                <td class="px-4 py-2 text-right text-slate-900">{{ fmtBaht(p.annualPremium) }}</td>
                <td class="px-4 py-2 text-slate-700">{{ fmtDate(p.effectiveDate) || '—' }}</td>
                <td class="px-4 py-2">
                  <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', statusBadge(p.status)]"
                    :title="p.status">{{ p.statusLabel || p.status }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">บันทึก</h2>
        <div class="text-sm">
          <EditableField v-for="f in NOTES_FIELDS" :key="f.path" entity="customers" :id="customer.id" :field="f.path"
            type="textarea" :value="fieldValue(f)" :placeholder="f.placeholder" :readonly="!editing" :controlled="editing"
            :error-text="fieldErrors[f.path] ?? null" @update="v => setField(f, v)" />
        </div>
      </section>

      <section class="card p-5" data-testid="customer-audit">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-slate-900">{{ t('customers.ui.auditTitle') }} <span class="text-slate-500 font-normal">({{ audit.length }})</span></h2>
          <button type="button" class="text-xs text-slate-500 hover:text-brand-600" @click="loadAudit">
            <i :class="['pi pi-refresh text-[10px]', auditLoading ? 'pi-spin' : '']" />
          </button>
        </div>
        <div v-if="!audit.length" class="text-sm text-slate-500">{{ auditLoading ? 'กำลังโหลด…' : t('customers.ui.auditEmpty') }}</div>
        <ol v-else class="space-y-3">
          <li v-for="a in audit" :key="a.id" class="border-l-2 border-brand-200 pl-3">
            <div class="text-xs text-slate-500 flex items-center gap-2 flex-wrap">
              <span class="font-medium text-slate-700">{{ auditActionLabel(a.action) }}</span>
              <span>·</span>
              <span>{{ fmtDateTime(a.occurredAt) }}</span>
              <span>·</span>
              <span>{{ t('customers.ui.auditBy', { actor: a.actor || '—' }) }}</span>
            </div>
            <ul class="mt-1 space-y-0.5 text-sm">
              <li v-for="r in auditRows(a)" :key="r.key" class="flex flex-wrap items-center gap-1.5">
                <span class="text-slate-500">{{ r.label }}:</span>
                <span class="text-slate-400 line-through decoration-slate-300">{{ r.from || '—' }}</span>
                <i class="pi pi-arrow-right text-[9px] text-slate-400" />
                <span class="text-slate-900">{{ r.to || '—' }}</span>
              </li>
            </ul>
          </li>
        </ol>
      </section>

      <footer class="border-t border-slate-200 pt-4 flex items-center justify-between">
        <div class="text-xs text-slate-400">
          {{ editing ? t('customers.ui.editModeHint') : (canEdit ? t('customers.ui.viewModeHint') : t('customers.ui.noEditPermission')) }}
        </div>
        <button v-if="canDelete && !editing" type="button" data-testid="customer-delete-btn"
          class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 text-sm flex items-center gap-1.5"
          @click="deleteError = null; showDelete = true">
          <i class="pi pi-trash text-xs" /> ลบลูกค้า
        </button>
      </footer>
    </template>

    <div v-if="customer && editing"
      class="fixed bottom-4 right-6 z-40 w-[min(560px,calc(100vw-3rem))] rounded-xl bg-white/95 backdrop-blur border border-brand-200 shadow-lg"
      data-testid="customer-edit-bar">
      <div class="px-5 py-3 flex items-center justify-between gap-3">
        <div class="text-sm text-slate-600">
          <i class="pi pi-pencil text-xs mr-1" />
          <span v-if="d.changes.value.length">แก้ไขแล้ว {{ d.changes.value.length }} รายการ</span>
          <span v-else class="text-slate-400">{{ t('customers.ui.noChanges') }}</span>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" data-testid="customer-cancel-btn"
            class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100"
            @click="cancelEdit">{{ t('customers.ui.cancel') }}</button>
          <button type="button" data-testid="customer-save-btn"
            class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50 flex items-center gap-1.5"
            :disabled="!d.changes.value.length"
            @click="requestSave">
            <i class="pi pi-save text-xs" /> {{ t('customers.ui.save') }}
          </button>
        </div>
      </div>
    </div>

    <ConfirmChangesDialog
      :open="showConfirm"
      :rows="confirmRows"
      :saving="saving"
      :error="saveError"
      :error-list="saveErrorList"
      @confirm="confirmSave"
      @cancel="showConfirm = false"
    />

    <div v-if="showAssign && customer" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4"
      data-testid="change-agent-modal" @click.self="!assignSaving && (showAssign = false)">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg">
        <header class="px-5 pt-5 pb-3 border-b border-slate-100">
          <h3 class="text-lg font-semibold text-slate-900">{{ t('customers.ui.changeAgentTitle') }}</h3>
          <p class="text-xs text-slate-500 mt-0.5">{{ customerDisplayName(customer) }} · {{ customer.customerCode }}</p>
        </header>
        <div class="px-5 py-4 space-y-3 text-sm">
          <div>
            <div class="text-xs text-slate-500">{{ t('customers.ui.currentAgent') }}</div>
            <div class="text-slate-900">
              {{ customer.assignedAgentCode ? `${customer.assignedAgentCode} · ${customer.assignedAgentName ?? ''}` : t('customers.ui.unassigned') }}
            </div>
          </div>
          <div>
            <label class="text-xs font-medium text-slate-600 mb-1 block">{{ t('customers.ui.newAgent') }} <span class="text-rose-500">*</span></label>
            <AgentPicker v-model="assignForm.agentId" :placeholder="t('customers.ui.newAgent')" />
          </div>
          <div>
            <label class="text-xs font-medium text-slate-600 mb-1 block">{{ t('customers.ui.reason') }}</label>
            <input v-model="assignForm.reason" :placeholder="t('customers.ui.reasonPlaceholder')" maxlength="255"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400" />
          </div>
          <p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-2.5">
            <i class="pi pi-info-circle text-[10px] mr-1" />{{ t('customers.ui.changeAgentNote') }}
          </p>
          <p v-if="assignError" class="text-xs text-rose-600">{{ assignError }}</p>
        </div>
        <footer class="px-5 py-3 border-t border-slate-100 flex items-center justify-end gap-2">
          <button type="button" class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100"
            :disabled="assignSaving" @click="showAssign = false">{{ t('customers.ui.cancel') }}</button>
          <button type="button" data-testid="change-agent-save"
            class="px-3 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50 flex items-center gap-1.5"
            :disabled="assignSaving" @click="submitAssign">
            <i v-if="assignSaving" class="pi pi-spin pi-spinner text-xs" />
            {{ t('customers.ui.assignSave') }}
          </button>
        </footer>
      </div>
    </div>

    <DeleteConfirmDialog
      v-if="customer"
      :open="showDelete"
      :label="`ลูกค้า ${customer.customerCode}`"
      :confirm-token="customer.customerCode"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
      @cancel="showDelete = false"
    />
  </div>
</template>
