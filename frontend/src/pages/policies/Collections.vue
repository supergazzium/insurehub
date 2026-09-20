<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchCollections, createReminder, fetchReminders,
  type CollectionRow, type CollectionKind, type ReminderRow,
} from '../../api/collections'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()

const rows = ref<CollectionRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const tab = ref<'policy' | 'installment'>('policy')  // รายกรมธรรม์ | รายงวด
const kind = ref<CollectionKind | 'all'>('all')
const q = ref('')

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const KIND_LABEL: Record<CollectionKind, string> = {
  cash: 'เงินสด', installment: 'ผ่อน (InsureHub)', split: 'แบ่งชำระ (บริษัทประกัน)',
}
const KIND_BADGE: Record<CollectionKind, string> = {
  cash: 'bg-slate-100 text-slate-600',
  installment: 'bg-sky-50 text-sky-700',
  split: 'bg-violet-50 text-violet-700',
}

let debounce: number | undefined
async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchCollections({ kind: kind.value, q: q.value || undefined })
    rows.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
function reload(): void { window.clearTimeout(debounce); debounce = window.setTimeout(load, 250) }

// รายงวด view = flatten each installment policy's overdue/unpaid งวด into rows.
interface GuadRow {
  policy: CollectionRow
  no: number
  amount: number
  dueDate: string | null
  overdue: boolean
}
const installmentGuadRows = computed<GuadRow[]>(() => {
  const out: GuadRow[] = []
  for (const p of rows.value) {
    if (!p.schedule) continue
    for (const s of p.schedule) {
      if (s.paid) continue
      out.push({ policy: p, no: s.no, amount: s.amount, dueDate: s.dueDate, overdue: s.overdue })
    }
  }
  // overdue first, then by due date
  return out.sort((a, b) => (Number(b.overdue) - Number(a.overdue)) || String(a.dueDate).localeCompare(String(b.dueDate)))
})

const summary = computed(() => {
  const totalOutstanding = rows.value.reduce((s, r) => s + r.outstanding, 0)
  const overduePolicies = rows.value.filter((r) => r.overdueCount > 0).length
  return { count: rows.value.length, totalOutstanding, overduePolicies }
})

function openPolicy(id: string) { router.push({ name: 'policy-edit-draft', params: { id } }) }

// ── Reminder modal ─────────────────────────────────────────────────────────
const reminderModal = ref<{ policy: CollectionRow; installmentNo: number | null } | null>(null)
const reminderForm = ref<{ channel: string; note: string }>({ channel: 'phone', note: '' })
const reminderHistory = ref<ReminderRow[]>([])
const reminderSaving = ref(false)
const flash = ref<{ ok: boolean; text: string } | null>(null)

async function openReminder(policy: CollectionRow, installmentNo: number | null = null): Promise<void> {
  reminderModal.value = { policy, installmentNo }
  reminderForm.value = { channel: 'phone', note: '' }
  reminderHistory.value = []
  try {
    const res = await fetchReminders(policy.policyId)
    reminderHistory.value = res.data
  } catch { /* history just empty */ }
}
async function submitReminder(): Promise<void> {
  const m = reminderModal.value
  if (!m) return
  reminderSaving.value = true
  try {
    await createReminder(m.policy.policyId, {
      channel: reminderForm.value.channel,
      note: reminderForm.value.note || undefined,
      installmentNo: m.installmentNo,
      amountDue: m.installmentNo
        ? (m.policy.schedule?.find((s) => s.no === m.installmentNo)?.amount ?? null)
        : m.policy.outstanding,
    })
    flash.value = { ok: true, text: 'บันทึกการทวงแล้ว' }
    reminderModal.value = null
    await load()
  } catch (e: unknown) {
    flash.value = { ok: false, text: e instanceof ApiError ? e.message : 'บันทึกล้มเหลว' }
  } finally {
    reminderSaving.value = false
    setTimeout(() => { flash.value = null }, 3500)
  }
}

