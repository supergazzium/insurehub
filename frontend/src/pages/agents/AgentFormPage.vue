<script setup lang="ts">
// สร้าง / แก้ไข ตัวแทน — full-page form (mirrors the customer create/edit style).
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormField from '../../components/FormField.vue'
import DateInput from '../../components/DateInput.vue'
import AddressPicker from '../../components/AddressPicker.vue'
import AgentPicker from '../../components/AgentPicker.vue'
import AgentsSubnav from './AgentsSubnav.vue'
import AgentDocUpload from '../../components/AgentDocUpload.vue'
import {
  fetchAgent, createAgentFull, updateAgentFull, fetchRanks,
  fetchLevelProgress, fetchRankPromotions, approveAgent, rejectAgent, setAgentActive,
  fetchAgentNotes, createAgentNote, fetchAgentRelations,
  type RankRow, type LevelProgress, type RankPromotionRow, type AgentNoteRow, type AgentRelations,
} from '../../api/agents'
import { fetchAgentCommissionDetail, type AgentCommissionResponse } from '../../api/mgm'
import { fetchAgentOutstandingDetail } from '../../api/commissionPayouts'
import { levelBadgeClass } from '../../utils/agentLevel'
import { fmtDate } from '../../util/dateFormat'
import { ApiError } from '../../api/client'

const route = useRoute()
const router = useRouter()
const editId = computed(() => (route.name === 'agent-edit' ? String(route.params.id) : null))
const isEdit = computed(() => editId.value !== null)

// AddressPicker (edit mode) emits (fieldKey, value) after it PATCHes agents/{id};
// mirror the value into the local form so displayed state stays in sync.
function applyAddress(field: string, value: string | null): void {
  if (field === 'province') form.province = value ?? ''
  else if (field === 'district') form.district = value ?? ''
  else if (field === 'subDistrict') form.subDistrict = value ?? ''
  else if (field === 'postcode') form.postcode = value ?? ''
}

const loading = ref(false)
const saving = ref(false)
// View-before-edit (#21): in edit mode the form opens read-only; the operator
// must click "แก้ไข" to unlock the data fields. Create mode is always editable.
const editing = ref(false)
const locked = computed(() => isEdit.value && !editing.value)
const error = ref<string | null>(null)
const ranks = ref<RankRow[]>([])
const progress = ref<LevelProgress | null>(null)
const relations = ref<AgentRelations | null>(null)
const commission = ref<AgentCommissionResponse | null>(null)
const PAYOUT_LABEL: Record<string, string> = { DIRECT_COMMISSION: 'ค่าคอมผู้ขาย', REFERRAL_FEE: 'ค่าแนะนำ', MANAGEMENT_DIFFERENTIAL: 'ส่วนต่างบริหาร' }
const PAYOUT_BADGE: Record<string, string> = { DIRECT_COMMISSION: 'bg-sky-50 text-sky-700', REFERRAL_FEE: 'bg-violet-50 text-violet-700', MANAGEMENT_DIFFERENTIAL: 'bg-amber-50 text-amber-700' }
const REL_LEVEL_DOT: Record<string, string> = {
  l1: 'bg-slate-400', l2: 'bg-sky-400', l3: 'bg-violet-400', l4: 'bg-amber-400', l5: 'bg-rose-400',
  l6: 'bg-orange-400', l7: 'bg-emerald-400', l8: 'bg-teal-400', l9: 'bg-indigo-400', l10: 'bg-fuchsia-500',
}
const promotions = ref<RankPromotionRow[]>([])
const notes = ref<AgentNoteRow[]>([])
const newNote = ref('')
const newNoteKind = ref('general')
const noteSaving = ref(false)
const approvalStatus = ref<string>('approved')
// Uploaded-document status (#22 license photos, #24 id-card + book-bank),
// hydrated from AgentResource.photos. The upload slots flip these to true.
const photos = reactive({ licenseLife: false, licenseNonLife: false, idCard: false, bankBook: false })
const busy = ref(false)
const money = (n: number) => n.toLocaleString('th-TH', { maximumFractionDigits: 0 })
// Corporate / VAT support + validation state (from main).
const fieldErrors = ref<Record<string, string>>({})
const currentLevel = ref<number | null>(null)
const originalType = ref<'AG' | 'IN'>('IN')
const original = reactive({ kind: 'individual', hasVat: false, vatMode: '' as string, taxId: '', juristicName: '' })

const form = reactive({
  agentCode: '',
  agentType: 'IN' as 'AG' | 'IN',
  kind: 'individual' as 'individual' | 'corporate',
  firstName: '', lastName: '', firstNameEn: '', lastNameEn: '', nickname: '',
  gender: '' as '' | 'male' | 'female' | 'other',
  email: '', phone: '', lineId: '',
  idCard: '', birthDate: '',
  juristicName: '', taxId: '',
  hasVat: false, vatMode: 'include' as 'include' | 'exclude',
  whtRate: '' as string | number,
  address: '', province: '', district: '', subDistrict: '', postcode: '',
  bankNameText: '', bankAccountNo: '', bankAccountName: '',
  licenseLifeNo: '', licenseLifeExpiry: '',
  licenseNonLifeNo: '', licenseNonLifeExpiry: '',
  parentAgentId: '',
  joinedAt: '',
  notes: '',
  active: true,
})

const taxIdRequired = computed(() => form.kind === 'corporate' || form.hasVat)
const agentNameOrCode = computed(() => (form.kind === 'corporate' && form.juristicName) || `${form.firstName} ${form.lastName}`.trim() || form.agentCode)

