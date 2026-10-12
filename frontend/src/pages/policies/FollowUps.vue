<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { openInNewTab } from '../../utils/openInNewTab'
import {
  fetchFollowUps, updateFreelookDocs,
  type AgingBucket, type FollowUpCategory, type FollowUpMeta, type FollowUpRow, type VerifyRow,
} from '../../api/followups'
import { fetchVerifications } from '../../api/verifications'
import { ApiError } from '../../api/client'
import { fmtDate, fmtDateTime } from '../../util/dateFormat'
import CancellationReviewPanel from './CancellationReviewPanel.vue'

const route = useRoute()
const router = useRouter()

const rows = ref<Array<FollowUpRow | VerifyRow>>([])
const meta = ref<FollowUpMeta | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const flash = ref<string | null>(null)
const category = ref<FollowUpCategory>('approval')
const aging = ref<AgingBucket | ''>('')
const stage = ref('')
const reviewed = ref(false)
const q = ref('')
const reviewTarget = ref<FollowUpRow | null>(null)
const busy = ref<string | null>(null)
const verifyScope = ref<'pending' | 'mine'>('pending')

interface CatDef {
  key: FollowUpCategory
  label: string
  hint: string
  icon: string
}
const CATEGORIES: CatDef[] = [
  { key: 'approval', label: 'สถานะรออนุมัติ', hint: 'ส่งบริษัทแล้ว รอบริษัทอนุมัติ', icon: 'pi-hourglass' },
  { key: 'no_policy_no', label: 'ยังไม่บันทึกเลขกรมธรรม์', hint: 'อนุมัติ/มีผลแล้วแต่ยังไม่มีเลขกรมธรรม์', icon: 'pi-id-card' },
  { key: 'not_delivered', label: 'ยังไม่จัดส่ง', hint: 'ยังไม่มีวันจัดส่งกรมธรรม์', icon: 'pi-send' },
  { key: 'freelook', label: 'ติดตามใบ Freelook', hint: 'ส่ง → ลูกค้าเซ็น → ส่งคืนบริษัท (ประกันชีวิต)', icon: 'pi-clock' },
  { key: 'unpaid', label: 'ยังไม่ชำระเงิน', hint: 'มีงวดถึงกำหนดแต่ยังไม่ชำระ', icon: 'pi-money-bill' },
  { key: 'no_commission', label: 'ยังไม่บันทึกค่าคอม', hint: 'ค่าคอม บ.ประกัน→ฮับ หรือ ฮับ→ตัวแทน ยังว่าง', icon: 'pi-wallet' },
  { key: 'cancelled', label: 'ตรวจสอบรายการยกเลิก', hint: 'กรมธรรม์ยกเลิกที่ยังไม่ได้ตรวจ', icon: 'pi-times-circle' },
  { key: 'verify', label: 'รอ Verify', hint: 'คำขอสร้าง/แก้/ลบกรมธรรม์รอคนที่ 2 ตรวจ', icon: 'pi-verified' },
]
const visibleCategories = computed(() => CATEGORIES.filter((c) => c.key !== 'verify' || meta.value?.verifyEnabled || (meta.value?.counts.verify ?? 0) > 0))

const AGING: Array<{ key: AgingBucket; label: string; cls: string }> = [
  { key: '0-7', label: '0-7 วัน', cls: 'border-emerald-300 text-emerald-700' },
  { key: '8-30', label: '8-30 วัน', cls: 'border-amber-300 text-amber-700' },
  { key: '30+', label: 'เกิน 30 วัน', cls: 'border-rose-300 text-rose-700' },
]
const STAGES: Record<string, Array<{ key: string; label: string }>> = {
  not_delivered: [{ key: '', label: 'ยังไม่ส่ง' }, { key: 'in_transit', label: 'ส่งแล้ว ลูกค้ายังไม่ได้รับ' }],
  freelook: [
    { key: '', label: 'ทั้งหมดที่ยังไม่ปิด' }, { key: 'not_sent', label: 'ยังไม่ส่งเอกสาร' },
    { key: 'awaiting_return', label: 'ส่งแล้ว ยังไม่ได้คืน' }, { key: 'near_end', label: 'ใกล้ครบ Freelook (≤7 วัน)' },
    { key: 'no_delivery_date', label: 'ยังไม่มีวันลูกค้าได้รับ' },
  ],
}
const stageCountKey = (k: string) => (k === '' ? 'all' : k)

