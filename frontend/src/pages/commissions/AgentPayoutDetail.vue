<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchAgentOutstandingDetail, payAgent,
  type AgentDetailItem,
} from '../../api/commissionPayouts'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const route = useRoute()
const router = useRouter()

const agentId = computed(() => String(route.params.agentId))
const fromDate = computed(() => (route.query.from ? String(route.query.from) : undefined))
const toDate = computed(() => (route.query.to ? String(route.query.to) : undefined))

const agent = ref<{ agentId: string; agentCode: string | null; agentName: string; vatType: string | null } | null>(null)
const items = ref<AgentDetailItem[]>([])
const totals = ref({ itemCount: 0, totalAmount: 0 })
const loading = ref(true)
const error = ref<string | null>(null)

const money = (n: number) =>
  n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const vatLabel = computed(() => {
  const t = agent.value?.vatType
  return t === '3' ? 'รวม VAT ในยอด' : t === '2' ? 'บวก VAT บนยอด' : 'ไม่มี VAT'
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchAgentOutstandingDetail(agentId.value, fromDate.value, toDate.value)
    agent.value = res.agent
    items.value = res.items
    totals.value = res.totals
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

// ── Pay flow ────────────────────────────────────────────────────────────
const showPay = ref(false)
const paymentDate = ref(new Date().toISOString().slice(0, 10))
const reference = ref('')
const note = ref('')
const paying = ref(false)
const payError = ref<string | null>(null)

async function doPay(): Promise<void> {
  if (!paymentDate.value) return
  paying.value = true
  payError.value = null
  try {
    const res = await payAgent(agentId.value, {
      paymentDate: paymentDate.value,
      reference: reference.value || undefined,
      fromDate: fromDate.value,
      toDate: toDate.value,
      note: note.value || undefined,
    })
    // Jump to the created batch so the user sees the audit record + PDFs.
    router.push({ name: 'commission-payout-detail', params: { id: res.data.id } })
  } catch (e: unknown) {
    payError.value = e instanceof ApiError ? e.message : 'ทำจ่ายไม่สำเร็จ'
  } finally {
    paying.value = false
  }
}

function openPolicy(it: AgentDetailItem): void {
  router.push({ name: 'policy-edit-draft', params: { id: String(it.policyId) } })
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-5xl px-4 py-6">
    <button
      type="button"
      class="mb-4 flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700"
      @click="router.push({ name: 'commission-payouts' })"
    >
      <i class="pi pi-arrow-left" /> กลับไปรายชื่อตัวแทน
    </button>

    <div v-if="loading" class="py-16 text-center text-slate-400">กำลังโหลด…</div>
    <div v-else-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>
    <div v-else-if="!agent" class="py-16 text-center text-slate-400">ไม่พบข้อมูลตัวแทน</div>

    <template v-else>
      <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold text-slate-800">{{ agent.agentName }}</h1>
          <p class="mt-1 text-sm text-slate-500">
            รหัส <b>{{ agent.agentCode }}</b> · {{ vatLabel }}
            <span v-if="fromDate || toDate"> · ช่วง {{ fmtDate(fromDate ?? null) }} – {{ fmtDate(toDate ?? null) }}</span>
          </p>
        </div>
        <button
          type="button"
          :disabled="totals.totalAmount <= 0"
          class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
          @click="showPay = true"
        >
          <i class="pi pi-check-circle mr-1" /> ทำจ่ายค่าคอม ฿{{ money(totals.totalAmount) }}
        </button>
      </header>

      <!-- Summary -->
      <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">รายการค้างจ่าย</div>
          <div class="mt-1 text-2xl font-semibold text-slate-800">{{ totals.itemCount }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ยอดค้างจ่ายรวม</div>
          <div class="mt-1 text-2xl font-semibold text-emerald-700">฿{{ money(totals.totalAmount) }}</div>
        </div>
      </div>

      <!-- Line items -->
      <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-3">เลขกรมธรรม์</th>
              <th class="px-4 py-3">วันแจ้งงาน</th>
              <th class="px-4 py-3 text-right">เบี้ยหลัก</th>
              <th class="px-4 py-3 text-right">ค่าคอม (หลัก)</th>
              <th class="px-4 py-3 text-right">ค่าคอม (พ่วง)</th>
              <th class="px-4 py-3 text-right">รวม</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="items.length === 0"><td colspan="7" class="px-4 py-10 text-center text-slate-400">ไม่มีรายการค้างจ่าย</td></tr>
            <tr v-for="(it, i) in items" :key="i" class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <button
                  v-if="it.policyNo || it.policyId"
                  type="button" class="text-brand-600 hover:underline"
                  @click="openPolicy(it)"
                >
                  {{ it.policyNo ?? '(ร่าง #' + it.policyId + ')' }}
                </button>
                <span v-else class="text-slate-400">—</span>
              </td>
              <td class="px-4 py-3 text-slate-600">{{ it.policyDate ? fmtDate(it.policyDate) : '—' }}</td>
              <td class="px-4 py-3 text-right text-slate-600">฿{{ money(it.basePremium) }}</td>
              <td class="px-4 py-3 text-right text-slate-600">฿{{ money(it.agentCommission) }}</td>
              <td class="px-4 py-3 text-right text-slate-600">฿{{ money(it.riderCommission) }}</td>
              <td class="px-4 py-3 text-right font-medium text-slate-800">฿{{ money(it.amount) }}</td>
              <td class="px-4 py-3 text-right">
                <span v-if="it.amount === 0" class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">฿0</span>
              </td>
            </tr>
          </tbody>
          <tfoot v-if="items.length" class="bg-slate-50">
            <tr>
              <td colspan="5" class="px-4 py-3 text-right text-sm font-medium text-slate-500">ยอดรวม</td>
              <td class="px-4 py-3 text-right text-base font-semibold text-emerald-700">฿{{ money(totals.totalAmount) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </template>

    <!-- Pay confirmation modal -->
    <div v-if="showPay" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
      <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold text-slate-800">ยืนยันการจ่ายค่าคอม</h2>
        <p class="mb-4 text-sm text-slate-500">
          {{ agent?.agentName }} ({{ agent?.agentCode }}) — {{ totals.itemCount }} รายการ
          รวม <b class="text-emerald-700">฿{{ money(totals.totalAmount) }}</b>
        </p>
        <div class="space-y-3">
          <div>
            <label class="mb-1 block text-xs text-slate-500">วันที่จ่าย</label>
            <input v-model="paymentDate" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-slate-500">เลขอ้างอิง (ถ้ามี)</label>
            <input v-model="reference" type="text" placeholder="เลขที่โอน / เช็ค" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-slate-500">หมายเหตุ (ถ้ามี)</label>
            <input v-model="note" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </div>
        </div>
        <div v-if="payError" class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ payError }}</div>
        <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
          <i class="pi pi-info-circle" /> ระบบจะสร้างรอบจ่ายเฉพาะตัวแทนนี้ อนุมัติ และบันทึกจ่ายให้อัตโนมัติ (มีบันทึกไว้สำหรับตรวจสอบ)
        </p>
        <div class="mt-5 flex justify-end gap-2">
          <button
            type="button" :disabled="paying"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
            @click="showPay = false"
          >
            ยกเลิก
          </button>
          <button
            type="button" :disabled="paying || !paymentDate"
            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
            @click="doPay"
          >
            <i v-if="paying" class="pi pi-spin pi-spinner mr-1" /> ยืนยันจ่าย
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
