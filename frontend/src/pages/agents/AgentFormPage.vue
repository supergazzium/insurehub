<script setup lang="ts">
// สร้าง / แก้ไข ตัวแทน — full-page form (mirrors the customer create/edit style).
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormField from '../../components/FormField.vue'
import DateInput from '../../components/DateInput.vue'
import AddressPicker from '../../components/AddressPicker.vue'
import AgentPicker from '../../components/AgentPicker.vue'
import SearchSelect, { type SearchOption } from '../../components/SearchSelect.vue'
import AgentsSubnav from './AgentsSubnav.vue'
import {
  fetchAgent, createAgentFull, updateAgentFull, fetchTeams, fetchRanks,
  fetchLevelProgress, fetchRankPromotions, approveAgent, rejectAgent, setAgentActive,
  fetchAgentNotes, createAgentNote,
  type TeamRow, type RankRow, type LevelProgress, type RankPromotionRow, type AgentNoteRow,
} from '../../api/agents'
import { fmtDate } from '../../util/dateFormat'
import { ApiError } from '../../api/client'

const route = useRoute()
const router = useRouter()
const editId = computed(() => (route.name === 'agent-detail' || route.name === 'agent-edit-info' ? String(route.params.id) : null))
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
const error = ref<string | null>(null)
const teams = ref<TeamRow[]>([])
const ranks = ref<RankRow[]>([])
const progress = ref<LevelProgress | null>(null)
const promotions = ref<RankPromotionRow[]>([])
const notes = ref<AgentNoteRow[]>([])
const newNote = ref('')
const newNoteKind = ref('general')
const noteSaving = ref(false)
const approvalStatus = ref<string>('approved')
const busy = ref(false)
const money = (n: number) => n.toLocaleString('th-TH', { maximumFractionDigits: 0 })
const agentNameOrCode = computed(() => `${form.firstName} ${form.lastName}`.trim() || form.agentCode)

const form = reactive({
  agentCode: '',
  agentType: 'AG' as 'AG' | 'IN',
  kind: 'individual' as 'individual' | 'corporate',
  firstName: '', lastName: '', firstNameEn: '', lastNameEn: '', nickname: '',
  gender: '' as '' | 'male' | 'female' | 'other',
  email: '', phone: '', lineId: '',
  idCard: '', birthDate: '',
  juristicName: '', taxId: '',
  hasVat: false, vatMode: 'include' as 'include' | 'exclude',
  address: '', province: '', district: '', subDistrict: '', postcode: '',
  bankNameText: '', bankAccountNo: '', bankAccountName: '',
  licenseLifeNo: '', licenseLifeExpiry: '',
  licenseNonLifeNo: '', licenseNonLifeExpiry: '',
  teamId: '', parentAgentId: '', level: '',
  joinedAt: '',
  notes: '',
  active: true,
})

const teamOptions = computed<SearchOption[]>(() => [
  { value: '', label: '— ไม่มีสายงาน —' },
  ...teams.value.map((t) => ({ value: t.id, label: `${t.code} · ${t.memberCount} คน` })),
])
const levelOptions = computed<SearchOption[]>(() => [
  { value: '', label: '— ไม่กำหนด —' },
  ...ranks.value.map((r) => ({ value: r.levelKey, label: `${r.nameTh} (${r.code})` })),
])

function s(k: string, v: unknown): void { (form as Record<string, unknown>)[k] = v == null ? '' : v }

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [t, r] = await Promise.all([fetchTeams(), fetchRanks()])
    teams.value = t.data; ranks.value = r.data
    if (isEdit.value) {
      const res = await fetchAgent(editId.value!)
      const d = res.data as Record<string, unknown>
      const bank = (d.bank ?? {}) as Record<string, unknown>
      form.agentCode = String(d.agentCode ?? '')
      form.agentType = (d.agentType as 'AG' | 'IN') || 'AG'
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
      s('teamId', d.teamId); s('parentAgentId', d.parentAgentId); s('level', d.level)
      s('joinedAt', d.joinedAt); s('notes', d.notes)
      form.active = d.active !== false
      approvalStatus.value = (d.approvalStatus as string) ?? 'approved'
      form.hasVat = d.hasVat === true
      form.vatMode = (d.vatMode as 'include' | 'exclude') || 'include'
      // Detail extras — progress + promotion history (best-effort).
      const [prog, promo] = await Promise.all([
        fetchLevelProgress(editId.value!).catch(() => ({ data: null as LevelProgress | null })),
        fetchRankPromotions('all').catch(() => ({ data: [] as RankPromotionRow[], meta: { pendingCount: 0 } })),
      ])
      progress.value = prog.data
      promotions.value = promo.data.filter((x) => x.agentId === editId.value)
      const nt = await fetchAgentNotes(editId.value!).catch(() => ({ data: [] as AgentNoteRow[] }))
      notes.value = nt.data
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally { loading.value = false }
}

