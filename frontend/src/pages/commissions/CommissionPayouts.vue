<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchOutstandingByAgent, fetchPayoutBatches,
  type AgentOutstandingRow, type BatchRow, type BatchStatus,
} from '../../api/commissionPayouts'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()

const agents = ref<AgentOutstandingRow[]>([])
const totals = ref<{ totalAgents: number; totalItems: number; totalAmount: number; totalWaiting?: number; totalNet?: number }>({ totalAgents: 0, totalItems: 0, totalAmount: 0 })
const loading = ref(true)
const error = ref<string | null>(null)

// Optional date filter (default: all dates).
const fromDate = ref('')
const toDate = ref('')
const search = ref('')

const money = (n: number) =>
  n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const filteredAgents = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return agents.value
  return agents.value.filter(
    (a) =>
      (a.agentCode ?? '').toLowerCase().includes(q) ||
      a.agentName.toLowerCase().includes(q),
  )
})
// Payable = agents with a non-zero outstanding amount.
const payableCount = computed(() => agents.value.filter((a) => a.amount > 0).length)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchOutstandingByAgent(fromDate.value || undefined, toDate.value || undefined)
    agents.value = res.agents
    totals.value = res.totals
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function clearFilter(): void {
  fromDate.value = ''
  toDate.value = ''
  void load()
}

function openAgent(a: AgentOutstandingRow): void {
  if (!a.agentId) return
  router.push({
    name: 'agent-payout-detail',
    params: { agentId: a.agentId },
    query: { from: fromDate.value || undefined, to: toDate.value || undefined },
  })
}

