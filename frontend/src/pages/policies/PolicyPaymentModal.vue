<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  createPolicyPayments, fetchPolicyInstallments, fetchPolicyPayments, uploadPolicyDocument,
  type PaymentSummary, type PolicyInstallmentRow,
} from '../../api/policies'
import { ApiError } from '../../api/client'
import PolicyPeriodBadge from '../../components/PolicyPeriodBadge.vue'
import { decomposeByFormula, type PremiumParts, type TaxFormula } from '../../utils/premiumBreakdown'
import { calcInstallment, INSTALLMENT_MODES, type InstallmentMode, type InstallmentResult } from '../../utils/installmentCalc'

export interface ExpectedPremium {
  netPremium: number
  dutyStamp: number
  vat: number
  totalPremiumPaid: number
  discountAmount: number
  commissionAmount: number
}

const props = defineProps<{
  open: boolean
  expected: ExpectedPremium
  carrierLabel: string
  policyId?: string | null
  installmentCount?: number
  frequencyLabel?: string
  mainPremium?: number
  compulsoryPremium?: number
  installmentMode?: string | null
}>()

const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

const saving = ref(false)
const saveError = ref<string | null>(null)
const proofError = ref<string | null>(null)
const summary = ref<PaymentSummary | null>(null)

const instMode = ref<InstallmentMode>('A')
const instCompulsory = ref<number>(0)
const instCount = ref<number>(2)

function inferFormula(): TaxFormula {
  const duty = props.expected.dutyStamp || 0
  const vat = props.expected.vat || 0
  if (Math.round(duty) === 150) return 4
  if (Math.round(duty) === 20) return 3
  if (duty === 0 && vat > 0) return 2
  return 1
}
const taxFormula = ref<TaxFormula>(inferFormula())
const TAX_FORMULAS: { value: TaxFormula; label: string }[] = [
  { value: 1, label: 'สูตร 1' },
  { value: 2, label: 'สูตร 2' },
  { value: 3, label: 'สูตร 3' },
  { value: 4, label: 'สูตร 4' },
]

function breakdownOf(amount: number | null | undefined, extra = 0): PremiumParts {
  return decomposeByFormula((Number(amount) || 0) + extra, taxFormula.value)
}

const pendingConfirm = ref<{ code: 'payment_duplicate' | 'payment_overpay'; message: string } | null>(null)
const confirmFlags = ref({ confirmDuplicate: false, confirmOverpay: false })

function confirmAndSubmit(): void {
  if (!pendingConfirm.value) return
  if (pendingConfirm.value.code === 'payment_duplicate') confirmFlags.value.confirmDuplicate = true
  if (pendingConfirm.value.code === 'payment_overpay') confirmFlags.value.confirmOverpay = true
  pendingConfirm.value = null
  void submitPayments()
}

type Payee = 'insurehub' | 'carrier'
type PayMode = 'full' | 'less_commission' | 'with_discount' | 'split' | 'installment'

const payee = ref<Payee>('insurehub')
const payMode = ref<PayMode>('full')
const adjustment = ref<number | null>(null)
const amountTouched = ref(false)

const hasAdjustment = computed(() => payMode.value === 'less_commission' || payMode.value === 'with_discount')
const adjustmentValue = computed<number>(() => (hasAdjustment.value ? round2(Math.max(0, Number(adjustment.value) || 0)) : 0))
const commissionExtra = computed<number>(() => (payMode.value === 'less_commission' ? adjustmentValue.value : 0))
const commissionCap = computed<number>(() => Number(props.expected.commissionAmount) || 0)

const blockedReason = computed<string | null>(() => (summary.value && !summary.value.payable ? summary.value.blockedReason : null))
const noPermission = computed<boolean>(() => !!summary.value && !summary.value.canRecord)

const dueTotal = computed<number>(() => {
  if (isInstallment.value && schedule.value.length > 1) {
    return round2(schedule.value.reduce((sum, i) => sum + i.amountDue, 0))
  }
  if (summary.value && summary.value.due > 0) return round2(summary.value.due)
  return round2(Number(props.expected.totalPremiumPaid) || 0)
})
const expectedAmount = dueTotal

function round2(x: number): number { return Math.round(x * 100) / 100 }
function fmt(n: number): string {
  return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0)
}

type PayMethod = 'credit_card' | 'transfer' | 'cash'
const PAY_METHODS: { value: PayMethod; label: string }[] = [
  { value: 'transfer', label: 'โอนเงิน' },
  { value: 'credit_card', label: 'บัตรเครดิต' },
  { value: 'cash', label: 'เงินสด' },
]

interface PayRow {
  expected: number | null
  amount: number | null
  date: string
  method: PayMethod
  note: string
  reference: string
  proofFile: File | null
  proofDocumentId: number | null
  installmentId?: string
  dueDate?: string | null
  paidAmount?: number
  locked?: boolean
}
function todayStr(): string {
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}
const today = todayStr()
function blankRow(): PayRow {
  return { expected: null, amount: null, date: todayStr(), method: 'transfer', note: '', reference: '', proofFile: null, proofDocumentId: null }
}

