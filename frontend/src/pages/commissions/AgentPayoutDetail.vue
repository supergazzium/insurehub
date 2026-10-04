<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchAgentOutstandingDetail, overridePolicyConditions, payAgent, revokePolicyOverride,
  type AgentDetailItem, type AgentDetailResult, type LineType, type PendingDeduction,
} from '../../api/commissionPayouts'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const route = useRoute()
const router = useRouter()

const agentId = computed(() => String(route.params.agentId))
const fromDate = computed(() => (route.query.from ? String(route.query.from) : undefined))
const toDate = computed(() => (route.query.to ? String(route.query.to) : undefined))

const detail = ref<AgentDetailResult | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const flash = ref<string | null>(null)

const money = (n: number | null | undefined) =>
  (n ?? 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const agent = computed(() => detail.value?.agent ?? null)
const items = computed<AgentDetailItem[]>(() => detail.value?.items ?? [])
const waiting = computed<AgentDetailItem[]>(() => detail.value?.waiting ?? [])
const deductions = computed<PendingDeduction[]>(() => detail.value?.pendingDeductions ?? [])
const totals = computed(() => detail.value?.totals)
const canPay = computed(() => detail.value?.meta?.canPay ?? false)
const canOverride = computed(() => detail.value?.meta?.canOverride ?? false)

const vatLabel = computed(() => {
  const t = agent.value?.vatType
  return t === '3' ? 'จด VAT · รวม VAT ในยอด' : t === '2' ? 'จด VAT · บวก VAT 7%' : 'ไม่จด VAT'
})

const TYPE_ORDER: LineType[] = ['base', 'self_mgmt', 'referral', 'override']
const TYPE_CLS: Record<LineType, string> = {
  base: 'bg-sky-50 text-sky-700 ring-sky-200',
  self_mgmt: 'bg-violet-50 text-violet-700 ring-violet-200',
  referral: 'bg-amber-50 text-amber-700 ring-amber-200',
  override: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
}
const groups = computed(() =>
  TYPE_ORDER.map((t) => ({
    type: t,
    label: items.value.find((i) => i.lineType === t)?.lineLabel ?? t,
    rows: items.value.filter((i) => i.lineType === t),
    total: items.value.filter((i) => i.lineType === t).reduce((s, i) => s + i.amount, 0),
  })).filter((g) => g.rows.length > 0),
)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    detail.value = await fetchAgentOutstandingDetail(agentId.value, fromDate.value, toDate.value)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

const showPay = ref(false)
const paymentDate = ref(new Date().toLocaleDateString('sv-SE'))
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
    router.push({ name: 'commission-payout-detail', params: { id: res.data.id } })
  } catch (e: unknown) {
    payError.value = e instanceof ApiError ? e.message : 'ทำจ่ายไม่สำเร็จ'
  } finally {
    paying.value = false
  }
}

const overrideTarget = ref<AgentDetailItem | null>(null)
const overrideReason = ref('')
const overrideBusy = ref(false)
const overrideError = ref<string | null>(null)

function openOverride(line: AgentDetailItem): void {
  overrideTarget.value = line
  overrideReason.value = ''
  overrideError.value = null
}

async function submitOverride(): Promise<void> {
  if (!overrideTarget.value) return
  overrideBusy.value = true
  overrideError.value = null
  try {
    await overridePolicyConditions(overrideTarget.value.policyId, overrideReason.value.trim())
    flash.value = `ยกเว้นเงื่อนไขกรมธรรม์ ${overrideTarget.value.policyNo || overrideTarget.value.applicationNo} แล้ว`
    overrideTarget.value = null
    await load()
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      overrideError.value = Object.values(errs)[0]?.[0] ?? e.message
    } else {
      overrideError.value = 'บันทึกไม่สำเร็จ'
    }
  } finally {
    overrideBusy.value = false
  }
}

async function revoke(line: AgentDetailItem): Promise<void> {
  try {
    await revokePolicyOverride(line.policyId)
    flash.value = 'ยกเลิกการยกเว้นแล้ว'
    await load()
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'ยกเลิกไม่สำเร็จ'
  }
}