// ── Batch history (audit) ───────────────────────────────────────────────
const showHistory = ref(false)
const batches = ref<BatchRow[]>([])
const batchesLoaded = ref(false)
const STATUS_LABEL: Record<BatchStatus, string> = {
  DRAFT: 'ร่าง', GENERATED: 'สร้างเอกสารแล้ว', APPROVED: 'อนุมัติแล้ว', PAID: 'จ่ายแล้ว', CANCELLED: 'ยกเลิก',
}
const STATUS_BADGE: Record<BatchStatus, string> = {
  DRAFT: 'bg-slate-100 text-slate-600',
  GENERATED: 'bg-sky-50 text-sky-700',
  APPROVED: 'bg-violet-50 text-violet-700',
  PAID: 'bg-emerald-50 text-emerald-700',
  CANCELLED: 'bg-rose-50 text-rose-700',
}
async function toggleHistory(): Promise<void> {
  showHistory.value = !showHistory.value
  if (showHistory.value && !batchesLoaded.value) {
    try {
      const res = await fetchPayoutBatches('all')
      batches.value = res.data
      batchesLoaded.value = true
    } catch { /* ignore — audit view is best-effort */ }
  }
}
function openBatch(b: BatchRow): void {
  router.push({ name: 'commission-payout-detail', params: { id: b.id } })
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-6">
    <header class="mb-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold text-slate-800">ทำจ่ายค่าคอม — ตัวแทน</h1>
          <p class="mt-1 text-sm text-slate-500">
            ค่าคอมพื้นฐาน + ค่าแนะนำ + ค่าบริหารสายงาน ของตัวแทนทุกคน หักภาษี ณ ที่จ่ายแล้ว คลิกที่ตัวแทนเพื่อดูรายละเอียดและทำจ่าย
          </p>
        </div>
        <button type="button" data-testid="open-wht-report" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
          @click="router.push({ name: 'commission-wht-report' })">
          <i class="pi pi-file mr-1 text-xs" /> รายงาน ภ.ง.ด.3 / 53
        </button>
      </div>
    </header>

    <!-- Summary cards -->
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ตัวแทนที่พร้อมจ่าย</div>
        <div class="mt-1 text-2xl font-semibold text-slate-800">{{ payableCount }}</div>
        <div class="text-[11px] text-slate-400">{{ totals.totalItems }} รายการ</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ยอดพร้อมจ่าย (ก่อนภาษี)</div>
        <div class="mt-1 text-2xl font-semibold text-slate-800">฿{{ money(totals.totalAmount) }}</div>
      </div>
      <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
        <div class="text-xs text-amber-700">รอเงื่อนไข</div>
        <div class="mt-1 text-2xl font-semibold text-amber-800">฿{{ money(totals.totalWaiting ?? 0) }}</div>
        <div class="text-[11px] text-amber-600">Freelook / รอรับค่าคอมจากบริษัท / รอลูกค้าชำระ</div>
      </div>
      <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
        <div class="text-xs text-emerald-700">ยอดโอนสุทธิ (หลังหัก ณ ที่จ่าย)</div>
        <div class="mt-1 text-2xl font-semibold text-emerald-800">฿{{ money(totals.totalNet ?? 0) }}</div>
      </div>
    </div>

    <!-- Filter bar -->
    <section class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-xs text-slate-500">วันแจ้งงาน (จาก)</label>
          <input v-model="fromDate" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">วันแจ้งงาน (ถึง)</label>
          <input v-model="toDate" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <button
          type="button"
          class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
          @click="load"
        >
          <i class="pi pi-filter mr-1" /> กรอง
        </button>
        <button
          v-if="fromDate || toDate"
          type="button"
          class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50"
          @click="clearFilter"
        >
          ล้างตัวกรอง (ดูทั้งหมด)
        </button>
        <div class="ml-auto">
          <label class="mb-1 block text-xs text-slate-500">ค้นหาตัวแทน</label>
          <input
            v-model="search" type="text" placeholder="รหัส / ชื่อ"
            class="w-52 rounded-lg border border-slate-200 px-3 py-2 text-sm"
          />
        </div>
      </div>
      <p v-if="!fromDate && !toDate" class="mt-2 text-xs text-slate-400">
        <i class="pi pi-info-circle" /> แสดงค่าคอมค้างจ่ายทั้งหมด (ยังไม่จ่าย) — ใช้ตัวกรองเพื่อจำกัดช่วงวันแจ้งงาน
      </p>
    </section>

    <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <!-- Agent list -->
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">รหัสตัวแทน</th>
            <th class="px-4 py-3">ชื่อตัวแทน</th>
            <th class="px-4 py-3">VAT / หัก ณ ที่จ่าย</th>
            <th class="px-4 py-3 text-right">ค่าคอมพื้นฐาน</th>
            <th class="px-4 py-3 text-right">ค่าแนะนำ / สายงาน</th>
            <th class="px-4 py-3 text-right">รอเงื่อนไข</th>
            <th class="px-4 py-3 text-right">โอนสุทธิ</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="8" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
          <tr v-else-if="filteredAgents.length === 0"><td colspan="8" class="px-4 py-10 text-center text-slate-400">ไม่มีตัวแทนที่มีค่าคอมค้างจ่าย</td></tr>
          <tr
            v-for="a in filteredAgents" :key="a.agentId ?? a.agentCode ?? ''"
            class="cursor-pointer hover:bg-slate-50"
            @click="openAgent(a)"
          >
            <td class="px-4 py-3 font-medium text-slate-700">{{ a.agentCode ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-700">{{ a.agentName }}</td>
            <td class="px-4 py-3 text-xs text-slate-500">
              {{ a.vatType === '3' ? 'รวม VAT' : a.vatType === '2' ? 'บวก VAT' : 'ไม่มี VAT' }} · {{ a.whtRate ?? 3 }}%
            </td>
            <td class="px-4 py-3 text-right text-slate-600">
              ฿{{ money((a.byType?.base ?? 0) + (a.byType?.self_mgmt ?? 0)) }}
              <div class="text-[11px] text-slate-400">{{ a.itemCount }} รายการ</div>
            </td>
            <td class="px-4 py-3 text-right text-slate-600">฿{{ money((a.byType?.referral ?? 0) + (a.byType?.override ?? 0)) }}</td>
            <td class="px-4 py-3 text-right" :class="(a.waitingAmount ?? 0) > 0 ? 'text-amber-700' : 'text-slate-300'">
              ฿{{ money(a.waitingAmount ?? 0) }}
              <div v-if="(a.waitingCount ?? 0) > 0" class="text-[11px]">{{ a.waitingCount }} รายการ</div>
            </td>
            <td class="px-4 py-3 text-right font-semibold" :class="(a.netAmount ?? 0) > 0 ? 'text-emerald-700' : 'text-slate-400'">
              ฿{{ money(a.netAmount ?? 0) }}
              <div v-if="(a.pendingDeduction ?? 0) > 0" class="text-[11px] font-normal text-rose-600">หักคืน −฿{{ money(a.pendingDeduction ?? 0) }}</div>
            </td>
            <td class="px-4 py-3 text-right"><i class="pi pi-chevron-right text-slate-300" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Batch history (audit) -->
    <section class="mt-6">
      <button
        type="button"
        class="flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-800"
        @click="toggleHistory"
      >
        <i :class="['pi', showHistory ? 'pi-chevron-down' : 'pi-chevron-right']" />
        ประวัติรอบจ่าย (สำหรับตรวจสอบ)
      </button>
      <div v-if="showHistory" class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-3">รอบ</th>
              <th class="px-4 py-3">สถานะ</th>
              <th class="px-4 py-3 text-right">ตัวแทน</th>
              <th class="px-4 py-3 text-right">รายการ</th>
              <th class="px-4 py-3 text-right">ยอดรวม</th>
              <th class="px-4 py-3 text-right">หัก ณ ที่จ่าย</th>
              <th class="px-4 py-3 text-right">โอนสุทธิ</th>
              <th class="px-4 py-3">วันที่จ่าย</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="batches.length === 0"><td colspan="9" class="px-4 py-8 text-center text-slate-400">ยังไม่มีรอบจ่าย</td></tr>
            <tr v-for="b in batches" :key="b.id" class="cursor-pointer hover:bg-slate-50" @click="openBatch(b)">
              <td class="px-4 py-3 text-slate-700">{{ fmtDate(b.fromDate) }} – {{ fmtDate(b.toDate) }}</td>
              <td class="px-4 py-3">
                <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[b.status]]">{{ STATUS_LABEL[b.status] }}</span>
              </td>
              <td class="px-4 py-3 text-right text-slate-600">{{ b.totalAgents }}</td>
              <td class="px-4 py-3 text-right text-slate-600">{{ b.totalItems }}</td>
              <td class="px-4 py-3 text-right font-medium text-slate-800">฿{{ money(b.totalAmount) }}</td>
              <td class="px-4 py-3 text-right text-slate-600">{{ b.whtTotal == null ? '—' : `฿${money(b.whtTotal)}` }}</td>
              <td class="px-4 py-3 text-right font-medium text-emerald-700">{{ b.netTotal == null ? '—' : `฿${money(b.netTotal)}` }}</td>
              <td class="px-4 py-3 text-slate-600">{{ b.paymentDate ? fmtDate(b.paymentDate) : '—' }}</td>
              <td class="px-4 py-3 text-right"><i class="pi pi-chevron-right text-slate-300" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