function rowDiff(r: PayRow): { status: 'exact' | 'over' | 'short' | 'pending'; diff: number } {
  const exp = round2((Number(r.expected) || 0) - (r.paidAmount || 0))
  const act = Number(r.amount)
  if (!r.amount && r.amount !== 0) return { status: 'pending', diff: -exp }
  const diff = round2(act - exp)
  if (diff === 0) return { status: 'exact', diff: 0 }
  return { status: diff > 0 ? 'over' : 'short', diff }
}

const rows = reactive<PayRow[]>([blankRow()])

function addRow(): void { rows.push(blankRow()) }
function removeRow(idx: number): void {
  rows.splice(idx, 1)
  if (rows.length === 0) rows.push(blankRow())
}

const MAX_PROOF_BYTES = 10 * 1024 * 1024
const PROOF_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
function onProofPick(idx: number, e: Event): void {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0] ?? null
  proofError.value = null
  if (f && f.size > MAX_PROOF_BYTES) {
    proofError.value = 'ไฟล์สลิปใหญ่เกิน 10 MB'
    input.value = ''
    return
  }
  if (f && !PROOF_TYPES.includes(f.type)) {
    proofError.value = 'แนบได้เฉพาะรูปภาพ (JPG, PNG, WEBP) หรือไฟล์ PDF'
    input.value = ''
    return
  }
  rows[idx].proofFile = f
  rows[idx].proofDocumentId = null
}
function clearProof(idx: number): void {
  rows[idx].proofFile = null
  rows[idx].proofDocumentId = null
}

const alreadyPaid = computed<number>(() =>
  summary.value ? summary.value.paid : round2(rows.reduce((sum, r) => sum + (r.paidAmount || 0), 0)),
)
const newPaid = computed<number>(() =>
  round2(rows.reduce((sum, r) => sum + (r.locked ? 0 : Number(r.amount) || 0), 0)),
)
const paidTotal = computed<number>(() => round2(alreadyPaid.value + newPaid.value + adjustmentValue.value))
const outstanding = computed<number>(() => round2(expectedAmount.value - paidTotal.value))

const plannedTotal = computed<number>(() =>
  round2(rows.reduce((sum, r) => sum + (Number(r.expected) || 0), 0)),
)
const planVsExpected = computed<number>(() => round2(plannedTotal.value - expectedAmount.value))

const installmentResult = computed<InstallmentResult | null>(() => {
  const main = Number(props.mainPremium) || Number(props.expected.netPremium) || 0
  const count = Math.floor(instCount.value)
  if (!(main > 0) || count < 2 || count > 10) return null
  try {
    return calcInstallment(main, Number(instCompulsory.value) || 0, count, instMode.value)
  } catch {
    return null
  }
})

const schedule = ref<PolicyInstallmentRow[]>([])
const scheduleApplied = computed(() => schedule.value.some((i) => i.amountPaid > 0))

function rowsFromSchedule(): PayRow[] {
  return schedule.value.map((i) => ({
    ...blankRow(),
    expected: i.amountDue,
    installmentId: i.id,
    dueDate: i.dueDate,
    paidAmount: i.amountPaid,
    locked: i.status === 'paid',
  }))
}

function buildInstallmentRows(n: number): PayRow[] {
  if (schedule.value.length > 1 && (scheduleApplied.value || !installmentResult.value)) {
    return rowsFromSchedule()
  }
  const built = buildPlannedRows(n)
  if (schedule.value.length === built.length) {
    built.forEach((r, i) => {
      r.installmentId = schedule.value[i].id
      r.dueDate = schedule.value[i].dueDate
    })
  }
  return built
}

async function loadSchedule(): Promise<void> {
  schedule.value = []
  if (!props.policyId) return
  try {
    const res = await fetchPolicyInstallments(props.policyId)
    schedule.value = res.data
  } catch {
    schedule.value = []
  }
  if (schedule.value.length > 1) {
    if (payMode.value !== 'installment' && payMode.value !== 'split') {
      payMode.value = 'installment'
    } else {
      rows.splice(0, rows.length, ...buildInstallmentRows(schedule.value.length))
    }
  }
}

async function loadSummary(): Promise<void> {
  summary.value = null
  if (!props.policyId) return
  try {
    const res = await fetchPolicyPayments(props.policyId)
    summary.value = res.meta ?? null
  } catch {
    summary.value = null
  }
}

function prefillAmount(): void {
  if (isInstallment.value || amountTouched.value || rows.length !== 1) return
  const remaining = round2(dueTotal.value - alreadyPaid.value - adjustmentValue.value)
  rows[0].amount = remaining > 0 ? remaining : null
}