function s(k: string, v: unknown): void { (form as Record<string, unknown>)[k] = v == null ? '' : v }

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    if (isEdit.value) {
      const res = await fetchAgent(editId.value!)
      const d = res.data as Record<string, unknown>
      const bank = (d.bank ?? {}) as Record<string, unknown>
      form.agentCode = String(d.agentCode ?? '')
      form.agentType = (d.agentType as 'AG' | 'IN') || 'IN'
      originalType.value = form.agentType
      form.kind = (d.kind as 'individual' | 'corporate') || 'individual'
      s('firstName', d.firstName); s('lastName', d.lastName)
      s('firstNameEn', d.firstNameEn); s('lastNameEn', d.lastNameEn)
      s('nickname', d.nickname); form.gender = (d.gender as '') || ''
      s('email', d.email); s('phone', d.phone); s('lineId', d.lineId)
      s('idCard', d.idCard); s('birthDate', d.birthDate)
      s('juristicName', d.juristicName); s('taxId', d.taxId)
      s('address', d.address); s('province', d.province); s('district', d.district)
      s('subDistrict', d.subDistrict); s('postcode', d.postcode)
      s('bankNameText', bank.bankName ?? d.bankNameText)
      s('bankAccountNo', bank.accountNo ?? d.bankAccountNo)
      s('bankAccountName', bank.accountName ?? d.bankAccountName)
      s('licenseLifeNo', d.licenseLifeNo); s('licenseLifeExpiry', d.licenseLifeExpiry)
      s('licenseNonLifeNo', d.licenseNonLifeNo); s('licenseNonLifeExpiry', d.licenseNonLifeExpiry)
      const ph = (d.photos ?? {}) as Record<string, boolean>
      photos.licenseLife = !!ph.licenseLife
      photos.licenseNonLife = !!ph.licenseNonLife
      photos.idCard = !!ph.idCard
      photos.bankBook = !!ph.bankBook
      s('parentAgentId', d.parentAgentId)
      currentLevel.value = typeof d.rankLevel === 'number' ? d.rankLevel : null
      s('joinedAt', d.joinedAt); s('notes', d.notes)
      form.active = d.active !== false
      approvalStatus.value = (d.approvalStatus as string) ?? 'approved'
      form.hasVat = d.hasVat === true
      form.vatMode = (d.vatMode as 'include' | 'exclude') || 'include'
      form.whtRate = d.whtRate === null || d.whtRate === undefined ? '' : Number(d.whtRate)
      Object.assign(original, {
        kind: form.kind, hasVat: form.hasVat, vatMode: form.hasVat ? form.vatMode : '', taxId: form.taxId, juristicName: form.juristicName,
      })
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally { loading.value = false }
}

const payload = computed<Record<string, unknown>>(() => {
  const p: Record<string, unknown> = {
    agentCode: form.agentCode.trim(),
    agentType: isEdit.value ? form.agentType : 'IN',
    kind: form.kind,
    firstName: form.firstName || null,
    lastName: form.lastName || null,
    firstNameEn: form.firstNameEn || null,
    lastNameEn: form.lastNameEn || null,
    nickname: form.nickname || null,
    gender: form.gender || null,
    email: form.email || null,
    phone: form.phone || null,
    lineId: form.lineId || null,
    idCard: form.idCard || null,
    birthDate: form.birthDate || null,
    juristicName: form.juristicName || null,
    taxId: form.taxId || null,
    hasVat: form.hasVat,
    vatMode: form.hasVat ? form.vatMode : null,
    whtRate: form.whtRate === '' || form.whtRate === null ? null : Number(form.whtRate),
    address: form.address || null,
    province: form.province || null,
    district: form.district || null,
    subDistrict: form.subDistrict || null,
    postcode: form.postcode || null,
    licenseLifeNo: form.licenseLifeNo || null,
    licenseLifeExpiry: form.licenseLifeExpiry || null,
    licenseNonLifeNo: form.licenseNonLifeNo || null,
    licenseNonLifeExpiry: form.licenseNonLifeExpiry || null,
    parentAgentId: form.parentAgentId || null,
    joinedAt: form.joinedAt || null,
    notes: form.notes || null,
    active: form.active,
    bank: { bankName: form.bankNameText || null, accountNo: form.bankAccountNo || null, accountName: form.bankAccountName || null },
  }
  return p
})

const kindLabel = (k: string) => (k === 'corporate' ? 'นิติบุคคล' : 'บุคคลธรรมดา')
const vatText = (has: boolean, mode: string) => (!has ? 'ไม่มี VAT' : mode === 'include' ? 'มี VAT — รวม VAT' : 'มี VAT — ไม่รวม VAT')
const taxChanges = computed(() => {
  if (!isEdit.value) return []
  const out: { label: string; before: string; after: string }[] = []
  if (original.kind !== form.kind) out.push({ label: 'ชนิด', before: kindLabel(original.kind), after: kindLabel(form.kind) })
  const beforeVat = vatText(original.hasVat, original.vatMode)
  const afterVat = vatText(form.hasVat, form.hasVat ? form.vatMode : '')
  if (beforeVat !== afterVat) out.push({ label: 'VAT', before: beforeVat, after: afterVat })
  if ((original.taxId || '') !== (form.taxId || '')) out.push({ label: 'เลขผู้เสียภาษี', before: original.taxId || '—', after: form.taxId || '—' })
  if (form.kind === 'corporate' && (original.juristicName || '') !== (form.juristicName || '')) {
    out.push({ label: 'ชื่อนิติบุคคล', before: original.juristicName || '—', after: form.juristicName || '—' })
  }
  return out
})
const confirmOpen = ref(false)
const outstanding = ref<{ itemCount: number; totalAmount: number } | null>(null)
const money2 = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

