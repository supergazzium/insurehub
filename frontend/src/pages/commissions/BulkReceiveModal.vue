<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  bulkReceive, createReceiptBatch, fetchReceiptBatches,
  type BulkRowError, type DiffReason, type ReceiptBatch, type ReceivableRow,
} from '../../api/commissionReceipts'
import { ApiError } from '../../api/client'
import { REASON_OPTIONS, money, signedMoney, parseAmount, today } from './receiptLabels'

const props = defineProps<{ rows: ReceivableRow[]; insurerId: number; insurerName: string; statementMonth: string | null }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'done'): void }>()

interface Line { amount: string; reason: DiffReason | ''; note: string }
const lines = reactive<Record<string, Line>>({})
for (const r of props.rows) {
  lines[r.id] = {
    amount: r.statementAmount !== null ? String(r.statementAmount) : '',
    reason: r.suggestedReason ?? '',
    note: '',
  }
}

const receivedDate = ref(today())
const batches = ref<ReceiptBatch[]>([])
const batchMode = ref<'none' | 'existing' | 'new'>('new')
const batchId = ref<string>('')
const newMonth = ref(props.statementMonth ?? '')
const newTotal = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const rowErrors = ref<BulkRowError[]>([])

onMounted(async () => {
  try {
    batches.value = (await fetchReceiptBatches(props.insurerId)).data
    if (batches.value.length) {
      batchMode.value = 'existing'
      batchId.value = batches.value[0].id
    }
  } catch {
    batches.value = []
  }
})

function amountOf(r: ReceivableRow): number | null {
  return parseAmount(lines[r.id].amount)
}

function diffOf(r: ReceivableRow): number | null {
  const a = amountOf(r)
  return a === null ? null : Math.round((a - r.expectedAmount) * 100) / 100
}

const hasDiff = (r: ReceivableRow) => Math.abs(diffOf(r) ?? 0) > 0.01

const problems = computed(() => props.rows.filter(r => {
  if (amountOf(r) === null) return true
  if (!hasDiff(r)) return false
  const l = lines[r.id]
  return l.reason === '' || (l.reason === 'other' && l.note.trim() === '')
}))

const total = computed(() => props.rows.reduce((s, r) => s + (amountOf(r) ?? 0), 0))
const selectedBatch = computed(() => batches.value.find(b => b.id === batchId.value) ?? null)

async function submit(): Promise<void> {
  if (problems.value.length) {
    error.value = `ยังมี ${problems.value.length} รายการที่ต้องกรอกยอดหรือเลือกสาเหตุส่วนต่าง`
    return
  }
  busy.value = true
  error.value = null
  rowErrors.value = []
  try {
    let receiptBatchId: number | null = null
    if (batchMode.value === 'existing' && batchId.value) receiptBatchId = Number(batchId.value)
    if (batchMode.value === 'new') {
      const b = await createReceiptBatch({
        insurerId: props.insurerId,
        statementMonth: newMonth.value || undefined,
        statementTotal: parseAmount(newTotal.value) ?? undefined,
        statementDate: receivedDate.value,
      })
      receiptBatchId = Number(b.data.id)
      batches.value = [b.data, ...batches.value]
      batchMode.value = 'existing'
      batchId.value = b.data.id
    }
    await bulkReceive({
      receivedDate: receivedDate.value,
      receiptBatchId,
      items: props.rows.map(r => ({
        id: r.id,
        version: r.version,
        receivedAmount: amountOf(r),
        diffReason: hasDiff(r) ? (lines[r.id].reason || null) : null,
        diffNote: hasDiff(r) ? (lines[r.id].note.trim() || null) : null,
      })),
    })
    emit('done')
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      error.value = e.message
      rowErrors.value = ((e.body as unknown as { errors?: BulkRowError[] })?.errors ?? []).filter(x => typeof x === 'object')
    } else {
      error.value = 'บันทึกไม่สำเร็จ'
    }
  } finally {
    busy.value = false
  }
}