const payload = computed<Record<string, unknown>>(() => {
  const p: Record<string, unknown> = {
    agentCode: form.agentCode.trim(),
    agentType: form.agentType,
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

async function submit(): Promise<void> {
  if (!form.agentCode.trim()) { error.value = 'กรุณากรอกรหัสตัวแทน'; return }
  saving.value = true
  error.value = null
  try {
    if (isEdit.value) {
      await updateAgentFull(editId.value!, payload.value)
      // team + level go through the hierarchy endpoint separately (kept in sync).
      router.push({ name: 'agents' })
    } else {
      const res = await createAgentFull(payload.value)
      const id = (res.data as Record<string, unknown>).id
      // Apply team/level via the hierarchy endpoint after creation if set.
      router.push(id ? { name: 'agent-detail', params: { id: String(id) } } : { name: 'agents' })
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
  } finally { saving.value = false }
}
function cancel(): void { router.push({ name: 'agents' }) }

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

onMounted(load)
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-4xl px-4 py-5">
      <button type="button" class="mb-2 text-xs text-slate-500 hover:text-slate-700" @click="cancel">
        <i class="pi pi-arrow-left text-[10px]" /> กลับไปรายชื่อตัวแทน
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
          <button v-if="approvalStatus === 'pending'" type="button" class="rounded bg-emerald-600 px-2.5 py-1.5 text-xs text-white disabled:opacity-50" :disabled="busy" @click="doApprove">อนุมัติ</button>
          <button v-if="approvalStatus === 'pending'" type="button" class="rounded border border-rose-300 px-2.5 py-1.5 text-xs text-rose-600 disabled:opacity-50" :disabled="busy" @click="doReject">ปฏิเสธ</button>
          <button type="button"
            :class="['rounded px-2.5 py-1.5 text-xs disabled:opacity-50', form.active ? 'border border-rose-300 text-rose-600 hover:bg-rose-50' : 'border border-emerald-300 text-emerald-600 hover:bg-emerald-50']"
            :disabled="busy" @click="doToggleActive">{{ form.active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}</button>
        </div>
      </div>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <div v-else class="space-y-4">
        <div v-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</div>

        <!-- ข้อมูลพื้นฐาน -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ข้อมูลพื้นฐาน</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="รหัสตัวแทน *"><input v-model.trim="form.agentCode" :disabled="isEdit" class="ipt" /></FormField>
            <FormField label="ประเภท">
              <select v-model="form.agentType" class="ipt"><option value="AG">AG (ตัวแทน)</option><option value="IN">IN (นายหน้า)</option></select>
            </FormField>
            <FormField label="ชนิด">
              <select v-model="form.kind" class="ipt"><option value="individual">บุคคลธรรมดา</option><option value="corporate">นิติบุคคล</option></select>
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
              <FormField label="ชื่อนิติบุคคล" class="col-span-2"><input v-model.trim="form.juristicName" class="ipt" /></FormField>
              <FormField label="เลขประจำตัวผู้เสียภาษี"><input v-model.trim="form.taxId" class="ipt" /></FormField>
            </template>
          </div>
          <!-- VAT -->
          <div class="mt-3 border-t border-slate-100 pt-3">
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" v-model="form.hasVat" class="h-4 w-4" /> ตัวแทนมี VAT
            </label>
            <div v-if="form.hasVat" class="mt-2">
              <label class="text-[11px] text-slate-500">รูปแบบ VAT</label>
              <div class="inline-flex overflow-hidden rounded-lg border border-slate-200 ml-2">
                <button type="button" :class="['px-3 py-1 text-xs', form.vatMode === 'include' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']" @click="form.vatMode = 'include'">รวม VAT (Include)</button>
                <button type="button" :class="['px-3 py-1 text-xs', form.vatMode === 'exclude' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']" @click="form.vatMode = 'exclude'">ไม่รวม VAT (Exclude)</button>
              </div>
            </div>
          </div>
        </section>

        <!-- ติดต่อ -->
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
              <!-- Edit mode: AddressPicker auto-PATCHes agents/{id} and emits the
                   change back into the form. Create mode has no id yet, so plain
                   inputs collect the fields and they save with the rest on submit. -->
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

        <!-- ธนาคาร -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">บัญชีธนาคาร</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <FormField label="ธนาคาร"><input v-model.trim="form.bankNameText" class="ipt" /></FormField>
            <FormField label="เลขบัญชี"><input v-model.trim="form.bankAccountNo" class="ipt" /></FormField>
            <FormField label="ชื่อบัญชี"><input v-model.trim="form.bankAccountName" class="ipt" /></FormField>
          </div>
        </section>

        <!-- ใบอนุญาต -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ใบอนุญาต</h2>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <FormField label="เลขใบอนุญาตชีวิต"><input v-model.trim="form.licenseLifeNo" class="ipt" /></FormField>
            <FormField label="วันหมดอายุ (ชีวิต)"><DateInput v-model="form.licenseLifeExpiry" /></FormField>
            <FormField label="เลขใบอนุญาตวินาศภัย"><input v-model.trim="form.licenseNonLifeNo" class="ipt" /></FormField>
            <FormField label="วันหมดอายุ (วินาศภัย)"><DateInput v-model="form.licenseNonLifeExpiry" /></FormField>
          </div>
        </section>

        <!-- สายงาน & ระดับ -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">สายงาน &amp; ระดับ</h2>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <FormField label="สายงาน (ทีม)"><SearchSelect v-model="form.teamId" :options="teamOptions" placeholder="เลือกทีม" /></FormField>
            <FormField label="ระดับ"><SearchSelect v-model="form.level" :options="levelOptions" placeholder="เลือกระดับ" /></FormField>
            <FormField label="วันที่เข้าร่วม"><DateInput v-model="form.joinedAt" /></FormField>
            <FormField label="ต้นสาย (Upline)" class="col-span-2 sm:col-span-3">
              <AgentPicker v-model="form.parentAgentId" placeholder="ค้นหาตัวแทนต้นสาย" />
            </FormField>
          </div>
          <p v-if="!isEdit && (form.teamId || form.level)" class="mt-1 text-[10px] text-amber-600">
            <i class="pi pi-info-circle text-[9px]" /> สายงาน/ระดับ จะตั้งค่าในหน้ารายละเอียดหลังสร้างตัวแทน
          </p>
        </section>

        <!-- หมายเหตุ + สถานะ -->
        <section class="rounded-lg border border-slate-200 bg-white p-4">
          <FormField label="หมายเหตุ"><textarea v-model="form.notes" rows="2" class="ipt"></textarea></FormField>
          <label class="mt-2 flex items-center gap-2 text-xs text-slate-600">
            <input type="checkbox" v-model="form.active" class="h-4 w-4" /> เปิดใช้งาน (active)
          </label>
        </section>

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

        <div class="flex justify-end gap-2">
          <button type="button" class="rounded border border-slate-300 px-4 py-1.5 text-sm text-slate-600 hover:bg-slate-50" @click="cancel">ยกเลิก</button>
          <button type="button" class="rounded bg-brand-600 px-4 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-50"
            :disabled="saving" @click="submit">
            <i :class="saving ? 'pi pi-spin pi-spinner' : 'pi pi-check'" class="mr-1 text-xs" />{{ isEdit ? 'บันทึก' : 'สร้างตัวแทน' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ipt { @apply w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400; }
</style>