const ALSO_LABEL: Record<string, string> = {
  approval: 'รออนุมัติ', no_policy_no: 'ไม่มีเลขกรมธรรม์', not_delivered: 'ยังไม่จัดส่ง', freelook: 'Freelook',
  unpaid: 'ค้างชำระ', no_commission: 'ไม่มีค่าคอม', cancelled: 'ยกเลิก',
}
const STATUS_LABEL: Record<string, string> = {
  submitted: 'รออนุมัติ', approved: 'อนุมัติแล้ว', active: 'มีผลบังคับ', issued: 'ออกกรมธรรม์แล้ว',
  cancelled: 'ยกเลิก', draft: 'ร่าง', quotation: 'ใบเสนอราคา', expired: 'หมดอายุ',
}
const CHANNEL_LABEL: Record<string, string> = { face_to_face: 'ขายตรง', telephone: 'โทรศัพท์', online: 'ออนไลน์' }
const VERIFY_STATUS: Record<string, { label: string; cls: string }> = {
  pending: { label: 'รอตรวจ', cls: 'bg-amber-50 text-amber-700' }, approved: { label: 'อนุมัติแล้ว', cls: 'bg-emerald-50 text-emerald-700' },
  rejected: { label: 'ไม่อนุมัติ', cls: 'bg-rose-50 text-rose-700' }, cancelled: { label: 'ถอนแล้ว', cls: 'bg-slate-100 text-slate-500' },
}
const CHECK_CLS: Record<string, string> = { done: 'bg-emerald-50 text-emerald-700', na: 'bg-slate-100 text-slate-500', pending: 'bg-amber-50 text-amber-700' }
const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const policyRows = computed(() => rows.value as FollowUpRow[])
const verifyRows = computed(() => rows.value as VerifyRow[])

function agingCls(d: number | null | undefined): string {
  if (d === null || d === undefined) return 'text-slate-400'
  if (d > 30) return 'bg-rose-50 text-rose-700'
  if (d > 7) return 'bg-amber-50 text-amber-700'
  return 'bg-emerald-50 text-emerald-700'
}

let debounce: number | undefined
async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchFollowUps({
      category: category.value, q: q.value || undefined, aging: aging.value || undefined,
      stage: stage.value || undefined, reviewed: category.value === 'cancelled' ? reviewed.value : undefined,
    })
    meta.value = res.meta
    rows.value = category.value === 'verify' && verifyScope.value === 'mine'
      ? (await fetchVerifications('mine')).data
      : res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
function syncUrl(): void {
  void router.replace({ query: { ...route.query, cat: category.value, aging: aging.value || undefined, stage: stage.value || undefined } })
}
function pick(c: FollowUpCategory): void {
  category.value = c
  aging.value = ''
  stage.value = ''
  reviewed.value = false
  verifyScope.value = 'pending'
  syncUrl()
  void load()
}
function setAging(a: AgingBucket): void { aging.value = aging.value === a ? '' : a; syncUrl(); void load() }
function setStage(s: string): void { stage.value = s; syncUrl(); void load() }
function reload(): void { window.clearTimeout(debounce); debounce = window.setTimeout(load, 250) }

// เปิดแถวในแท็บใหม่ — พนักงานกดเปิดกรมธรรม์ไปเคลียร์ทีละรายการได้ โดยไม่เสีย
// รายการ follow-ups ที่กำลังไล่อยู่ (กลับมาแถวเดิมต่อได้ทันที). router.resolve
// ให้ URL เต็ม (รวม BASE_URL /insurehub) แล้ว window.open แท็บใหม่.
function rowTarget(r: FollowUpRow | VerifyRow): { name: string; params: Record<string, string> } {
  return category.value === 'verify'
    ? { name: 'policy-verification', params: { id: (r as VerifyRow).id } }
    : { name: 'policy-edit-draft', params: { id: r.policyId } }
}
function openRow(r: FollowUpRow | VerifyRow): void {
  openInNewTab(router, rowTarget(r))
}

