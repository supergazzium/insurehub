<script setup lang="ts">
// Payment-tracking modal for the create-policy wizard. FRONTEND-ONLY (C-24):
// nothing is persisted to the backend yet — this is the UI/UX skeleton so
// the flow can be reviewed before the payments API lands. All state lives in
// this component and is dropped when the modal closes.
//
// Flow:
//   Step 1  ผู้รับชำระ    — pay to InsureHub or pay to the carrier company
//   Step 2  รูปแบบการชำระ — จ่ายเต็ม / จ่ายหักคอมมิสชั่น / จ่ายมีส่วนลด / ผ่อน
//   Then    ยอดที่ต้องจ่าย (expected, from the wizard's computed premium) vs
//           ยอดที่ชำระจริง (realized). For ผ่อน the operator enters each
//           installment row (amount / date / note / proof); ยอดค้าง auto-
//           computes as expected − Σ paid.

import { computed, reactive, ref, watch } from 'vue'

/** Expected (computed) premium numbers passed in from the wizard. */
export interface ExpectedPremium {
  netPremium: number
  dutyStamp: number
  vat: number
  totalPremiumPaid: number
  discountAmount: number
  commissionAmount: number // hub → agent commission (for จ่ายหักคอมมิสชั่น)
}

const props = defineProps<{
  open: boolean
  expected: ExpectedPremium
  carrierLabel: string
  /** Number of installment งวด, from the wizard's แผนการผ่อนชำระ. When > 1
   *  the modal opens in ผ่อน mode with this many prefilled rows. */
  installmentCount?: number
  /** Human label of the payment frequency (รายเดือน / รายปี / …). */
  frequencyLabel?: string
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

type Payee = 'insurehub' | 'carrier'
type PayMode = 'full' | 'less_commission' | 'with_discount' | 'installment'

const payee = ref<Payee>('insurehub')
const payMode = ref<PayMode>('full')

// ── Expected amount by pay mode ──────────────────────────────────────────
// จ่ายเต็ม        = รวมเบี้ยที่ต้องชำระ
// จ่ายหักคอมมิสชั่น = รวมเบี้ย − ค่าคอมมิชชั่น (hub→agent)
// จ่ายมีส่วนลด     = รวมเบี้ย − ส่วนลด
// ผ่อน            = รวมเบี้ย (split across installments)
const expectedAmount = computed<number>(() => {
  const total = Number(props.expected.totalPremiumPaid) || 0
  if (payMode.value === 'less_commission') return round2(total - (Number(props.expected.commissionAmount) || 0))
  if (payMode.value === 'with_discount') return round2(total - (Number(props.expected.discountAmount) || 0))
  return round2(total)
})

function round2(x: number): number { return Math.round(x * 100) / 100 }
function fmt(n: number): string {
  return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0)
}

// ── Payment method (ช่องทางชำระ) ─────────────────────────────────────────
type PayMethod = 'credit_card' | 'transfer' | 'cash'
const PAY_METHODS: { value: PayMethod; label: string }[] = [
  { value: 'credit_card', label: 'บัตรเครดิต' },
  { value: 'transfer', label: 'โอนเงิน' },
  { value: 'cash', label: 'เงินสด' },
]

// ── Payment rows (realized) ──────────────────────────────────────────────
// For จ่ายเต็ม/หักคอม/ส่วนลด there's normally one row; ผ่อน has many. Each row
// carries amount / date / method / note / proof file. Kept generic so every
// mode uses the same entry table.
interface PayRow {
  expected: number | null // ยอดที่ต้องชำระ ของงวดนี้ (installment only)
  amount: number | null   // ยอดชำระจริง
  date: string
  method: PayMethod
  note: string
  proofName: string // just the file name; no real upload in this FE-only build
}
function blankRow(): PayRow { return { expected: null, amount: null, date: '', method: 'transfer', note: '', proofName: '' } }

/** Per-installment reconciliation: actual − expected.
 *  status: 'exact' (ครบ) | 'over' (เกิน) | 'short' (ขาด) | 'pending' (ยังไม่จ่าย). */
