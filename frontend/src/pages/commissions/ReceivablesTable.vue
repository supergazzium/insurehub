<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { ReceivableRow } from '../../api/commissionReceipts'
import { fmtDate } from '../../util/dateFormat'
import { HINT_STYLE, STATUS_BADGE, STATUS_LABEL, money, signedMoney, parseAmount } from './receiptLabels'

const props = defineProps<{
  rows: ReceivableRow[]
  loading: boolean
  canRecord: boolean
  showType?: boolean
  busyIds?: string[]
}>()

const emit = defineEmits<{
  (e: 'open', row: ReceivableRow): void
  (e: 'review', row: ReceivableRow, amount: number): void
}>()

const selected = defineModel<string[]>('selected', { default: () => [] })

const drafts = reactive<Record<string, string>>({})
watch(
  () => props.rows,
  (rows) => {
    for (const r of rows) {
      drafts[r.id] = r.statementAmount !== null ? String(r.statementAmount) : ''
    }
  },
  { immediate: true },
)

const isOpen = (r: ReceivableRow) => ['Pending', 'Matched', 'Mismatch'].includes(r.status)
const selectable = computed(() => props.rows.filter(isOpen))
const allChecked = computed(() => selectable.value.length > 0 && selectable.value.every(r => selected.value.includes(r.id)))

function toggleAll(): void {
  selected.value = allChecked.value ? [] : selectable.value.map(r => r.id)
}

function toggle(id: string): void {
  selected.value = selected.value.includes(id) ? selected.value.filter(x => x !== id) : [...selected.value, id]
}

function commit(r: ReceivableRow): void {
  const amount = parseAmount(drafts[r.id] ?? '')
  if (amount === null) {
    drafts[r.id] = r.statementAmount !== null ? String(r.statementAmount) : ''
    return
  }
  if (r.statementAmount !== null && Math.abs(amount - r.statementAmount) < 0.005) return
  emit('review', r, amount)
}

function matchExpected(r: ReceivableRow): void {
  drafts[r.id] = String(r.expectedAmount)
  emit('review', r, r.expectedAmount)
}

const busy = (r: ReceivableRow) => (props.busyIds ?? []).includes(r.id)

function typeLabel(r: ReceivableRow): string {
  if (r.commissionType === 'ADJ') return `ปรับปรุง #${r.lineNo}`
  return r.commissionType === 'MAIN' ? 'หลัก' : 'OV'
}
</script>

