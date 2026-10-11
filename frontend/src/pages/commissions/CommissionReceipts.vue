<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import {
  fetchReceivables, fetchReconSummary, receivablesExportPath, reviewReceivable,
  type ReceivableFilters, type ReceivableRow, type ReceivableStatus, type ReconSummary,
} from '../../api/commissionReceipts'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { ApiError } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import { downloadAuthed } from '../../util/fileDownload'
import ReceivableDetailDrawer from './ReceivableDetailDrawer.vue'
import ReceiptBatchesPanel from './ReceiptBatchesPanel.vue'
import ReconDashboardPanel from './ReconDashboardPanel.vue'
import ReceivablesTable from './ReceivablesTable.vue'
import StatementPasteModal from './StatementPasteModal.vue'
import BulkReceiveModal from './BulkReceiveModal.vue'
import { STATUSES, STATUS_LABEL, money, thisMonth } from './receiptLabels'

type Tab = 'MAIN' | 'OV' | 'HISTORY' | 'BATCHES' | 'DASHBOARD'
const TABS: { key: Tab; label: string }[] = [
  { key: 'MAIN', label: 'ค่าคอมหลัก' },
  { key: 'OV', label: 'ค่าคอม OV' },
  { key: 'HISTORY', label: 'ประวัติทั้งหมด' },
  { key: 'BATCHES', label: 'รอบ statement' },
  { key: 'DASHBOARD', label: 'สรุปผล' },
]

const auth = useAuthStore()
const canView = computed(() => auth.hasPermission('receipts.view'))
const canRecord = computed(() => auth.hasPermission('receipts.record'))

const tab = ref<Tab>('MAIN')
const carriers = ref<CarrierListRow[]>([])
const insurerId = ref<number | null>(null)
const monthFrom = ref(thisMonth())
const monthTo = ref(thisMonth())
const includeOutstanding = ref(true)
const policyYear = ref<number | null>(null)
const statusFilter = ref<ReceivableStatus | ''>('')
const q = ref('')

const rows = ref<ReceivableRow[]>([])
const truncated = ref(false)
const summary = ref<ReconSummary | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const message = ref<string | null>(null)
const selected = ref<string[]>([])
const busyIds = ref<string[]>([])

const isHistory = computed(() => tab.value === 'HISTORY')
const isWork = computed(() => tab.value === 'MAIN' || tab.value === 'OV')
const insurerName = computed(() => carriers.value.find(c => Number(c.id) === insurerId.value)?.name ?? '')
const selectedRows = computed(() => rows.value.filter(r => selected.value.includes(r.id)))

function filters(): ReceivableFilters {
  return {
    insurerId: insurerId.value ?? undefined,
    monthFrom: monthFrom.value || undefined,
    monthTo: monthTo.value || undefined,
    includeOutstanding: isWork.value ? includeOutstanding.value : undefined,
    policyYear: policyYear.value ?? undefined,
    type: tab.value === 'MAIN' ? 'MAIN_ADJ' : tab.value === 'OV' ? 'OV' : 'ALL',
    status: isHistory.value && statusFilter.value ? statusFilter.value : undefined,
    q: q.value.trim() || undefined,
    all: isHistory.value || undefined,
  }
}

async function load(): Promise<void> {
  if (!canView.value || tab.value === 'BATCHES' || tab.value === 'DASHBOARD') return
  if (isWork.value && !insurerId.value) {
    rows.value = []
    summary.value = null
    return
  }
  loading.value = true
  error.value = null
  try {
    const f = filters()
    const res = await fetchReceivables(f)
    rows.value = res.data
    truncated.value = res.meta.truncated
    selected.value = selected.value.filter(id => rows.value.some(r => r.id === id))
    summary.value = (await fetchReconSummary(f)).data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
    rows.value = []
  } finally {
    loading.value = false
  }
}

let timer: number | undefined
watch(q, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(load, 350)
})
watch([insurerId, monthFrom, monthTo, includeOutstanding, policyYear, statusFilter], () => {
  selected.value = []
  load()
})

function switchTab(t: Tab): void {
  tab.value = t
  selected.value = []
  message.value = null
  load()
}

