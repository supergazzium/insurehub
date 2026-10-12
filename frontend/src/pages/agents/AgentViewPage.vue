<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AgentsSubnav from './AgentsSubnav.vue'
import AgentDocSlot from './AgentDocSlot.vue'
import AgentActiveDialog from './AgentActiveDialog.vue'
import { useAuthStore } from '../../stores/auth'
import { levelBadgeClass, levelDotClass, PROMOTION_STATUS, promotionTriggerLabel } from '../../utils/agentLevel'
import {
  fetchAgent, fetchRanks, createRankRequest, fetchLevelProgress, fetchRankPromotions,
  approveAgent, rejectAgent, setAgentActive, fetchAgentNotes, createAgentNote, fetchAgentRelations,
  type RankRow, type LevelProgress, type RankPromotionRow, type AgentNoteRow, type AgentRelations,
  type AgentDocumentPresence,
} from '../../api/agents'
import { fetchAgentAudit, type AuditEntry } from '../../api/adminAgents'
import { fetchAgentCommissionDetail, type AgentCommissionResponse } from '../../api/mgm'
import { fmtDate, fmtDateTime } from '../../util/dateFormat'
import { ApiError } from '../../api/client'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const agentId = computed(() => String(route.params.id))
const canEdit = computed(() => auth.hasPermission('agents.update'))
const canSeeAudit = computed(() => auth.hasPermission('admin.audit_log'))

const loading = ref(true)
const error = ref<string | null>(null)
const flash = ref<string | null>(null)
const agent = ref<Record<string, unknown>>({})
const ranks = ref<RankRow[]>([])
const progress = ref<LevelProgress | null>(null)
const promotions = ref<RankPromotionRow[]>([])
const relations = ref<AgentRelations | null>(null)
const commission = ref<AgentCommissionResponse | null>(null)
const notes = ref<AgentNoteRow[]>([])
const audit = ref<AuditEntry[]>([])

const money = (n: number) => n.toLocaleString('th-TH', { maximumFractionDigits: 0 })
const money2 = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const str = (k: string): string => {
  const v = agent.value[k]
  return v === null || v === undefined ? '' : String(v)
}

const isCorporate = computed(() => str('kind') === 'corporate')
const displayName = computed(() => {
  if (isCorporate.value && str('juristicName')) return str('juristicName')
  return `${str('firstName')} ${str('lastName')}`.trim() || str('agentCode')
})
const approvalStatus = computed(() => str('approvalStatus') || 'approved')
const active = computed(() => agent.value.active !== false)
const currentLevel = computed(() => (typeof agent.value.rankLevel === 'number' ? (agent.value.rankLevel as number) : null))
const licenseValid = computed(() => agent.value.licenseValid === true)
const documents = computed<AgentDocumentPresence>(() => (agent.value.documents as AgentDocumentPresence) ?? {
  id: false, bank: false, licenseLife: false, licenseNonLife: false, profile: false,
})
const bank = computed(() => (agent.value.bank ?? {}) as Record<string, string | null>)
const hasVat = computed(() => agent.value.hasVat === true)
const vatLabel = computed(() => {
  if (!hasVat.value) return 'ไม่มี VAT'
  return str('vatMode') === 'include' ? 'มี VAT — รวม VAT (Include)' : 'มี VAT — ไม่รวม VAT (Exclude)'
})
const vatEffect = computed(() => {
  if (!hasVat.value) return 'จ่ายค่าคอมตามยอด ไม่มีภาษีมูลค่าเพิ่ม'
  return str('vatMode') === 'include'
    ? 'ค่าคอมที่จ่ายรวม VAT 7% อยู่แล้ว (แยกฐานภาษีในใบจ่าย)'
    : 'จ่ายค่าคอม + VAT 7% เพิ่มจากยอด'
})
const fullAddress = computed(() => [str('address'), str('subDistrict'), str('district'), str('province'), str('postcode')].filter(Boolean).join(' '))

function licenseState(expiry: string): { label: string; cls: string } {
  if (!expiry) return { label: 'ไม่มีข้อมูล', cls: 'bg-slate-100 text-slate-500' }
  const days = Math.floor((new Date(expiry + 'T00:00:00').getTime() - new Date(new Date().toDateString()).getTime()) / 86400000)
  if (days < 0) return { label: 'หมดอายุแล้ว', cls: 'bg-rose-50 text-rose-700' }
  if (days <= 60) return { label: `ใกล้หมดอายุ (${days} วัน)`, cls: 'bg-amber-50 text-amber-700' }
  return { label: 'ใช้ได้', cls: 'bg-emerald-50 text-emerald-700' }
}