function buildPlannedRows(n: number): PayRow[] {
  const count = Math.max(1, Math.floor(n) || 1)
  const res = installmentResult.value
  if (res && res.installmentCount === count) {
    return res.installments.map((l) => ({ ...blankRow(), expected: l.amount }))
  }
  const total = expectedAmount.value
  const per = round2(total / count)
  const out: PayRow[] = []
  for (let i = 0; i < count; i++) {
    const expected = i === count - 1 ? round2(total - per * (count - 1)) : per
    out.push({ ...blankRow(), expected })
  }
  return out
}

watch(payMode, (mode) => {
  if (mode === 'installment' || mode === 'split') {
    rows.splice(0, rows.length, ...buildInstallmentRows(instCount.value))
  } else {
    rows.splice(0, rows.length, blankRow())
  }
  if (mode === 'split') payee.value = 'carrier'
  else if (mode === 'installment') payee.value = 'insurehub'
  if (mode === 'less_commission') adjustment.value = commissionCap.value > 0 ? commissionCap.value : null
  else if (mode === 'with_discount') adjustment.value = Number(props.expected.discountAmount) > 0 ? Number(props.expected.discountAmount) : null
  else adjustment.value = null
  amountTouched.value = false
  prefillAmount()
})

watch(adjustment, () => { prefillAmount() })

function resetOnOpen(): void {
  payee.value = 'insurehub'
  taxFormula.value = inferFormula()
  saveError.value = null
  proofError.value = null
  pendingConfirm.value = null
  confirmFlags.value = { confirmDuplicate: false, confirmOverpay: false }
  amountTouched.value = false
  adjustment.value = null
  const savedMode = (props.installmentMode as InstallmentMode) || 'A'
  instMode.value = (['A', 'B', 'C'] as const).includes(savedMode) ? savedMode : 'A'
  instCompulsory.value = Number(props.compulsoryPremium) || 0
  const n = props.installmentCount ?? 1
  instCount.value = Math.min(10, Math.max(2, n > 1 ? n : 2))
  if (n > 1) {
    payMode.value = 'installment'
    rows.splice(0, rows.length, ...buildInstallmentRows(n))
  } else {
    payMode.value = 'full'
    rows.splice(0, rows.length, blankRow())
  }
  void loadSummary().then(prefillAmount)
  void loadSchedule()
}
watch(() => props.open, (v) => { if (v) resetOnOpen() })
onMounted(() => { if (props.open) resetOnOpen() })

watch([instMode, instCompulsory, instCount], () => {
  if (scheduleApplied.value) return
  if (payMode.value === 'installment' || payMode.value === 'split') {
    rows.splice(0, rows.length, ...buildInstallmentRows(instCount.value))
  }
})

const isInstallment = computed(() => payMode.value === 'installment' || payMode.value === 'split')

const PAY_MODES: { value: PayMode; label: string }[] = [
  { value: 'full', label: 'จ่ายเต็ม' },
  { value: 'less_commission', label: 'จ่ายหักคอมมิสชั่น' },
  { value: 'with_discount', label: 'จ่ายมีส่วนลด' },
  { value: 'split', label: 'แบ่งชำระ' },
  { value: 'installment', label: 'ผ่อน' },
]

function apiMessage(e: unknown): string {
  if (!(e instanceof ApiError)) return 'บันทึกการชำระล้มเหลว กรุณาลองใหม่อีกครั้ง'
  const errors = (e.body as unknown as { errors?: Record<string, string[]> } | null)?.errors
  const first = errors ? Object.values(errors)[0]?.[0] : undefined
  return first ?? e.message
}

function validateRows(): string | null {
  const entered = rows.filter((r) => !r.locked && r.amount !== null && r.amount !== undefined && String(r.amount) !== '')
  if (!entered.length) return 'กรุณากรอกจำนวนเงินที่ได้รับอย่างน้อย 1 รายการ'
  if (entered.some((r) => !(Number(r.amount) > 0))) return 'ยอดเงินต้องมากกว่า 0'
  if (entered.some((r) => !r.date)) return 'กรุณาระบุวันที่ชำระของแต่ละงวด'
  if (entered.some((r) => r.date > today)) return 'วันที่ชำระต้องไม่เป็นวันในอนาคต'
  if (payMode.value === 'less_commission' && commissionCap.value > 0 && adjustmentValue.value - commissionCap.value > 0.005) {
    return `ค่าคอมที่หักต้องไม่เกินค่าคอมของตัวแทนในกรมธรรม์นี้ (฿${fmt(commissionCap.value)})`
  }
  return null
}