function openPolicy(policyId: number): void {
  router.push({ name: 'policy-edit-draft', params: { id: String(policyId) } })
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-6">
    <button type="button" class="mb-4 flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700" @click="router.push({ name: 'commission-payouts' })">
      <i class="pi pi-arrow-left" /> กลับไปรายชื่อตัวแทน
    </button>

    <div v-if="loading" class="py-16 text-center text-slate-400">กำลังโหลด…</div>
    <div v-else-if="error && !detail" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>
    <div v-else-if="!agent" class="py-16 text-center text-slate-400">ไม่พบข้อมูลตัวแทน</div>

    <template v-else-if="totals">
      <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold text-slate-800">{{ agent.agentName }}</h1>
          <p class="mt-1 text-sm text-slate-500">
            รหัส <b>{{ agent.agentCode }}</b> · {{ agent.kind === 'corporate' ? 'นิติบุคคล' : 'บุคคลธรรมดา' }} · {{ vatLabel }}
            · หัก ณ ที่จ่าย <b>{{ agent.whtRate }}%</b> ({{ agent.whtFormLabel }})
            <span v-if="fromDate || toDate"> · ถึงวันแจ้งงาน {{ fmtDate(toDate ?? null) }}</span>
          </p>
          <p class="mt-0.5 text-xs" :class="agent.hasBankAccount ? 'text-slate-400' : 'text-rose-600'">
            <i class="pi pi-building-columns text-[10px]" />
            {{ agent.hasBankAccount ? `โอนเข้า ${agent.bankName || 'ธนาคาร'}` : 'ยังไม่มีเลขบัญชีธนาคาร' }}
          </p>
        </div>
        <button v-if="canPay" type="button" data-testid="pay-agent" :disabled="totals.itemCount === 0"
          class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" @click="showPay = true">
          <i class="pi pi-check-circle mr-1" /> ทำจ่าย โอนสุทธิ ฿{{ money(totals.netAmount) }}
        </button>
      </header>

      <div v-if="flash" class="mb-3 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ flash }}</div>
      <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700">{{ error }}</div>

      <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5" data-testid="payout-type-cards">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ค่าคอมพื้นฐาน</div>
          <div class="mt-1 text-lg font-semibold text-slate-800">฿{{ money(totals.byType.base) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ค่าแนะนำ</div>
          <div class="mt-1 text-lg font-semibold text-amber-700">฿{{ money(totals.byType.referral) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <div class="text-xs text-slate-500">ค่าบริหารสายงาน</div>
          <div class="mt-1 text-lg font-semibold text-emerald-700">฿{{ money(totals.byType.override + totals.byType.self_mgmt) }}</div>
          <div v-if="totals.byType.self_mgmt > 0" class="text-[11px] text-slate-400">รวมค่าบริหารยอดขายตัวเอง ฿{{ money(totals.byType.self_mgmt) }}</div>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
          <div class="text-xs text-amber-700">รอเงื่อนไข</div>
          <div class="mt-1 text-lg font-semibold text-amber-800">฿{{ money(totals.waitingAmount) }}</div>
          <div class="text-[11px] text-amber-600">{{ totals.waitingCount }} รายการ</div>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4" data-testid="payout-net">
          <div class="text-xs text-emerald-700">ยอดโอนสุทธิ</div>
          <div class="mt-1 text-lg font-semibold text-emerald-800">฿{{ money(totals.netAmount) }}</div>
        </div>
      </div>

      <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
          <div v-for="g in groups" :key="g.type" class="overflow-x-auto rounded-xl border border-slate-200 bg-white" :data-testid="`group-${g.type}`">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2">
              <span :class="['rounded px-2 py-0.5 text-xs font-medium ring-1', TYPE_CLS[g.type]]">{{ g.label }}</span>
              <span class="text-sm font-semibold text-slate-700">฿{{ money(g.total) }}</span>
            </div>
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-left text-xs text-slate-500">
                <tr>
                  <th class="px-4 py-2">กรมธรรม์</th>
                  <th class="px-4 py-2">ลูกค้า</th>
                  <th class="px-4 py-2">{{ g.type === 'base' ? 'ลูกค้าชำระ' : g.type === 'self_mgmt' ? 'ฐาน' : 'จากยอดขายของ' }}</th>
                  <th class="px-4 py-2 text-right">ยอด</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="it in g.rows" :key="it.key" class="hover:bg-slate-50">
                  <td class="px-4 py-2">
                    <button type="button" class="text-brand-600 hover:underline" @click="openPolicy(it.policyId)">{{ it.policyNo || it.applicationNo || `#${it.policyId}` }}</button>
                    <div class="text-[11px] text-slate-400">{{ fmtDate(it.policyDate) }}</div>
                  </td>
                  <td class="px-4 py-2 text-slate-600">{{ it.customerName || '—' }}</td>
                  <td class="px-4 py-2 text-xs text-slate-600">
                    <template v-if="g.type === 'base'">
                      {{ it.ratioLabel || 'ชำระแล้ว' }}
                      <span v-if="it.amount < it.entitlement" class="text-slate-400"> · จาก ฿{{ money(it.entitlement) }}</span>
                    </template>
                    <template v-else-if="g.type === 'self_mgmt'">เบี้ย ฿{{ money(it.basePremium) }}</template>
                    <template v-else>{{ it.sourceAgent || '—' }}</template>
                    <div v-if="it.override" class="mt-0.5">
                      <span class="rounded bg-orange-50 px-1.5 py-0.5 text-[10px] text-orange-700 ring-1 ring-orange-200" :title="it.override.reason">ยกเว้นเงื่อนไขโดย {{ it.override.by || '—' }}</span>
                      <button v-if="canOverride" type="button" class="ml-1 text-[10px] text-slate-400 hover:text-rose-600" @click="revoke(it)">ยกเลิก</button>
                    </div>
                  </td>
                  <td class="px-4 py-2 text-right font-medium text-slate-800">฿{{ money(it.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="groups.length === 0" class="rounded-xl border border-dashed border-slate-300 bg-white py-10 text-center text-sm text-slate-400">ไม่มีรายการที่พร้อมจ่าย</div>

          <div v-if="waiting.length" class="overflow-x-auto rounded-xl border border-amber-200 bg-white" data-testid="waiting-lines">
            <div class="border-b border-amber-100 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800">
              <i class="pi pi-clock text-xs" /> รอเงื่อนไขก่อนจ่าย ({{ waiting.length }})
            </div>
            <table class="min-w-full text-sm">
              <tbody class="divide-y divide-slate-100">
                <tr v-for="it in waiting" :key="it.key">
                  <td class="px-4 py-2">
                    <span :class="['rounded px-1.5 py-0.5 text-[10px] ring-1', TYPE_CLS[it.lineType]]">{{ it.lineLabel }}</span>
                    <button type="button" class="ml-1 text-brand-600 hover:underline" @click="openPolicy(it.policyId)">{{ it.policyNo || it.applicationNo || `#${it.policyId}` }}</button>
                    <div class="text-[11px] text-slate-400">{{ it.customerName || '' }}</div>
                  </td>
                  <td class="px-4 py-2">
                    <div class="flex flex-wrap gap-1">
                      <span v-for="r in it.reasons" :key="r.code" class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800 ring-1 ring-amber-200">{{ r.label }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-2 text-right font-medium text-slate-600">฿{{ money(it.amount) }}</td>
                  <td class="px-4 py-2 text-right">
                    <button v-if="canOverride && !it.key.startsWith('base-rest')" type="button" :data-testid="`override-${it.policyId}`"
                      class="whitespace-nowrap rounded border border-orange-300 px-2 py-1 text-xs text-orange-700 hover:bg-orange-50" @click="openOverride(it)">ยกเว้นเงื่อนไข</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="space-y-4">
          <div class="rounded-xl border border-slate-200 bg-white p-4" data-testid="tax-preview">
            <div class="mb-2 text-sm font-semibold text-slate-700">สรุปยอดจ่ายรอบนี้</div>
            <dl class="space-y-1.5 text-sm">
              <div class="flex justify-between"><dt class="text-slate-500">รวมค่าคอม ({{ totals.itemCount }} รายการ)</dt><dd>฿{{ money(totals.totalAmount) }}</dd></div>
              <div v-if="totals.appliedDeduction > 0" class="flex justify-between text-rose-600"><dt>หัก รายการหัก</dt><dd>−฿{{ money(totals.appliedDeduction) }}</dd></div>
              <div class="flex justify-between"><dt class="text-slate-500">ยอดก่อน VAT</dt><dd>฿{{ money(totals.taxableAmount) }}</dd></div>
              <div v-if="agent.vatType !== '1'" class="flex justify-between"><dt class="text-slate-500">VAT 7%{{ agent.vatType === '3' ? ' (รวมในยอด)' : '' }}</dt><dd>฿{{ money(totals.vatAmount) }}</dd></div>
              <div class="flex justify-between text-rose-600"><dt>หัก ณ ที่จ่าย {{ agent.whtRate }}%</dt><dd>−฿{{ money(totals.whtAmount) }}</dd></div>
              <div class="flex justify-between border-t border-slate-200 pt-1.5 text-base font-semibold text-emerald-700"><dt>โอนสุทธิ</dt><dd>฿{{ money(totals.netAmount) }}</dd></div>
            </dl>
            <p v-if="totals.carryForward > 0" class="mt-2 text-[11px] text-rose-500">ยอดหักที่เหลือ ฿{{ money(totals.carryForward) }} ยกไปหักรอบหน้า</p>
          </div>

          <div v-if="deductions.length" class="rounded-xl border border-rose-200 bg-white" data-testid="payout-deductions">
            <div class="border-b border-rose-100 bg-rose-50 px-4 py-2 text-sm font-medium text-rose-700">รายการหักค้าง</div>
            <ul class="divide-y divide-slate-100 text-sm">
              <li v-for="d in deductions" :key="d.id" class="flex items-start justify-between gap-2 px-4 py-2">
                <div>
                  <div class="text-slate-700">{{ d.typeLabel || d.type }}</div>
                  <div class="text-[11px] text-slate-400">{{ d.policyNo || '' }} · {{ fmtDate(d.createdAt) }}</div>
                </div>
                <div class="font-medium text-rose-700">−฿{{ money(d.amount) }}</div>
              </li>
            </ul>
          </div>

          <ul v-if="detail?.warnings?.length" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-[11px] text-slate-500">
            <li v-for="w in detail.warnings.slice(0, 6)" :key="w">· {{ w }}</li>
          </ul>
        </div>
      </div>
    </template>

    <div v-if="showPay && totals && agent" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
      <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold text-slate-800">ยืนยันการจ่ายค่าคอม</h2>
        <p class="mb-4 text-sm text-slate-500">
          {{ agent.agentName }} ({{ agent.agentCode }}) — {{ totals.itemCount }} รายการ
          รวม ฿{{ money(totals.totalAmount) }} · หัก ณ ที่จ่าย ฿{{ money(totals.whtAmount) }}
          · โอนสุทธิ <b class="text-emerald-700">฿{{ money(totals.netAmount) }}</b>
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
          <i class="pi pi-info-circle" /> ระบบจะสร้างรอบจ่าย อนุมัติ บันทึกจ่าย และออกหนังสือรับรองการหักภาษี ณ ที่จ่าย (50 ทวิ) ให้อัตโนมัติ
        </p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" :disabled="paying" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50" @click="showPay = false">ยกเลิก</button>
          <button type="button" data-testid="confirm-pay" :disabled="paying || !paymentDate" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" @click="doPay">
            <i v-if="paying" class="pi pi-spin pi-spinner mr-1" /> ยืนยันจ่าย
          </button>
        </div>
      </div>
    </div>

    <div v-if="overrideTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" data-testid="override-dialog">
      <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold text-slate-800">ยกเว้นเงื่อนไขก่อนจ่าย</h2>
        <p class="mb-2 text-sm text-slate-500">กรมธรรม์ {{ overrideTarget.policyNo || overrideTarget.applicationNo }} — ทุกบรรทัดของกรมธรรม์นี้จะจ่ายได้เต็มจำนวน</p>
        <div class="mb-3 flex flex-wrap gap-1">
          <span v-for="r in overrideTarget.reasons" :key="r.code" class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800 ring-1 ring-amber-200">{{ r.label }}</span>
        </div>
        <label class="block text-xs text-slate-600">เหตุผล (บันทึกใน audit)
          <textarea v-model="overrideReason" rows="3" data-testid="override-reason" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </label>
        <div v-if="overrideError" class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ overrideError }}</div>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600" @click="overrideTarget = null">ยกเลิก</button>
          <button type="button" data-testid="override-submit" :disabled="overrideBusy || !overrideReason.trim()"
            class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700 disabled:opacity-50" @click="submitOverride">ยืนยันยกเว้น</button>
        </div>
      </div>
    </div>
  </div>
</template>
