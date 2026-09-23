<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchCollection, fetchReminders, createReminder,
  type CollectionDetail, type CollectionKind, type ReminderRow,
} from '../../api/collections'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'
import PolicyPaymentModal from './PolicyPaymentModal.vue'

const route = useRoute()
const router = useRouter()
const id = String(route.params.id)

const data = ref<CollectionDetail | null>(null)
const reminders = ref<ReminderRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const KIND_LABEL: Record<CollectionKind, string> = {
  cash: 'เงินสด', installment: 'ผ่อน (InsureHub)', split: 'แบ่งชำระ (บริษัทประกัน)',
}
const KIND_BADGE: Record<CollectionKind, string> = {
  cash: 'bg-slate-100 text-slate-600', installment: 'bg-sky-50 text-sky-700', split: 'bg-violet-50 text-violet-700',
}
function channelLabel(c: string): string {
  return { phone: 'โทรศัพท์', line: 'LINE', email: 'อีเมล', sms: 'SMS', inperson: 'พบตัว', other: 'อื่นๆ' }[c] ?? c
}
const METHOD_LABEL: Record<string, string> = {
  bankTransfer: 'โอน', cash: 'เงินสด', creditCard: 'บัตรเครดิต', cheque: 'เช็ค', legacyImport: 'ข้อมูลเดิม',
}

const progressPct = computed(() => {
  const d = data.value
  if (!d || d.totalDue <= 0) return 0
  return Math.min(100, Math.round((d.paid / d.totalDue) * 100))
})

