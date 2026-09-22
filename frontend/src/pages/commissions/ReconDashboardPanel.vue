<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import { fetchReconDashboard, type ReconDashboard } from '../../api/commissionReceipts'
import type { CarrierListRow } from '../../api/carriers'
import { ApiError } from '../../api/client'

const props = defineProps<{ carriers: CarrierListRow[]; policyYear: number | null; insurerId: number | null }>()

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
const money2 = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const data = ref<ReconDashboard | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const STATUS_LABEL: Record<string, string> = {
  'Pending': 'รอตรวจ', 'Matched': 'ยอดตรง', 'Mismatch': 'ยอดไม่ตรง', 'Received': 'รับแล้ว', 'No Commission': 'ไม่มีค่าคอม',
}
const STATUS_BADGE: Record<string, string> = {
  'Pending': 'bg-slate-100 text-slate-600', 'Matched': 'bg-emerald-50 text-emerald-700',
  'Mismatch': 'bg-rose-50 text-rose-700', 'Received': 'bg-emerald-100 text-emerald-800', 'No Commission': 'bg-slate-200 text-slate-600',
}

async function load(): Promise<void> {
  loading.value = true; error.value = null
  try {
    data.value = (await fetchReconDashboard(props.policyYear ?? undefined, props.insurerId ?? undefined)).data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

// max for the monthly chart scale
const monthlyMax = computed(() => {
  const m = data.value?.monthly ?? []
  return Math.max(1, ...m.flatMap(p => [p.expected, p.received]))
})
const insurerMax = computed(() => Math.max(1, ...(data.value?.outstandingByInsurer ?? []).map(i => i.amount)))

watch(() => [props.policyYear, props.insurerId], load)
onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading" class="py-10 text-center text-slate-400">กำลังโหลด…</div>
    <div v-else-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>
    <template v-else-if="data">
      <!-- KPI cards -->
      <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ควรได้รับ (บริษัทประกัน)</div>
          <div class="mt-1 text-xl font-semibold text-slate-800">฿{{ money(data.kpi.expectedInsurer) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">รับแล้ว</div>
          <div class="mt-1 text-xl font-semibold text-emerald-700">฿{{ money(data.kpi.receivedInsurer) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ค้างรับ</div>
          <div class="mt-1 text-xl font-semibold text-amber-700">฿{{ money(data.kpi.outstanding) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ยอดไม่ตรง / ไม่มีค่าคอม</div>
          <div class="mt-1 text-xl font-semibold text-rose-700">{{ data.kpi.mismatchCount }} / {{ data.kpi.noCommissionCount }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ต้องจ่ายตัวแทน</div>
          <div class="mt-1 text-xl font-semibold text-slate-800">฿{{ money(data.kpi.agentPayable) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">จ่ายตัวแทนแล้ว</div>
          <div class="mt-1 text-xl font-semibold text-sky-700">฿{{ money(data.kpi.agentPaid) }}</div>
        </div>
        <div class="rounded-xl border border-brand-200 bg-brand-50 p-4">
          <div class="text-xs text-brand-600">กำไรคาดการณ์ (Expected Margin)</div>
          <div class="mt-1 text-xl font-semibold text-brand-700">฿{{ money(data.kpi.expectedMargin) }}</div>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
          <div class="text-xs text-emerald-600">กำไรที่รับรู้ (Realized Margin)</div>
          <div class="mt-1 text-xl font-semibold text-emerald-700">฿{{ money(data.kpi.realizedMargin) }}</div>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- Chart 1: Expected vs Received by month -->
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <h3 class="mb-3 text-sm font-semibold text-slate-700">ควรได้ vs รับแล้ว รายเดือน</h3>
          <div v-if="data.monthly.length === 0" class="py-6 text-center text-xs text-slate-400">ไม่มีข้อมูล</div>
          <div v-else class="space-y-2">
            <div v-for="p in data.monthly" :key="p.month" class="text-xs">
              <div class="mb-0.5 flex justify-between text-slate-500"><span>{{ p.month }}</span><span>฿{{ money(p.received) }} / ฿{{ money(p.expected) }}</span></div>
              <div class="relative h-3 rounded bg-slate-100">
                <div class="absolute inset-y-0 left-0 rounded bg-slate-300" :style="{ width: (p.expected / monthlyMax * 100) + '%' }" />
                <div class="absolute inset-y-0 left-0 rounded bg-emerald-500" :style="{ width: (p.received / monthlyMax * 100) + '%' }" />
              </div>
            </div>
            <div class="mt-2 flex gap-4 text-[10px] text-slate-500">
              <span><i class="inline-block h-2 w-2 rounded-sm bg-slate-300" /> ควรได้</span>
              <span><i class="inline-block h-2 w-2 rounded-sm bg-emerald-500" /> รับแล้ว</span>
            </div>
          </div>
        </div>

        <!-- Chart 2: Outstanding by insurer -->
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <h3 class="mb-3 text-sm font-semibold text-slate-700">ค้างรับ แยกตามบริษัทประกัน</h3>
          <div v-if="data.outstandingByInsurer.length === 0" class="py-6 text-center text-xs text-slate-400">ไม่มีข้อมูล</div>
          <div v-else class="space-y-2">
            <div v-for="i in data.outstandingByInsurer" :key="i.insurer" class="text-xs">
              <div class="mb-0.5 flex justify-between text-slate-500"><span class="truncate pr-2">{{ i.insurer }}</span><span>฿{{ money(i.amount) }}</span></div>
              <div class="h-3 rounded bg-slate-100"><div class="h-3 rounded bg-amber-500" :style="{ width: (i.amount / insurerMax * 100) + '%' }" /></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Policy-level reconciliation table -->
      <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-700">กระทบยอดระดับกรมธรรม์</div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-2">กรมธรรม์</th>
              <th class="px-4 py-2 text-right">ควรได้ (บ.ประกัน)</th>
              <th class="px-4 py-2 text-right">รับแล้ว</th>
              <th class="px-4 py-2">Main</th>
              <th class="px-4 py-2">OV</th>
              <th class="px-4 py-2 text-right">จ่ายตัวแทน (ต้อง)</th>
              <th class="px-4 py-2 text-right">จ่ายแล้ว</th>
              <th class="px-4 py-2 text-right">กำไรคาด</th>
              <th class="px-4 py-2 text-right">กำไรเงินสด</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="data.policyLevel.length === 0"><td colspan="9" class="px-4 py-8 text-center text-slate-400">ไม่มีข้อมูล</td></tr>
            <tr v-for="r in data.policyLevel" :key="r.policyId" class="hover:bg-slate-50">
              <td class="px-4 py-2 text-slate-700">{{ r.policyNo || '—' }}</td>
              <td class="px-4 py-2 text-right text-slate-600">฿{{ money2(r.insurerExpected) }}</td>
              <td class="px-4 py-2 text-right text-slate-600">฿{{ money2(r.insurerReceived) }}</td>
              <td class="px-4 py-2"><span v-if="r.mainStatus" :class="['rounded-full px-2 py-0.5 text-[10px]', STATUS_BADGE[r.mainStatus]]">{{ STATUS_LABEL[r.mainStatus] }}</span></td>
              <td class="px-4 py-2"><span v-if="r.ovStatus" :class="['rounded-full px-2 py-0.5 text-[10px]', STATUS_BADGE[r.ovStatus]]">{{ STATUS_LABEL[r.ovStatus] }}</span></td>
              <td class="px-4 py-2 text-right text-slate-600">฿{{ money2(r.agentPayable) }}</td>
              <td class="px-4 py-2 text-right text-slate-600">฿{{ money2(r.agentPaid) }}</td>
              <td class="px-4 py-2 text-right" :class="r.expectedMargin < 0 ? 'text-rose-600' : 'text-slate-700'">฿{{ money2(r.expectedMargin) }}</td>
              <td class="px-4 py-2 text-right" :class="r.cashMargin < 0 ? 'text-rose-600' : 'text-slate-700'">฿{{ money2(r.cashMargin) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
