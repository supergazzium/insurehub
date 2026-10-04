<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  createAdjustment, deleteAdjustment, fetchReceiptBatches, fetchReceivable, fetchReceivableAudit,
  markNoCommission, receiveReceivable, reopenReceivable, resyncExpected, reviewReceivable, suggestAdjustment,
  type AdjustmentKind, type AdjustmentSuggestion, type AuditRow, type DiffReason, type ReceiptBatch, type ReceivableRow,
} from '../../api/commissionReceipts'
import { ApiError } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import { fmtDate, fmtDateTime } from '../../util/dateFormat'
import {
  HINT_STYLE, KIND_OPTIONS, REASON_OPTIONS, STATUS_BADGE, STATUS_LABEL, differs, money, parseAmount, signedMoney, today,
} from './receiptLabels'

const props = defineProps<{ id: string }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>()

const auth = useAuthStore()
const canRecord = computed(() => auth.hasPermission('receipts.record'))
const canReopen = computed(() => auth.hasPermission('receipts.reopen'))

const head = ref<ReceivableRow | null>(null)
const lines = ref<ReceivableRow[]>([])
const auditFor = ref<string>(props.id)
const audit = ref<AuditRow[]>([])
const batches = ref<ReceiptBatch[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const busy = ref(false)

interface Edit {
  statement: string
  amount: string
  date: string
  batchId: string
  reason: DiffReason | ''
  note: string
  panel: '' | 'noComm' | 'reopen' | 'delete'
  panelReason: string
}
const edits = reactive<Record<string, Edit>>({})

function seed(r: ReceivableRow): void {
  edits[r.id] = {
    statement: r.statementAmount !== null ? String(r.statementAmount) : '',
    amount: r.statementAmount !== null ? String(r.statementAmount) : '',
    date: today(),
    batchId: edits[r.id]?.batchId ?? (batches.value[0]?.id ?? ''),
    reason: r.suggestedReason ?? '',
    note: '',
    panel: '',
    panelReason: '',
  }
}

async function load(): Promise<void> {
  error.value = null
  try {
    const res = await fetchReceivable(props.id)
    head.value = res.data
    lines.value = res.rows
    if (batches.value.length === 0 && res.data.insurerId) {
      batches.value = (await fetchReceiptBatches(Number(res.data.insurerId))).data
    }
    for (const r of res.rows) seed(r)
    await loadAudit(auditFor.value)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

async function loadAudit(id: string): Promise<void> {
  auditFor.value = id
  audit.value = (await fetchReceivableAudit(id)).data
}

async function act(fn: () => Promise<unknown>, after?: (res: unknown) => void): Promise<void> {
  busy.value = true
  error.value = null
  notice.value = null
  try {
    const res = await fn()
    after?.(res)
    await load()
    emit('changed')
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'ทำรายการไม่สำเร็จ'
  } finally {
    busy.value = false
  }
}

const isOpen = (r: ReceivableRow) => ['Pending', 'Matched', 'Mismatch'].includes(r.status)

function receiveDiff(r: ReceivableRow): number | null {
  const a = parseAmount(edits[r.id]?.amount ?? '')
  return a === null ? null : Math.round((a - r.expectedAmount) * 100) / 100
}

function review(r: ReceivableRow): void {
  const amount = parseAmount(edits[r.id].statement)
  if (amount === null) { error.value = 'กรุณากรอกยอดตามบริษัท'; return }
  act(() => reviewReceivable(r.id, amount, r.version))
}

function receive(r: ReceivableRow): void {
  const e = edits[r.id]
  const amount = parseAmount(e.amount)
  if (amount === null) { error.value = 'กรุณากรอกยอดที่ได้รับจริง'; return }
  const diff = receiveDiff(r) ?? 0
  act(() => receiveReceivable(r.id, {
    receivedAmount: amount,
    receivedDate: e.date,
    receiptBatchId: e.batchId ? Number(e.batchId) : null,
    diffReason: Math.abs(diff) > 0.01 ? (e.reason || null) : null,
    diffNote: Math.abs(diff) > 0.01 ? (e.note.trim() || null) : null,
    version: r.version,
  }))
}

function confirmPanel(r: ReceivableRow): void {
  const e = edits[r.id]
  if (e.panel === 'delete') {
    act(() => deleteAdjustment(r.id))
    return
  }
  if (e.panelReason.trim() === '') { error.value = 'กรุณาระบุเหตุผล'; return }
  if (e.panel === 'noComm') act(() => markNoCommission(r.id, e.panelReason.trim(), r.version))
  if (e.panel === 'reopen') {
    act(() => reopenReceivable(r.id, e.panelReason.trim(), r.version), (res) => {
      const w = (res as { meta?: { warning?: string | null } }).meta?.warning
      if (w) notice.value = w
    })
  }
}

function lineTitle(r: ReceivableRow): string {
  if (r.commissionType === 'ADJ') return `รายการปรับปรุง #${r.lineNo} · ${r.adjustmentKindLabel ?? ''}`
  return r.commissionType === 'MAIN' ? 'ค่าคอมหลัก' : 'ค่าคอม OV'
}

const adj = reactive({ open: false, kind: 'endorsement' as AdjustmentKind, amount: '', eventId: '', note: '' })
const suggestion = ref<AdjustmentSuggestion | null>(null)

async function loadSuggestion(): Promise<void> {
  if (!head.value) return
  suggestion.value = null
  try {
    suggestion.value = (await suggestAdjustment(head.value.policyId, adj.kind)).data
    const firstOpen = suggestion.value.events.find(ev => !ev.linked)
    adj.eventId = adj.kind === 'endorsement' && firstOpen ? firstOpen.id : ''
    adj.amount = suggestion.value.amount !== null ? String(suggestion.value.amount) : ''
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดยอดแนะนำไม่สำเร็จ'
  }
}

function pickEvent(id: string): void {
  adj.eventId = id
  const ev = suggestion.value?.events.find(x => x.id === id)
  if (ev && ev.estimatedCommission !== null) adj.amount = String(ev.estimatedCommission)
}

function openAdjustment(): void {
  adj.open = true
  adj.kind = head.value?.policyStatus === 'cancelled' ? 'cancellation' : 'endorsement'
  adj.note = ''
  loadSuggestion()
}

function saveAdjustment(): void {
  if (!head.value) return
  const amount = parseAmount(adj.amount)
  if (amount === null) { error.value = 'กรุณากรอกยอดรายการปรับปรุง'; return }
  act(() => createAdjustment({
    policyId: head.value!.policyId,
    kind: adj.kind,
    expectedAmount: amount,
    policyEventId: adj.kind === 'endorsement' && adj.eventId ? adj.eventId : null,
    note: adj.note.trim() || null,
  }), () => { adj.open = false })
}

const ACTION_LABEL: Record<string, string> = {
  'receivable.match': 'คีย์ยอด — ตรง',
  'receivable.mismatch': 'คีย์ยอด — ไม่ตรง',
  'receivable.receive': 'ยืนยันรับเงิน',
  'receivable.no_commission': 'ตั้งเป็นไม่มีค่าคอม',
  'receivable.reopen': 'เปิดแก้',
  'receivable.resync': 'ดึงยอดจากกรมธรรม์',
  'receivable.adjustment_created': 'สร้างรายการปรับปรุง',
  'receivable.adjustment_deleted': 'ลบรายการปรับปรุง',
  'receivable.legacy_received': 'รับแล้วในระบบเดิม',
}

function changeText(a: AuditRow): string {
  const n = a.new ?? {}
  const parts: string[] = []
  if ('statement_amount' in n && n.statement_amount !== null) parts.push(`ยอดตามบริษัท ${money(Number(n.statement_amount))}`)
  if ('received_amount' in n && n.received_amount !== null) parts.push(`รับ ${money(Number(n.received_amount))}`)
  if ('expected' in n) parts.push(`ควรได้ ${money(Number((a.old ?? {}).expected ?? 0))} → ${money(Number(n.expected))}`)
  return parts.join(' · ')
}

onMounted(load)
</script>

<template>
  <div class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="emit('close')">
    <div class="h-full w-full max-w-2xl overflow-y-auto bg-white shadow-xl">
      <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-5 py-3">
        <h2 class="font-semibold text-slate-800">รายละเอียดการรับค่าคอม</h2>
        <button type="button" class="text-slate-400 hover:text-slate-700" @click="emit('close')"><i class="pi pi-times" /></button>
      </div>

      <div v-if="loading" class="p-8 text-center text-slate-400">กำลังโหลด…</div>
      <div v-else-if="head" class="space-y-4 p-5">
        <div v-if="error" class="rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700">{{ error }}</div>
        <div v-if="notice" class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800"><i class="pi pi-exclamation-triangle mr-1" />{{ notice }}</div>

        <div class="rounded-lg bg-slate-50 p-3 text-sm">
          <div class="flex items-center justify-between">
            <div class="font-medium text-slate-800">{{ head.policyNo || head.applicationNo }}</div>
            <div class="flex gap-1">
              <span v-if="head.importedFromLegacy" class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] text-slate-600">ระบบเดิม</span>
              <span v-if="head.policyStatus === 'cancelled'" class="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] text-rose-700">ยกเลิก{{ head.cancelDate ? ' ' + fmtDate(head.cancelDate) : '' }}</span>
            </div>
          </div>
          <div class="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-slate-500">
            <div>ลูกค้า: {{ head.customerName || '—' }}</div>
            <div>บริษัทประกัน: {{ head.insurerName || '—' }}</div>
            <div>แบบประกัน: {{ head.productName || '—' }}</div>
            <div>ตัวแทน: {{ head.agentName || '—' }}<span v-if="head.agentCode"> ({{ head.agentCode }})</span></div>
            <div>เริ่มคุ้มครอง: {{ head.effectiveDate ? fmtDate(head.effectiveDate) : '—' }} · ปีที่ {{ head.policyYear ?? '—' }}</div>
            <div>เบี้ยสุทธิ: {{ money(head.netPremium) }}</div>
            <div class="col-span-2">ค่าคอมจากบริษัทในกรมธรรม์: <b class="text-slate-700">{{ money(head.policyCarrierAmount) }}</b><span v-if="head.rate"> ({{ (head.rate * 100).toFixed(2) }}%)</span></div>
          </div>
        </div>

        <div v-for="r in lines" :key="r.id" :class="['rounded-xl border p-4', r.id === auditFor ? 'border-brand-300' : 'border-slate-200']">
          <div class="mb-2 flex items-center justify-between">
            <button type="button" class="text-sm font-semibold text-slate-700 hover:text-brand-700" @click="loadAudit(r.id)">{{ lineTitle(r) }}</button>
            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[r.status]]">{{ STATUS_LABEL[r.status] }}</span>
          </div>
          <div v-if="r.hints.length" class="mb-2 flex flex-wrap gap-1">
            <span v-for="h in r.hints" :key="h.code" :class="['rounded px-1.5 py-0.5 text-[10px] ring-1', HINT_STYLE[h.code] ?? 'bg-slate-50 text-slate-600 ring-slate-200']">{{ h.label }}</span>
          </div>
          <div v-if="r.note" class="mb-2 text-xs text-slate-500">หมายเหตุ: {{ r.note }}</div>

          <div class="grid grid-cols-3 gap-3 text-sm">
            <div>
              <div class="text-xs text-slate-500">ควรได้</div>
              <div class="font-medium text-slate-800">{{ money(r.expectedAmount) }}</div>
              <button
                v-if="canRecord && isOpen(r) && r.commissionType === 'MAIN' && differs(r.policyCarrierAmount, r.expectedAmount)"
                type="button" :disabled="busy" class="mt-1 text-[11px] text-amber-700 hover:text-amber-800"
                @click="act(() => resyncExpected(r.id))"
              ><i class="pi pi-sync text-[9px]" /> ดึงยอดจากกรมธรรม์ ({{ money(r.policyCarrierAmount) }})</button>
            </div>
            <div>
              <div class="text-xs text-slate-500">ยอดตามบริษัท</div>
              <div class="text-slate-700">{{ money(r.statementAmount) }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500">ส่วนต่าง</div>
              <div :class="(r.differenceAmount ?? 0) !== 0 ? 'text-rose-600' : 'text-slate-400'">{{ signedMoney(r.differenceAmount) }}</div>
            </div>
          </div>

          <div v-if="r.status === 'Received'" class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
            รับ {{ money(r.receivedAmount) }} บาท
            <span v-if="r.receivedDate">เมื่อ {{ fmtDate(r.receivedDate) }}</span>
            <span v-if="r.batchNo"> · รอบ {{ r.batchNo }}</span>
            <span v-if="r.receivedByName"> · โดย {{ r.receivedByName }}</span>
            <span v-if="r.source === 'legacy'"> · บันทึกจากระบบเดิม</span>
            <div v-if="r.diffReasonLabel" class="mt-0.5">สาเหตุส่วนต่าง: {{ r.diffReasonLabel }}<span v-if="r.diffNote"> — {{ r.diffNote }}</span></div>
          </div>

          <template v-if="canRecord && isOpen(r)">
            <div class="mt-3 flex items-end gap-2">
              <div class="flex-1">
                <label class="mb-1 block text-xs text-slate-500">คีย์ยอดตามบริษัท</label>
                <input v-model="edits[r.id].statement" type="text" inputmode="decimal" class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-right text-sm" @keydown.enter.prevent="review(r)" />
              </div>
              <button type="button" :disabled="busy" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="review(r)">ตรวจยอด</button>
            </div>

            <div class="mt-3 rounded-lg border border-emerald-100 bg-emerald-50/40 p-3">
              <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <div>
                  <label class="mb-1 block text-xs text-slate-500">ยอดที่ได้รับจริง *</label>
                  <input v-model="edits[r.id].amount" type="text" inputmode="decimal" class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-right text-sm" />
                </div>
                <div>
                  <label class="mb-1 block text-xs text-slate-500">วันที่รับเงิน</label>
                  <input v-model="edits[r.id].date" type="date" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" />
                </div>
                <div class="col-span-2 sm:col-span-1">
                  <label class="mb-1 block text-xs text-slate-500">รอบ statement</label>
                  <select v-model="edits[r.id].batchId" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm">
                    <option value="">— ไม่ระบุ —</option>
                    <option v-for="b in batches" :key="b.id" :value="b.id">{{ b.batchNo }}{{ b.statementMonth ? ' · ' + b.statementMonth : '' }}</option>
                  </select>
                </div>
              </div>
              <div v-if="Math.abs(receiveDiff(r) ?? 0) > 0.01" class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <div>
                  <label class="mb-1 block text-xs text-rose-600">ต่างจากยอดที่ควรได้ {{ signedMoney(receiveDiff(r)) }} — สาเหตุ *</label>
                  <select v-model="edits[r.id].reason" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm">
                    <option value="">— เลือกสาเหตุ —</option>
                    <option v-for="o in REASON_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                  </select>
                </div>
                <div>
                  <label class="mb-1 block text-xs text-slate-500">รายละเอียด{{ edits[r.id].reason === 'other' ? ' *' : '' }}</label>
                  <input v-model="edits[r.id].note" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-sm" />
                </div>
              </div>
              <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" :disabled="busy" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" @click="receive(r)">ยืนยันรับเงิน</button>
                <button v-if="r.commissionType !== 'ADJ'" type="button" :disabled="busy" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-white disabled:opacity-50" @click="edits[r.id].panel = 'noComm'">ไม่มีค่าคอม</button>
                <button v-else type="button" :disabled="busy" class="rounded-lg border border-rose-200 px-3 py-1.5 text-sm text-rose-600 hover:bg-white disabled:opacity-50" @click="edits[r.id].panel = 'delete'">ลบรายการปรับปรุง</button>
              </div>
            </div>
          </template>

          <div v-if="canReopen && !isOpen(r)" class="mt-3">
            <button type="button" :disabled="busy" class="rounded-lg border border-amber-300 px-3 py-1.5 text-sm text-amber-700 hover:bg-amber-50 disabled:opacity-50" @click="edits[r.id].panel = 'reopen'">เปิดแก้</button>
          </div>

          <div v-if="edits[r.id]?.panel" class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
            <template v-if="edits[r.id].panel === 'delete'">
              <p class="text-sm text-slate-700">ลบรายการปรับปรุงนี้? (บันทึกไว้ในประวัติ)</p>
            </template>
            <template v-else>
              <label class="mb-1 block text-xs text-slate-500">{{ edits[r.id].panel === 'reopen' ? 'เหตุผลที่เปิดแก้ *' : 'เหตุผลที่ไม่มีค่าคอม *' }}</label>
              <input v-model="edits[r.id].panelReason" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-sm" />
            </template>
            <div class="mt-2 flex gap-2">
              <button type="button" :disabled="busy" class="rounded-lg bg-slate-800 px-3 py-1.5 text-sm text-white disabled:opacity-50" @click="confirmPanel(r)">ยืนยัน</button>
              <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600" @click="edits[r.id].panel = ''; edits[r.id].panelReason = ''">ยกเลิก</button>
            </div>
          </div>
        </div>

        <div v-if="canRecord" class="rounded-xl border border-dashed border-violet-300 p-4">
          <button v-if="!adj.open" type="button" class="text-sm font-medium text-violet-700 hover:text-violet-800" @click="openAdjustment">
            <i class="pi pi-plus text-xs" /> เพิ่มรายการปรับปรุง (บริษัทจ่ายเพิ่ม / หักคืน)
          </button>
          <template v-else>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <div>
                <label class="mb-1 block text-xs text-slate-500">ประเภท</label>
                <select v-model="adj.kind" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" @change="loadSuggestion">
                  <option v-for="k in KIND_OPTIONS" :key="k.value" :value="k.value">{{ k.label }}</option>
                </select>
              </div>
              <div>
                <label class="mb-1 block text-xs text-slate-500">ยอดที่ควรได้ (ติดลบ = บริษัทหักคืน)</label>
                <input v-model="adj.amount" type="text" inputmode="decimal" class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-right text-sm" />
              </div>
            </div>
            <p v-if="suggestion" class="mt-1 text-xs text-slate-500">ยอดแนะนำ: {{ suggestion.basis }}</p>
            <div v-if="adj.kind === 'endorsement' && suggestion?.events.length" class="mt-2">
              <label class="mb-1 block text-xs text-slate-500">สลักหลังที่เกี่ยวข้อง</label>
              <select :value="adj.eventId" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" @change="pickEvent(($event.target as HTMLSelectElement).value)">
                <option value="">— ไม่ผูกกับสลักหลัง —</option>
                <option v-for="ev in suggestion.events" :key="ev.id" :value="ev.id" :disabled="ev.linked">
                  ${{ ev.seq }} · เบี้ย {{ money(ev.additionalPremium) }}{{ ev.estimatedCommission !== null ? ' · ค่าคอมประมาณ ' + money(ev.estimatedCommission) : '' }}{{ ev.linked ? ' (บันทึกแล้ว)' : '' }}
                </option>
              </select>
            </div>
            <div class="mt-2">
              <label class="mb-1 block text-xs text-slate-500">รายละเอียด{{ adj.kind === 'other' ? ' *' : '' }}</label>
              <input v-model="adj.note" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-sm" />
            </div>
            <div class="mt-3 flex gap-2">
              <button type="button" :disabled="busy" class="rounded-lg bg-violet-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-violet-700 disabled:opacity-50" @click="saveAdjustment">บันทึกรายการปรับปรุง</button>
              <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600" @click="adj.open = false">ยกเลิก</button>
            </div>
          </template>
        </div>

        <div>
          <h3 class="mb-2 text-sm font-semibold text-slate-700">ประวัติการทำรายการ · {{ lineTitle(lines.find(l => l.id === auditFor) ?? head) }}</h3>
          <div v-if="audit.length === 0" class="text-xs text-slate-400">ยังไม่มีประวัติ</div>
          <ul class="space-y-2 text-xs">
            <li v-for="(a, i) in audit" :key="i" class="rounded-lg bg-slate-50 px-3 py-2">
              <div class="flex flex-wrap justify-between gap-2">
                <span class="font-medium text-slate-700">{{ ACTION_LABEL[a.action] ?? a.action }}</span>
                <span class="text-slate-400">{{ a.at ? fmtDateTime(a.at) : '' }} · {{ a.actor }}</span>
              </div>
              <div v-if="changeText(a)" class="text-slate-500">{{ changeText(a) }}</div>
              <div v-if="a.reason" class="text-slate-500">เหตุผล: {{ a.reason }}</div>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