async function load(): Promise<void> {
  loading.value = true; error.value = null
  try {
    const [c, r] = await Promise.all([fetchCollection(id), fetchReminders(id)])
    data.value = c.data
    reminders.value = r.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

// ── record payment (reuse the policy payment modal) ──────────────────────
const paymentExpected = computed(() => ({
  netPremium: data.value?.totalDue ?? 0,
  dutyStamp: 0, vat: 0,
  totalPremiumPaid: data.value?.totalDue ?? 0,
  discountAmount: 0, commissionAmount: 0,
}))
const showPayment = ref(false)
function onPaymentSaved(): void { showPayment.value = false; load() }

// ── log a reminder ───────────────────────────────────────────────────────
const showReminder = ref(false)
const remForm = ref<{ channel: string; note: string; installmentNo: number | null }>({ channel: 'phone', note: '', installmentNo: null })
const remSaving = ref(false)
const remError = ref<string | null>(null)
function openReminder(installmentNo: number | null = null): void {
  remForm.value = { channel: 'phone', note: '', installmentNo }
  remError.value = null
  showReminder.value = true
}
async function saveReminder(): Promise<void> {
  remSaving.value = true; remError.value = null
  try {
    await createReminder(id, {
      channel: remForm.value.channel,
      note: remForm.value.note || undefined,
      installmentNo: remForm.value.installmentNo ?? undefined,
      amountDue: data.value?.outstanding,
    })
    showReminder.value = false
    await load()
  } catch (e: unknown) {
    remError.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
  } finally {
    remSaving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-6">
    <button type="button" class="mb-3 text-sm text-slate-500 hover:text-slate-700" @click="router.push({ name: 'policies-collections' })">
      <i class="pi pi-arrow-left mr-1" /> กลับหน้าติดตามเงิน
    </button>

    <div v-if="loading" class="py-10 text-center text-slate-400">กำลังโหลด…</div>
    <div v-else-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <template v-else-if="data">
      <!-- Header -->
      <header class="mb-4">
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="text-xl font-semibold text-slate-800">{{ data.policyNo || data.applicationNo || '—' }}</h1>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', KIND_BADGE[data.kind]]">{{ KIND_LABEL[data.kind] }}</span>
        </div>
        <p class="mt-1 text-sm text-slate-500">
          {{ data.customerName }}<span v-if="data.customerCode" class="text-slate-400"> ({{ data.customerCode }})</span>
          <span v-if="data.customerPhone"> · <a :href="`tel:${data.customerPhone}`" class="text-brand-600 hover:underline">{{ data.customerPhone }}</a></span>
        </p>
        <p class="text-xs text-slate-400">{{ data.carrierName }} · {{ data.productName }} · ตัวแทน {{ data.agentName || '—' }}</p>
      </header>

      <!-- Money summary -->
      <div class="mb-4 rounded-xl border border-slate-200 bg-white p-5">
        <div class="grid grid-cols-3 gap-4">
          <div>
            <div class="text-xs text-slate-500">ยอดที่ต้องชำระ</div>
            <div class="mt-0.5 text-xl font-semibold text-slate-800">฿{{ money(data.totalDue) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">ชำระแล้ว</div>
            <div class="mt-0.5 text-xl font-semibold text-emerald-700">฿{{ money(data.paid) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">ค้างชำระ</div>
            <div class="mt-0.5 text-xl font-semibold text-rose-700">฿{{ money(data.outstanding) }}</div>
          </div>
        </div>
        <div class="mt-3">
          <div class="h-2 overflow-hidden rounded-full bg-slate-100">
            <div class="h-2 rounded-full bg-emerald-500" :style="{ width: progressPct + '%' }" />
          </div>
          <div class="mt-1 text-[11px] text-slate-400">ชำระแล้ว {{ progressPct }}%<span v-if="data.overdueCount > 0" class="ml-2 text-rose-600">· เกินกำหนด {{ data.overdueCount }} งวด</span></div>
        </div>
        <div class="mt-4 flex gap-2">
          <button type="button" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showPayment = true">
            <i class="pi pi-wallet mr-1" /> บันทึกการชำระเงิน
          </button>
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="openReminder()">
            <i class="pi pi-bell mr-1" /> บันทึกการทวง
          </button>
        </div>
      </div>

      <!-- Installment schedule -->
      <div v-if="data.schedule && data.schedule.length" class="mb-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-700">ตารางผ่อนชำระ ({{ data.installmentCount }} งวด)</div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr><th class="px-4 py-2">งวด</th><th class="px-4 py-2 text-right">จำนวน</th><th class="px-4 py-2">ครบกำหนด</th><th class="px-4 py-2">สถานะ</th><th class="px-4 py-2"></th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="s in data.schedule" :key="s.no" :class="s.overdue ? 'bg-rose-50/40' : ''">
              <td class="px-4 py-2 text-slate-700">งวดที่ {{ s.no }}</td>
              <td class="px-4 py-2 text-right text-slate-700">฿{{ money(s.amount) }}</td>
              <td class="px-4 py-2 text-slate-500">{{ s.dueDate ? fmtDate(s.dueDate) : '—' }}</td>
              <td class="px-4 py-2">
                <span v-if="s.paid" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">ชำระแล้ว</span>
                <span v-else-if="s.overdue" class="rounded-full bg-rose-50 px-2 py-0.5 text-xs text-rose-700">เกินกำหนด</span>
                <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">รอชำระ</span>
              </td>
              <td class="px-4 py-2 text-right">
                <button v-if="!s.paid" type="button" class="text-xs text-brand-600 hover:text-brand-700" @click="openReminder(s.no)">ทวงงวดนี้</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- Payment records -->
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <h3 class="mb-2 text-sm font-semibold text-slate-700">ประวัติการชำระเงิน ({{ data.payments.length }})</h3>
          <div v-if="data.payments.length === 0" class="text-xs text-slate-400">— ยังไม่มีการบันทึกชำระ —</div>
          <ul v-else class="divide-y divide-slate-100 text-sm">
            <li v-for="p in data.payments" :key="p.id" class="flex items-center justify-between py-2">
              <div>
                <div class="text-slate-700">{{ p.paymentDate ? fmtDate(p.paymentDate) : '—' }}</div>
                <div class="text-[10px] text-slate-400">{{ METHOD_LABEL[p.method ?? ''] ?? p.method }}<span v-if="p.reference"> · {{ p.reference }}</span></div>
              </div>
              <div class="font-medium text-emerald-700">฿{{ money(p.amount) }}</div>
            </li>
          </ul>
        </div>

        <!-- Reminder history -->
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <h3 class="mb-2 text-sm font-semibold text-slate-700">ประวัติการทวง ({{ reminders.length }})</h3>
          <div v-if="reminders.length === 0" class="text-xs text-slate-400">— ยังไม่มีประวัติการทวง —</div>
          <ul v-else class="divide-y divide-slate-100 text-sm">
            <li v-for="rm in reminders" :key="rm.id" class="py-2">
              <div class="flex items-center justify-between">
                <span class="text-slate-700">{{ channelLabel(rm.channel) }}<span v-if="rm.installmentNo" class="text-slate-400"> · งวดที่ {{ rm.installmentNo }}</span></span>
                <span class="text-[10px] text-slate-400">{{ rm.createdAt ? fmtDate(rm.createdAt) : '' }}</span>
              </div>
              <div v-if="rm.note" class="text-xs text-slate-500">{{ rm.note }}</div>
            </li>
          </ul>
        </div>
      </div>
    </template>

    <!-- Payment modal (reused) -->
    <PolicyPaymentModal
      v-if="showPayment && data"
      :open="showPayment"
      :policy-id="id"
      :expected="paymentExpected"
      :carrier-label="data.carrierName || ''"
      :main-premium="data.totalDue"
      :installment-mode="data.installmentMode"
      :installment-count="data.installmentCount ?? undefined"
      @close="showPayment = false" @saved="onPaymentSaved" />

    <!-- Reminder dialog -->
    <div v-if="showReminder" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="showReminder = false">
      <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-1 font-semibold text-slate-800">บันทึกการทวง</h3>
        <p class="mb-3 text-xs text-slate-500">{{ data?.policyNo || data?.applicationNo }}<span v-if="remForm.installmentNo"> · งวดที่ {{ remForm.installmentNo }}</span></p>
        <label class="mb-1 block text-xs text-slate-500">ช่องทาง</label>
        <select v-model="remForm.channel" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
          <option value="phone">โทรศัพท์</option><option value="line">LINE</option><option value="email">อีเมล</option>
          <option value="sms">SMS</option><option value="inperson">พบตัว</option><option value="other">อื่นๆ</option>
        </select>
        <label class="mb-1 block text-xs text-slate-500">หมายเหตุ</label>
        <textarea v-model="remForm.note" rows="2" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" placeholder="เช่น โทรแล้วลูกค้าจะโอนภายในวันศุกร์" />
        <div v-if="remError" class="mb-2 text-sm text-rose-600">{{ remError }}</div>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600" @click="showReminder = false">ยกเลิก</button>
          <button type="button" :disabled="remSaving" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="saveReminder">บันทึก</button>
        </div>
      </div>
    </div>
  </div>
</template>