function mask(v: string, keep = 4): string {
  if (!v) return '—'
  return v.length <= keep ? v : '•'.repeat(v.length - keep) + v.slice(-keep)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [a, r] = await Promise.all([fetchAgent(agentId.value), fetchRanks()])
    agent.value = a.data
    ranks.value = r.data
    const [prog, promo, rel, nt] = await Promise.all([
      fetchLevelProgress(agentId.value).catch(() => ({ data: null as LevelProgress | null })),
      fetchRankPromotions('all', agentId.value).catch(() => ({ data: [] as RankPromotionRow[], meta: { pendingCount: 0 } })),
      fetchAgentRelations(agentId.value).catch(() => null),
      fetchAgentNotes(agentId.value).catch(() => ({ data: [] as AgentNoteRow[] })),
    ])
    progress.value = prog.data
    promotions.value = promo.data
    relations.value = rel
    notes.value = nt.data
    commission.value = str('agentCode') ? await fetchAgentCommissionDetail(str('agentCode')).catch(() => null) : null
    audit.value = canSeeAudit.value ? (await fetchAgentAudit(agentId.value).catch(() => ({ data: [] as AuditEntry[] }))).data : []
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally { loading.value = false }
}

function onDocChanged(data: Record<string, unknown>): void {
  agent.value = { ...agent.value, documents: data.documents }
  if (canSeeAudit.value) void fetchAgentAudit(agentId.value).then((r) => { audit.value = r.data }).catch(() => undefined)
}

const busy = ref(false)
const rejectOpen = ref(false)
const rejectNote = ref('')
async function doApprove(): Promise<void> {
  busy.value = true
  try { await approveAgent(agentId.value); flash.value = 'อนุมัติตัวแทนแล้ว'; await load() }
  catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'อนุมัติล้มเหลว' }
  finally { busy.value = false }
}
async function doReject(): Promise<void> {
  if (!rejectNote.value.trim()) { error.value = 'กรุณาระบุเหตุผลที่ปฏิเสธ'; return }
  busy.value = true
  try { await rejectAgent(agentId.value, rejectNote.value.trim()); rejectOpen.value = false; flash.value = 'ปฏิเสธการสมัครแล้ว'; await load() }
  catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'ปฏิเสธล้มเหลว' }
  finally { busy.value = false }
}

const activeDialog = ref(false)
const activeError = ref<string | null>(null)
async function confirmActive(): Promise<void> {
  busy.value = true
  activeError.value = null
  try {
    await setAgentActive(agentId.value, !active.value)
    activeDialog.value = false
    flash.value = active.value ? 'ปิดใช้งานตัวแทนแล้ว' : 'เปิดใช้งานตัวแทนแล้ว'
    await load()
  } catch (e: unknown) { activeError.value = e instanceof ApiError ? e.message : 'ดำเนินการล้มเหลว' }
  finally { busy.value = false }
}

const pendingRequest = computed(() => promotions.value.find((p) => p.status === 'pending') ?? null)
const requestOpen = ref(false)
const requestForm = reactive({ toLevel: 0, note: '' })
const requestError = ref<string | null>(null)
const requestSaving = ref(false)
const targetRank = computed(() => ranks.value.find((r) => r.level === requestForm.toLevel) ?? null)
const requestLicenseBlocked = computed(() => !!targetRank.value?.licenseRequired && !licenseValid.value)
function openRequest(): void {
  requestForm.toLevel = 0
  requestForm.note = ''
  requestError.value = null
  requestOpen.value = true
}
async function submitRequest(): Promise<void> {
  if (!requestForm.toLevel) { requestError.value = 'เลือกระดับที่ต้องการ'; return }
  if (!requestForm.note.trim()) { requestError.value = 'กรุณาระบุเหตุผลที่ขอปรับระดับ'; return }
  requestSaving.value = true
  requestError.value = null
  try {
    await createRankRequest(agentId.value, requestForm.toLevel, requestForm.note.trim())
    requestOpen.value = false
    flash.value = 'ส่งคำขอปรับระดับแล้ว — ระดับจะเปลี่ยนเมื่อ InsureHub อนุมัติ'
    promotions.value = (await fetchRankPromotions('all', agentId.value)).data
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      requestError.value = Object.values(errs)[0]?.[0] ?? e.message
    } else {
      requestError.value = 'ส่งคำขอไม่สำเร็จ'
    }
  } finally { requestSaving.value = false }
}

const newNote = ref('')
const newNoteKind = ref('general')
const noteSaving = ref(false)
async function addNote(): Promise<void> {
  if (!newNote.value.trim()) return
  noteSaving.value = true
  try {
    await createAgentNote(agentId.value, newNote.value.trim(), newNoteKind.value)
    newNote.value = ''
    newNoteKind.value = 'general'
    notes.value = (await fetchAgentNotes(agentId.value)).data
  } catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'บันทึกโน้ตล้มเหลว' }
  finally { noteSaving.value = false }
}
function noteKindLabel(k: string): string {
  return { general: 'ทั่วไป', call: 'โทรติดตาม', followup: 'ติดตาม', document: 'เอกสาร' }[k] ?? k
}