async function onReview(row: ReceivableRow, amount: number): Promise<void> {
  busyIds.value = [...busyIds.value, row.id]
  error.value = null
  try {
    const res = await reviewReceivable(row.id, amount, row.version)
    rows.value = rows.value.map(r => (r.id === row.id ? res.data : r))
    summary.value = (await fetchReconSummary(filters())).data
  } catch (e: unknown) {
    error.value = `${row.policyNo ?? ''}: ${e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'}`
    await load()
  } finally {
    busyIds.value = busyIds.value.filter(id => id !== row.id)
  }
}

const openId = ref<string | null>(null)
const showPaste = ref(false)
const showBulk = ref(false)

async function onPasted(ids: string[]): Promise<void> {
  showPaste.value = false
  await load()
  selected.value = ids.filter(id => rows.value.some(r => r.id === id))
  message.value = `บันทึกยอดตามบริษัทแล้ว ${ids.length} รายการ — ตรวจส่วนต่างแล้วกด "ยืนยันรับเงิน"`
}

async function onBulkDone(): Promise<void> {
  const n = selected.value.length
  showBulk.value = false
  selected.value = []
  await load()
  message.value = `ยืนยันรับเงินแล้ว ${n} รายการ`
}

async function exportCsv(): Promise<void> {
  try {
    await downloadAuthed(receivablesExportPath(filters()), `รับค่าคอม-${insurerName.value || 'ทั้งหมด'}-${monthFrom.value || 'all'}.csv`)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'ส่งออกไม่สำเร็จ'
  }
}

