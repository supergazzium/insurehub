<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import {
  fetchReceivables, fetchReconSummary,
  type ReceivableRow, type CommissionType, type ReceivableStatus, type ReconSummary,
} from '../../api/commissionReceipts'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'
import ReceivableDetailDrawer from './ReceivableDetailDrawer.vue'
import ReceiptBatchesPanel from './ReceiptBatchesPanel.vue'
import ReconDashboardPanel from './ReconDashboardPanel.vue'

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

type Tab = 'MAIN' | 'OV' | 'HISTORY' | 'BATCHES' | 'DASHBOARD'
const tab = ref<Tab>('MAIN')
const TABS: { key: Tab; label: string }[] = [
  { key: 'MAIN', label: 'ค่าคอมหลัก' },
  { key: 'OV', label: 'ค่าคอม OV' },
  { key: 'HISTORY', label: 'ประวัติ' },
  { key: 'BATCHES', label: 'รอบรับเอกสาร' },
  { key: 'DASHBOARD', label: 'Dashboard' },
]

const carriers = ref<CarrierListRow[]>([])
const policyYear = ref<number | null>(null)
const insurerId = ref<number | null>(null)
const q = ref('')
const statusFilter = ref<ReceivableStatus | ''>('')

const rows = ref<ReceivableRow[]>([])
const summary = ref<ReconSummary | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const STATUS_LABEL: Record<ReceivableStatus, string> = {
  'Pending': 'รอตรวจ', 'Matched': 'ยอดตรง', 'Mismatch': 'ยอดไม่ตรง', 'Received': 'รับแล้ว', 'No Commission': 'ไม่มีค่าคอม',
}
const STATUS_BADGE: Record<ReceivableStatus, string> = {
  'Pending': 'bg-slate-100 text-slate-600',
  'Matched': 'bg-emerald-50 text-emerald-700',
  'Mismatch': 'bg-rose-50 text-rose-700',
  'Received': 'bg-emerald-100 text-emerald-800',
  'No Commission': 'bg-slate-200 text-slate-600',
}
const STATUSES: ReceivableStatus[] = ['Pending', 'Matched', 'Mismatch', 'Received', 'No Commission']

const isHistory = computed(() => tab.value === 'HISTORY')
const needsFilter = computed(() => !isHistory.value && tab.value !== 'BATCHES')
const canQuery = computed(() => isHistory.value || (!!policyYear.value && !!insurerId.value))

const yearOptions = computed(() => {
  return Array.from({ length: 10 }, (_, i) => i + 1)
})