const PAYOUT_LABEL: Record<string, string> = { DIRECT_COMMISSION: 'ค่าคอมผู้ขาย', REFERRAL_FEE: 'ค่าแนะนำ', MANAGEMENT_DIFFERENTIAL: 'ส่วนต่างบริหาร' }
const PAYOUT_BADGE: Record<string, string> = { DIRECT_COMMISSION: 'bg-sky-50 text-sky-700', REFERRAL_FEE: 'bg-violet-50 text-violet-700', MANAGEMENT_DIFFERENTIAL: 'bg-amber-50 text-amber-700' }

const FIELD_LABEL: Record<string, string> = {
  kind: 'ชนิด', juristic_name: 'ชื่อนิติบุคคล', tax_id: 'เลขผู้เสียภาษี', has_vat: 'VAT', vat_mode: 'รูปแบบ VAT',
  vat_type: 'รหัส VAT', agent_type: 'ประเภท', active: 'สถานะใช้งาน', approval_status: 'สถานะอนุมัติ', rank_id: 'ระดับ (rank_id)',
  id_card: 'เลขบัตรประชาชน', bank_id: 'ธนาคาร', bank_account_no: 'เลขบัญชี', bank_account_name: 'ชื่อบัญชี', bank_name_text: 'ธนาคาร',
  license_number: 'เลขใบอนุญาต', license_life_no: 'เลขใบอนุญาตชีวิต', license_life_expiry: 'วันหมดอายุ (ชีวิต)',
  license_non_life_no: 'เลขใบอนุญาตวินาศ', license_non_life_expiry: 'วันหมดอายุ (วินาศ)',
  id_card_photo_path: 'ไฟล์บัตรประชาชน', bank_book_photo_path: 'ไฟล์ Book Bank',
  license_life_doc_path: 'ไฟล์ใบอนุญาตชีวิต', license_non_life_doc_path: 'ไฟล์ใบอนุญาตวินาศ',
}
const ACTION_LABEL: Record<string, string> = {
  'agent.created': 'สร้างตัวแทน', 'agent.updated': 'แก้ไขข้อมูล', 'agent.approved': 'อนุมัติการสมัคร', 'agent.rejected': 'ปฏิเสธการสมัคร',
  'agent.rank_requested': 'ขอปรับระดับ', 'agent.rank_approved': 'อนุมัติเปลี่ยนระดับ', 'agent.rank_rejected': 'ไม่อนุมัติเปลี่ยนระดับ',
}
function fmtValue(field: string, v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (field === 'kind') return v === 'corporate' ? 'นิติบุคคล' : 'บุคคลธรรมดา'
  if (field === 'has_vat') return v === true || v === 1 || v === '1' ? 'มี VAT' : 'ไม่มี VAT'
  if (field === 'vat_mode') return v === 'include' ? 'รวม VAT' : 'ไม่รวม VAT'
  if (field === 'active') return v === true || v === 1 || v === '1' ? 'เปิด' : 'ปิด'
  return String(v)
}
function auditChanges(a: AuditEntry): { label: string; text: string }[] {
  const m = (a.metadata ?? {}) as { fields?: string[]; old?: Record<string, unknown>; new?: Record<string, unknown> }
  return (m.fields ?? []).map((f) => {
    const hasValues = m.old && f in m.old
    return {
      label: FIELD_LABEL[f] ?? f,
      text: hasValues ? `${fmtValue(f, m.old?.[f])} → ${fmtValue(f, m.new?.[f])}` : 'มีการเปลี่ยนแปลง',
    }
  })
}

function goAgent(id: string): void { router.push({ name: 'agent-detail', params: { id } }) }
function goEdit(): void { router.push({ name: 'agent-edit', params: { id: agentId.value } }) }

