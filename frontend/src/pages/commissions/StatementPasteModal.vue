<script setup lang="ts">
import { computed, ref } from 'vue'
import { bulkReview, statementMatch, type StatementLine, type StatementMatchResult, type BulkRowError } from '../../api/commissionReceipts'
import { ApiError } from '../../api/client'
import { money, signedMoney, parseAmount, STATUS_LABEL } from './receiptLabels'

const props = defineProps<{ insurerId: number; insurerName: string; type: 'MAIN' | 'OV' }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'applied', ids: string[]): void }>()

const text = ref('')
const parsed = ref<StatementLine[]>([])
const skipped = ref(0)
const result = ref<StatementMatchResult | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
const rowErrors = ref<BulkRowError[]>([])

function parse(raw: string): { lines: StatementLine[]; skipped: number } {
  const lines: StatementLine[] = []
  let skip = 0
  for (const line of raw.split(/\r?\n/)) {
    if (line.trim() === '') continue
    let cells = (line.includes('\t') ? line.split('\t') : line.split(/\s{2,}|;|,(?=\s*[^\d\s])/)).map(c => c.trim()).filter(c => c !== '')
    if (cells.length === 1) {
      const parts = cells[0].split(/\s+/)
      cells = parts.length > 1 ? [parts.slice(0, -1).join(' '), parts[parts.length - 1]] : parts
    }
    const ref = cells[0] ?? ''
    let amount: number | null = null
    for (let i = cells.length - 1; i >= 1; i--) {
      amount = parseAmount(cells[i])
      if (amount !== null) break
    }
    if (!/\d/.test(ref) || (amount === null && cells.length > 1)) {
      skip++
      continue
    }
    lines.push({ ref, amount })
  }
  return { lines, skipped: skip }
}

async function check(): Promise<void> {
  error.value = null
  rowErrors.value = []
  const p = parse(text.value)
  parsed.value = p.lines
  skipped.value = p.skipped
  if (p.lines.length === 0) {
    error.value = 'ไม่พบบรรทัดที่มีเลขกรมธรรม์และยอดเงิน'
    result.value = null
    return
  }
  busy.value = true
  try {
    result.value = (await statementMatch(props.insurerId, p.lines, props.type)).data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'จับคู่ไม่สำเร็จ'
  } finally {
    busy.value = false
  }
}

const applicable = computed(() => (result.value?.matched ?? []).filter(m => m.amount !== null))