function rowDiff(r: PayRow): { status: 'exact' | 'over' | 'short' | 'pending'; diff: number } {
  const exp = Number(r.expected) || 0
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

/** Attach a proof file to a row (name only — no upload in this build). */
function onProofPick(idx: number, e: Event): void {
  const f = (e.target as HTMLInputElement).files?.[0]
  rows[idx].proofName = f ? f.name : ''
}

const paidTotal = computed<number>(() =>
  round2(rows.reduce((sum, r) => sum + (Number(r.amount) || 0), 0)),
)
const outstanding = computed<number>(() => round2(expectedAmount.value - paidTotal.value))

/** Sum of the per-งวด planned amounts (installment mode). Lets the operator
 *  see whether the installment plan adds up to the total expected. */
const plannedTotal = computed<number>(() =>
  round2(rows.reduce((sum, r) => sum + (Number(r.expected) || 0), 0)),
)
/** Plan vs total expected: >0 plan exceeds, <0 plan is short of the premium. */
const planVsExpected = computed<number>(() => round2(plannedTotal.value - expectedAmount.value))

/** Build `n` installment rows with the total expected split evenly across
 *  them (the last row absorbs any rounding remainder so the plan sums exactly
 *  to the expected amount). Each row's ยอดที่ต้องชำระ is prefilled; ยอดชำระจริง
 *  stays blank until the customer pays. */
function buildInstallmentRows(n: number): PayRow[] {
  const count = Math.max(1, Math.floor(n) || 1)
  const total = expectedAmount.value
  const per = round2(total / count)
  const out: PayRow[] = []
  for (let i = 0; i < count; i++) {
    const expected = i === count - 1 ? round2(total - per * (count - 1)) : per
    out.push({ ...blankRow(), expected })
  }
  return out
}

// Reset rows when the pay mode changes. Switching TO installment prefills the
// planned งวด (count + split from the wizard); other modes get one blank row.
watch(payMode, (mode) => {
  if (mode === 'installment') {
    rows.splice(0, rows.length, ...buildInstallmentRows(props.installmentCount ?? 1))
  } else {
    rows.splice(0, rows.length, blankRow())
  }
})

// Reset everything each time the modal opens. If the wizard's plan has more
// than one งวด, open directly in ผ่อน with the rows prefilled.
watch(() => props.open, (v) => {
  if (v) {
    payee.value = 'insurehub'
    const n = props.installmentCount ?? 1
    if (n > 1) {
      payMode.value = 'installment'
      rows.splice(0, rows.length, ...buildInstallmentRows(n))
    } else {
      payMode.value = 'full'
      rows.splice(0, rows.length, blankRow())
    }
  }
})

const isInstallment = computed(() => payMode.value === 'installment')

const PAY_MODES: { value: PayMode; label: string }[] = [
  { value: 'full', label: 'จ่ายเต็ม' },
  { value: 'less_commission', label: 'จ่ายหักคอมมิสชั่น' },
  { value: 'with_discount', label: 'จ่ายมีส่วนลด' },
  { value: 'installment', label: 'ผ่อน' },
]
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="emit('close')">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto flex flex-col">
      <!-- Header -->
      <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white z-10">
        <div class="text-lg font-semibold text-slate-900">บันทึกการชำระเงิน</div>
        <button class="text-slate-400 hover:text-slate-700 p-1" @click="emit('close')"><i class="pi pi-times" /></button>
      </div>

      <div class="p-5 space-y-5">
        <!-- Step 1: payee -->
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
        </div>

        <!-- Step 2: pay mode -->
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

        <!-- Expected vs paid summary -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-[11px] text-slate-400">ยอดที่ต้องจ่าย (Expected)</div>
            <div class="text-lg font-semibold text-slate-900 tabular-nums">฿{{ fmt(expectedAmount) }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-[11px] text-slate-400">ชำระแล้ว (Realized)</div>
            <div class="text-lg font-semibold text-emerald-600 tabular-nums">฿{{ fmt(paidTotal) }}</div>
          </div>
          <div class="rounded-lg border p-3" :class="outstanding > 0 ? 'border-amber-300 bg-amber-50' : 'border-emerald-300 bg-emerald-50'">
            <div class="text-[11px]" :class="outstanding > 0 ? 'text-amber-600' : 'text-emerald-600'">ยอดค้าง (Outstanding)</div>
            <div class="text-lg font-semibold tabular-nums" :class="outstanding > 0 ? 'text-amber-700' : 'text-emerald-700'">฿{{ fmt(outstanding) }}</div>
          </div>
        </div>

        <!-- Expected breakdown (from the 4 สูตร) -->
        <div class="rounded-lg bg-slate-50 border border-slate-100 p-3 text-xs text-slate-600">
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-y-1 gap-x-4">
            <div>เบี้ยสุทธิ <span class="float-right tabular-nums text-slate-900">{{ fmt(expected.netPremium) }}</span></div>
            <div>อากรแสตมป์ <span class="float-right tabular-nums text-slate-900">{{ fmt(expected.dutyStamp) }}</span></div>
            <div>ภาษีมูลค่าเพิ่ม <span class="float-right tabular-nums text-slate-900">{{ fmt(expected.vat) }}</span></div>
            <div>รวมเบี้ย <span class="float-right tabular-nums text-slate-900">{{ fmt(expected.totalPremiumPaid) }}</span></div>
            <div v-if="payMode === 'with_discount'">ส่วนลด <span class="float-right tabular-nums text-rose-600">−{{ fmt(expected.discountAmount) }}</span></div>
            <div v-if="payMode === 'less_commission'">หักคอมมิชชั่น <span class="float-right tabular-nums text-rose-600">−{{ fmt(expected.commissionAmount) }}</span></div>
          </div>
        </div>

        <!-- Payment rows -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <div class="text-xs uppercase tracking-wider text-slate-400">
              {{ isInstallment ? 'งวดการผ่อน' : 'รายการชำระ' }}
              <span v-if="isInstallment && frequencyLabel" class="normal-case tracking-normal text-slate-400 lowercase">· {{ frequencyLabel }} ({{ rows.length }} งวด)</span>
            </div>
            <button v-if="isInstallment" type="button" @click="addRow"
              class="text-xs px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50">
              <i class="pi pi-plus" /> เพิ่มงวด
            </button>
          </div>

          <!-- ── Single-payment modes: one compact labeled grid ──────────── -->
          <div v-if="!isInstallment" class="rounded-lg border border-slate-200 p-3">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div>
                <label class="block text-[10px] text-slate-400 mb-0.5">ยอดเงิน (บาท)</label>
                <input v-model.number="rows[0].amount" type="number" min="0" step="0.01" placeholder="0.00"
                  class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
              </div>
              <div>
                <label class="block text-[10px] text-slate-400 mb-0.5">วันที่</label>
                <input v-model="rows[0].date" type="date"
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
                <label class="block text-[10px] text-slate-400 mb-0.5">หลักฐาน</label>
                <label class="flex items-center gap-1.5 text-xs px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50 cursor-pointer">
                  <i class="pi pi-upload text-[10px]" />
                  <span class="truncate">{{ rows[0].proofName || 'แนบไฟล์' }}</span>
                  <input type="file" class="hidden" accept="image/*,.pdf" @change="onProofPick(0, $event)" />
                </label>
              </div>
              <div class="col-span-2 sm:col-span-4">
                <label class="block text-[10px] text-slate-400 mb-0.5">หมายเหตุ</label>
                <input v-model.trim="rows[0].note" type="text" placeholder="—"
                  class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
              </div>
            </div>
          </div>

          <!-- ── Installment mode: one card per งวด (fits any width) ──────── -->
          <div v-else class="space-y-2">
            <div v-for="(row, idx) in rows" :key="idx" class="rounded-lg border border-slate-200 p-3">
              <!-- Card header: งวด number + per-งวด status + delete -->
              <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                  <span class="text-xs font-medium text-slate-600">งวดที่ {{ idx + 1 }}</span>
                  <span v-if="rowDiff(row).status === 'exact'" class="inline-flex items-center gap-1 text-xs text-emerald-600">
                    <i class="pi pi-check-circle" /> ครบ
                  </span>
                  <span v-else-if="rowDiff(row).status === 'over'" class="inline-flex items-center gap-1 text-xs text-sky-600">
                    <i class="pi pi-arrow-up-right" /> เกิน {{ fmt(Math.abs(rowDiff(row).diff)) }}
                  </span>
                  <span v-else-if="rowDiff(row).status === 'short'" class="inline-flex items-center gap-1 text-xs text-amber-600">
                    <i class="pi pi-exclamation-triangle" /> ขาด {{ fmt(Math.abs(rowDiff(row).diff)) }}
                  </span>
                  <span v-else class="inline-flex items-center gap-1 text-xs text-slate-400">
                    <i class="pi pi-clock" /> ยังไม่จ่าย
                  </span>
                </div>
                <button type="button" @click="removeRow(idx)" class="text-slate-400 hover:text-rose-500">
                  <i class="pi pi-trash text-xs" />
                </button>
              </div>
              <!-- Fields grid: wraps to fit; no horizontal scroll -->
              <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5">
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">ยอดที่ต้องชำระ</label>
                  <input v-model.number="row.expected" type="number" min="0" step="0.01" placeholder="0.00"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
                </div>
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">ยอดชำระจริง</label>
                  <input v-model.number="row.amount" type="number" min="0" step="0.01" placeholder="0.00"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
                </div>
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">วันที่</label>
                  <input v-model="row.date" type="date"
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
                  <label class="block text-[10px] text-slate-400 mb-0.5">หลักฐาน</label>
                  <label class="flex items-center gap-1.5 text-xs px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50 cursor-pointer">
                    <i class="pi pi-upload text-[10px]" />
                    <span class="truncate">{{ row.proofName || 'แนบไฟล์' }}</span>
                    <input type="file" class="hidden" accept="image/*,.pdf" @change="onProofPick(idx, $event)" />
                  </label>
                </div>
                <div>
                  <label class="block text-[10px] text-slate-400 mb-0.5">หมายเหตุ</label>
                  <input v-model.trim="row.note" type="text" placeholder="—"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
                </div>
              </div>
            </div>
          </div>

          <!-- Plan reconciliation: sum of per-งวด planned vs total expected. -->
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
            <i class="pi pi-info-circle mr-1" />กรอกยอดที่ต้องชำระและยอดชำระจริงแต่ละงวด — สถานะเกิน/ขาดคำนวณต่องวด, ยอดค้างรวมคำนวณจากยอดเต็ม − ยอดที่ชำระแล้ว
          </p>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-5 py-4 border-t border-slate-200 flex items-center justify-between sticky bottom-0 bg-white">
        <p class="text-[10px] text-slate-400"><i class="pi pi-info-circle mr-1" />ตัวอย่างหน้าจอ (ยังไม่บันทึกเข้าระบบ)</p>
        <div class="flex gap-2">
          <button type="button" @click="emit('close')" class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100">ปิด</button>
          <button type="button" @click="emit('close')" class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700">
            <i class="pi pi-check text-xs mr-1" />บันทึก
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