async function load(): Promise<void> {
  if (tab.value === 'BATCHES' || tab.value === 'DASHBOARD') return
  if (needsFilter.value && !canQuery.value) { rows.value = []; return }
  loading.value = true
  error.value = null
  try {
    const res = await fetchReceivables({
      policyYear: policyYear.value ?? undefined,
      insurerId: insurerId.value ?? undefined,
      type: tab.value === 'HISTORY' ? 'ALL' : (tab.value as CommissionType),
      status: statusFilter.value || undefined,
      q: q.value || undefined,
      all: isHistory.value || undefined,
    })
    rows.value = res.data
    if (insurerId.value || isHistory.value) {
      const s = await fetchReconSummary(policyYear.value ?? undefined, insurerId.value ?? undefined)
      summary.value = s.data
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
    rows.value = []
  } finally {
    loading.value = false
  }
}

function switchTab(t: Tab): void { tab.value = t; if (t !== 'BATCHES' && t !== 'DASHBOARD') load() }

// ── detail drawer ───────────────────────────────────────────────────────
const openId = ref<string | null>(null)
function openRow(r: ReceivableRow): void { openId.value = r.id }
function onDrawerSaved(): void { openId.value = null; load() }

onMounted(async () => {
  const c = await fetchCarrierList({})
  carriers.value = c.data
})
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-6">
    <header class="mb-4">
      <h1 class="text-xl font-semibold text-slate-800">รับค่าคอมจากบริษัทประกัน</h1>
      <p class="mt-1 text-sm text-slate-500">ตรวจและบันทึกรับค่าคอม (Main / OV) เทียบกับที่บริษัทประกันแจ้ง — บันทึกแบบ Manual</p>
    </header>

    <!-- Tabs -->
    <div class="mb-4 flex gap-1 border-b border-slate-200">
      <button
        v-for="t in TABS" :key="t.key" type="button"
        :class="['px-4 py-2 text-sm font-medium', tab === t.key ? 'border-b-2 border-brand-600 text-brand-700' : 'text-slate-500 hover:text-slate-700']"
        @click="switchTab(t.key)"
      >{{ t.label }}</button>
    </div>

    <ReceiptBatchesPanel v-if="tab === 'BATCHES'" :carriers="carriers" />
    <ReconDashboardPanel v-else-if="tab === 'DASHBOARD'" :carriers="carriers" :policy-year="policyYear" :insurer-id="insurerId" />

    <template v-else>
      <!-- Filter bar -->
      <div class="mb-4 flex flex-wrap items-end gap-3">
        <div v-if="needsFilter || isHistory">
          <label class="mb-1 block text-xs text-slate-500">ปีกรมธรรม์ <span v-if="needsFilter" class="text-rose-500">*</span></label>
          <select v-model.number="policyYear" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" @change="load">
            <option :value="null">— ทั้งหมด —</option>
            <option v-for="y in yearOptions" :key="y" :value="y">ปีที่ {{ y }}</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">บริษัทประกัน <span v-if="needsFilter" class="text-rose-500">*</span></label>
          <select v-model.number="insurerId" class="min-w-[240px] rounded-lg border border-slate-200 px-3 py-2 text-sm" @change="load">
            <option :value="null">— เลือกบริษัทประกัน —</option>
            <option v-for="c in carriers" :key="c.id" :value="Number(c.id)">{{ c.name }}</option>
          </select>
        </div>
        <div v-if="isHistory">
          <label class="mb-1 block text-xs text-slate-500">สถานะ</label>
          <select v-model="statusFilter" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" @change="load">
            <option value="">ทั้งหมด</option>
            <option v-for="s in STATUSES" :key="s" :value="s">{{ STATUS_LABEL[s] }}</option>
          </select>
        </div>
        <div class="relative">
          <label class="mb-1 block text-xs text-slate-500">ค้นหา</label>
          <input v-model="q" type="text" placeholder="เลขกรมธรรม์ / ลูกค้า" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" @input="load" />
        </div>
      </div>

      <!-- Summary strip -->
      <div v-if="summary" class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">ควรได้รับ</div><div class="text-lg font-semibold text-slate-800">฿{{ money(summary.expected) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">รับแล้ว</div><div class="text-lg font-semibold text-emerald-700">฿{{ money(summary.received) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">ค้างรับ</div><div class="text-lg font-semibold text-amber-700">฿{{ money(summary.outstanding) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">ไม่ตรง / ไม่มีค่าคอม</div><div class="text-lg font-semibold text-rose-700">{{ summary.mismatchCount }} / {{ summary.noCommissionCount }}</div>
        </div>
      </div>

      <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>
      <div v-if="needsFilter && !canQuery" class="rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-700">กรุณาเลือกปีกรมธรรม์และบริษัทประกันเพื่อแสดงรายการ</div>

      <!-- Table -->
      <div v-else class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-3">กรมธรรม์ / ลูกค้า</th>
              <th v-if="isHistory" class="px-4 py-3">ประเภท</th>
              <th class="px-4 py-3 text-right">ควรได้</th>
              <th class="px-4 py-3 text-right">บริษัทแจ้ง</th>
              <th class="px-4 py-3 text-right">ผลต่าง</th>
              <th class="px-4 py-3">สถานะ</th>
              <th class="px-4 py-3">รับเมื่อ</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loading"><td colspan="8" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
            <tr v-else-if="rows.length === 0"><td colspan="8" class="px-4 py-10 text-center text-slate-400">ไม่มีรายการ</td></tr>
            <tr v-for="r in rows" :key="r.id" class="cursor-pointer hover:bg-slate-50" @click="openRow(r)">
              <td class="px-4 py-3">
                <div class="font-medium text-slate-800">{{ r.policyNo || r.applicationNo || '—' }}</div>
                <div class="text-xs text-slate-400">{{ r.customerName }}</div>
              </td>
              <td v-if="isHistory" class="px-4 py-3 text-xs text-slate-500">{{ r.commissionType }}</td>
              <td class="px-4 py-3 text-right text-slate-700">฿{{ money(r.expectedAmount) }}</td>
              <td class="px-4 py-3 text-right text-slate-600">{{ r.statementAmount !== null ? '฿' + money(r.statementAmount) : '—' }}</td>
              <td class="px-4 py-3 text-right" :class="(r.differenceAmount ?? 0) !== 0 ? 'text-rose-600' : 'text-slate-400'">
                {{ r.differenceAmount !== null ? (r.differenceAmount > 0 ? '+' : '') + '฿' + money(r.differenceAmount) : '—' }}
              </td>
              <td class="px-4 py-3"><span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[r.status]]">{{ STATUS_LABEL[r.status] }}</span></td>
              <td class="px-4 py-3 text-slate-500">{{ r.receivedDate ? fmtDate(r.receivedDate) : '—' }}</td>
              <td class="px-4 py-3 text-right"><i class="pi pi-chevron-right text-slate-300" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <ReceivableDetailDrawer v-if="openId" :id="openId" @close="openId = null" @saved="onDrawerSaved" />
  </div>
</template>