<template>
  <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
      <thead class="bg-slate-50 text-left text-xs text-slate-500">
        <tr>
          <th v-if="canRecord" class="w-10 px-3 py-3">
            <input type="checkbox" :checked="allChecked" :disabled="selectable.length === 0" @change="toggleAll" />
          </th>
          <th class="px-3 py-3">กรมธรรม์</th>
          <th class="px-3 py-3">ลูกค้า</th>
          <th class="px-3 py-3">เริ่มคุ้มครอง</th>
          <th v-if="showType" class="px-3 py-3">ประเภท</th>
          <th class="px-3 py-3 text-right">ควรได้</th>
          <th class="px-3 py-3 text-right">ยอดตามบริษัท</th>
          <th class="px-3 py-3 text-right">ส่วนต่าง</th>
          <th class="px-3 py-3">สถานะ</th>
          <th class="px-3 py-3">ข้อสังเกต</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <tr v-if="loading"><td colspan="10" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
        <tr v-else-if="rows.length === 0"><td colspan="10" class="px-4 py-10 text-center text-slate-400">ไม่มีรายการ</td></tr>
        <template v-else>
        <tr
          v-for="r in rows" :key="r.id"
          :class="['cursor-pointer align-top hover:bg-slate-50', selected.includes(r.id) ? 'bg-sky-50/60' : '']"
          @click="emit('open', r)"
        >
          <td v-if="canRecord" class="px-3 py-3" @click.stop>
            <input v-if="isOpen(r)" type="checkbox" :checked="selected.includes(r.id)" @change="toggle(r.id)" />
          </td>
          <td class="px-3 py-3">
            <div class="font-medium text-slate-800">{{ r.policyNo || r.applicationNo || '—' }}</div>
            <div class="text-xs text-slate-400">{{ r.policyNo ? r.applicationNo : '' }}</div>
            <div class="mt-0.5 flex flex-wrap gap-1">
              <span v-if="r.commissionType === 'ADJ'" class="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] text-violet-700">{{ typeLabel(r) }} · {{ r.adjustmentKindLabel }}</span>
              <span v-if="r.importedFromLegacy" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">ระบบเดิม</span>
            </div>
          </td>
          <td class="px-3 py-3">
            <div class="max-w-[180px] truncate text-slate-700" :title="r.customerName || ''">{{ r.customerName || '—' }}</div>
            <div class="max-w-[180px] truncate text-xs text-slate-400" :title="r.productName || ''">{{ r.productName || '' }}</div>
          </td>
          <td class="px-3 py-3 text-slate-500">{{ r.effectiveDate ? fmtDate(r.effectiveDate) : '—' }}</td>
          <td v-if="showType" class="px-3 py-3 text-xs text-slate-500">{{ typeLabel(r) }}</td>
          <td class="px-3 py-3 text-right font-medium text-slate-800">
            {{ money(r.expectedAmount) }}
            <div v-if="r.rate" class="text-[10px] font-normal text-slate-400">{{ (r.rate * 100).toFixed(2) }}%</div>
          </td>
          <td class="px-3 py-3 text-right" @click.stop>
            <template v-if="canRecord && isOpen(r)">
              <div class="flex items-center justify-end gap-1">
                <input
                  v-model="drafts[r.id]"
                  type="text" inputmode="decimal" placeholder="0.00"
                  :disabled="busy(r)"
                  class="w-28 rounded-md border border-slate-200 px-2 py-1 text-right text-sm focus:border-brand-500 focus:outline-none"
                  @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
                  @blur="commit(r)"
                />
                <button
                  type="button" :disabled="busy(r)" title="ตรงกับยอดที่ควรได้"
                  class="whitespace-nowrap rounded-md border border-emerald-200 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 disabled:opacity-50"
                  @mousedown.prevent
                  @click="matchExpected(r)"
                >ตรงยอด</button>
              </div>
            </template>
            <template v-else>
              <span class="text-slate-600">{{ r.receivedAmount !== null ? money(r.receivedAmount) : r.statementAmount !== null ? money(r.statementAmount) : '—' }}</span>
              <div v-if="r.receivedDate" class="text-[10px] text-slate-400">รับ {{ fmtDate(r.receivedDate) }}<span v-if="r.batchNo"> · {{ r.batchNo }}</span></div>
            </template>
          </td>
          <td class="px-3 py-3 text-right" :class="(r.differenceAmount ?? 0) < 0 ? 'text-rose-600' : (r.differenceAmount ?? 0) > 0 ? 'text-amber-600' : 'text-slate-400'">
            {{ r.differenceAmount !== null && Math.abs(r.differenceAmount) > 0.005 ? signedMoney(r.differenceAmount) : r.differenceAmount !== null ? '0.00' : '—' }}
          </td>
          <td class="px-3 py-3">
            <span :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[r.status]]">{{ STATUS_LABEL[r.status] }}</span>
            <div v-if="r.diffReasonLabel" class="mt-1 text-[11px] text-slate-500">สาเหตุ: {{ r.diffReasonLabel }}</div>
            <div v-if="r.source === 'legacy' && r.status === 'Received'" class="mt-1 text-[11px] text-slate-400">รับในระบบเดิม</div>
          </td>
          <td class="px-3 py-3">
            <div class="flex max-w-[260px] flex-wrap gap-1">
              <span
                v-for="h in r.hints" :key="h.code"
                :class="['rounded px-1.5 py-0.5 text-[10px] ring-1', HINT_STYLE[h.code] ?? 'bg-slate-50 text-slate-600 ring-slate-200']"
              >{{ h.label }}</span>
            </div>
          </td>
        </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