function validateLocal(): boolean {
  const errs: Record<string, string> = {}
  if (!form.agentCode.trim()) errs.agentCode = 'กรุณากรอกรหัสตัวแทน'
  if (form.kind === 'corporate' && !form.juristicName.trim()) errs.juristicName = 'กรุณากรอกชื่อนิติบุคคล'
  if (taxIdRequired.value && !form.taxId.trim()) errs.taxId = 'ต้องกรอกเลขประจำตัวผู้เสียภาษีเมื่อเป็นนิติบุคคลหรือมี VAT'
  fieldErrors.value = errs
  return Object.keys(errs).length === 0
}

async function submit(): Promise<void> {
  error.value = null
  if (!validateLocal()) { error.value = Object.values(fieldErrors.value)[0]; return }
  if (isEdit.value && taxChanges.value.length > 0 && !confirmOpen.value) {
    outstanding.value = null
    confirmOpen.value = true
    fetchAgentOutstandingDetail(editId.value!).then((r) => { outstanding.value = r.totals }).catch(() => { outstanding.value = null })
    return
  }
  await save()
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    if (isEdit.value) {
      await updateAgentFull(editId.value!, payload.value)
      confirmOpen.value = false
      router.push({ name: 'agent-detail', params: { id: editId.value! } })
    } else {
      const res = await createAgentFull(payload.value)
      const id = (res.data as Record<string, unknown>).id
      router.push(id ? { name: 'agent-detail', params: { id: String(id) } } : { name: 'agents' })
    }
  } catch (e: unknown) {
    confirmOpen.value = false
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      fieldErrors.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]))
      error.value = Object.values(errs)[0]?.[0] ?? e.message
    } else {
      error.value = 'บันทึกไม่สำเร็จ'
    }
  } finally { saving.value = false }
}
function cancel(): void {
  if (isEdit.value) router.push({ name: 'agent-detail', params: { id: editId.value! } })
  else router.push({ name: 'agents' })
}
// Leave edit mode and discard unsaved changes by re-loading the saved values.
function cancelEdit(): void { editing.value = false; error.value = null; void load() }
function goAgent(id: string): void { router.push({ name: 'agent-detail', params: { id } }) }

// ── Status actions (approve / deactivate) — edit mode only ─────────────────
async function doApprove(): Promise<void> {
  if (!editId.value) return
  busy.value = true
  try { await approveAgent(editId.value); await load() }
  catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'อนุมัติล้มเหลว' }
  finally { busy.value = false }
}
async function doReject(): Promise<void> {
  if (!editId.value) return
  const note = window.prompt('เหตุผลที่ปฏิเสธ:')
  if (note === null || note.trim() === '') return
  busy.value = true
  try { await rejectAgent(editId.value, note.trim()); await load() }
  catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'ปฏิเสธล้มเหลว' }
  finally { busy.value = false }
}
async function doToggleActive(): Promise<void> {
  if (!editId.value) return
  const next = !form.active
  if (!next && !window.confirm('ปิดใช้งานตัวแทนนี้?')) return
  busy.value = true
  try { await setAgentActive(editId.value, next); form.active = next }
  catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'ดำเนินการล้มเหลว' }
  finally { busy.value = false }
}

function statusBadge(st: string): string {
  return st === 'approved' ? 'bg-emerald-50 text-emerald-700' : st === 'rejected' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'
}
function statusLabel(st: string): string {
  return st === 'approved' ? 'อนุมัติแล้ว' : st === 'rejected' ? 'ปฏิเสธ' : 'รออนุมัติ'
}

async function addNote(): Promise<void> {
  if (!editId.value || !newNote.value.trim()) return
  noteSaving.value = true
  try {
    await createAgentNote(editId.value, newNote.value.trim(), newNoteKind.value)
    newNote.value = ''
    newNoteKind.value = 'general'
    const nt = await fetchAgentNotes(editId.value)
    notes.value = nt.data
  } catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'บันทึกโน้ตล้มเหลว' }
  finally { noteSaving.value = false }
}
function noteKindLabel(k: string): string {
  return { general: 'ทั่วไป', call: 'โทรติดตาม', followup: 'ติดตาม', document: 'เอกสาร' }[k] ?? k
}

// Load the read-only detail panels (ranks, level progress, promotions,
// upline/downline relations, commission ledger, notes). Kept separate from
// load() so the form hydration and the detail fetches stay independent.
async function loadDetail(): Promise<void> {
  try {
    const r = await fetchRanks()
    ranks.value = r.data
  } catch { /* ranks dropdown just empty */ }
  if (!isEdit.value || !editId.value) return
  try {
    const [prog, promo] = await Promise.all([
      fetchLevelProgress(editId.value).catch(() => ({ data: null as LevelProgress | null })),
      fetchRankPromotions('all').catch(() => ({ data: [] as RankPromotionRow[], meta: { pendingCount: 0 } })),
    ])
    progress.value = prog.data
    promotions.value = promo.data.filter((x) => x.agentId === editId.value)
    relations.value = await fetchAgentRelations(editId.value).catch(() => null)
    commission.value = form.agentCode ? await fetchAgentCommissionDetail(form.agentCode).catch(() => null) : null
    const nt = await fetchAgentNotes(editId.value).catch(() => ({ data: [] as AgentNoteRow[] }))
    notes.value = nt.data
  } catch { /* detail panels just stay empty */ }
}