function channelLabel(c: string): string {
  return { phone: 'โทรศัพท์', line: 'LINE', email: 'อีเมล', sms: 'SMS', inperson: 'พบตัว', other: 'อื่นๆ' }[c] ?? c
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-5">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-lg font-semibold text-slate-800">ติดตามเงิน (ค้างชำระ)</h1>
        <p class="text-[11px] text-slate-400">
          ทั้งหมด {{ summary.count }} กรมธรรม์ · ค้างชำระรวม ฿{{ money(summary.totalOutstanding) }}
          <span v-if="summary.overduePolicies" class="text-rose-600"> · เกินกำหนด {{ summary.overduePolicies }}</span>
        </p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="mb-3 flex gap-1 border-b border-slate-200">
      <button type="button"
        :class="['px-4 py-2 text-sm font-medium -mb-px border-b-2', tab === 'policy' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800']"
        @click="tab = 'policy'">รายกรมธรรม์</button>
      <button type="button"
        :class="['px-4 py-2 text-sm font-medium -mb-px border-b-2', tab === 'installment' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800']"
        @click="tab = 'installment'; kind = 'installment'; reload()">รายงวด</button>
    </div>

    <!-- Filters -->
    <div class="mb-3 flex flex-wrap items-center gap-2">
      <div class="inline-flex overflow-hidden rounded-lg border border-slate-200">
        <button v-for="k in (['all','cash','installment','split'] as const)" :key="k" type="button"
          :class="['px-3 py-1 text-xs', kind === k ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']"
          @click="kind = k; reload()">
          {{ k === 'all' ? 'ทั้งหมด' : k === 'cash' ? 'เงินสด' : k === 'installment' ? 'ผ่อน' : 'แบ่งชำระ' }}
        </button>
      </div>
      <input v-model="q" @input="reload" placeholder="ค้นหา ชื่อ/เลขกรมธรรม์"
        class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
    </div>

    <transition name="fade">
      <div v-if="flash" :class="['mb-3 rounded px-3 py-2 text-sm', flash.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']">{{ flash.text }}</div>
    </transition>

    <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
    <div v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</div>

    <!-- ── Tab: รายกรมธรรม์ ─────────────────────────────────────────────── -->
    <div v-else-if="tab === 'policy'" class="overflow-hidden rounded-lg border border-slate-200">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500">
          <tr>
            <th class="px-3 py-2 text-left">กรมธรรม์ / ลูกค้า</th>
            <th class="px-3 py-2 text-left">ประเภท</th>
            <th class="px-3 py-2 text-right">ยอดที่ต้องจ่าย</th>
            <th class="px-3 py-2 text-right">จ่ายแล้ว</th>
            <th class="px-3 py-2 text-right">ค้างชำระ</th>
            <th class="px-3 py-2 text-center">การทวง</th>
            <th class="px-3 py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="r in rows" :key="r.policyId" class="hover:bg-slate-50">
            <td class="px-3 py-2">
              <button type="button" class="text-left text-slate-800 hover:text-brand-700" @click="openPolicy(r.policyId)">
                <div class="font-medium">{{ r.customerName || '—' }}</div>
                <div class="text-xs text-slate-400">{{ r.policyNo || r.applicationNo || '—' }} · {{ r.customerPhone || '—' }}</div>
              </button>
            </td>
            <td class="px-3 py-2">
              <span :class="['inline-flex rounded px-1.5 py-0.5 text-[10px]', KIND_BADGE[r.kind]]">{{ KIND_LABEL[r.kind] }}</span>
              <span v-if="r.overdueCount > 0" class="ml-1 rounded bg-rose-50 px-1.5 py-0.5 text-[10px] text-rose-600">เกิน {{ r.overdueCount }} งวด</span>
            </td>
            <td class="px-3 py-2 text-right font-mono text-slate-600">{{ money(r.totalDue) }}</td>
            <td class="px-3 py-2 text-right font-mono text-emerald-700">{{ money(r.paid) }}</td>
            <td class="px-3 py-2 text-right font-mono font-medium text-rose-600">{{ money(r.outstanding) }}</td>
            <td class="px-3 py-2 text-center text-xs text-slate-500">
              <span v-if="r.reminderCount">ทวง {{ r.reminderCount }} ครั้ง<br><span class="text-[10px] text-slate-400">ล่าสุด {{ fmtDate(r.lastReminderAt) }}</span></span>
              <span v-else class="text-slate-300">—</span>
            </td>
            <td class="px-3 py-2 text-right">
              <button type="button" class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-700 hover:bg-amber-100"
                @click="openReminder(r)"><i class="pi pi-bell text-[10px]" /> บันทึกการทวง</button>
            </td>
          </tr>
          <tr v-if="!rows.length"><td colspan="7" class="px-3 py-10 text-center text-sm text-slate-400">ไม่มีรายการค้างชำระ</td></tr>
        </tbody>
      </table>
    </div>

    <!-- ── Tab: รายงวด ──────────────────────────────────────────────────── -->
    <div v-else class="overflow-hidden rounded-lg border border-slate-200">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500">
          <tr>
            <th class="px-3 py-2 text-left">ลูกค้า / กรมธรรม์</th>
            <th class="px-3 py-2 text-left">งวด</th>
            <th class="px-3 py-2 text-right">ยอดงวด</th>
            <th class="px-3 py-2 text-left">ครบกำหนด</th>
            <th class="px-3 py-2 text-center">สถานะ</th>
            <th class="px-3 py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="g in installmentGuadRows" :key="g.policy.policyId + '-' + g.no" class="hover:bg-slate-50">
            <td class="px-3 py-2">
              <button type="button" class="text-left text-slate-800 hover:text-brand-700" @click="openPolicy(g.policy.policyId)">
                <div class="font-medium">{{ g.policy.customerName || '—' }}</div>
                <div class="text-xs text-slate-400">{{ g.policy.policyNo || g.policy.applicationNo || '—' }} · {{ g.policy.customerPhone || '—' }}</div>
              </button>
            </td>
            <td class="px-3 py-2 text-slate-700">งวด {{ g.no }}/{{ g.policy.installmentCount }}</td>
            <td class="px-3 py-2 text-right font-mono text-slate-700">{{ money(g.amount) }}</td>
            <td class="px-3 py-2 text-slate-500">{{ g.dueDate ? fmtDate(g.dueDate) : '—' }}</td>
            <td class="px-3 py-2 text-center">
              <span v-if="g.overdue" class="rounded bg-rose-50 px-1.5 py-0.5 text-[10px] text-rose-600">เกินกำหนด</span>
              <span v-else class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">รอชำระ</span>
            </td>
            <td class="px-3 py-2 text-right">
              <button type="button" class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-700 hover:bg-amber-100"
                @click="openReminder(g.policy, g.no)"><i class="pi pi-bell text-[10px]" /> ทวงงวดนี้</button>
            </td>
          </tr>
          <tr v-if="!installmentGuadRows.length"><td colspan="6" class="px-3 py-10 text-center text-sm text-slate-400">ไม่มีงวดค้างชำระ</td></tr>
        </tbody>
      </table>
    </div>

    <!-- ── Reminder modal ───────────────────────────────────────────────── -->
    <div v-if="reminderModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="reminderModal = null">
      <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-xl">
        <h3 class="mb-1 text-sm font-semibold text-slate-800">
          บันทึกการทวงเงิน<span v-if="reminderModal.installmentNo"> · งวด {{ reminderModal.installmentNo }}</span>
        </h3>
        <p class="mb-3 text-xs text-slate-500">
          {{ reminderModal.policy.customerName }} · ค้าง ฿{{ money(reminderModal.installmentNo ? (reminderModal.policy.schedule?.find(s => s.no === reminderModal!.installmentNo)?.amount ?? 0) : reminderModal.policy.outstanding) }}
        </p>
        <div class="mb-2 flex gap-2">
          <select v-model="reminderForm.channel" class="rounded border border-slate-300 px-2 py-1 text-xs">
            <option value="phone">โทรศัพท์</option><option value="line">LINE</option>
            <option value="email">อีเมล</option><option value="sms">SMS</option>
            <option value="inperson">พบตัว</option><option value="other">อื่นๆ</option>
          </select>
          <input v-model="reminderForm.note" placeholder="บันทึกย่อ (ไม่บังคับ)"
            class="flex-1 rounded border border-slate-300 px-2 py-1 text-xs" />
        </div>
        <button type="button" class="rounded bg-amber-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
          :disabled="reminderSaving" @click="submitReminder">{{ reminderSaving ? 'กำลังบันทึก…' : 'บันทึกการทวง' }}</button>

        <!-- history -->
        <div v-if="reminderHistory.length" class="mt-3 border-t border-slate-100 pt-2">
          <p class="mb-1 text-[10px] text-slate-400">ประวัติการทวง ({{ reminderHistory.length }})</p>
          <ul class="max-h-40 space-y-1 overflow-y-auto">
            <li v-for="h in reminderHistory" :key="h.id" class="rounded bg-slate-50 px-2 py-1 text-[11px]">
              <span class="text-slate-600">{{ fmtDate(h.createdAt) }} · {{ channelLabel(h.channel) }}<span v-if="h.installmentNo"> · งวด {{ h.installmentNo }}</span></span>
              <span v-if="h.note" class="block text-slate-500">{{ h.note }}</span>
            </li>
          </ul>
        </div>
        <button type="button" class="mt-3 text-xs text-slate-500 hover:text-slate-700" @click="reminderModal = null">ปิด</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
