<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import {
  downloadPolicyDocument, fetchPolicyPayments, voidPolicyPayment,
  type PaymentSummary, type PolicyPaymentRow,
} from '../../api/policies'
import { ApiError } from '../../api/client'
import { fmtDate, fmtDateTime } from '../../util/dateFormat'
import PolicyPeriodBadge from '../../components/PolicyPeriodBadge.vue'
import PolicyPaymentModal, { type ExpectedPremium } from './PolicyPaymentModal.vue'

const props = defineProps<{
  policyId: string | null
  disabled?: boolean
  expected: ExpectedPremium
  carrierLabel: string
  installmentCount?: number
  frequencyLabel?: string
  mainPremium?: number
  compulsoryPremium?: number
  installmentMode?: string | null
  ensureDraft: () => Promise<void>
}>()

const emit = defineEmits<{ (e: 'changed'): void }>()

const rows = ref<PolicyPaymentRow[]>([])
const summary = ref<PaymentSummary | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const flash = ref<{ kind: 'ok' | 'error'; text: string } | null>(null)
const showModal = ref(false)

const voidTarget = ref<PolicyPaymentRow | null>(null)
const voidReason = ref('')
const voidSaving = ref(false)
const voidError = ref<string | null>(null)

const METHOD_LABELS: Record<string, string> = {
  bankTransfer: 'โอนเงิน',
  creditCard: 'บัตรเครดิต',
  cash: 'เงินสด',
  cheque: 'เช็ค',
  directDebit: 'หักบัญชีอัตโนมัติ',
  legacyImport: 'ข้อมูลระบบเดิม',
}
const MODE_LABELS: Record<string, string> = {
  full: 'จ่ายเต็ม',
  less_commission: 'จ่ายหักคอมมิสชั่น',
  with_discount: 'จ่ายมีส่วนลด',
  split: 'แบ่งชำระ',
  installment: 'ผ่อน',
}
const STATUS_CHIPS: Record<string, { label: string; cls: string }> = {
  paid: { label: 'ชำระครบแล้ว', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  partial: { label: 'ชำระบางส่วน', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  unpaid: { label: 'ยังไม่ชำระ', cls: 'bg-slate-100 text-slate-600 ring-slate-200' },
  overpaid: { label: 'ชำระเกินยอด', cls: 'bg-sky-50 text-sky-700 ring-sky-200' },
  legacy_settled: { label: 'ระบบเดิม · ถือว่าชำระครบ', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  unknown: { label: 'ยังไม่มียอดเบี้ย', cls: 'bg-slate-100 text-slate-500 ring-slate-200' },
}

function fmt(n: number | null | undefined): string {
  return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0)
}
const chip = computed(() => STATUS_CHIPS[summary.value?.status ?? 'unknown'] ?? STATUS_CHIPS.unknown)

const blockedText = computed<string | null>(() => {
  if (props.disabled) return 'เลือกลูกค้าก่อน จึงจะบันทึกการชำระเงินได้'
  if (summary.value && !summary.value.payable) return summary.value.blockedReason
  if (summary.value && !summary.value.canRecord) return 'บัญชีของคุณไม่มีสิทธิ์บันทึกการชำระเงิน'
  return null
})

const canVoid = computed(() => !!summary.value?.canVoid)
const liveCount = computed(() => rows.value.filter((r) => !r.voidedAt).length)

async function load(): Promise<void> {
  if (!props.policyId) {
    rows.value = []
    summary.value = null
    return
  }
  loading.value = true
  loadError.value = null
  try {
    const res = await fetchPolicyPayments(props.policyId, { withVoided: true })
    rows.value = res.data
    summary.value = res.meta ?? null
  } catch {
    loadError.value = 'โหลดประวัติการชำระเงินไม่สำเร็จ กรุณาลองใหม่'
  } finally {
    loading.value = false
  }
}
watch(() => props.policyId, () => { void load() }, { immediate: true })

function notify(kind: 'ok' | 'error', text: string): void {
  flash.value = { kind, text }
  window.setTimeout(() => { flash.value = null }, 4000)
}

async function openModal(): Promise<void> {
  if (blockedText.value) return
  if (!props.policyId) {
    try { await props.ensureDraft() } catch { return }
    await nextTick()
  }
  if (!props.policyId) return
  showModal.value = true
}

async function onSaved(): Promise<void> {
  notify('ok', 'บันทึกการชำระเงินเรียบร้อยแล้ว')
  await load()
  emit('changed')
}

function startVoid(row: PolicyPaymentRow): void {
  voidTarget.value = row
  voidReason.value = ''
  voidError.value = null
}

function apiMessage(e: unknown): string {
  if (!(e instanceof ApiError)) return 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'
  const errors = (e.body as unknown as { errors?: Record<string, string[]> } | null)?.errors
  const first = errors ? Object.values(errors)[0]?.[0] : undefined
  return first ?? e.message
}

async function submitVoid(): Promise<void> {
  if (!props.policyId || !voidTarget.value || voidSaving.value) return
  const reason = voidReason.value.trim()
  if (reason.length < 3) {
    voidError.value = 'กรุณาระบุเหตุผลที่ยกเลิก อย่างน้อย 3 ตัวอักษร'
    return
  }
  voidSaving.value = true
  voidError.value = null
  try {
    await voidPolicyPayment(props.policyId, voidTarget.value.id, reason)
    voidTarget.value = null
    notify('ok', 'ยกเลิกรายการชำระเงินแล้ว ยอดและค่าคอมถูกปรับให้อัตโนมัติ')
    await load()
    emit('changed')
  } catch (e) {
    voidError.value = apiMessage(e)
  } finally {
    voidSaving.value = false
  }
}

async function openSlip(row: PolicyPaymentRow): Promise<void> {
  if (!props.policyId || !row.proof) return
  try {
    await downloadPolicyDocument(props.policyId, row.proof.documentId, row.proof.fileName, 'open')
  } catch {
    notify('error', 'เปิดไฟล์สลิปไม่สำเร็จ')
  }
}
</script>

<template>
  <section class="card p-5" data-testid="payment-panel">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="flex items-center gap-2">
          <h2 class="font-semibold text-slate-900">การชำระเงิน</h2>
          <span v-if="summary" :class="['inline-flex px-2 py-0.5 rounded-full text-[11px] font-medium ring-1', chip.cls]" data-testid="payment-status">
            {{ chip.label }}
          </span>
        </div>
        <p class="text-xs text-slate-500 mt-0.5">บันทึกเงินที่ได้รับจากลูกค้า — จ่ายเต็ม / หักคอมมิสชั่น / มีส่วนลด / ผ่อน</p>
      </div>
      <button type="button" :disabled="!!blockedText" :title="blockedText ?? undefined" data-testid="payment-open" @click="openModal"
        class="px-3 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1.5">
        <i class="pi pi-wallet text-xs" /> บันทึกการชำระเงิน
      </button>
    </div>

    <p v-if="blockedText" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800" data-testid="payment-blocked-note">
      <i class="pi pi-info-circle mr-1" />{{ blockedText }}
    </p>

    <p v-if="flash" :class="['mt-3 rounded-lg px-3 py-2 text-xs', flash.kind === 'ok' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']" data-testid="payment-flash">
      <i :class="['mr-1', flash.kind === 'ok' ? 'pi pi-check-circle' : 'pi pi-exclamation-circle']" />{{ flash.text }}
    </p>

    <template v-if="policyId">
      <div v-if="summary" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="rounded-lg border border-slate-200 p-3">
          <div class="text-[11px] text-slate-400">เบี้ยที่ต้องชำระ</div>
          <div class="text-lg font-semibold text-slate-900 tabular-nums" data-testid="payment-summary-due">฿{{ fmt(summary.due) }}</div>
        </div>
        <div class="rounded-lg border border-slate-200 p-3">
          <div class="text-[11px] text-slate-400">ชำระแล้ว</div>
          <div class="text-lg font-semibold text-emerald-600 tabular-nums" data-testid="payment-summary-paid">฿{{ fmt(summary.paid) }}</div>
          <div v-if="summary.toCarrier > 0" class="mt-0.5 text-[11px] text-slate-500">
            เข้า InsureHub ฿{{ fmt(summary.toInsureHub) }} · จ่ายตรงบริษัทประกัน ฿{{ fmt(summary.toCarrier) }}
          </div>
        </div>
        <div class="rounded-lg border p-3"
          :class="summary.outstanding > 1 ? 'border-amber-300 bg-amber-50' : summary.outstanding < -1 ? 'border-sky-300 bg-sky-50' : 'border-emerald-300 bg-emerald-50'">
          <div class="text-[11px]" :class="summary.outstanding > 1 ? 'text-amber-600' : summary.outstanding < -1 ? 'text-sky-600' : 'text-emerald-600'">
            {{ summary.outstanding < -1 ? 'ชำระเกินยอด' : 'ยอดค้างชำระ' }}
          </div>
          <div class="text-lg font-semibold tabular-nums"
            :class="summary.outstanding > 1 ? 'text-amber-700' : summary.outstanding < -1 ? 'text-sky-700' : 'text-emerald-700'" data-testid="payment-summary-outstanding">
            ฿{{ fmt(Math.abs(summary.outstanding)) }}
          </div>
        </div>
      </div>
      <p v-if="summary?.status === 'overpaid'" class="mt-2 text-[11px] text-sky-700">
        <i class="pi pi-info-circle mr-1" />ยอดที่บันทึกไว้เกินเบี้ยที่ต้องชำระ ถ้ามีรายการที่บันทึกผิด ให้กด “ยกเลิกรายการ” ในตารางด้านล่าง
      </p>
      <p v-if="summary?.legacySettled" class="mt-2 text-[11px] text-slate-500">
        <i class="pi pi-info-circle mr-1" />กรมธรรม์นี้นำเข้าจากระบบเดิมและถือว่าชำระครบแล้ว หากบันทึกเงินเพิ่ม ระบบจะนับจากรายการที่บันทึกใหม่
      </p>

      <p v-if="loadError" class="mt-3 text-xs text-rose-600"><i class="pi pi-exclamation-circle mr-1" />{{ loadError }}</p>
      <p v-else-if="loading && !rows.length" class="mt-3 text-xs text-slate-400"><i class="pi pi-spin pi-spinner mr-1" />กำลังโหลดประวัติการชำระเงิน…</p>
      <p v-else-if="!rows.length" class="mt-4 text-xs text-slate-400" data-testid="payment-empty">ยังไม่มีรายการชำระเงิน</p>

      <div v-else class="mt-4 overflow-x-auto">
        <table class="w-full text-sm" data-testid="payment-table">
          <thead>
            <tr class="text-left text-xs text-slate-500 border-b border-slate-100">
              <th class="py-2 pr-3 whitespace-nowrap">วันที่ชำระ</th>
              <th class="py-2 pr-3 whitespace-nowrap">งวด</th>
              <th class="py-2 pr-3 text-right whitespace-nowrap">จำนวนเงิน</th>
              <th class="py-2 pr-3">ช่องทาง / ผู้รับชำระ</th>
              <th class="py-2 pr-3 whitespace-nowrap">สลิป</th>
              <th class="py-2 pr-3 whitespace-nowrap">ผู้บันทึก</th>
              <th class="py-2 pl-3 text-right w-28"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="r in rows" :key="r.id">
              <tr :class="['align-top', r.voidedAt ? 'bg-slate-50/70 text-slate-400' : 'border-b border-slate-50']" :data-testid="`payment-row-${r.id}`">
                <td class="py-2 pr-3 whitespace-nowrap" :class="r.voidedAt ? 'line-through' : 'text-slate-700'">{{ fmtDate(r.paymentDate) }}</td>
                <td class="py-2 pr-3 whitespace-nowrap">
                  <PolicyPeriodBadge v-if="r.periodLabel" :label="r.periodLabel" />
                  <span v-else class="text-slate-300">—</span>
                </td>
                <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                  <div :class="r.voidedAt ? 'line-through' : 'font-medium text-slate-900'">฿{{ fmt(r.amount) }}</div>
                  <div v-if="(r.adjustmentAmount ?? 0) > 0" class="text-[11px]" :class="r.voidedAt ? '' : 'text-violet-600'">
                    + {{ r.adjustmentKind === 'commission_deducted' ? 'ค่าคอมที่หักไว้' : 'ส่วนลด' }} ฿{{ fmt(r.adjustmentAmount) }}
                  </div>
                </td>
                <td class="py-2 pr-3">
                  <div class="flex flex-wrap items-center gap-1.5">
                    <span>{{ METHOD_LABELS[r.method] ?? r.method }}</span>
                    <span v-if="r.payee === 'carrier'" class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200">จ่ายตรงบริษัทประกัน</span>
                    <span v-if="r.payMode && r.payMode !== 'full'" class="text-[11px] text-slate-400">· {{ MODE_LABELS[r.payMode] ?? r.payMode }}</span>
                  </div>
                  <div v-if="r.reference" class="text-[11px] text-slate-500">อ้างอิง {{ r.reference }}</div>
                  <div v-if="r.note" class="text-[11px] text-slate-500">{{ r.note }}</div>
                </td>
                <td class="py-2 pr-3 whitespace-nowrap">
                  <button v-if="r.proof" type="button" class="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline" :title="r.proof.fileName" @click="openSlip(r)">
                    <i class="pi pi-paperclip text-[10px]" />ดูสลิป
                  </button>
                  <span v-else class="text-slate-300">—</span>
                </td>
                <td class="py-2 pr-3 whitespace-nowrap">
                  <div class="text-slate-600">{{ r.recordedByName ?? '—' }}</div>
                  <div class="text-[10px] text-slate-400">{{ fmtDateTime(r.recordedAt) }}</div>
                </td>
                <td class="py-2 pl-3 text-right whitespace-nowrap">
                  <span v-if="r.voidedAt" class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-rose-50 text-rose-600 ring-1 ring-rose-200">ยกเลิกแล้ว</span>
                  <button v-else-if="canVoid" type="button" class="text-xs text-slate-500 hover:text-rose-600" :data-testid="`payment-void-${r.id}`" @click="startVoid(r)">
                    <i class="pi pi-ban mr-1 text-[10px]" />ยกเลิกรายการ
                  </button>
                </td>
              </tr>
              <tr v-if="r.voidedAt" class="bg-slate-50/70 border-b border-slate-100">
                <td colspan="7" class="pb-2 pr-3 text-[11px] text-rose-500">
                  ยกเลิกโดย {{ r.voidedByName ?? '—' }} เมื่อ {{ fmtDateTime(r.voidedAt) }} · เหตุผล: {{ r.voidReason }}
                </td>
              </tr>
            </template>
          </tbody>
        </table>
        <p class="mt-2 text-[11px] text-slate-400">
          {{ liveCount }} รายการที่ใช้งาน<span v-if="rows.length > liveCount"> · {{ rows.length - liveCount }} รายการที่ยกเลิก (เก็บไว้เป็นประวัติ ไม่นับในยอด)</span>
        </p>
      </div>
    </template>
    <p v-else class="mt-4 text-xs text-slate-400">กด “บันทึกการชำระเงิน” ระบบจะบันทึกร่างกรมธรรม์ให้อัตโนมัติ แล้วจึงเปิดหน้ากรอกการชำระเงิน</p>

    <PolicyPaymentModal
      :open="showModal"
      :policy-id="policyId"
      :expected="expected"
      :carrier-label="carrierLabel"
      :installment-count="installmentCount"
      :frequency-label="frequencyLabel"
      :main-premium="mainPremium"
      :compulsory-premium="compulsoryPremium"
      :installment-mode="installmentMode"
      @close="showModal = false"
      @saved="onSaved"
    />

    <div v-if="voidTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" data-testid="payment-void-dialog"
      @click.self="!voidSaving && (voidTarget = null)">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-5">
        <h3 class="font-semibold text-slate-900">ยกเลิกรายการชำระเงิน</h3>
        <p class="mt-1 text-xs text-slate-500">
          รายการวันที่ {{ fmtDate(voidTarget.paymentDate) }} จำนวน ฿{{ fmt(voidTarget.amount) }} จะไม่ถูกลบ แต่ถูกทำเครื่องหมายว่ายกเลิก
          และตัดออกจากยอดชำระ ยอดงวด และค่าคอมที่ยังไม่เข้ารอบจ่าย
        </p>
        <label class="mt-3 block text-xs font-medium text-slate-700">เหตุผลที่ยกเลิก <span class="text-rose-500">*</span></label>
        <textarea v-model="voidReason" rows="3" maxlength="255" placeholder="เช่น ลงยอดผิด / ลูกค้าโอนผิดบัญชี / บันทึกซ้ำ" data-testid="payment-void-reason"
          class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        <p v-if="voidError" class="mt-1 text-xs text-rose-600"><i class="pi pi-exclamation-circle mr-1" />{{ voidError }}</p>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" :disabled="voidSaving" class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100 disabled:opacity-50" @click="voidTarget = null">ไม่ยกเลิก</button>
          <button type="button" :disabled="voidSaving" data-testid="payment-void-confirm"
            class="px-4 py-1.5 rounded-lg text-sm bg-rose-600 text-white hover:bg-rose-700 disabled:opacity-50" @click="submitVoid">
            <i :class="voidSaving ? 'pi pi-spin pi-spinner' : 'pi pi-ban'" class="text-xs mr-1" />ยืนยันยกเลิกรายการ
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