onMounted(async () => {
  if (!canView.value) return
  carriers.value = (await fetchCarrierList({ perPage: 100 })).data
  load()
})
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-6">
    <header class="mb-4">
      <h1 class="text-xl font-semibold text-slate-800">รับค่าคอมจากบริษัทประกัน</h1>
      <p class="mt-1 text-sm text-slate-500">เรียกรายการจากกรมธรรม์ตามบริษัทและเดือนที่เริ่มคุ้มครอง คีย์ยอดตาม statement แล้วยืนยันรับเงิน</p>
    </header>

    <div v-if="!canView" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
      <i class="pi pi-lock mr-1" /> บัญชีของคุณยังไม่มีสิทธิ์ดูการรับค่าคอม กรุณาติดต่อผู้ดูแลระบบ
    </div>

    <template v-else>
      <div class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
        <button
          v-for="t in TABS" :key="t.key" type="button"
          :class="['whitespace-nowrap px-4 py-2 text-sm font-medium', tab === t.key ? 'border-b-2 border-brand-600 text-brand-700' : 'text-slate-500 hover:text-slate-700']"
          @click="switchTab(t.key)"
        >{{ t.label }}</button>
      </div>

      <ReceiptBatchesPanel v-if="tab === 'BATCHES'" :carriers="carriers" />

      <template v-else>
        <div class="mb-4 flex flex-wrap items-end gap-3">
          <div>
            <label class="mb-1 block text-xs text-slate-500">บริษัทประกัน <span v-if="isWork" class="text-rose-500">*</span></label>
            <select v-model.number="insurerId" class="min-w-[260px] rounded-lg border border-slate-200 px-3 py-2 text-sm">
              <option :value="null">{{ isWork ? '— เลือกบริษัทประกัน —' : '— ทุกบริษัท —' }}</option>
              <option v-for="c in carriers" :key="c.id" :value="Number(c.id)">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-xs text-slate-500">เริ่มคุ้มครองตั้งแต่เดือน</label>
            <input v-model="monthFrom" type="month" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-slate-500">ถึงเดือน</label>
            <input v-model="monthTo" type="month" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </div>
          <label v-if="isWork" class="mb-2 flex items-center gap-1.5 text-sm text-slate-600">
            <input v-model="includeOutstanding" type="checkbox" /> รวมรายการค้างจากเดือนก่อน
          </label>
          <div>
            <label class="mb-1 block text-xs text-slate-500">ปีกรมธรรม์</label>
            <select v-model.number="policyYear" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
              <option :value="null">ทุกปี</option>
              <option v-for="y in 10" :key="y" :value="y">ปีที่ {{ y }}</option>
            </select>
          </div>
          <div v-if="isHistory">
            <label class="mb-1 block text-xs text-slate-500">สถานะ</label>
            <select v-model="statusFilter" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
              <option value="">ทั้งหมด</option>
              <option v-for="s in STATUSES" :key="s" :value="s">{{ STATUS_LABEL[s] }}</option>
            </select>
          </div>
          <div v-if="tab !== 'DASHBOARD'">
            <label class="mb-1 block text-xs text-slate-500">ค้นหา</label>
            <input v-model="q" type="text" placeholder="เลขกรมธรรม์ / ลูกค้า" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </div>
        </div>

        <ReconDashboardPanel
          v-if="tab === 'DASHBOARD'"
          :insurer-id="insurerId" :policy-year="policyYear" :month-from="monthFrom" :month-to="monthTo"
        />

        <template v-else>
          <div v-if="summary" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-white p-3">
              <div class="text-xs text-slate-500">ควรได้รับ</div><div class="text-lg font-semibold text-slate-800">{{ money(summary.expected) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3">
              <div class="text-xs text-slate-500">รับแล้ว</div><div class="text-lg font-semibold text-emerald-700">{{ money(summary.received) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3">
              <div class="text-xs text-slate-500">ค้างรับ</div><div class="text-lg font-semibold text-amber-700">{{ money(summary.outstanding) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3">
              <div class="text-xs text-slate-500">ยอดไม่ตรง / ไม่มีค่าคอม</div><div class="text-lg font-semibold text-rose-700">{{ summary.mismatchCount }} / {{ summary.noCommissionCount }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3">
              <div class="text-xs text-slate-500">รายการปรับปรุง รับแล้ว / ค้าง</div>
              <div class="text-lg font-semibold text-violet-700">{{ money(summary.adjustmentReceived) }} <span class="text-sm text-slate-400">/ {{ money(summary.adjustmentOpen) }}</span></div>
            </div>
          </div>

          <div v-if="isWork && insurerId" class="mb-3 flex flex-wrap items-center gap-2">
            <button v-if="canRecord" type="button" class="rounded-lg border border-brand-300 bg-white px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50" @click="showPaste = true">
              <i class="pi pi-clipboard mr-1" /> วางยอดจาก Excel
            </button>
            <button
              v-if="canRecord" type="button" :disabled="selected.length === 0"
              class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-40"
              @click="showBulk = true"
            ><i class="pi pi-check mr-1" /> ยืนยันรับเงิน ({{ selected.length }})</button>
            <button type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="exportCsv">
              <i class="pi pi-download mr-1" /> ส่งออก CSV
            </button>
            <span class="text-xs text-slate-400">คีย์ยอดในช่อง "ยอดตามบริษัท" แล้วกด Enter หรือกด "ตรงยอด" · ติ๊กเลือกรายการเพื่อยืนยันรับหลายรายการ</span>
          </div>
          <div v-else-if="isHistory" class="mb-3 flex justify-end">
            <button type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="exportCsv">
              <i class="pi pi-download mr-1" /> ส่งออก CSV
            </button>
          </div>

          <div v-if="message" class="mb-3 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ message }}</div>
          <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700">{{ error }}</div>
          <div v-if="truncated" class="mb-3 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800">แสดง 1,000 รายการแรก กรุณากรองช่วงเดือนให้แคบลง</div>

          <div v-if="isWork && !insurerId" class="rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-700">เลือกบริษัทประกันเพื่อเรียกรายการจากกรมธรรม์</div>
          <ReceivablesTable
            v-else
            v-model:selected="selected"
            :rows="rows" :loading="loading" :can-record="canRecord && isWork" :show-type="isHistory" :busy-ids="busyIds"
            @open="r => (openId = r.id)"
            @review="onReview"
          />
        </template>
      </template>
    </template>

    <ReceivableDetailDrawer v-if="openId" :id="openId" @close="openId = null" @changed="load" />
    <StatementPasteModal
      v-if="showPaste && insurerId"
      :insurer-id="insurerId" :insurer-name="insurerName" :type="tab === 'OV' ? 'OV' : 'MAIN'"
      @close="showPaste = false" @applied="onPasted"
    />
    <BulkReceiveModal
      v-if="showBulk && insurerId"
      :rows="selectedRows" :insurer-id="insurerId" :insurer-name="insurerName" :statement-month="monthTo || null"
      @close="showBulk = false" @done="onBulkDone"
    />
  </div>
</template>