const errorFor = (id: string) => rowErrors.value.find(e => e.id === id)?.message ?? null
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/30 p-4" @click.self="emit('close')">
    <div class="mt-8 w-full max-w-4xl rounded-2xl bg-white shadow-xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
        <div>
          <h2 class="font-semibold text-slate-800">ยืนยันรับเงิน {{ rows.length }} รายการ</h2>
          <p class="text-xs text-slate-500">{{ insurerName }}</p>
        </div>
        <button type="button" class="text-slate-400 hover:text-slate-700" @click="emit('close')"><i class="pi pi-times" /></button>
      </div>

      <div class="space-y-4 p-5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div>
            <label class="mb-1 block text-xs text-slate-500">วันที่รับเงิน *</label>
            <input v-model="receivedDate" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="mb-1 block text-xs text-slate-500">รอบ statement ที่รับ</label>
            <div class="flex flex-wrap gap-3 text-sm">
              <label class="flex items-center gap-1"><input v-model="batchMode" type="radio" value="existing" :disabled="batches.length === 0" /> รอบที่มีอยู่</label>
              <label class="flex items-center gap-1"><input v-model="batchMode" type="radio" value="new" /> สร้างรอบใหม่</label>
              <label class="flex items-center gap-1"><input v-model="batchMode" type="radio" value="none" /> ไม่ระบุ</label>
            </div>
          </div>
        </div>

        <div v-if="batchMode === 'existing'" class="rounded-lg bg-slate-50 p-3 text-sm">
          <select v-model="batchId" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
            <option v-for="b in batches" :key="b.id" :value="b.id">
              {{ b.batchNo }}{{ b.statementMonth ? ' · เดือน ' + b.statementMonth : '' }}{{ b.statementTotal !== null ? ' · ยอด statement ' + money(b.statementTotal) : '' }}
            </option>
          </select>
          <p v-if="selectedBatch" class="mt-1 text-xs text-slate-500">
            รับเข้ารอบนี้แล้ว {{ selectedBatch.receivedCount }} รายการ {{ money(selectedBatch.receivedTotal) }} บาท
            <template v-if="selectedBatch.statementTotal !== null"> · หลังบันทึกชุดนี้จะเป็น {{ money(selectedBatch.receivedTotal + total) }} จากยอด statement {{ money(selectedBatch.statementTotal) }}</template>
          </p>
        </div>
        <div v-else-if="batchMode === 'new'" class="grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-2">
          <div>
            <label class="mb-1 block text-xs text-slate-500">statement เดือน</label>
            <input v-model="newMonth" type="month" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-slate-500">ยอดรวมตาม statement (ไว้เทียบ)</label>
            <input v-model="newTotal" type="text" inputmode="decimal" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-right text-sm" />
          </div>
        </div>

        <div class="max-h-[22rem] overflow-y-auto rounded-xl border border-slate-200">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="sticky top-0 bg-slate-50 text-left text-xs text-slate-500">
              <tr>
                <th class="px-3 py-2">กรมธรรม์</th>
                <th class="px-3 py-2 text-right">ควรได้</th>
                <th class="px-3 py-2 text-right">ยอดรับจริง</th>
                <th class="px-3 py-2 text-right">ส่วนต่าง</th>
                <th class="px-3 py-2">สาเหตุส่วนต่าง</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="r in rows" :key="r.id" class="align-top">
                <td class="px-3 py-2">
                  <div class="text-slate-800">{{ r.policyNo || r.applicationNo }}<span v-if="r.commissionType === 'ADJ'" class="ml-1 text-xs text-violet-600">ปรับปรุง #{{ r.lineNo }}</span></div>
                  <div v-for="h in r.hints" :key="h.code" class="text-[11px] text-slate-400">{{ h.label }}</div>
                  <div v-if="errorFor(r.id)" class="text-xs text-rose-600">{{ errorFor(r.id) }}</div>
                </td>
                <td class="px-3 py-2 text-right text-slate-600">{{ money(r.expectedAmount) }}</td>
                <td class="px-3 py-2 text-right">
                  <input v-model="lines[r.id].amount" type="text" inputmode="decimal" class="w-28 rounded-md border border-slate-200 px-2 py-1 text-right" :class="amountOf(r) === null ? 'border-rose-300' : ''" />
                </td>
                <td class="px-3 py-2 text-right" :class="hasDiff(r) ? 'text-rose-600' : 'text-slate-400'">{{ diffOf(r) !== null ? signedMoney(diffOf(r)) : '—' }}</td>
                <td class="px-3 py-2">
                  <template v-if="hasDiff(r)">
                    <select v-model="lines[r.id].reason" class="w-full rounded-md border px-2 py-1 text-sm" :class="lines[r.id].reason === '' ? 'border-rose-300' : 'border-slate-200'">
                      <option value="">— เลือกสาเหตุ —</option>
                      <option v-for="o in REASON_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <input v-model="lines[r.id].note" type="text" placeholder="รายละเอียด" class="mt-1 w-full rounded-md border px-2 py-1 text-xs" :class="lines[r.id].reason === 'other' && lines[r.id].note.trim() === '' ? 'border-rose-300' : 'border-slate-200'" />
                  </template>
                  <span v-else class="text-xs text-slate-400">ยอดตรง</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex items-center justify-between text-sm">
          <span class="text-slate-500">รวมยอดรับชุดนี้</span>
          <span class="text-lg font-semibold text-slate-800">{{ money(total) }} บาท</span>
        </div>
        <div v-if="error" class="rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700">{{ error }}</div>
      </div>

      <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-3">
        <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="emit('close')">ยกเลิก</button>
        <button type="button" :disabled="busy || rows.length === 0" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" @click="submit">
          {{ busy ? 'กำลังบันทึก…' : 'ยืนยันรับเงิน' }}
        </button>
      </div>
    </div>
  </div>
</template>
