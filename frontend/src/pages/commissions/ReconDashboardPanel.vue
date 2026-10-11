<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { fetchReconDashboard, type ReconDashboard } from '../../api/commissionReceipts'
import { ApiError } from '../../api/client'
import { STATUS_BADGE, STATUS_LABEL, money, signedMoney } from './receiptLabels'

const props = defineProps<{ insurerId: number | null; policyYear: number | null; monthFrom: string; monthTo: string }>()

const data = ref<ReconDashboard | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    data.value = (await fetchReconDashboard({
      insurerId: props.insurerId ?? undefined,
      policyYear: props.policyYear ?? undefined,
      monthFrom: props.monthFrom || undefined,
      monthTo: props.monthTo || undefined,
    })).data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

const monthlyMax = computed(() => Math.max(1, ...(data.value?.monthly ?? []).flatMap(p => [p.expected, p.received])))
const insurerMax = computed(() => Math.max(1, ...(data.value?.outstandingByInsurer ?? []).map(i => i.amount)))
const reasonTotal = computed(() => (data.value?.mismatchByReason ?? []).reduce((s, r) => s + r.count, 0))

watch(() => [props.insurerId, props.policyYear, props.monthFrom, props.monthTo], load)
onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading" class="py-10 text-center text-slate-400">กำลังโหลด…</div>
    <div v-else-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>
    <template v-else-if="data">
      <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ควรได้รับจากบริษัท</div>
          <div class="mt-1 text-xl font-semibold text-slate-800">{{ money(data.kpi.expectedInsurer || data.kpi.potentialExpected) }}</div>
          <div v-if="data.kpi.expectedInsurer === 0 && data.kpi.potentialExpected > 0" class="mt-0.5 text-[10px] text-sky-600">ยอดคาดการณ์จากกรมธรรม์</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">รับแล้ว</div>
          <div class="mt-1 text-xl font-semibold text-emerald-700">{{ money(data.kpi.receivedInsurer) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ค้างรับ</div>
          <div class="mt-1 text-xl font-semibold text-amber-700">{{ money(data.kpi.outstanding) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">รายการปรับปรุง รับแล้ว / ค้าง</div>
          <div class="mt-1 text-xl font-semibold text-violet-700">{{ money(data.kpi.adjustmentReceived) }}</div>
          <div class="text-[11px] text-slate-400">ค้าง {{ money(data.kpi.adjustmentOpen) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ค่าคอมตัวแทนที่ต้องจ่าย (พื้นฐาน)</div>
          <div class="mt-1 text-xl font-semibold text-slate-800">{{ money(data.kpi.agentPayable) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">จ่ายตัวแทนแล้ว (พื้นฐาน)</div>
          <div class="mt-1 text-xl font-semibold text-sky-700">{{ money(data.kpi.agentPaid) }}</div>
          <div class="text-[11px] text-slate-400">ค่าแนะนำ / ค่าบริหารที่จ่าย {{ money(data.kpi.agentMgmPaid) }}</div>
        </div>
        <div class="rounded-xl border border-brand-200 bg-brand-50 p-4">
          <div class="text-xs text-brand-600">ส่วนต่างที่คาดไว้</div>
          <div class="mt-1 text-xl font-semibold text-brand-700">{{ money(data.kpi.expectedMargin) }}</div>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
          <div class="text-xs text-emerald-600">ส่วนต่างจากเงินจริง</div>
          <div class="mt-1 text-xl font-semibold text-emerald-700">{{ money(data.kpi.realizedMargin) }}</div>
        </div>
      </div>

      <div class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">สรุปเคสยอดไม่ตรงตามสาเหตุ</h3>
        <div v-if="reasonTotal === 0 && data.unresolvedMismatch.count === 0" class="py-3 text-center text-xs text-slate-400">ยังไม่มีเคสยอดไม่ตรง</div>
        <table v-else class="min-w-full text-sm">
          <thead class="text-left text-xs text-slate-500">
            <tr><th class="py-2">สาเหตุ</th><th class="py-2 text-right">จำนวนรายการ</th><th class="py-2 text-right">ส่วนต่างรวม</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in data.mismatchByReason" :key="r.reason">
              <td class="py-2 text-slate-700">{{ r.label }}</td>
              <td class="py-2 text-right text-slate-700">{{ r.count }}</td>
              <td class="py-2 text-right" :class="r.difference < 0 ? 'text-rose-600' : 'text-amber-600'">{{ signedMoney(r.difference) }}</td>
            </tr>
            <tr v-if="data.unresolvedMismatch.count">
              <td class="py-2 text-slate-500">ยังไม่ได้ระบุสาเหตุ (ยอดไม่ตรง ยังไม่รับ)</td>
              <td class="py-2 text-right text-slate-500">{{ data.unresolvedMismatch.count }}</td>
              <td class="py-2 text-right text-slate-500">{{ signedMoney(data.unresolvedMismatch.difference) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <h3 class="mb-3 text-sm font-semibold text-slate-700">ควรได้ เทียบ รับแล้ว ตามเดือนเริ่มคุ้มครอง</h3>
          <div v-if="data.monthly.length === 0" class="py-6 text-center text-xs text-slate-400">ไม่มีข้อมูล</div>
          <div v-else class="space-y-2">
            <div v-for="p in data.monthly" :key="p.month" class="text-xs">
              <div class="mb-0.5 flex justify-between text-slate-500"><span>{{ p.month }}</span><span>{{ money(p.received) }} / {{ money(p.expected) }}</span></div>
              <div class="relative h-3 rounded bg-slate-100">
                <div class="absolute inset-y-0 left-0 rounded bg-slate-300" :style="{ width: (Math.max(0, p.expected) / monthlyMax * 100) + '%' }" />
                <div class="absolute inset-y-0 left-0 rounded bg-emerald-500" :style="{ width: (Math.max(0, p.received) / monthlyMax * 100) + '%' }" />
              </div>
            </div>
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <h3 class="mb-3 text-sm font-semibold text-slate-700">ค้างรับ แยกตามบริษัทประกัน</h3>
          <div v-if="data.outstandingByInsurer.length === 0" class="py-6 text-center text-xs text-slate-400">ไม่มีข้อมูล</div>
          <div v-else class="space-y-2">
            <div v-for="i in data.outstandingByInsurer" :key="i.insurer" class="text-xs">
              <div class="mb-0.5 flex justify-between text-slate-500"><span class="truncate pr-2">{{ i.insurer }}</span><span>{{ money(i.amount) }}</span></div>
              <div class="h-3 rounded bg-slate-100"><div class="h-3 rounded bg-amber-500" :style="{ width: (Math.max(0, i.amount) / insurerMax * 100) + '%' }" /></div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-700">กระทบยอดรายกรมธรรม์</div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs text-slate-500">
            <tr>
              <th class="px-4 py-2">กรมธรรม์</th>
              <th class="px-4 py-2 text-right">ควรได้</th>
              <th class="px-4 py-2 text-right">รับแล้ว</th>
              <th class="px-4 py-2">หลัก</th>
              <th class="px-4 py-2">OV</th>
              <th class="px-4 py-2 text-center">ปรับปรุง</th>
              <th class="px-4 py-2 text-right">ต้องจ่ายตัวแทน</th>
              <th class="px-4 py-2 text-right">จ่ายแล้ว</th>
              <th class="px-4 py-2 text-right">ส่วนต่างเงินจริง</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="data.policyLevel.length === 0"><td colspan="9" class="px-4 py-8 text-center text-slate-400">ไม่มีข้อมูล</td></tr>
            <tr v-for="r in data.policyLevel" :key="r.policyId" class="hover:bg-slate-50">
              <td class="px-4 py-2 text-slate-700">{{ r.policyNo || '—' }}</td>
              <td class="px-4 py-2 text-right text-slate-600">{{ money(r.insurerExpected) }}</td>
              <td class="px-4 py-2 text-right text-slate-600">{{ money(r.insurerReceived) }}</td>
              <td class="px-4 py-2"><span v-if="r.mainStatus" :class="['rounded-full px-2 py-0.5 text-[10px]', STATUS_BADGE[r.mainStatus]]">{{ STATUS_LABEL[r.mainStatus] }}</span></td>
              <td class="px-4 py-2"><span v-if="r.ovStatus" :class="['rounded-full px-2 py-0.5 text-[10px]', STATUS_BADGE[r.ovStatus]]">{{ STATUS_LABEL[r.ovStatus] }}</span></td>
              <td class="px-4 py-2 text-center text-slate-500">{{ r.adjustments || '' }}</td>
              <td class="px-4 py-2 text-right text-slate-600">{{ money(r.agentPayable) }}</td>
              <td class="px-4 py-2 text-right text-slate-600">{{ money(r.agentPaid) }}</td>
              <td class="px-4 py-2 text-right" :class="r.cashMargin < 0 ? 'text-rose-600' : 'text-slate-700'">{{ money(r.cashMargin) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