async function apply(): Promise<void> {
  if (applicable.value.length === 0) return
  busy.value = true
  error.value = null
  rowErrors.value = []
  try {
    await bulkReview(applicable.value.map(m => ({ id: m.receivableId, statementAmount: m.amount as number, version: m.version })))
    emit('applied', applicable.value.map(m => m.receivableId))
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

function errorFor(id: string): string | null {
  return rowErrors.value.find(e => e.id === id)?.message ?? null
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/30 p-4" @click.self="emit('close')">
    <div class="mt-8 w-full max-w-4xl rounded-2xl bg-white shadow-xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
        <div>
          <h2 class="font-semibold text-slate-800">วางยอดจาก statement</h2>
          <p class="text-xs text-slate-500">{{ insurerName }} · {{ type === 'MAIN' ? 'ค่าคอมหลัก' : 'ค่าคอม OV' }}</p>
        </div>
        <button type="button" class="text-slate-400 hover:text-slate-700" @click="emit('close')"><i class="pi pi-times" /></button>
      </div>

      <div class="space-y-4 p-5">
        <div>
          <label class="mb-1 block text-xs text-slate-500">คัดลอก 2 คอลัมน์จาก Excel (เลขกรมธรรม์หรือเลขใบคำขอ และยอดค่าคอม) แล้ววางที่นี่ ทีละบรรทัด</label>
          <textarea
            v-model="text" rows="7" spellcheck="false"
            placeholder="POL-0001&#9;1,800.00&#10;POL-0002&#9;2,400.00"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-sm focus:border-brand-500 focus:outline-none"
          />
          <div class="mt-2 flex items-center gap-3">
            <button type="button" :disabled="busy || text.trim() === ''" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50" @click="check">
              {{ busy && !result ? 'กำลังจับคู่…' : 'ตรวจจับคู่' }}
            </button>
            <span v-if="parsed.length" class="text-xs text-slate-500">อ่านได้ {{ parsed.length }} บรรทัด<span v-if="skipped"> · ข้าม {{ skipped }} บรรทัด (หัวตารางหรือไม่มียอด)</span></span>
          </div>
        </div>

        <div v-if="error" class="rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700">{{ error }}</div>

        <template v-if="result">
          <div class="grid grid-cols-2 gap-2 text-center text-xs sm:grid-cols-5">
            <div class="rounded-lg bg-emerald-50 p-2 text-emerald-700"><div class="text-lg font-semibold">{{ result.matched.length }}</div>จับคู่ได้</div>
            <div class="rounded-lg bg-rose-50 p-2 text-rose-700"><div class="text-lg font-semibold">{{ result.notFound.length }}</div>ไม่พบในระบบ</div>
            <div class="rounded-lg bg-amber-50 p-2 text-amber-700"><div class="text-lg font-semibold">{{ result.otherInsurer.length }}</div>เป็นของบริษัทอื่น</div>
            <div class="rounded-lg bg-slate-100 p-2 text-slate-600"><div class="text-lg font-semibold">{{ result.alreadyClosed.length }}</div>รับไปแล้ว</div>
            <div class="rounded-lg bg-slate-100 p-2 text-slate-600"><div class="text-lg font-semibold">{{ result.duplicates.length }}</div>ซ้ำในรายการที่วาง</div>
          </div>

          <div v-if="result.matched.length" class="max-h-72 overflow-y-auto rounded-xl border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
              <thead class="sticky top-0 bg-slate-50 text-left text-xs text-slate-500">
                <tr>
                  <th class="px-3 py-2">บรรทัด</th><th class="px-3 py-2">กรมธรรม์</th>
                  <th class="px-3 py-2 text-right">ควรได้</th><th class="px-3 py-2 text-right">ยอดตามบริษัท</th>
                  <th class="px-3 py-2 text-right">ส่วนต่าง</th><th class="px-3 py-2">สถานะเดิม</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="m in result.matched" :key="m.receivableId">
                  <td class="px-3 py-1.5 text-xs text-slate-400">{{ m.line }}</td>
                  <td class="px-3 py-1.5">
                    <div class="text-slate-800">{{ m.policyNo }}</div>
                    <div v-if="errorFor(m.receivableId)" class="text-xs text-rose-600">{{ errorFor(m.receivableId) }}</div>
                  </td>
                  <td class="px-3 py-1.5 text-right text-slate-600">{{ money(m.expectedAmount) }}</td>
                  <td class="px-3 py-1.5 text-right text-slate-800">{{ m.amount !== null ? money(m.amount) : '—' }}</td>
                  <td class="px-3 py-1.5 text-right" :class="(m.difference ?? 0) < -0.005 ? 'text-rose-600' : (m.difference ?? 0) > 0.005 ? 'text-amber-600' : 'text-slate-400'">
                    {{ m.difference !== null ? signedMoney(m.difference) : '—' }}
                  </td>
                  <td class="px-3 py-1.5 text-xs text-slate-500">{{ STATUS_LABEL[m.status] }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="result.notFound.length || result.otherInsurer.length || result.alreadyClosed.length || result.duplicates.length" class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
            <div v-if="result.notFound.length" class="rounded-lg border border-rose-200 p-3">
              <div class="mb-1 font-medium text-rose-700">ไม่พบในระบบ — เก็บเป็นเคสตรวจสอบ</div>
              <div v-for="n in result.notFound" :key="'n' + n.line" class="text-slate-600">บรรทัด {{ n.line }}: {{ n.ref }} ({{ n.amount !== null ? money(n.amount) : '—' }})</div>
            </div>
            <div v-if="result.otherInsurer.length" class="rounded-lg border border-amber-200 p-3">
              <div class="mb-1 font-medium text-amber-700">กรมธรรม์ของบริษัทอื่น</div>
              <div v-for="o in result.otherInsurer" :key="'o' + o.line" class="text-slate-600">บรรทัด {{ o.line }}: {{ o.policyNo }} — {{ o.insurerName }}</div>
            </div>
            <div v-if="result.alreadyClosed.length" class="rounded-lg border border-slate-200 p-3">
              <div class="mb-1 font-medium text-slate-700">รับไปแล้ว — ถ้าเป็นยอดเพิ่ม/หักคืน ให้ทำรายการปรับปรุงที่กรมธรรม์นั้น</div>
              <div v-for="c in result.alreadyClosed" :key="'c' + c.line" class="text-slate-600">บรรทัด {{ c.line }}: {{ c.policyNo }} ({{ c.amount !== null ? money(c.amount) : '—' }})</div>
            </div>
            <div v-if="result.duplicates.length" class="rounded-lg border border-slate-200 p-3">
              <div class="mb-1 font-medium text-slate-700">ซ้ำในรายการที่วาง (ใช้บรรทัดแรก)</div>
              <div v-for="d in result.duplicates" :key="'d' + d.line" class="text-slate-600">บรรทัด {{ d.line }}: {{ d.ref }}</div>
            </div>
          </div>
        </template>
      </div>

      <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-3">
        <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="emit('close')">ปิด</button>
        <button
          type="button" :disabled="busy || applicable.length === 0"
          class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
          @click="apply"
        >บันทึกยอดตามบริษัท ({{ applicable.length }} รายการ)</button>
      </div>
    </div>
  </div>
</template>