async function markFreelook(r: FollowUpRow, field: 'sentAt' | 'signedAt' | 'returnedAt'): Promise<void> {
  busy.value = `${r.policyId}:${field}`
  try {
    await updateFreelookDocs(r.policyId, { [field]: new Date().toLocaleDateString('sv-SE') })
    flash.value = 'บันทึกวันที่แล้ว'
    await load()
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      error.value = Object.values(errs)[0]?.[0] ?? e.message
    } else {
      error.value = 'บันทึกไม่สำเร็จ'
    }
  } finally {
    busy.value = null
    window.setTimeout(() => { flash.value = null }, 2500)
  }
}

watch(() => route.query.cat, (v) => {
  if (typeof v === 'string' && v !== category.value && CATEGORIES.some((c) => c.key === v)) {
    category.value = v as FollowUpCategory
    void load()
  }
})

onMounted(() => {
  const c = route.query.cat
  if (typeof c === 'string' && CATEGORIES.some((x) => x.key === c)) category.value = c as FollowUpCategory
  if (typeof route.query.aging === 'string') aging.value = route.query.aging as AgingBucket
  if (typeof route.query.stage === 'string') stage.value = route.query.stage
  void load()
})
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-slate-800">ติดตามงานค้าง</h1>
      <p class="mt-1 text-sm text-slate-500">รายการงานที่ต้องติดตามและดำเนินการต่อ แยกตามประเภท · เรียงงานที่ค้างนานที่สุดก่อน</p>
    </header>

    <div class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4" data-testid="followup-chips">
      <button v-for="c in visibleCategories" :key="c.key" type="button" :data-testid="`chip-${c.key}`"
        :class="['flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition',
          category === c.key ? 'border-sky-500 bg-sky-50 text-sky-800 ring-1 ring-sky-300' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50']"
        @click="pick(c.key)">
        <i :class="['pi', c.icon, 'text-base']" />
        <span class="flex min-w-0 flex-col">
          <span class="font-medium">{{ c.label }}</span>
          <span class="truncate text-xs text-slate-400">{{ c.hint }}</span>
        </span>
        <span :class="['ml-auto inline-flex min-w-[1.5rem] justify-center rounded-full px-2 py-0.5 text-xs font-semibold',
          category === c.key ? 'bg-sky-600 text-white' : (meta?.counts[c.key] ?? 0) > 0 ? 'bg-slate-800 text-white' : 'bg-slate-200 text-slate-500']">
          {{ meta?.counts[c.key] ?? 0 }}
        </span>
      </button>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2" data-testid="aging-bar">
      <span class="text-xs text-slate-500">อายุงานค้าง:</span>
      <button v-for="a in AGING" :key="a.key" type="button" :data-testid="`aging-${a.key}`"
        :class="['rounded-full border px-3 py-1 text-xs', a.cls, aging === a.key ? 'bg-slate-800 !text-white !border-slate-800' : 'bg-white hover:bg-slate-50']"
        @click="setAging(a.key)">
        {{ a.label }} <b class="ml-1">{{ meta?.aging?.[a.key] ?? 0 }}</b>
      </button>
      <span v-if="aging" class="text-xs text-slate-400">(กดซ้ำเพื่อดูทั้งหมด)</span>
    </div>

    <div v-if="STAGES[category]" class="mb-3 flex flex-wrap items-center gap-2" data-testid="stage-bar">
      <span class="text-xs text-slate-500">ขั้นตอน:</span>
      <button v-for="s in STAGES[category]" :key="s.key" type="button" :data-testid="`stage-${s.key || 'all'}`"
        :class="['rounded-full border px-3 py-1 text-xs', stage === s.key ? 'border-sky-500 bg-sky-50 text-sky-800' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50']"
        @click="setStage(s.key)">
        {{ s.label }} <b class="ml-1">{{ meta?.stages?.[stageCountKey(s.key)] ?? 0 }}</b>
      </button>
    </div>
    <div v-if="category === 'cancelled'" class="mb-3 flex items-center gap-2">
      <button type="button" :class="['rounded-full border px-3 py-1 text-xs', !reviewed ? 'border-sky-500 bg-sky-50 text-sky-800' : 'border-slate-200 bg-white text-slate-600']"
        @click="reviewed = false; load()">ยังไม่ตรวจ <b>{{ meta?.stages?.unreviewed ?? 0 }}</b></button>
      <button type="button" data-testid="show-reviewed" :class="['rounded-full border px-3 py-1 text-xs', reviewed ? 'border-sky-500 bg-sky-50 text-sky-800' : 'border-slate-200 bg-white text-slate-600']"
        @click="reviewed = true; load()">ตรวจแล้ว <b>{{ meta?.stages?.reviewed ?? 0 }}</b></button>
    </div>
    <div v-if="category === 'verify'" class="mb-3 flex items-center gap-2">
      <button type="button" :class="['rounded-full border px-3 py-1 text-xs', verifyScope === 'pending' ? 'border-sky-500 bg-sky-50 text-sky-800' : 'border-slate-200 bg-white text-slate-600']"
        @click="verifyScope = 'pending'; load()">รอตรวจ <b>{{ meta?.counts.verify ?? 0 }}</b></button>
      <button type="button" data-testid="verify-mine" :class="['rounded-full border px-3 py-1 text-xs', verifyScope === 'mine' ? 'border-sky-500 bg-sky-50 text-sky-800' : 'border-slate-200 bg-white text-slate-600']"
        @click="verifyScope = 'mine'; load()">คำขอของฉัน</button>
    </div>
    <p v-if="category === 'no_commission' && meta?.noCommissionWindowDays" class="mb-2 text-xs text-slate-400">
      แสดงกรมธรรม์ที่เริ่มคุ้มครองภายใน {{ meta.noCommissionWindowDays }} วันที่ผ่านมา (ตั้งค่าได้ที่ตั้งค่าหน่วยงาน)
    </p>

    <div class="mb-3 flex items-center gap-2">
      <div class="relative w-full max-w-sm">
        <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input v-model="q" type="text" placeholder="ค้นหา เลขกรมธรรม์ / ใบคำขอ / ลูกค้า / ตัวแทน"
          class="w-full rounded-lg border border-slate-200 py-2 pl-9 pr-3 text-sm focus:border-sky-400 focus:outline-none focus:ring-1 focus:ring-sky-300"
          @input="reload">
      </div>
      <span class="text-sm text-slate-500">{{ rows.length }} รายการ</span>
      <span v-if="flash" class="text-sm text-emerald-600">{{ flash }}</span>
    </div>

    <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <div v-if="category === 'verify'" class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="min-w-full divide-y divide-slate-200 text-sm" data-testid="verify-table">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">ประเภท</th><th class="px-4 py-3">กรมธรรม์</th><th class="px-4 py-3">ลูกค้า</th>
            <th class="px-4 py-3">ผู้คีย์</th><th class="px-4 py-3">ส่งเมื่อ</th><th class="px-4 py-3">{{ verifyScope === 'mine' ? 'ผล' : 'ค้าง' }}</th><th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="7" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
          <tr v-else-if="verifyRows.length === 0"><td colspan="7" class="px-4 py-10 text-center text-slate-400">{{ verifyScope === 'mine' ? 'ยังไม่มีคำขอที่คุณส่ง' : 'ไม่มีคำขอรอตรวจ 🎉' }}</td></tr>
          <tr v-for="v in verifyRows" :key="v.id" class="cursor-pointer hover:bg-slate-50" @click="openRow(v)">
            <td class="px-4 py-3">
              <span :class="['rounded px-2 py-0.5 text-xs font-medium', v.action === 'delete' ? 'bg-rose-50 text-rose-700' : v.action === 'create' ? 'bg-sky-50 text-sky-700' : 'bg-indigo-50 text-indigo-700']">{{ v.actionLabel }}</span>
              <span v-if="v.fieldCount" class="ml-1 text-[11px] text-slate-400">{{ v.fieldCount }} ช่อง</span>
            </td>
            <td class="px-4 py-3"><div class="font-medium text-slate-800">{{ v.policyNo || '—' }}</div><div class="text-xs text-slate-400">{{ v.applicationNo }}</div></td>
            <td class="px-4 py-3 text-slate-700">{{ v.customerName || '—' }}</td>
            <td class="px-4 py-3 text-slate-700">{{ v.makerName || '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ fmtDateTime(v.createdAt) }}</td>
            <td v-if="verifyScope === 'mine'" class="px-4 py-3">
              <span :class="['rounded px-2 py-0.5 text-xs', VERIFY_STATUS[v.status]?.cls]">{{ VERIFY_STATUS[v.status]?.label ?? v.status }}</span>
              <div v-if="v.status === 'rejected' && v.reason" class="mt-0.5 max-w-[14rem] truncate text-[11px] text-rose-600" :title="v.reason">{{ v.reason }}</div>
            </td>
            <td v-else class="px-4 py-3 whitespace-nowrap"><span :class="['rounded px-2 py-0.5 text-xs', agingCls(v.agingDays)]">{{ v.agingDays ?? 0 }} วัน</span></td>
            <td class="px-4 py-3 text-right"><i class="pi pi-chevron-right text-slate-300" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="min-w-full divide-y divide-slate-200 text-sm" data-testid="followup-table">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">เลขกรมธรรม์ / ใบคำขอ</th>
            <th class="px-4 py-3">ลูกค้า</th>
            <th class="px-4 py-3">ตัวแทน</th>
            <th class="px-4 py-3">บริษัท / แบบประกัน</th>
            <th class="px-4 py-3">สถานะ</th>
            <th class="px-4 py-3">รายละเอียด</th>
            <th class="px-4 py-3">ค้าง</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="8" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
          <tr v-else-if="policyRows.length === 0"><td colspan="8" class="px-4 py-10 text-center text-slate-400">ไม่มีรายการค้างในหมวดนี้ 🎉</td></tr>
          <tr v-for="r in policyRows" :key="r.policyId" class="cursor-pointer hover:bg-slate-50" :data-testid="`row-${r.policyId}`" @click="openRow(r)">
            <td class="px-4 py-3">
              <div class="font-medium text-slate-800">{{ r.policyNo || '—' }}</div>
              <div class="text-xs text-slate-400">{{ r.applicationNo || '—' }}</div>
              <div v-if="r.alsoIn && r.alsoIn.length" class="mt-1 flex flex-wrap gap-1">
                <span v-for="k in r.alsoIn" :key="k" class="inline-flex items-center rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 ring-1 ring-amber-200"
                  title="กรมธรรม์นี้อยู่ในรายการติดตามอื่นด้วย">+ {{ ALSO_LABEL[k] || k }}</span>
              </div>
            </td>
            <td class="px-4 py-3">
              <div class="text-slate-700">{{ r.customerName || '—' }}</div>
              <div class="text-xs text-slate-400">{{ r.customerPhone || r.customerCode || '' }}</div>
            </td>
            <td class="px-4 py-3"><div class="text-slate-700">{{ r.agentName || '—' }}</div><div class="text-xs text-slate-400">{{ r.agentCode || '' }}</div></td>
            <td class="px-4 py-3"><div class="text-slate-700">{{ r.carrierName || '—' }}</div><div class="text-xs text-slate-400">{{ r.productName || '' }}</div></td>
            <td class="px-4 py-3 whitespace-nowrap"><span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ STATUS_LABEL[r.status] || r.status }}</span></td>
            <td class="px-4 py-3 text-xs text-slate-600" @click.stop>
              <template v-if="category === 'unpaid'">
                <div class="font-medium text-rose-700">ค้าง ฿{{ money(r.overdueAmount) }}</div>
                <div class="text-slate-400">{{ r.overdueCount }} งวด · ครบกำหนดเก่าสุด {{ fmtDate(r.oldestDueDate) }}</div>
              </template>
              <template v-else-if="category === 'freelook'">
                <div>ได้รับ {{ fmtDate(r.deliveredDate) || '—' }} · {{ CHANNEL_LABEL[r.salesChannel ?? ''] ?? '' }}</div>
                <div :class="r.freelookEndDate ? 'text-violet-700' : 'text-slate-400'">สิ้นสุด Freelook {{ fmtDate(r.freelookEndDate) || 'ยังคำนวณไม่ได้' }}</div>
                <div class="mt-1 flex flex-wrap gap-1">
                  <button type="button" :disabled="busy !== null || !!r.freelookDocSentAt" :data-testid="`fl-sent-${r.policyId}`"
                    :class="['rounded border px-1.5 py-0.5', r.freelookDocSentAt ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-300 hover:bg-slate-50']"
                    @click="markFreelook(r, 'sentAt')">{{ r.freelookDocSentAt ? `ส่ง ${fmtDate(r.freelookDocSentAt)}` : 'ส่งเอกสารวันนี้' }}</button>
                  <button type="button" :disabled="busy !== null || !r.freelookDocSentAt || !!r.freelookDocSignedAt" :data-testid="`fl-signed-${r.policyId}`"
                    :class="['rounded border px-1.5 py-0.5 disabled:opacity-50', r.freelookDocSignedAt ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-300 hover:bg-slate-50']"
                    @click="markFreelook(r, 'signedAt')">{{ r.freelookDocSignedAt ? `เซ็น ${fmtDate(r.freelookDocSignedAt)}` : 'ลูกค้าเซ็นวันนี้' }}</button>
                  <button type="button" :disabled="busy !== null || !r.freelookDocSignedAt" :data-testid="`fl-returned-${r.policyId}`"
                    class="rounded border border-slate-300 px-1.5 py-0.5 hover:bg-slate-50 disabled:opacity-50"
                    @click="markFreelook(r, 'returnedAt')">ส่งคืนบริษัทวันนี้</button>
                </div>
              </template>
              <template v-else-if="category === 'cancelled'">
                <div>ยกเลิก {{ fmtDate(r.cancelDate) || '—' }} <span class="text-slate-400">{{ r.cancelStatus || '' }}</span></div>
                <div class="mt-1 flex flex-wrap items-center gap-1">
                  <span :class="['rounded px-1 text-[10px]', CHECK_CLS[r.cancelChecks.refund]]">คืนเบี้ย</span>
                  <span :class="['rounded px-1 text-[10px]', CHECK_CLS[r.cancelChecks.clawback]]">ค่าคอมคืน</span>
                  <span :class="['rounded px-1 text-[10px]', CHECK_CLS[r.cancelChecks.carrierDeduct]]">บ.ประกันหัก</span>
                  <button type="button" :data-testid="`review-${r.policyId}`" class="ml-1 rounded bg-sky-600 px-2 py-0.5 text-[11px] text-white hover:bg-sky-700" @click="reviewTarget = r">
                    {{ r.cancellationReviewedAt ? 'ดูผลตรวจ' : 'ตรวจสอบ' }}
                  </button>
                </div>
              </template>
              <template v-else-if="category === 'not_delivered'">
                <span v-if="stage === 'in_transit'">ส่งเมื่อ {{ fmtDate(r.shippedDate) }}</span>
                <span v-else>ออก/รับกรมธรรม์ {{ fmtDate(r.issueDate || r.receivedDate) || '—' }}</span>
              </template>
              <template v-else>มีผล {{ fmtDate(r.effectiveDate) || '—' }}</template>
            </td>
            <td class="px-4 py-3 whitespace-nowrap"><span :class="['rounded px-2 py-0.5 text-xs', agingCls(r.agingDays)]">{{ r.agingDays ?? '—' }} วัน</span></td>
            <td class="px-4 py-3 text-right"><i class="pi pi-chevron-right text-slate-300" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <CancellationReviewPanel :policy-id="reviewTarget?.policyId ?? null"
      :title="reviewTarget ? `${reviewTarget.policyNo || reviewTarget.applicationNo} · ${reviewTarget.customerName}` : ''"
      @close="reviewTarget = null" @saved="load" />
  </div>
</template>
