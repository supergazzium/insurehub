<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchOutstandingByAgent, fetchPayoutBatches, createPayoutBatch,
  type AgentOutstandingRow, type BatchRow, type BatchStatus,
} from '../../api/commissionPayouts'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()

const agents = ref<AgentOutstandingRow[]>([])
const totals = ref({ totalAgents: 0, totalItems: 0, totalAmount: 0 })
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

// ── Bulk selection → create batch (30–50 agents at a time) ──────────────
const selected = ref<Set<string>>(new Set())
// Only agents with a payable id + non-zero amount are selectable.
const selectableAgents = computed(() =>
  filteredAgents.value.filter((a) => a.agentId && a.amount > 0),
)
const allSelected = computed(
  () => selectableAgents.value.length > 0 && selectableAgents.value.every((a) => selected.value.has(a.agentId!)),
)
const selectedCount = computed(() => selected.value.size)
const selectedAmount = computed(() =>
  agents.value.filter((a) => a.agentId && selected.value.has(a.agentId)).reduce((s, a) => s + a.amount, 0),
)
function toggleAgent(a: AgentOutstandingRow): void {
  if (!a.agentId || a.amount <= 0) return
  const s = new Set(selected.value)
  s.has(a.agentId) ? s.delete(a.agentId) : s.add(a.agentId)
  selected.value = s
}
function toggleAll(): void {
  if (allSelected.value) { selected.value = new Set(); return }
  selected.value = new Set(selectableAgents.value.map((a) => a.agentId!))
}
function clearSelection(): void { selected.value = new Set() }

const creating = ref(false)
async function createBatch(mode: 'selected' | 'all'): Promise<void> {
  creating.value = true
  error.value = null
  try {
    const agentIds =
      mode === 'selected'
        ? [...selected.value].map((x) => Number(x))
        : selectableAgents.value.map((a) => Number(a.agentId))
    if (agentIds.length === 0) { error.value = 'ยังไม่ได้เลือกตัวแทน'; return }
    const res = await createPayoutBatch({
      fromDate: fromDate.value || undefined,
      toDate: toDate.value || undefined,
      agentIds,
    })
    router.push({ name: 'commission-payout-detail', params: { id: res.data.id } })
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'สร้างรอบจ่ายไม่สำเร็จ'
  } finally {
    creating.value = false
  }
}