onMounted(load)
watch(() => route.params.id, () => { if (route.name === 'agent-detail') void load() })
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-5xl px-4 py-5">
      <button type="button" class="mb-2 text-xs text-slate-500 hover:text-slate-700" @click="router.push({ name: 'agents' })">
        <i class="pi pi-arrow-left text-[10px]" /> กลับไปรายชื่อตัวแทน
      </button>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <template v-else>
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3" data-testid="agent-view-header">
          <div>
            <h1 class="text-lg font-semibold text-slate-800">{{ displayName }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
              <span class="font-mono text-slate-500">{{ str('agentCode') }}</span>
              <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">{{ str('agentType') === 'AG' ? 'AG ตัวแทน' : 'IN นายหน้า' }}</span>
              <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">{{ isCorporate ? 'นิติบุคคล' : 'บุคคลธรรมดา' }}</span>
              <span :class="['rounded px-1.5 py-0.5 text-[10px] font-semibold', levelBadgeClass(currentLevel)]">ระดับ {{ currentLevel ?? '—' }}</span>
              <span :class="['rounded px-1.5 py-0.5 text-[10px]', approvalStatus === 'approved' ? 'bg-emerald-50 text-emerald-700' : approvalStatus === 'rejected' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700']">
                {{ approvalStatus === 'approved' ? 'อนุมัติแล้ว' : approvalStatus === 'rejected' ? 'ปฏิเสธ' : 'รออนุมัติ' }}
              </span>
              <span :class="['rounded px-1.5 py-0.5 text-[10px]', active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-200 text-slate-600']">{{ active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span>
            </div>
          </div>
          <div class="flex shrink-0 flex-wrap gap-2">
            <button v-if="approvalStatus === 'pending'" type="button" class="rounded bg-emerald-600 px-3 py-1.5 text-xs text-white disabled:opacity-50" :disabled="busy" @click="doApprove">อนุมัติการสมัคร</button>
            <button v-if="approvalStatus === 'pending'" type="button" class="rounded border border-rose-300 px-3 py-1.5 text-xs text-rose-600 disabled:opacity-50" :disabled="busy" @click="rejectOpen = !rejectOpen">ปฏิเสธ</button>
            <button v-if="canEdit" type="button" data-testid="agent-toggle-active"
              :class="['rounded px-3 py-1.5 text-xs', active ? 'border border-rose-300 text-rose-600 hover:bg-rose-50' : 'border border-emerald-300 text-emerald-600 hover:bg-emerald-50']"
              @click="activeError = null; activeDialog = true">{{ active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}</button>
            <!-- Jump to the full team tree for this agent. -->
            <RouterLink :to="{ name: 'agents-hierarchy', query: { agent: agentId } }"
              class="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50 inline-flex items-center gap-1">
              <i class="pi pi-sitemap text-[10px]" /> ดูสายงาน
            </RouterLink>
            <button v-if="canEdit" type="button" data-testid="agent-edit" class="rounded bg-brand-600 px-3 py-1.5 text-xs text-white hover:bg-brand-700" @click="goEdit">
              <i class="pi pi-pencil text-[10px]" /> แก้ไขข้อมูล
            </button>
          </div>
        </div>

        <div v-if="rejectOpen" class="mb-4 space-y-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs">
          <label class="block text-rose-800">เหตุผลที่ปฏิเสธการสมัคร (จะแจ้งผู้สมัครทางอีเมล)</label>
          <textarea v-model="rejectNote" rows="2" class="w-full rounded border border-rose-200 bg-white px-2 py-1 text-xs" />
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded border border-slate-300 bg-white px-3 py-1 text-slate-600" @click="rejectOpen = false">ยกเลิก</button>
            <button type="button" class="rounded bg-rose-600 px-3 py-1 text-white disabled:opacity-50" :disabled="busy" @click="doReject">ยืนยันปฏิเสธ</button>
          </div>
        </div>

        <div v-if="flash" class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ flash }}</div>
        <div v-if="error" class="mb-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <section class="rounded-lg border border-slate-200 bg-white p-4" data-testid="agent-view-contact">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">ข้อมูลติดต่อ</h2>
            <dl class="grid grid-cols-3 gap-x-3 gap-y-1.5 text-sm">
              <template v-if="!isCorporate">
                <dt class="text-slate-500">ชื่อ-นามสกุล</dt><dd class="col-span-2 text-slate-800">{{ `${str('firstName')} ${str('lastName')}`.trim() || '—' }}<span v-if="str('nickname')" class="text-slate-400"> ({{ str('nickname') }})</span></dd>
                <dt class="text-slate-500">วันเกิด</dt><dd class="col-span-2 text-slate-800">{{ str('birthDate') ? fmtDate(str('birthDate')) : '—' }}</dd>
              </template>
              <dt class="text-slate-500">โทรศัพท์</dt><dd class="col-span-2 text-slate-800" data-testid="agent-view-phone">{{ str('phone') || '—' }}</dd>
              <dt class="text-slate-500">อีเมล</dt><dd class="col-span-2 text-slate-800">{{ str('email') || '—' }}</dd>
              <dt class="text-slate-500">LINE</dt><dd class="col-span-2 text-slate-800">{{ str('lineId') || '—' }}</dd>
              <dt class="text-slate-500">ที่อยู่</dt><dd class="col-span-2 text-slate-800">{{ fullAddress || '—' }}</dd>
              <dt class="text-slate-500">วันที่เข้าร่วม</dt><dd class="col-span-2 text-slate-800">{{ str('joinedAt') ? fmtDate(str('joinedAt')) : '—' }}</dd>
              <template v-if="str('notes')"><dt class="text-slate-500">หมายเหตุ</dt><dd class="col-span-2 whitespace-pre-line text-slate-800">{{ str('notes') }}</dd></template>
            </dl>
          </section>

          <section class="rounded-lg border border-slate-200 bg-white p-4" data-testid="agent-view-tax">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">ภาษี &amp; VAT</h2>
            <dl class="grid grid-cols-3 gap-x-3 gap-y-1.5 text-sm">
              <dt class="text-slate-500">ชนิด</dt><dd class="col-span-2 text-slate-800">{{ isCorporate ? 'นิติบุคคล' : 'บุคคลธรรมดา' }}</dd>
              <template v-if="isCorporate"><dt class="text-slate-500">ชื่อนิติบุคคล</dt><dd class="col-span-2 text-slate-800">{{ str('juristicName') || '—' }}</dd></template>
              <dt class="text-slate-500">เลขผู้เสียภาษี</dt><dd class="col-span-2 font-mono text-slate-800">{{ str('taxId') || '—' }}</dd>
              <dt class="text-slate-500">VAT</dt>
              <dd class="col-span-2"><span :class="['rounded px-1.5 py-0.5 text-xs', hasVat ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-600']">{{ vatLabel }}</span></dd>
              <dt class="text-slate-500">หัก ณ ที่จ่าย</dt>
              <dd class="col-span-2 text-slate-800" data-testid="agent-wht">
                {{ agent.whtRate === null || agent.whtRate === undefined ? 'ค่าเริ่มต้นของหน่วยงาน' : `${agent.whtRate}%` }}
                <span class="text-xs text-slate-500">· ยื่น {{ isCorporate ? 'ภ.ง.ด.53' : 'ภ.ง.ด.3' }}</span>
              </dd>
            </dl>
            <p class="mt-2 rounded bg-slate-50 px-2 py-1.5 text-[11px] text-slate-500">
              <i class="pi pi-info-circle text-[10px]" /> {{ vatEffect }} · การเปลี่ยนชนิด/VAT มีผลกับรอบจ่ายค่าคอมที่สร้างหลังเปลี่ยนเท่านั้น
            </p>
          </section>

          <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">บัญชีธนาคาร</h2>
            <dl class="grid grid-cols-3 gap-x-3 gap-y-1.5 text-sm">
              <dt class="text-slate-500">ธนาคาร</dt><dd class="col-span-2 text-slate-800">{{ bank.bankName || str('bankNameText') || '—' }}</dd>
              <dt class="text-slate-500">เลขบัญชี</dt><dd class="col-span-2 font-mono text-slate-800">{{ canEdit ? (bank.accountNo || '—') : mask(bank.accountNo || '') }}</dd>
              <dt class="text-slate-500">ชื่อบัญชี</dt><dd class="col-span-2 text-slate-800">{{ bank.accountName || '—' }}</dd>
            </dl>
          </section>

          <section class="rounded-lg border border-slate-200 bg-white p-4" data-testid="agent-view-identity">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">เอกสารยืนยันตัวตน</h2>
            <div class="grid grid-cols-2 gap-2">
              <AgentDocSlot :agent-id="agentId" kind="id" :label="isCorporate ? 'หนังสือรับรอง / บัตรผู้มีอำนาจ' : 'บัตรประชาชน'" :present="documents.id" :can-manage="canEdit" @changed="onDocChanged" />
              <AgentDocSlot :agent-id="agentId" kind="bank" label="หน้า Book Bank" :present="documents.bank" :can-manage="canEdit" @changed="onDocChanged" />
            </div>
          </section>
        </div>

        <h2 class="mb-2 mt-5 text-sm font-semibold text-slate-700">ใบอนุญาต</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <section class="rounded-lg border-2 border-sky-100 bg-white p-4" data-testid="agent-view-license-life">
            <div class="mb-2 flex items-center justify-between">
              <h3 class="text-sm font-semibold text-sky-800"><i class="pi pi-heart text-xs" /> ใบอนุญาตตัวแทน/นายหน้าประกันชีวิต</h3>
              <span :class="['rounded px-1.5 py-0.5 text-[10px]', licenseState(str('licenseLifeExpiry')).cls]">{{ licenseState(str('licenseLifeExpiry')).label }}</span>
            </div>
            <dl class="mb-3 grid grid-cols-3 gap-x-3 gap-y-1 text-sm">
              <dt class="text-slate-500">เลขที่</dt><dd class="col-span-2 font-mono text-slate-800">{{ str('licenseLifeNo') || '—' }}</dd>
              <dt class="text-slate-500">หมดอายุ</dt><dd class="col-span-2 text-slate-800">{{ str('licenseLifeExpiry') ? fmtDate(str('licenseLifeExpiry')) : '—' }}</dd>
            </dl>
            <AgentDocSlot :agent-id="agentId" kind="license_life" label="รูป/ไฟล์ใบอนุญาตชีวิต" :present="documents.licenseLife" :can-manage="canEdit" @changed="onDocChanged" />
          </section>
          <section class="rounded-lg border-2 border-amber-100 bg-white p-4" data-testid="agent-view-license-non-life">
            <div class="mb-2 flex items-center justify-between">
              <h3 class="text-sm font-semibold text-amber-800"><i class="pi pi-car text-xs" /> ใบอนุญาตตัวแทน/นายหน้าประกันวินาศภัย</h3>
              <span :class="['rounded px-1.5 py-0.5 text-[10px]', licenseState(str('licenseNonLifeExpiry')).cls]">{{ licenseState(str('licenseNonLifeExpiry')).label }}</span>
            </div>
            <dl class="mb-3 grid grid-cols-3 gap-x-3 gap-y-1 text-sm">
              <dt class="text-slate-500">เลขที่</dt><dd class="col-span-2 font-mono text-slate-800">{{ str('licenseNonLifeNo') || '—' }}</dd>
              <dt class="text-slate-500">หมดอายุ</dt><dd class="col-span-2 text-slate-800">{{ str('licenseNonLifeExpiry') ? fmtDate(str('licenseNonLifeExpiry')) : '—' }}</dd>
            </dl>
            <AgentDocSlot :agent-id="agentId" kind="license_non_life" label="รูป/ไฟล์ใบอนุญาตวินาศภัย" :present="documents.licenseNonLife" :can-manage="canEdit" @changed="onDocChanged" />
          </section>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
          <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">ระดับ</h2>
            <div class="flex flex-wrap items-center gap-2" data-testid="agent-level">
              <span :class="['rounded px-2 py-1 text-sm font-semibold', levelBadgeClass(currentLevel)]">{{ currentLevel ? `ระดับ ${currentLevel}` : '—' }}</span>
              <button v-if="canEdit && !pendingRequest && !requestOpen" type="button" data-testid="open-rank-request"
                class="rounded border border-indigo-200 px-2 py-1 text-xs text-indigo-700 hover:bg-indigo-50" @click="openRequest">
                <i class="pi pi-sort-alt text-[10px]" /> ขอปรับระดับ
              </button>
            </div>
            <p v-if="pendingRequest" class="mt-1 text-[11px] text-amber-600" data-testid="pending-rank-request">
              <i class="pi pi-clock text-[9px]" /> รออนุมัติ: ระดับ {{ pendingRequest.toLevel }} · {{ promotionTriggerLabel(pendingRequest.trigger, pendingRequest.requestedByName) }}
            </p>
            <div v-if="requestOpen" class="mt-3 space-y-2 rounded-lg border border-indigo-200 bg-indigo-50/40 p-3" data-testid="rank-request-panel">
              <div class="text-xs font-medium text-indigo-900">ขอปรับระดับ — ระดับจะเปลี่ยนเมื่อ InsureHub อนุมัติ</div>
              <select v-model.number="requestForm.toLevel" data-testid="rank-request-level" class="w-full rounded border border-slate-300 bg-white px-2 py-1.5 text-sm">
                <option :value="0" disabled>เลือกระดับ</option>
                <option v-for="r in ranks" :key="r.id" :value="r.level" :disabled="r.level === currentLevel">
                  {{ r.nameTh }} ({{ r.code }}){{ r.licenseRequired ? ' · ต้องมีใบอนุญาต' : '' }}
                </option>
              </select>
              <textarea v-model="requestForm.note" rows="2" maxlength="1000" data-testid="rank-request-note" placeholder="เหตุผล (จำเป็น)"
                class="w-full rounded border border-slate-300 bg-white px-2 py-1.5 text-sm" />
              <p v-if="requestLicenseBlocked" class="text-xs text-rose-600">ระดับ {{ requestForm.toLevel }} ต้องมีใบอนุญาตชีวิตหรือวินาศที่ยังไม่หมดอายุ — ตัวแทนนี้ยังไม่มี</p>
              <p v-if="requestError" class="text-xs text-rose-600">{{ requestError }}</p>
              <div class="flex justify-end gap-2">
                <button type="button" class="rounded border border-slate-300 bg-white px-3 py-1 text-xs text-slate-600" @click="requestOpen = false">ยกเลิก</button>
                <button type="button" data-testid="rank-request-submit" :disabled="requestSaving || requestLicenseBlocked"
                  class="rounded bg-indigo-600 px-3 py-1 text-xs text-white disabled:opacity-50" @click="submitRequest">ส่งคำขอ</button>
              </div>
            </div>
            <div v-if="progress && progress.nextLevel !== null" class="mt-4">
              <div class="mb-1 flex items-baseline justify-between text-xs">
                <span class="text-slate-600">ระดับ {{ progress.currentLevel }} <i class="pi pi-arrow-right mx-1 text-[9px] text-slate-400" /> <span class="text-sky-700">{{ progress.nextRankLabel }}</span></span>
                <span class="font-semibold text-sky-700">{{ progress.progressPct }}%</span>
              </div>
              <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-gradient-to-r from-sky-400 to-sky-600" :style="{ width: progress.progressPct + '%' }" />
              </div>
              <div class="mt-1 flex justify-between text-[11px] text-slate-500">
                <span>ยอดทีมสะสม 3 เดือน ฿{{ money(progress.currentVolume) }}</span>
                <span>{{ progress.target > 0 ? `เป้า ฿${money(progress.target)}` : 'เป้า: มียอดขายใน 3 เดือน' }}</span>
              </div>
            </div>
            <ul v-if="promotions.length" class="mt-4 space-y-1.5" data-testid="rank-history">
              <li v-for="p in promotions" :key="p.id" class="rounded border border-slate-100 px-2 py-1.5 text-xs">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-slate-700">ระดับ {{ p.fromLevel ?? '—' }} → ระดับ {{ p.toLevel ?? '—' }} <span class="text-[10px] text-slate-400">({{ promotionTriggerLabel(p.trigger, p.requestedByName) }})</span></span>
                  <span :class="['rounded px-1.5 py-0.5 text-[10px]', PROMOTION_STATUS[p.status]?.cls ?? 'bg-slate-100 text-slate-500']">{{ PROMOTION_STATUS[p.status]?.label ?? p.status }}</span>
                </div>
                <div v-if="p.requestNote" class="mt-0.5 text-[11px] text-slate-500">เหตุผลที่ขอ: {{ p.requestNote }}</div>
                <div v-if="p.decidedByName && p.status !== 'pending'" class="mt-0.5 text-[11px] text-slate-500">{{ p.status === 'approved' ? 'อนุมัติโดย' : p.status === 'rejected' ? 'ไม่อนุมัติโดย' : 'ปิดโดย' }} {{ p.decidedByName }} · {{ fmtDate(p.decidedAt) }}</div>
                <div v-if="p.status === 'rejected' && p.notes" class="mt-0.5 text-[11px] text-rose-600">เหตุผลที่ไม่อนุมัติ: {{ p.notes }}</div>
              </li>
            </ul>
          </section>

          <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">สายงาน — ต้นสาย &amp; ลูกทีม</h2>
            <template v-if="relations">
              <div class="mb-3">
                <div class="mb-1 text-xs text-slate-500">ต้นสาย (ขึ้นไปด้านบน)</div>
                <div v-if="relations.upline.length === 0" class="text-xs text-slate-400">— ไม่มีต้นสาย —</div>
                <div v-else class="flex flex-wrap items-center gap-1.5">
                  <template v-for="(u, i) in relations.upline" :key="u.id">
                    <i v-if="i > 0" class="pi pi-arrow-up text-[9px] text-slate-300" />
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2 py-1 text-xs text-slate-700 hover:border-emerald-300 hover:bg-emerald-50" @click="goAgent(u.id)">
                      <span :class="['h-2 w-2 rounded-full', levelDotClass(u.level)]" />{{ u.name || u.agentCode }}
                    </button>
                  </template>
                </div>
              </div>
              <div class="mb-1 text-xs text-slate-500">ลูกทีมโดยตรง ({{ relations.downline.length }} คน)</div>
              <div v-if="relations.downline.length === 0" class="text-xs text-slate-400">— ยังไม่มีลูกทีม —</div>
              <div v-else class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                <button v-for="d in relations.downline" :key="d.id" type="button"
                  :class="['flex items-center gap-2 rounded-lg border px-2 py-1 text-left text-xs hover:border-emerald-300 hover:bg-emerald-50', d.active ? 'border-slate-200 text-slate-700' : 'border-slate-200 bg-slate-50 text-slate-400']"
                  @click="goAgent(d.id)">
                  <span :class="['h-2 w-2 shrink-0 rounded-full', levelDotClass(d.level)]" />
                  <span class="min-w-0 flex-1 truncate">{{ d.name || d.agentCode }}</span>
                  <span class="font-mono text-[10px] opacity-60">{{ d.agentCode }}</span>
                </button>
              </div>
            </template>
          </section>
        </div>

        <section v-if="commission" class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ยอดขาย &amp; ค่าคอมมิชชั่น</h2>
          <div class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="rounded-lg border border-slate-200 p-3"><div class="text-[10px] text-slate-500">ค่าคอมผู้ขาย</div><div class="text-base font-semibold text-sky-700">฿{{ money2(commission.totals.directCommission) }}</div></div>
            <div class="rounded-lg border border-slate-200 p-3"><div class="text-[10px] text-slate-500">ค่าแนะนำ</div><div class="text-base font-semibold text-violet-700">฿{{ money2(commission.totals.referralFee) }}</div></div>
            <div class="rounded-lg border border-slate-200 p-3"><div class="text-[10px] text-slate-500">ส่วนต่างบริหาร</div><div class="text-base font-semibold text-amber-700">฿{{ money2(commission.totals.managementDifferential) }}</div></div>
            <div class="rounded-lg border border-brand-200 bg-brand-50 p-3"><div class="text-[10px] text-brand-600">รวมทั้งหมด</div><div class="text-base font-semibold text-brand-700">฿{{ money2(commission.totals.grandTotal) }}</div></div>
          </div>
          <div v-if="commission.ledger.length === 0" class="text-xs text-slate-400">— ยังไม่มีรายการค่าคอม —</div>
          <table v-else class="min-w-full divide-y divide-slate-200 text-sm">
            <tbody class="divide-y divide-slate-100">
              <tr v-for="l in commission.ledger.slice(0, 10)" :key="l.id">
                <td class="py-1.5 pr-3 text-xs text-slate-500">{{ l.paymentDate ? fmtDate(l.paymentDate) : (l.createdAt ? fmtDate(l.createdAt) : '—') }}</td>
                <td class="py-1.5 pr-3 text-xs text-slate-700">{{ l.policyNo || '—' }}</td>
                <td class="py-1.5 pr-3"><span :class="['rounded-full px-2 py-0.5 text-[10px]', PAYOUT_BADGE[l.payoutType]]">{{ PAYOUT_LABEL[l.payoutType] || l.payoutType }}</span></td>
                <td class="py-1.5 text-right text-slate-800">฿{{ money2(l.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
          <section class="rounded-lg border border-slate-200 bg-white p-4" data-testid="agent-view-notes">
            <h2 class="mb-2 text-sm font-semibold text-slate-700">บันทึกการติดตาม / โน้ต</h2>
            <div class="mb-3 flex gap-2">
              <select v-model="newNoteKind" class="rounded border border-slate-300 px-2 py-1 text-xs">
                <option value="general">ทั่วไป</option>
                <option value="call">โทรติดตาม</option>
                <option value="followup">ติดตาม</option>
                <option value="document">เอกสาร</option>
              </select>
              <input v-model="newNote" data-testid="note-input" placeholder="เช่น โทรคุยแล้ว สนใจสมัคร…" class="min-w-0 flex-1 rounded border border-slate-300 px-2 py-1 text-xs" @keyup.enter="addNote" />
              <button type="button" data-testid="note-add" class="rounded bg-brand-600 px-3 py-1 text-xs text-white disabled:opacity-50" :disabled="noteSaving || !newNote.trim()" @click="addNote">เพิ่ม</button>
            </div>
            <ul v-if="notes.length" class="space-y-1.5">
              <li v-for="n in notes" :key="n.id" class="rounded border border-slate-100 px-2 py-1.5 text-xs">
                <div class="flex items-center justify-between gap-2">
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">{{ noteKindLabel(n.kind) }}</span>
                  <span class="text-[10px] text-slate-400">{{ n.createdByName ? `${n.createdByName} · ` : '' }}{{ fmtDateTime(n.createdAt) }}</span>
                </div>
                <div class="mt-0.5 text-slate-700">{{ n.note }}</div>
              </li>
            </ul>
            <p v-else class="text-xs text-slate-400">ยังไม่มีโน้ต</p>
          </section>

          <section v-if="canSeeAudit" class="rounded-lg border border-slate-200 bg-white p-4" data-testid="agent-view-history">
            <h2 class="mb-2 text-sm font-semibold text-slate-700">ประวัติการแก้ไข</h2>
            <ul v-if="audit.length" class="max-h-96 space-y-1.5 overflow-y-auto">
              <li v-for="a in audit" :key="a.id" class="rounded border border-slate-100 px-2 py-1.5 text-xs">
                <div class="flex items-center justify-between gap-2">
                  <span class="font-medium text-slate-700">{{ ACTION_LABEL[a.action] ?? a.action }}</span>
                  <span class="text-[10px] text-slate-400">{{ a.actor }} · {{ fmtDateTime(a.occurredAt) }}</span>
                </div>
                <ul v-if="a.action === 'agent.updated'" class="mt-0.5 space-y-0.5">
                  <li v-for="(c, i) in auditChanges(a)" :key="i" class="text-[11px] text-slate-500"><span class="text-slate-600">{{ c.label }}:</span> {{ c.text }}</li>
                </ul>
              </li>
            </ul>
            <p v-else class="text-xs text-slate-400">ยังไม่มีประวัติ</p>
          </section>
        </div>
      </template>
    </div>

    <AgentActiveDialog :open="activeDialog" :agent-name="displayName" :active="active" :busy="busy" :error="activeError"
      @cancel="activeDialog = false" @confirm="confirmActive" />
  </div>
</template>