async function submitPayments(): Promise<void> {
  if (!props.policyId) { emit('close'); return }
  if (blockedReason.value || noPermission.value) return
  const problem = validateRows()
  if (problem) { saveError.value = problem; return }

  const policyId = props.policyId
  const entered = rows.filter((r) => !r.locked && r.amount !== null && r.amount !== undefined && String(r.amount) !== '')
  saving.value = true
  saveError.value = null
  try {
    for (const r of entered) {
      if (r.proofFile && r.proofDocumentId === null) {
        const up = await uploadPolicyDocument(policyId, 'receipt', r.proofFile)
        r.proofDocumentId = Number(up.data.id)
      }
    }
    const res = installmentResult.value
    const payload = {
      ...confirmFlags.value,
      payMode: payMode.value,
      payee: payee.value,
      ...(hasAdjustment.value && adjustmentValue.value > 0 ? { adjustmentAmount: adjustmentValue.value } : {}),
      ...(isInstallment.value && res && !scheduleApplied.value ? {
        installmentMode: res.mode,
        agentFeeCost: res.agentFeeCost,
        agentInterestCost: res.agentInterestCost,
      } : {}),
      payments: entered.map((r, idx) => {
        const b = breakdownOf(r.amount, idx === 0 ? commissionExtra.value : 0)
        return {
          paymentDate: r.date,
          amount: Number(r.amount),
          method: r.method,
          note: r.note || undefined,
          reference: r.reference || undefined,
          ...(r.proofDocumentId !== null ? { proofDocumentId: r.proofDocumentId } : {}),
          taxFormula: taxFormula.value,
          netAmount: b.net,
          dutyAmount: b.duty,
          vatAmount: b.vat,
          ...(isInstallment.value && r.installmentId ? { installmentId: Number(r.installmentId) } : {}),
        }
      }),
    }
    await createPolicyPayments(policyId, payload)
    confirmFlags.value = { confirmDuplicate: false, confirmOverpay: false }
    emit('saved')
    emit('close')
  } catch (e: unknown) {
    const code = e instanceof ApiError ? (e.body as unknown as { code?: string } | null)?.code : undefined
    if (e instanceof ApiError && e.status === 409 && (code === 'payment_duplicate' || code === 'payment_overpay')) {
      pendingConfirm.value = { code, message: e.message }
    } else {
      saveError.value = apiMessage(e)
    }
  } finally { saving.value = false }
}
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="emit('close')">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto flex flex-col">
      <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white z-10">
        <div class="text-lg font-semibold text-slate-900">บันทึกการชำระเงิน</div>
        <button class="text-slate-400 hover:text-slate-700 p-1" @click="emit('close')"><i class="pi pi-times" /></button>
      </div>

      <div class="p-5 space-y-5">
        <div v-if="blockedReason" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="payment-blocked">
          <i class="pi pi-ban mr-1.5" />{{ blockedReason }}
        </div>
        <div v-else-if="noPermission" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800" data-testid="payment-no-permission">
          <i class="pi pi-lock mr-1.5" />บัญชีของคุณไม่มีสิทธิ์บันทึกการชำระเงิน กรุณาติดต่อผู้ดูแลระบบ
        </div>

        <div>
          <div class="text-xs uppercase tracking-wider text-slate-400 mb-2">1. ชำระให้</div>
          <div class="flex flex-wrap gap-2">
            <label :class="['flex-1 min-w-[180px] flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer text-sm transition-colors',
              payee === 'insurehub' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 hover:bg-slate-50 text-slate-700']">
              <input type="radio" value="insurehub" v-model="payee" class="accent-brand-500" />
              <span class="font-medium">ชำระให้ InsureHub</span>
            </label>
            <label :class="['flex-1 min-w-[180px] flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer text-sm transition-colors',
              payee === 'carrier' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 hover:bg-slate-50 text-slate-700']">
              <input type="radio" value="carrier" v-model="payee" class="accent-brand-500" />
              <span class="font-medium">ชำระให้บริษัทประกัน<span v-if="carrierLabel" class="text-slate-500 font-normal"> · {{ carrierLabel }}</span></span>
            </label>
          </div>
          <p v-if="payee === 'carrier'" class="mt-1.5 text-[11px] text-slate-500">
            <i class="pi pi-info-circle mr-1" />ลูกค้าจ่ายตรงถึงบริษัทประกัน ไม่มีเงินเข้า InsureHub — ระบบนับเป็นเบี้ยที่ชำระแล้ว ลูกค้าจะไม่ค้างชำระ และแยกยอดนี้ไว้ในรายงาน
          </p>
        </div>

        <div>
          <div class="text-xs uppercase tracking-wider text-slate-400 mb-2">2. รูปแบบการชำระ</div>
          <div class="flex flex-wrap gap-2">
            <button v-for="m in PAY_MODES" :key="m.value" type="button" @click="payMode = m.value"
              :class="['px-3 py-1.5 text-sm rounded-lg border',
                payMode === m.value ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-slate-200 hover:border-brand-300']">
              {{ m.label }}
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-[11px] text-slate-400">เบี้ยที่ต้องชำระ</div>
            <div class="text-lg font-semibold text-slate-900 tabular-nums" data-testid="payment-expected">฿{{ fmt(expectedAmount) }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-[11px] text-slate-400">ชำระแล้ว (รวมรายการที่กำลังกรอก)</div>
            <div class="text-lg font-semibold text-emerald-600 tabular-nums" data-testid="payment-paid">฿{{ fmt(paidTotal) }}</div>
          </div>
          <div class="rounded-lg border p-3" :class="outstanding > 0 ? 'border-amber-300 bg-amber-50' : outstanding < 0 ? 'border-sky-300 bg-sky-50' : 'border-emerald-300 bg-emerald-50'">
            <div class="text-[11px]" :class="outstanding > 0 ? 'text-amber-600' : outstanding < 0 ? 'text-sky-600' : 'text-emerald-600'">
              {{ outstanding < 0 ? 'ชำระเกินยอด' : 'ยอดค้างชำระ' }}
            </div>
            <div class="text-lg font-semibold tabular-nums" :class="outstanding > 0 ? 'text-amber-700' : outstanding < 0 ? 'text-sky-700' : 'text-emerald-700'" data-testid="payment-outstanding">
              ฿{{ fmt(Math.abs(outstanding)) }}
            </div>
          </div>
        </div>
        <p v-if="alreadyPaid > 0" class="-mt-3 text-[11px] text-slate-500">
          <i class="pi pi-history mr-1" />บันทึกไว้ก่อนหน้านี้แล้ว ฿{{ fmt(alreadyPaid) }}
        </p>

        <div class="rounded-lg bg-slate-50 border border-slate-100 p-3 text-xs text-slate-600">
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-y-1 gap-x-4">
            <div>เบี้ยสุทธิ <span class="float-right tabular-nums text-slate-900">{{ fmt(expected.netPremium) }}</span></div>
            <div>อากรแสตมป์ <span class="float-right tabular-nums text-slate-900">{{ fmt(expected.dutyStamp) }}</span></div>
            <div>ภาษีมูลค่าเพิ่ม <span class="float-right tabular-nums text-slate-900">{{ fmt(expected.vat) }}</span></div>
            <div>รวมเบี้ย <span class="float-right tabular-nums text-slate-900">{{ fmt(expectedAmount) }}</span></div>
          </div>
        </div>

        <div v-if="hasAdjustment" class="rounded-lg border border-violet-200 bg-violet-50/50 p-3">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
            <div>
              <label class="block text-[11px] font-medium text-violet-800 mb-0.5">
                {{ payMode === 'less_commission' ? 'ค่าคอมมิชชั่นที่ตัวแทนหักไว้ (บาท)' : 'ส่วนลดให้ลูกค้า (บาท)' }}
              </label>
              <input v-model.number="adjustment" type="number" min="0" step="0.01" placeholder="0.00" data-testid="payment-adjustment"
                class="w-full border border-violet-200 bg-white rounded-md px-2 py-1 text-sm focus:outline-none focus:border-violet-400" />
            </div>
            <p class="sm:col-span-2 text-[11px] text-violet-800 leading-relaxed">
              <template v-if="payMode === 'less_commission'">
                ยอดที่หักนี้นับเป็นเบี้ยที่ชำระแล้ว ลูกค้าไม่ค้าง และระบบจะหักออกจากรอบจ่ายค่าคอมของตัวแทนอัตโนมัติ ไม่จ่ายซ้ำ
                <span v-if="commissionCap > 0">(ไม่เกิน ฿{{ fmt(commissionCap) }})</span>
              </template>
              <template v-else>ส่วนลดนับเป็นยอดที่ปิดเบี้ยแล้ว ลูกค้าจะไม่ค้างชำระส่วนนี้ — กรอกเฉพาะเงินที่ได้รับจริงในช่องด้านล่าง</template>
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
          <span class="text-[11px] text-slate-500">สูตรถอดภาษี (จากยอดจ่ายจริง):</span>
          <div class="inline-flex rounded-lg border border-slate-200 overflow-hidden">
            <button v-for="f in TAX_FORMULAS" :key="f.value" type="button"
              @click="taxFormula = f.value"
              :class="['px-3 py-1 text-xs', taxFormula === f.value ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']">
              {{ f.label }}
            </button>
          </div>
          <span class="text-[10px] text-slate-400">
            <i class="pi pi-info-circle mr-0.5" />สูตร1=อากร0.4%+VAT7% · สูตร2=VAT7% · สูตร3=อากร20 · สูตร4=อากร150
          </span>
        </div>

        <div v-if="isInstallment" class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-3">
          <div class="mb-2 text-[11px] font-medium text-indigo-800">คำนวณงวดผ่อน (ตามสูตร Insurehub)</div>
          <p v-if="scheduleApplied" class="text-[11px] text-amber-700">
            <i class="pi pi-lock mr-1" />มีการรับชำระแล้ว แผนผ่อนและจำนวนเงินต่องวดจึงล็อกตามตารางงวดของกรมธรรม์
          </p>
          <template v-else>
          <fieldset class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
              <label class="block text-[10px] text-slate-500 mb-0.5">รูปแบบ (Mode)</label>
              <select v-model="instMode" class="w-full border border-slate-300 rounded-md px-2 py-1 text-xs">
                <option v-for="m in INSTALLMENT_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-[10px] text-slate-500 mb-0.5">จำนวนงวด (2–10)</label>
              <input v-model.number="instCount" type="number" min="2" max="10"
                class="w-full border border-slate-300 rounded-md px-2 py-1 text-xs" />
            </div>
            <div>
              <label class="block text-[10px] text-slate-500 mb-0.5">เบี้ยหลัก (main)</label>
              <input :value="Number(mainPremium) || expected.netPremium" type="number" readonly
                class="w-full border border-slate-200 bg-slate-50 rounded-md px-2 py-1 text-xs text-slate-500" />
            </div>
            <div>
              <label class="block text-[10px] text-slate-500 mb-0.5">พ.ร.บ. (งวดแรก)</label>
              <input v-model.number="instCompulsory" type="number" min="0" step="0.01"
                class="w-full border border-slate-300 rounded-md px-2 py-1 text-xs" />
            </div>
          </fieldset>
          <p class="mt-1 text-[10px] text-slate-500">
            {{ INSTALLMENT_MODES.find((m) => m.value === instMode)?.hint }}
          </p>
          <div v-if="installmentResult" class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1 border-t border-indigo-100 pt-2 text-[11px]">
            <span>งวด 1 <span class="float-right tabular-nums font-medium text-slate-800">฿{{ fmt(installmentResult.installments[0].amount) }}</span></span>
            <span>งวด 2–{{ installmentResult.installmentCount }} <span class="float-right tabular-nums text-slate-800">฿{{ fmt(installmentResult.installments[1]?.amount ?? 0) }}</span></span>
            <span>Fee ลูกค้า <span class="float-right tabular-nums text-slate-800">฿{{ fmt(installmentResult.customerFee) }}</span></span>
            <span>ดอกเบี้ยลูกค้า <span class="float-right tabular-nums text-slate-800">฿{{ fmt(installmentResult.customerInterest) }}</span></span>
            <span class="text-emerald-700">รวมลูกค้าจ่าย <span class="float-right tabular-nums font-medium">฿{{ fmt(installmentResult.totalCustomerPayment) }}</span></span>
            <span v-if="installmentResult.agentFeeCost > 0" class="text-amber-700">Fee ตัวแทนรับภาระ <span class="float-right tabular-nums">฿{{ fmt(installmentResult.agentFeeCost) }}</span></span>
            <span v-if="installmentResult.agentInterestCost > 0" class="text-amber-700">ดอกเบี้ยตัวแทนรับภาระ <span class="float-right tabular-nums">฿{{ fmt(installmentResult.agentInterestCost) }}</span></span>
          </div>
          <p v-else class="mt-2 text-[10px] text-rose-600">กรอกเบี้ยหลัก &gt; 0 และจำนวนงวด 2–10 เพื่อคำนวณ</p>
          </template>
        </div>

        <div>
          <div class="flex items-center justify-between mb-2">
            <div class="text-xs uppercase tracking-wider text-slate-400">
              {{ isInstallment ? 'งวดการผ่อน' : 'รายการที่ได้รับ' }}
              <span v-if="isInstallment && frequencyLabel" class="normal-case tracking-normal text-slate-400 lowercase">· {{ frequencyLabel }} ({{ rows.length }} งวด)</span>
            </div>
            <button v-if="isInstallment" type="button" @click="addRow"
              class="text-xs px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50">
              <i class="pi pi-plus" /> เพิ่มงวด
            </button>
          </div>

          <div v-if="!isInstallment" class="rounded-lg border border-slate-200 p-3">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div>
                <label class="block text-[10px] text-slate-400 mb-0.5">เงินที่ได้รับจริง (บาท)</label>
                <input v-model.number="rows[0].amount" type="number" min="0" step="0.01" placeholder="0.00" data-testid="payment-amount"
                  @input="amountTouched = true"
                  class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
              </div>
              <div>
                <label class="block text-[10px] text-slate-400 mb-0.5">วันที่ชำระ</label>
                <input v-model="rows[0].date" type="date" :max="today"
                  class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
              </div>
              <div>
                <label class="block text-[10px] text-slate-400 mb-0.5">ช่องทาง</label>
                <select v-model="rows[0].method"
                  class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm bg-white focus:outline-none focus:border-brand-400">
                  <option v-for="pm in PAY_METHODS" :key="pm.value" :value="pm.value">{{ pm.label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-[10px] text-slate-400 mb-0.5">สลิป / หลักฐาน</label>
                <div class="flex items-center gap-1">
                  <label class="flex-1 min-w-0 flex items-center gap-1.5 text-xs px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50 cursor-pointer">
                    <i class="pi pi-upload text-[10px]" />
                    <span class="truncate">{{ rows[0].proofFile?.name || 'แนบไฟล์' }}</span>
                    <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp,application/pdf" data-testid="payment-proof" @change="onProofPick(0, $event)" />
                  </label>
                  <button v-if="rows[0].proofFile" type="button" class="text-slate-400 hover:text-rose-500 p-1" title="เอาไฟล์ออก" @click="clearProof(0)"><i class="pi pi-times text-[10px]" /></button>
                </div>
              </div>
              <div class="col-span-2">
                <label class="block text-[10px] text-slate-400 mb-0.5">เลขอ้างอิง / เลขที่สลิป (ถ้ามี)</label>
                <input v-model.trim="rows[0].reference" type="text" maxlength="255" placeholder="—"
                  class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
              </div>
              <div class="col-span-2">
                <label class="block text-[10px] text-slate-400 mb-0.5">หมายเหตุ</label>
                <input v-model.trim="rows[0].note" type="text" maxlength="255" placeholder="—"
                  class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
              </div>
            </div>
            <div v-if="rows[0].amount" class="mt-2 pt-2 border-t border-dashed border-slate-200 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-slate-500">
              <span>เบี้ยสุทธิ <span class="tabular-nums text-slate-800">฿{{ fmt(breakdownOf(rows[0].amount, commissionExtra).net) }}</span></span>
              <span>อากรแสตมป์ <span class="tabular-nums text-slate-800">฿{{ fmt(breakdownOf(rows[0].amount, commissionExtra).duty) }}</span></span>
              <span>ภาษีมูลค่าเพิ่ม <span class="tabular-nums text-slate-800">฿{{ fmt(breakdownOf(rows[0].amount, commissionExtra).vat) }}</span></span>
            </div>
          </div>

          <div v-else class="space-y-2">
            <div v-for="(row, idx) in rows" :key="idx"
              :class="['rounded-lg border p-3', row.locked ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200']">
              <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-xs font-medium text-slate-600">งวดที่ {{ idx + 1 }}/{{ rows.length }}</span>
                  <PolicyPeriodBadge v-if="schedule[idx]?.periodLabel" :label="schedule[idx]?.periodLabel" />
                  <span v-if="row.dueDate" class="text-[11px] text-slate-400">ครบกำหนด {{ row.dueDate }}</span>
                  <span v-if="row.locked" class="inline-flex items-center gap-1 text-xs text-emerald-700">
                    <i class="pi pi-check-circle" /> ชำระแล้ว ฿{{ fmt(row.paidAmount || 0) }}
                  </span>
                  <span v-else-if="row.paidAmount" class="text-xs text-amber-700">ชำระแล้วบางส่วน ฿{{ fmt(row.paidAmount) }}</span>
                  <template v-if="!row.locked">
                  <span v-if="rowDiff(row).status === 'exact'" class="inline-flex items-center gap-1 text-xs text-emerald-600">
                    <i class="pi pi-check-circle" /> ครบ
                  </span>
                  <span v-else-if="rowDiff(row).status === 'over'" class="inline-flex items-center gap-1 text-xs text-sky-600">
                    <i class="pi pi-arrow-up-right" /> เกิน {{ fmt(Math.abs(rowDiff(row).diff)) }}
                  </span>
                  <span v-else-if="rowDiff(row).status === 'short'" class="inline-flex items-center gap-1 text-xs text-amber-600">
                    <i class="pi pi-exclamation-triangle" /> ขาด {{ fmt(Math.abs(rowDiff(row).diff)) }}
                  </span>
                  <span v-else-if="row.paidAmount" class="inline-flex items-center gap-1 text-xs text-slate-500">
                    คงค้าง ฿{{ fmt(Math.abs(rowDiff(row).diff)) }}
                  </span>
                  <span v-else class="inline-flex items-center gap-1 text-xs text-slate-400">
                    <i class="pi pi-clock" /> ยังไม่จ่าย
                  </span>
                  </template>
                </div>
                <button v-if="!(scheduleApplied && row.installmentId)" type="button" @click="removeRow(idx)" class="text-slate-400 hover:text-rose-500">
                  <i class="pi pi-trash text-xs" />
                </button>
              </div>
              <fieldset v-if="!row.locked" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5">
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">ยอดที่ต้องชำระ</label>
                  <input v-model.number="row.expected" type="number" min="0" step="0.01" placeholder="0.00" :readonly="scheduleApplied"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400 read-only:bg-slate-50 read-only:text-slate-500" />
                </div>
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">เงินที่ได้รับจริง</label>
                  <input v-model.number="row.amount" type="number" min="0" step="0.01" placeholder="0.00"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
                </div>
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">วันที่ชำระ</label>
                  <input v-model="row.date" type="date" :max="today"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
                </div>
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">ช่องทาง</label>
                  <select v-model="row.method"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm bg-white focus:outline-none focus:border-brand-400">
                    <option v-for="pm in PAY_METHODS" :key="pm.value" :value="pm.value">{{ pm.label }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">สลิป / หลักฐาน</label>
                  <div class="flex items-center gap-1">
                    <label class="flex-1 min-w-0 flex items-center gap-1.5 text-xs px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50 cursor-pointer">
                      <i class="pi pi-upload text-[10px]" />
                      <span class="truncate">{{ row.proofFile?.name || 'แนบไฟล์' }}</span>
                      <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp,application/pdf" @change="onProofPick(idx, $event)" />
                    </label>
                    <button v-if="row.proofFile" type="button" class="text-slate-400 hover:text-rose-500 p-1" title="เอาไฟล์ออก" @click="clearProof(idx)"><i class="pi pi-times text-[10px]" /></button>
                  </div>
                </div>
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">หมายเหตุ</label>
                  <input v-model.trim="row.note" type="text" maxlength="255" placeholder="—"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
                </div>
              </fieldset>
              <div v-if="row.amount" class="mt-2 pt-2 border-t border-dashed border-slate-200 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-slate-500">
                <span>เบี้ยสุทธิ <span class="tabular-nums text-slate-800">฿{{ fmt(breakdownOf(row.amount).net) }}</span></span>
                <span>อากรแสตมป์ <span class="tabular-nums text-slate-800">฿{{ fmt(breakdownOf(row.amount).duty) }}</span></span>
                <span>ภาษีมูลค่าเพิ่ม <span class="tabular-nums text-slate-800">฿{{ fmt(breakdownOf(row.amount).vat) }}</span></span>
              </div>
            </div>
          </div>

          <p v-if="proofError" class="mt-1.5 text-[11px] text-rose-600"><i class="pi pi-exclamation-circle mr-1" />{{ proofError }}</p>

          <div v-if="isInstallment" class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px]">
            <span class="text-slate-500">รวมยอดที่ต้องชำระ (แผน): <span class="tabular-nums text-slate-800 font-medium">฿{{ fmt(plannedTotal) }}</span></span>
            <span v-if="planVsExpected === 0" class="inline-flex items-center gap-1 text-emerald-600">
              <i class="pi pi-check-circle" /> ตรงกับยอดเต็ม ฿{{ fmt(expectedAmount) }}
            </span>
            <span v-else-if="planVsExpected > 0" class="inline-flex items-center gap-1 text-sky-600">
              <i class="pi pi-arrow-up-right" /> แผนเกินยอดเต็ม {{ fmt(planVsExpected) }} (ยอดเต็ม ฿{{ fmt(expectedAmount) }})
            </span>
            <span v-else class="inline-flex items-center gap-1 text-amber-600">
              <i class="pi pi-exclamation-triangle" /> แผนขาดอีก {{ fmt(Math.abs(planVsExpected)) }} จากยอดเต็ม ฿{{ fmt(expectedAmount) }}
            </span>
          </div>
          <p v-if="isInstallment" class="text-[10px] text-slate-500 mt-1">
            <i class="pi pi-info-circle mr-1" />กรอกเฉพาะงวดที่ลูกค้าจ่ายแล้ว — งวดที่ยังไม่จ่ายเว้นว่างไว้ได้ ระบบจะคงเป็นยอดค้าง
          </p>
        </div>
      </div>

      <div class="px-5 py-4 border-t border-slate-200 flex items-center justify-between sticky bottom-0 bg-white gap-3">
        <div v-if="pendingConfirm" class="flex flex-1 flex-wrap items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
          <span><i class="pi pi-exclamation-triangle mr-1" />{{ pendingConfirm.message }}</span>
          <button type="button" class="rounded-md bg-amber-600 px-2 py-1 font-medium text-white hover:bg-amber-700" :disabled="saving" @click="confirmAndSubmit">ยืนยันบันทึก</button>
          <button type="button" class="rounded-md border border-amber-300 px-2 py-1 text-amber-700" @click="pendingConfirm = null">ยกเลิก</button>
        </div>
        <p v-else-if="saveError" class="text-xs text-rose-600 flex-1" data-testid="payment-error"><i class="pi pi-exclamation-circle mr-1" />{{ saveError }}</p>
        <p v-else-if="!policyId" class="text-[10px] text-amber-600 flex-1"><i class="pi pi-info-circle mr-1" />บันทึกร่างกรมธรรม์ก่อน จึงจะบันทึกการชำระเงินได้ (กด “บันทึกร่าง” ที่ด้านบน)</p>
        <p v-else class="text-[10px] text-slate-400 flex-1"><i class="pi pi-info-circle mr-1" />บันทึกแล้วระบบจะคำนวณค่าคอมมิชชั่นและอัปเดตยอดค้างให้อัตโนมัติ</p>
        <div class="flex gap-2 shrink-0">
          <button type="button" @click="emit('close')" :disabled="saving" class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100 disabled:opacity-50">ปิด</button>
          <button type="button" @click="submitPayments" :disabled="saving || !policyId || !!blockedReason || noPermission" data-testid="payment-save"
            class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:bg-slate-300 disabled:cursor-not-allowed">
            <i :class="saving ? 'pi pi-spin pi-spinner' : 'pi pi-check'" class="text-xs mr-1" />{{ saving ? 'กำลังบันทึก…' : 'บันทึก' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
