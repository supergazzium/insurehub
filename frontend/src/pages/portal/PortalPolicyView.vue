<script setup lang="ts">
// Agent portal — READ-ONLY policy detail. Reached from the follow-up list.
// Shows a clean summary of one of the agent's own policies (header, coverage,
// premium, payment history) with NO edit controls. The backend
// (me/agent/policies/{id}) returns 404 for any policy this agent doesn't write,
// so an agent can never view another agent's policy here.
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchMyAgentPolicy } from '../../api/policies'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'
import type { Policy } from '../../stores/policies'

const route = useRoute()
const router = useRouter()

const policy = ref<Policy | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const STATUS_LABEL: Record<string, string> = {
  submitted: 'รออนุมัติ', approved: 'อนุมัติแล้ว', active: 'มีผลบังคับ', issued: 'ออกกรมธรรม์แล้ว',
  cancelled: 'ยกเลิก', draft: 'ร่าง', quotation: 'ใบเสนอราคา', expired: 'หมดอายุ',
}
const MODE_LABEL: Record<string, string> = {
  monthly: 'รายเดือน', quarterly: 'ราย 3 เดือน', semiannual: 'ราย 6 เดือน', annual: 'รายปี', single: 'จ่ายครั้งเดียว',
}
const money = (n: number | null | undefined) =>
  n == null ? '—' : `฿${Number(n).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

// PolicyResource embeds the payment rows; render them read-only.
interface EmbeddedPayment {
  id: string | number
  paymentDate?: string | null
  amount?: number | null
  method?: string | null
  installmentNo?: number | null
  voidedAt?: string | null
}
const payments = computed<EmbeddedPayment[]>(() => {
  const p = policy.value as unknown as { payments?: EmbeddedPayment[] } | null
  return (p?.payments ?? []).filter((x) => !x.voidedAt)
})
const statusLabel = computed(() => {
  const p = policy.value
  if (!p) return ''
  return p.statusLabel || STATUS_LABEL[p.status] || p.status
})
// The policy-detail resource doesn't compose a customer name; the follow-up list
// passes it through as a query param. Falls back to the customer id.
const customerName = computed(() => {
  const q = route.query.customer
  if (typeof q === 'string' && q.trim()) return q
  return policy.value?.customerId ? `#${policy.value.customerId}` : '—'
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchMyAgentPolicy(String(route.params.id))
    policy.value = res.data
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 404) {
      error.value = 'ไม่พบกรมธรรม์นี้ หรือไม่ใช่กรมธรรม์ของคุณ'
    } else {
      error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
    }
  } finally {
    loading.value = false
  }
}

function goBack(): void {
  if (window.history.length > 1) router.back()
  else void router.push({ name: 'portal-follow-ups' })
}

onMounted(load)
</script>

<template>
  <div>
    <button type="button" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800" @click="goBack">
      <i class="pi pi-arrow-left text-xs" /> กลับ
    </button>

    <div v-if="loading" class="rounded-xl border border-slate-200 bg-white px-6 py-16 text-center text-slate-400">กำลังโหลด…</div>
    <div v-else-if="error" class="rounded-xl bg-rose-50 px-6 py-12 text-center text-rose-700">{{ error }}</div>

    <div v-else-if="policy" class="space-y-4">
      <!-- Header -->
      <div class="rounded-xl border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="text-xs uppercase tracking-wide text-slate-400">เลขกรมธรรม์</div>
            <div class="text-xl font-semibold text-slate-800">{{ policy.policyNo || policy.applicationNo || '—' }}</div>
            <div v-if="policy.applicationNo" class="text-xs text-slate-400">ใบคำขอ {{ policy.applicationNo }}</div>
          </div>
          <span class="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-600">{{ statusLabel }}</span>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
          <div><div class="text-xs text-slate-400">ลูกค้า</div><div class="text-slate-800">{{ customerName }}</div></div>
          <div><div class="text-xs text-slate-400">บริษัทประกัน</div><div class="text-slate-800">{{ policy.carrierName || '—' }}</div></div>
          <div><div class="text-xs text-slate-400">แบบประกัน</div><div class="text-slate-800">{{ policy.productName || '—' }}</div></div>
        </div>
      </div>

      <!-- Coverage + premium -->
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ความคุ้มครอง</h2>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-slate-400">วันเริ่มคุ้มครอง</dt><dd class="text-slate-800">{{ fmtDate(policy.effectiveDate) || '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-400">วันสิ้นสุด</dt><dd class="text-slate-800">{{ fmtDate(policy.expiryDate) || '—' }}</dd></div>
          </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">เบี้ยประกัน</h2>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-slate-400">งวดการชำระ</dt><dd class="text-slate-800">{{ MODE_LABEL[policy.premiumMode] || policy.premiumMode || '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-400">เบี้ยรวม/ปี</dt><dd class="font-medium text-slate-800">{{ money((policy as unknown as { annualPremium?: number }).annualPremium) }}</dd></div>
          </dl>
        </div>
      </div>

      <!-- Payment history (read-only) -->
      <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="mb-3 text-sm font-semibold text-slate-700">ประวัติการชำระเงิน <span class="text-xs font-normal text-slate-400">({{ payments.length }} รายการ)</span></h2>
        <div v-if="payments.length === 0" class="py-6 text-center text-sm text-slate-400">ยังไม่มีรายการชำระเงิน</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
              <tr><th class="py-2 pr-4">วันที่ชำระ</th><th class="py-2 pr-4">งวด</th><th class="py-2 pr-4">จำนวนเงิน</th><th class="py-2">ช่องทาง</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="pay in payments" :key="pay.id">
                <td class="py-2 pr-4 text-slate-700">{{ fmtDate(pay.paymentDate) || '—' }}</td>
                <td class="py-2 pr-4 text-slate-500">{{ pay.installmentNo != null ? `${pay.installmentNo}#` : '—' }}</td>
                <td class="py-2 pr-4 font-medium text-slate-800">{{ money(pay.amount) }}</td>
                <td class="py-2 text-slate-500">{{ pay.method || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="text-center text-xs text-slate-400">ดูอย่างเดียว — หากต้องแก้ไข กรุณาติดต่อเจ้าหน้าที่</p>
    </div>
  </div>
</template>