// ── Batch history (audit) ───────────────────────────────────────────────
const showHistory = ref(false)
const batches = ref<BatchRow[]>([])
const batchesLoaded = ref(false)
const STATUS_LABEL: Record<BatchStatus, string> = {
  DRAFT: 'ร่าง', GENERATED: 'สร้างเอกสารแล้ว', APPROVED: 'อนุมัติแล้ว', PAID: 'จ่ายแล้ว', CANCELLED: 'ยกเลิก', REVERSED: 'ย้อนกลับ',
}
const STATUS_BADGE: Record<BatchStatus, string> = {
  DRAFT: 'bg-slate-100 text-slate-600',
  GENERATED: 'bg-sky-50 text-sky-700',
  APPROVED: 'bg-violet-50 text-violet-700',
  PAID: 'bg-emerald-50 text-emerald-700',
  CANCELLED: 'bg-rose-50 text-rose-700',
  REVERSED: 'bg-amber-50 text-amber-700',
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
      <h1 class="text-xl font-semibold text-slate-800">ทำจ่ายค่าคอม — ตัวแทน</h1>
      <p class="mt-1 text-sm text-slate-500">
        เลือกตัวแทนหลายคน (30–50) เพื่อสร้างรอบจ่ายพร้อมกัน แล้วสร้างเอกสารและบันทึกจ่ายทีละตัวแทน · หรือคลิกชื่อเพื่อทำจ่ายรายตัว
      </p>
    </header>

    <!-- Summary cards -->
    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ตัวแทนที่มียอดค้างจ่าย</div>
        <div class="mt-1 text-2xl font-semibold text-slate-800">{{ payableCount }}</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">รายการค้างจ่าย</div>
        <div class="mt-1 text-2xl font-semibold text-slate-800">{{ totals.totalItems }}</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ยอดค้างจ่ายรวม</div>
        <div class="mt-1 text-2xl font-semibold text-emerald-700">฿{{ money(totals.totalAmount) }}</div>
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

    <!-- Bulk-select action bar -->
    <div
      v-if="selectedCount > 0"
      class="sticky top-2 z-10 mb-3 flex flex-wrap items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 shadow-sm"
    >
      <span class="text-sm font-medium text-brand-800">
        เลือก {{ selectedCount }} ตัวแทน · รวม ฿{{ money(selectedAmount) }}
      </span>
      <button type="button" class="text-xs text-slate-500 hover:text-slate-700" @click="clearSelection">ล้างการเลือก</button>
      <div class="ml-auto flex gap-2">
        <button
          type="button" :disabled="creating"
          class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
          @click="createBatch('selected')"
        >
          <i :class="['pi mr-1', creating ? 'pi-spin pi-spinner' : 'pi-plus-circle']" /> สร้างรอบจ่าย (ที่เลือก)
        </button>
      </div>
    </div>

    <!-- Agent list -->
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="w-10 px-4 py-3">
              <input
                type="checkbox" :checked="allSelected" :disabled="selectableAgents.length === 0"
                class="h-4 w-4 rounded border-slate-300" @change="toggleAll"
              />
            </th>
            <th class="px-4 py-3">รหัสตัวแทน</th>
            <th class="px-4 py-3">ชื่อตัวแทน</th>
            <th class="px-4 py-3">VAT</th>
            <th class="px-4 py-3 text-right">รายการ</th>
            <th class="px-4 py-3 text-right">ยอดค้างจ่าย</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="7" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
          <tr v-else-if="filteredAgents.length === 0"><td colspan="7" class="px-4 py-10 text-center text-slate-400">ไม่มีตัวแทนที่มีค่าคอมค้างจ่าย</td></tr>
          <tr
            v-for="a in filteredAgents" :key="a.agentId ?? a.agentCode ?? ''"
            class="hover:bg-slate-50"
            :class="a.agentId && selected.has(a.agentId) ? 'bg-brand-50/60' : ''"
          >
            <td class="px-4 py-3" @click.stop>
              <input
                type="checkbox"
                :checked="!!(a.agentId && selected.has(a.agentId))"
                :disabled="!a.agentId || a.amount <= 0"
                class="h-4 w-4 rounded border-slate-300"
                @change="toggleAgent(a)"
              />
            </td>
            <td class="cursor-pointer px-4 py-3 font-medium text-slate-700" @click="openAgent(a)">{{ a.agentCode ?? '—' }}</td>
            <td class="cursor-pointer px-4 py-3 text-slate-700" @click="openAgent(a)">{{ a.agentName }}</td>
            <td class="px-4 py-3 text-xs text-slate-500">
              {{ a.vatType === '3' ? 'รวม VAT' : a.vatType === '2' ? 'บวก VAT' : 'ไม่มี' }}
            </td>
            <td class="px-4 py-3 text-right text-slate-600">{{ a.itemCount }}</td>
            <td class="px-4 py-3 text-right font-semibold" :class="a.amount > 0 ? 'text-emerald-700' : 'text-slate-400'">
              ฿{{ money(a.amount) }}
            </td>
            <td class="cursor-pointer px-4 py-3 text-right" @click="openAgent(a)"><i class="pi pi-chevron-right text-slate-300" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create-batch-for-all shortcut -->
    <div v-if="!loading && selectableAgents.length > 0" class="mt-3 flex justify-end">
      <button
        type="button" :disabled="creating"
        class="rounded-lg border border-brand-300 px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50 disabled:opacity-50"
        @click="createBatch('all')"
      >
        <i :class="['pi mr-1', creating ? 'pi-spin pi-spinner' : 'pi-users']" />
        สร้างรอบจ่ายทั้งหมด ({{ selectableAgents.length }} ตัวแทน)
      </button>
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
              <th class="px-4 py-3">วันที่จ่าย</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="batches.length === 0"><td colspan="7" class="px-4 py-8 text-center text-slate-400">ยังไม่มีรอบจ่าย</td></tr>
            <tr v-for="b in batches" :key="b.id" class="cursor-pointer hover:bg-slate-50" @click="openBatch(b)">
              <td class="px-4 py-3 text-slate-700">{{ fmtDate(b.fromDate) }} – {{ fmtDate(b.toDate) }}</td>
              <td class="px-4 py-3">
                <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[b.status]]">{{ STATUS_LABEL[b.status] }}</span>
              </td>
              <td class="px-4 py-3 text-right text-slate-600">{{ b.totalAgents }}</td>
              <td class="px-4 py-3 text-right text-slate-600">{{ b.totalItems }}</td>
              <td class="px-4 py-3 text-right font-medium text-slate-800">฿{{ money(b.totalAmount) }}</td>
              <td class="px-4 py-3 text-slate-600">{{ b.paymentDate ? fmtDate(b.paymentDate) : '—' }}</td>
              <td class="px-4 py-3 text-right"><i class="pi pi-chevron-right text-slate-300" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