onMounted(async () => { await load(); void loadDetail() })
watch(() => route.params.id, () => { if (route.name === 'agent-edit') { void load().then(() => loadDetail()) } })
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-4xl px-4 py-5">
      <button type="button" class="mb-2 text-xs text-slate-500 hover:text-slate-700" @click="cancel">
        <i class="pi pi-arrow-left text-[10px]" /> {{ isEdit ? 'กลับไปหน้าข้อมูลตัวแทน' : 'กลับไปรายชื่อตัวแทน' }}
      </button>
      <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-lg font-semibold text-slate-800">
            {{ isEdit ? (agentNameOrCode || 'แก้ไขข้อมูลตัวแทน') : 'เพิ่มตัวแทนใหม่' }}
          </h1>
          <p v-if="isEdit" class="text-xs text-slate-500">
            {{ form.agentCode }}
            <span :class="['ml-1 rounded px-1.5 py-0.5 text-[10px]', statusBadge(approvalStatus)]">{{ statusLabel(approvalStatus) }}</span>
            <span :class="['ml-1 rounded px-1.5 py-0.5 text-[10px]', form.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500']">{{ form.active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span>
          </p>
        </div>
        <div v-if="isEdit" class="flex shrink-0 gap-2">
          <!-- View mode: a single "แก้ไข" button unlocks the data fields (#21). -->
          <button v-if="!editing" type="button" class="rounded bg-brand-600 px-3 py-1.5 text-xs text-white hover:bg-brand-700" @click="editing = true">
            <i class="pi pi-pencil mr-1 text-[10px]" />แก้ไข
          </button>
          <button v-if="approvalStatus === 'pending'" type="button" class="rounded bg-emerald-600 px-2.5 py-1.5 text-xs text-white disabled:opacity-50" :disabled="busy" @click="doApprove">อนุมัติ</button>
          <button v-if="approvalStatus === 'pending'" type="button" class="rounded border border-rose-300 px-2.5 py-1.5 text-xs text-rose-600 disabled:opacity-50" :disabled="busy" @click="doReject">ปฏิเสธ</button>
          <button type="button"
            :class="['rounded px-2.5 py-1.5 text-xs disabled:opacity-50', form.active ? 'border border-rose-300 text-rose-600 hover:bg-rose-50' : 'border border-emerald-300 text-emerald-600 hover:bg-emerald-50']"
            :disabled="busy" @click="doToggleActive">{{ form.active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}</button>
        </div>
      </div>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <div v-else class="space-y-4">
        <div v-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="agent-form-error">{{ error }}</div>

        <!-- Editable data sections — locked in view mode (#21). The display-only
             relations/commission sections below stay outside so their navigation
             links keep working. -->
        <fieldset :disabled="locked" :class="['m-0 min-w-0 space-y-4 border-0 p-0', locked && 'opacity-70']">

        <!-- ข้อมูลพื้นฐาน -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ข้อมูลพื้นฐาน</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="รหัสตัวแทน *"><input v-model.trim="form.agentCode" :disabled="isEdit" class="ipt" /></FormField>
            <FormField label="ประเภท">
              <!-- New agents are always IN now (#27). AG kept only to display
                   pre-existing AG agents in edit mode, never as a new-agent option. -->
              <select v-if="isEdit && originalType === 'AG'" v-model="form.agentType" class="ipt" data-testid="agent-type">
                <option value="AG">AG (ตัวแทน)</option><option value="IN">IN (นายหน้า)</option>
              </select>
              <div v-else class="ipt bg-slate-50 text-slate-600" data-testid="agent-type">IN (นายหน้า)</div>
              <p v-if="!isEdit" class="mt-1 text-[11px] text-slate-400">ตัวแทนใหม่ทุกคนเป็นประเภท IN</p>
            </FormField>
            <FormField label="ชนิด">
              <select v-model="form.kind" class="ipt" data-testid="agent-kind"><option value="individual">บุคคลธรรมดา</option><option value="corporate">นิติบุคคล</option></select>
            </FormField>
            <template v-if="form.kind === 'individual'">
              <FormField label="ชื่อ"><input v-model.trim="form.firstName" class="ipt" /></FormField>
              <FormField label="นามสกุล"><input v-model.trim="form.lastName" class="ipt" /></FormField>
              <FormField label="ชื่อเล่น"><input v-model.trim="form.nickname" class="ipt" /></FormField>
              <FormField label="ชื่อ (อังกฤษ)"><input v-model.trim="form.firstNameEn" class="ipt" /></FormField>
              <FormField label="นามสกุล (อังกฤษ)"><input v-model.trim="form.lastNameEn" class="ipt" /></FormField>
              <FormField label="เพศ">
                <select v-model="form.gender" class="ipt"><option value="">—</option><option value="male">ชาย</option><option value="female">หญิง</option><option value="other">อื่นๆ</option></select>
              </FormField>
              <FormField label="เลขบัตรประชาชน"><input v-model.trim="form.idCard" class="ipt" /></FormField>
              <FormField label="วันเกิด"><DateInput v-model="form.birthDate" /></FormField>
            </template>
            <template v-else>
              <FormField label="ชื่อนิติบุคคล *" class="col-span-2">
                <input v-model.trim="form.juristicName" :class="['ipt', fieldErrors.juristicName ? 'border-rose-400' : '']" data-testid="agent-juristic-name" />
                <p v-if="fieldErrors.juristicName" class="mt-1 text-[11px] text-rose-600">{{ fieldErrors.juristicName }}</p>
              </FormField>
            </template>
            <FormField :label="taxIdRequired ? 'เลขประจำตัวผู้เสียภาษี *' : 'เลขประจำตัวผู้เสียภาษี'">
              <input v-model.trim="form.taxId" maxlength="13" inputmode="numeric" :class="['ipt font-mono', fieldErrors.taxId ? 'border-rose-400' : '']" data-testid="agent-tax-id" />
              <p v-if="fieldErrors.taxId" class="mt-1 text-[11px] text-rose-600">{{ fieldErrors.taxId }}</p>
              <p v-else-if="taxIdRequired" class="mt-1 text-[11px] text-slate-400">13 หลัก · จำเป็นเมื่อเป็นนิติบุคคลหรือมี VAT</p>
            </FormField>
          </div>
          <div class="mt-3 border-t border-slate-100 pt-3">
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" v-model="form.hasVat" class="h-4 w-4" data-testid="agent-has-vat" /> ตัวแทนจดทะเบียน VAT
            </label>
            <div v-if="form.hasVat" class="mt-2">
              <label class="text-[11px] text-slate-500">จ่ายค่าคอมแบบ</label>
              <div class="ml-2 inline-flex overflow-hidden rounded-lg border border-slate-200">
                <button type="button" data-testid="vat-include" :class="['px-3 py-1 text-xs', form.vatMode === 'include' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']" @click="form.vatMode = 'include'">รวม VAT (Include)</button>
                <button type="button" data-testid="vat-exclude" :class="['px-3 py-1 text-xs', form.vatMode === 'exclude' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']" @click="form.vatMode = 'exclude'">ไม่รวม VAT (Exclude)</button>
              </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-slate-700">
              <label for="agent-wht-rate">อัตราหัก ณ ที่จ่าย</label>
              <input id="agent-wht-rate" v-model="form.whtRate" type="number" min="0" max="15" step="0.01" placeholder="3.00" data-testid="agent-wht-rate"
                class="w-24 rounded-lg border border-slate-200 px-2 py-1 text-right text-sm" />
              <span class="text-xs text-slate-500">% · เว้นว่าง = ค่าเริ่มต้นของหน่วยงาน ({{ form.kind === 'corporate' ? 'นิติบุคคล ยื่น ภ.ง.ด.53' : 'บุคคลธรรมดา ยื่น ภ.ง.ด.3' }})</span>
            </div>
            <p v-if="isEdit" class="mt-2 text-[11px] text-amber-700"><i class="pi pi-info-circle text-[10px]" /> เปลี่ยนชนิด/VAT/เลขผู้เสียภาษี/อัตราหัก ณ ที่จ่าย มีผลกับรอบจ่ายค่าคอมที่สร้างหลังบันทึกเท่านั้น และถูกบันทึกประวัติ</p>
          </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ข้อมูลติดต่อ</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="อีเมล"><input v-model.trim="form.email" type="email" class="ipt" /></FormField>
            <FormField label="โทรศัพท์"><input v-model.trim="form.phone" class="ipt" /></FormField>
            <FormField label="LINE ID"><input v-model.trim="form.lineId" class="ipt" /></FormField>
          </div>
          <div class="mt-3">
            <FormField label="ที่อยู่"><input v-model.trim="form.address" class="ipt" /></FormField>
            <div class="mt-2">
              <AddressPicker
                v-if="isEdit && editId"
                entity="agents" :id="editId"
                :fields="{ province: 'province', district: 'district', subDistrict: 'subDistrict', postcode: 'postcode' }"
                :province="form.province" :district="form.district" :sub-district="form.subDistrict" :postcode="form.postcode"
                @update="(f: string, v: string | null) => applyAddress(f, v)" />
              <div v-else class="grid grid-cols-2 gap-3">
                <FormField label="จังหวัด"><input v-model.trim="form.province" class="ipt" /></FormField>
                <FormField label="อำเภอ/เขต"><input v-model.trim="form.district" class="ipt" /></FormField>
                <FormField label="ตำบล/แขวง"><input v-model.trim="form.subDistrict" class="ipt" /></FormField>
                <FormField label="รหัสไปรษณีย์"><input v-model.trim="form.postcode" class="ipt" /></FormField>
              </div>
            </div>
          </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">บัญชีธนาคาร</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="ธนาคาร"><input v-model.trim="form.bankNameText" class="ipt" /></FormField>
            <FormField label="เลขบัญชี"><input v-model.trim="form.bankAccountNo" class="ipt" /></FormField>
            <FormField label="ชื่อบัญชี"><input v-model.trim="form.bankAccountName" class="ipt" /></FormField>
          </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ใบอนุญาต</h2>
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="rounded-lg border-2 border-sky-100 p-3" data-testid="form-license-life">
              <div class="mb-2 text-xs font-semibold text-sky-800"><i class="pi pi-heart text-[10px]" /> ประกันชีวิต</div>
              <div class="grid grid-cols-2 gap-2">
                <FormField label="เลขใบอนุญาต"><input v-model.trim="form.licenseLifeNo" class="ipt" /></FormField>
                <FormField label="วันหมดอายุ"><DateInput v-model="form.licenseLifeExpiry" /></FormField>
              </div>
            </div>
            <div class="rounded-lg border-2 border-amber-100 p-3" data-testid="form-license-non-life">
              <div class="mb-2 text-xs font-semibold text-amber-800"><i class="pi pi-car text-[10px]" /> ประกันวินาศภัย</div>
              <div class="grid grid-cols-2 gap-2">
                <FormField label="เลขใบอนุญาต"><input v-model.trim="form.licenseNonLifeNo" class="ipt" /></FormField>
                <FormField label="วันหมดอายุ"><DateInput v-model="form.licenseNonLifeExpiry" /></FormField>
              </div>
            </div>
          </div>
          <!-- รูปใบอนุญาต (#22) — ชีวิต / วินาศภัย แยกกันชัดเจน -->
          <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <AgentDocUpload :agent-id="editId" kind="license_life" label="รูปใบอนุญาต (ชีวิต)"
              :uploaded="photos.licenseLife" @uploaded="photos.licenseLife = true" />
            <AgentDocUpload :agent-id="editId" kind="license_non_life" label="รูปใบอนุญาต (วินาศภัย)"
              :uploaded="photos.licenseNonLife" @uploaded="photos.licenseNonLife = true" />
          </div>
        </section>

        <!-- เอกสารยืนยันตัวตน (#24) — บัตรประชาชน + หน้า Book Bank -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-1 text-sm font-semibold text-slate-700">เอกสารยืนยันตัวตน</h2>
          <p class="mb-3 text-xs text-slate-500">ใช้ยืนยันตัวตน และตรวจสอบก่อนจ่ายเงินออกให้ตัวแทน</p>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <AgentDocUpload :agent-id="editId" kind="id_card" label="รูปบัตรประชาชน"
              :uploaded="photos.idCard" @uploaded="photos.idCard = true" />
            <AgentDocUpload :agent-id="editId" kind="bank_book" label="หน้าสมุดบัญชี (Book Bank)"
              :uploaded="photos.bankBook" @uploaded="photos.bankBook = true" />
          </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ต้นสาย &amp; ระดับ</h2>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <FormField label="ระดับ">
              <span :class="['inline-block rounded px-2 py-1 text-sm font-semibold', levelBadgeClass(isEdit ? currentLevel : 1)]" data-testid="agent-level">
                {{ isEdit ? (currentLevel ? `ระดับ ${currentLevel}` : '—') : 'ระดับ 1' }}
              </span>
              <p class="mt-1 text-[11px] text-slate-400">{{ isEdit ? 'ขอปรับระดับได้ที่หน้าข้อมูลตัวแทน' : 'ตัวแทนใหม่เริ่มที่ระดับ 1' }}</p>
            </FormField>
            <FormField label="วันที่เข้าร่วม"><DateInput v-model="form.joinedAt" /></FormField>
            <FormField label="ต้นสาย (Upline)" class="col-span-2 sm:col-span-3">
              <AgentPicker v-model="form.parentAgentId" placeholder="ค้นหาตัวแทนต้นสาย" />
            </FormField>
          </div>
        </section>

        </fieldset>
        <!-- /editable sections (fieldset 1) -->

        <!-- สายงาน — ต้นสาย & ลูกทีม (upline / downline at a glance) -->
        <section v-if="isEdit && relations" class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">สายงาน — ต้นสาย &amp; ลูกทีม</h2>

          <!-- Upline chain: this person → up to the top -->
          <div class="mb-4">
            <div class="mb-1.5 text-xs font-medium text-slate-500">ต้นสาย (ขึ้นไปด้านบน)</div>
            <div v-if="relations.upline.length === 0" class="text-xs text-slate-400">— ไม่มีต้นสาย (เป็นระดับบนสุด) —</div>
            <div v-else class="flex flex-wrap items-center gap-1.5">
              <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white">
                <span :class="['h-2 w-2 rounded-full', REL_LEVEL_DOT[relations.self.level ?? ''] ?? 'bg-white/60']" />
                {{ relations.self.name || relations.self.agentCode }} <span class="opacity-70">{{ relations.self.level }}</span>
              </span>
              <template v-for="u in relations.upline" :key="u.id">
                <i class="pi pi-arrow-up text-[9px] text-slate-300" />
                <button type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-700 hover:border-emerald-300 hover:bg-emerald-50"
                  @click="goAgent(u.id)">
                  <span :class="['h-2 w-2 rounded-full', REL_LEVEL_DOT[u.level ?? ''] ?? 'bg-slate-300']" />
                  {{ u.name || u.agentCode }} <span class="opacity-60">{{ u.level }}</span>
                </button>
              </template>
            </div>
          </div>

          <!-- Direct downline -->
          <div>
            <div class="mb-1.5 text-xs font-medium text-slate-500">ลูกทีมโดยตรง ({{ relations.downline.length }} คน)</div>
            <div v-if="relations.downline.length === 0" class="text-xs text-slate-400">— ยังไม่มีลูกทีม —</div>
            <div v-else class="grid grid-cols-1 gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
              <button v-for="d in relations.downline" :key="d.id" type="button"
                :class="['flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-left text-xs transition hover:border-emerald-300 hover:bg-emerald-50',
                  d.active ? 'border-slate-200 bg-white text-slate-700' : 'border-slate-200 bg-slate-50 text-slate-400']"
                @click="goAgent(d.id)">
                <span :class="['h-2 w-2 shrink-0 rounded-full', REL_LEVEL_DOT[d.level ?? ''] ?? 'bg-slate-300']" />
                <span class="min-w-0 flex-1 truncate">{{ d.name || d.agentCode }}</span>
                <span class="shrink-0 font-mono text-[10px] opacity-60">{{ d.agentCode }}</span>
                <span class="shrink-0 text-[10px] uppercase opacity-50">{{ d.level }}</span>
              </button>
            </div>
          </div>
        </section>

        <!-- ยอดขาย & ค่าคอมมิชชั่น (sales + commission history) -->
        <section v-if="isEdit && commission" class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ยอดขาย &amp; ค่าคอมมิชชั่น</h2>

          <!-- Totals cards -->
          <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="rounded-lg border border-slate-200 p-3">
              <div class="text-[10px] text-slate-500">ค่าคอมผู้ขาย</div>
              <div class="mt-0.5 text-base font-semibold text-sky-700">฿{{ money2(commission.totals.directCommission) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 p-3">
              <div class="text-[10px] text-slate-500">ค่าแนะนำ</div>
              <div class="mt-0.5 text-base font-semibold text-violet-700">฿{{ money2(commission.totals.referralFee) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 p-3">
              <div class="text-[10px] text-slate-500">ส่วนต่างบริหาร</div>
              <div class="mt-0.5 text-base font-semibold text-amber-700">฿{{ money2(commission.totals.managementDifferential) }}</div>
            </div>
            <div class="rounded-lg border border-brand-200 bg-brand-50 p-3">
              <div class="text-[10px] text-brand-600">รวมทั้งหมด</div>
              <div class="mt-0.5 text-base font-semibold text-brand-700">฿{{ money2(commission.totals.grandTotal) }}</div>
            </div>
          </div>

          <!-- Ledger table -->
          <div v-if="commission.ledger.length === 0" class="text-xs text-slate-400">— ยังไม่มีรายการค่าคอม —</div>
          <div v-else class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
              <thead class="text-left text-[10px] uppercase tracking-wide text-slate-400">
                <tr>
                  <th class="py-2 pr-3">วันที่</th>
                  <th class="py-2 pr-3">กรมธรรม์</th>
                  <th class="py-2 pr-3">สินค้า / บริษัท</th>
                  <th class="py-2 pr-3">ประเภท</th>
                  <th class="py-2 pr-3 text-right">เบี้ย × อัตรา</th>
                  <th class="py-2 text-right">ค่าคอม</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="l in commission.ledger" :key="l.id" class="hover:bg-slate-50">
                  <td class="py-2 pr-3 text-xs text-slate-500">{{ l.paymentDate ? fmtDate(l.paymentDate) : (l.createdAt ? fmtDate(l.createdAt) : '—') }}</td>
                  <td class="py-2 pr-3">
                    <button type="button" class="text-slate-700 hover:text-brand-700" @click="router.push({ name: 'policy-edit-draft', params: { id: l.policyId } })">{{ l.policyNo || '—' }}</button>
                    <div v-if="l.sourceAgentCode && l.sourceAgentCode !== form.agentCode" class="text-[10px] text-slate-400">จากการขายของ {{ l.sourceAgentCode }}</div>
                  </td>
                  <td class="py-2 pr-3">
                    <div class="text-xs text-slate-600">{{ l.productTypeNameTh || l.productTypeCode || '—' }}</div>
                    <div class="text-[10px] text-slate-400">{{ l.carrierName || l.carrierCode || '' }}</div>
                  </td>
                  <td class="py-2 pr-3"><span :class="['rounded-full px-2 py-0.5 text-[10px] font-medium', PAYOUT_BADGE[l.payoutType]]">{{ PAYOUT_LABEL[l.payoutType] || l.payoutType }}</span></td>
                  <td class="py-2 pr-3 text-right text-xs text-slate-500">฿{{ money2(l.basePremium) }} × {{ (l.rateApplied * 100).toFixed(2) }}%</td>
                  <td class="py-2 text-right font-medium text-slate-800">฿{{ money2(l.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="mt-2 text-[10px] text-slate-400">ค่าคอมจากการขายเอง + ค่าแนะนำ/ส่วนต่างจากสายงานลูกทีม (คลิกเลขกรมธรรม์เพื่อเปิดกรมธรรม์)</p>
        </section>

        <!-- หมายเหตุ + สถานะ — editable, locked in view mode (#21) -->
        <fieldset :disabled="locked" :class="['m-0 min-w-0 border-0 p-0', locked && 'opacity-70']">
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <FormField label="หมายเหตุ"><textarea v-model="form.notes" rows="2" class="ipt"></textarea></FormField>
          <p v-if="isEdit" class="mt-2 text-[11px] text-slate-400">ปิด/เปิดใช้งานตัวแทนได้ที่หน้าข้อมูลตัวแทน</p>
        </section>
        </fieldset>

        <!-- ── Detail-only (edit mode): level progress + promotion history ─── -->
        <template v-if="isEdit">
          <section v-if="progress && progress.nextLevel !== null" class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-sm font-semibold text-slate-700">ความคืบหน้าสู่ระดับถัดไป</h2>
            <div class="mb-1 flex items-baseline justify-between text-xs">
              <span class="text-slate-600">
                <span class="font-medium">{{ progress.currentRankLabel ?? ('ระดับ ' + progress.currentLevel) }}</span>
                <i class="pi pi-arrow-right mx-1 text-[9px] text-slate-400" />
                <span class="font-medium text-sky-700">{{ progress.nextRankLabel }}</span>
              </span>
              <span class="font-semibold text-sky-700">{{ progress.progressPct }}%</span>
            </div>
            <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
              <div class="h-full rounded-full bg-gradient-to-r from-sky-400 to-sky-600 transition-all" :style="{ width: progress.progressPct + '%' }" />
            </div>
            <div class="mt-1.5 flex items-center justify-between text-[11px] text-slate-500">
              <span>ยอดสะสม 3 เดือน ฿{{ money(progress.currentVolume) }}</span>
              <span>เป้า ฿{{ money(progress.target) }}</span>
            </div>
            <p v-if="progress.remaining > 0" class="mt-1 text-[11px] text-amber-600">เหลืออีก ฿{{ money(progress.remaining) }} เพื่อเลื่อนระดับ</p>
            <p v-if="progress.licenseBlocked" class="mt-1 text-[11px] text-rose-600"><i class="pi pi-exclamation-triangle text-[9px]" /> ระดับนี้ต้องมีใบอนุญาต — ตัวแทนยังไม่มีใบอนุญาต</p>
          </section>

          <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-sm font-semibold text-slate-700">ประวัติการเลื่อนระดับ</h2>
            <ul v-if="promotions.length" class="space-y-1.5">
              <li v-for="p in promotions" :key="p.id" class="flex items-center justify-between gap-2 rounded border border-slate-100 px-2 py-1.5 text-xs">
                <span class="text-slate-700">{{ p.fromRankLabel ?? '—' }} → {{ p.toRankLabel ?? '—' }} <span class="text-[10px] text-slate-400">({{ p.trigger === 'auto' ? 'อัตโนมัติ' : 'กำหนดเอง' }})</span></span>
                <span class="flex items-center gap-2">
                  <span class="text-[10px] text-slate-400">{{ fmtDate(p.decidedAt ?? p.requestedAt) }}</span>
                  <span :class="['rounded px-1.5 py-0.5 text-[10px]', statusBadge(p.status)]">{{ statusLabel(p.status) }}</span>
                </span>
              </li>
            </ul>
            <p v-else class="text-xs text-slate-400">ยังไม่มีประวัติการเลื่อนระดับ</p>
          </section>

          <!-- Note history (follow-ups) -->
          <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-sm font-semibold text-slate-700">บันทึกการติดตาม / โน้ต</h2>
            <div class="mb-3 flex gap-2">
              <select v-model="newNoteKind" class="rounded border border-slate-300 px-2 py-1 text-xs">
                <option value="general">ทั่วไป</option>
                <option value="call">โทรติดตาม</option>
                <option value="followup">ติดตาม</option>
                <option value="document">เอกสาร</option>
              </select>
              <input v-model="newNote" placeholder="เช่น โทรคุยแล้ว ลูกค้าสนใจสมัคร…" class="flex-1 rounded border border-slate-300 px-2 py-1 text-xs" @keyup.enter="addNote" />
              <button type="button" class="rounded bg-brand-600 px-3 py-1 text-xs text-white disabled:opacity-50" :disabled="noteSaving || !newNote.trim()" @click="addNote">เพิ่มโน้ต</button>
            </div>
            <ul v-if="notes.length" class="space-y-1.5">
              <li v-for="n in notes" :key="n.id" class="rounded border border-slate-100 px-2 py-1.5 text-xs">
                <div class="flex items-center justify-between">
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">{{ noteKindLabel(n.kind) }}</span>
                  <span class="text-[10px] text-slate-400">{{ fmtDate(n.createdAt) }}</span>
                </div>
                <div class="mt-0.5 text-slate-700">{{ n.note }}</div>
              </li>
            </ul>
            <p v-else class="text-xs text-slate-400">ยังไม่มีโน้ต</p>
          </section>
        </template>

        <!-- Save/cancel only while editing (create mode is always editing). In
             edit view mode the header "แก้ไข" button is the entry point (#21). -->
        <div v-if="!locked" class="flex justify-end gap-2">
          <button type="button" class="rounded border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50"
            @click="isEdit ? cancelEdit() : cancel()">ยกเลิก</button>
          <button type="button" data-testid="agent-save" class="rounded bg-brand-600 px-4 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-50"
            :disabled="saving" @click="submit">
            <i :class="saving ? 'pi pi-spin pi-spinner' : 'pi pi-check'" class="mr-1 text-xs" />{{ isEdit ? 'บันทึก' : 'สร้างตัวแทน' }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="confirmOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" data-testid="tax-change-dialog" @click.self="confirmOpen = false">
      <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
        <h3 class="text-base font-semibold text-slate-800">ยืนยันเปลี่ยนข้อมูลภาษี/VAT ของ {{ agentNameOrCode }}</h3>
        <table class="mt-3 w-full text-sm">
          <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">รายการ</th><th class="py-1">เดิม</th><th class="py-1">ใหม่</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in taxChanges" :key="c.label">
              <td class="py-1.5 text-slate-500">{{ c.label }}</td>
              <td class="py-1.5 text-slate-500 line-through">{{ c.before }}</td>
              <td class="py-1.5 font-medium text-slate-800">{{ c.after }}</td>
            </tr>
          </tbody>
        </table>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">
          <li>มีผลกับ<b>รอบจ่ายค่าคอมที่สร้างหลังจากนี้</b> — รอบที่สร้างแล้วไม่เปลี่ยน</li>
          <li v-if="outstanding">ค่าคอมที่ยังไม่เข้ารอบจ่าย <b>{{ outstanding.itemCount }} รายการ ฿{{ money2(outstanding.totalAmount) }}</b> จะถูกจ่ายตามข้อมูลใหม่</li>
          <li>ระบบบันทึกค่าเดิม/ใหม่ และผู้แก้ไขไว้ในประวัติ</li>
        </ul>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50" @click="confirmOpen = false">กลับไปแก้ไข</button>
          <button type="button" data-testid="tax-change-confirm" class="rounded bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-50" :disabled="saving" @click="save">ยืนยันและบันทึก</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ipt { @apply w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400; }
</style>
