<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import {
  fetchReceivable, reviewReceivable, confirmReceived, markNoCommission, reopenReceivable, resyncExpected,
  fetchReceivableAudit, type ReceivableDetail, type AuditRow,
} from '../../api/commissionReceipts'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const props = defineProps<{ id: string }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const main = ref<ReceivableDetail | null>(null)
const ov = ref<ReceivableDetail | null>(null)
const audit = ref<AuditRow[]>([])
const ref0 = computed(() => main.value ?? ov.value)
const loading = ref(true)
const error = ref<string | null>(null)

// per-leg edit state
interface LegState { statement: number | null; received: number | null; receivedDate: string; note: string }
const mainEdit = ref<LegState>({ statement: null, received: null, receivedDate: '', note: '' })
const ovEdit = ref<LegState>({ statement: null, received: null, receivedDate: '', note: '' })

const STATUS_LABEL: Record<string, string> = {
  'Pending': 'รอตรวจ', 'Matched': 'ยอดตรง', 'Mismatch': 'ยอดไม่ตรง', 'Received': 'รับแล้ว', 'No Commission': 'ไม่มีค่าคอม',
}

async function load(): Promise<void> {
  loading.value = true; error.value = null
  try {
    const res = await fetchReceivable(props.id)
    // The opened row may be MAIN or OV; put them in the right slots.
    const both = [res.data, res.sibling].filter(Boolean) as ReceivableDetail[]
    main.value = both.find(x => x.commissionType === 'MAIN') ?? null
    ov.value = both.find(x => x.commissionType === 'OV') ?? null
    if (main.value) mainEdit.value = seedEdit(main.value)
    if (ov.value) ovEdit.value = seedEdit(ov.value)
    const a = await fetchReceivableAudit(props.id)
    audit.value = a.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
function seedEdit(d: ReceivableDetail): LegState {
  return {
    statement: d.statementAmount,
    received: d.receivedAmount ?? d.statementAmount,
    receivedDate: d.receivedDate ?? new Date().toISOString().slice(0, 10),
    note: d.note ?? '',
  }
}

const busy = ref(false)
async function act(fn: () => Promise<unknown>): Promise<void> {
  busy.value = true; error.value = null
  try { await fn(); await load(); emit('saved') }
  catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'ทำรายการไม่สำเร็จ' }
  finally { busy.value = false }
}

function legMismatch(d: ReceivableDetail | null): boolean {
  if (!d || d.policyCarrierAmount == null) return false
  return Math.abs(d.policyCarrierAmount - d.expectedAmount) > 0.01
}
function diff(leg: LegState, d: ReceivableDetail | null): number | null {
  if (!d || leg.statement === null) return null
  return Math.round((leg.statement - d.expectedAmount) * 100) / 100
}

onMounted(load)
</script>

<template>
  <div class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="emit('close')">
    <div class="h-full w-full max-w-2xl overflow-y-auto bg-white shadow-xl">
      <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-5 py-3">
        <h2 class="font-semibold text-slate-800">บันทึกรับค่าคอม</h2>
        <button type="button" class="text-slate-400 hover:text-slate-700" @click="emit('close')"><i class="pi pi-times" /></button>
      </div>

      <div v-if="loading" class="p-8 text-center text-slate-400">กำลังโหลด…</div>
      <div v-else class="p-5">
        <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700">{{ error }}</div>

        <!-- Reference — ข้อมูลสำหรับตรวจสอบการรับค่าคอม -->
        <div class="mb-4 rounded-lg bg-slate-50 p-3 text-sm">
          <div class="flex items-center justify-between">
            <div class="font-medium text-slate-800">{{ ref0?.policyNo || ref0?.applicationNo || '—' }}</div>
            <div class="text-xs text-slate-400">{{ ref0?.applicationNo && ref0?.policyNo ? ref0.applicationNo : '' }}</div>
          </div>
          <div class="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-slate-500">
            <div><span class="text-slate-400">ลูกค้า:</span> {{ ref0?.customerName || '—' }}<span v-if="ref0?.customerCode" class="text-slate-400"> ({{ ref0.customerCode }})</span></div>
            <div><span class="text-slate-400">บริษัทประกัน:</span> {{ ref0?.insurerName || '—' }}</div>
            <div><span class="text-slate-400">แบบประกัน:</span> {{ ref0?.productName || '—' }}</div>
            <div><span class="text-slate-400">ปีกรมธรรม์:</span> ปีที่ {{ ref0?.policyYear ?? '—' }}</div>
            <div><span class="text-slate-400">วันเริ่มคุ้มครอง:</span> {{ ref0?.effectiveDate ? fmtDate(ref0.effectiveDate) : '—' }}</div>
            <div><span class="text-slate-400">เบี้ยหลัก:</span> {{ ref0?.mainPremium != null ? '฿' + money(ref0.mainPremium) : '—' }}</div>
            <div class="col-span-2 rounded bg-white px-2 py-1 ring-1 ring-slate-200">
              <span class="text-slate-400">ค่าคอม บ.ประกัน → ฮับ (จากกรมธรรม์):</span>
              <b class="text-slate-700">{{ ref0?.policyCarrierAmount != null ? '฿' + money(ref0.policyCarrierAmount) : '—' }}</b>
              <span v-if="ref0?.policyCarrierRate" class="text-slate-400"> ({{ (ref0.policyCarrierRate * 100).toFixed(2) }}%)</span>
            </div>
            <div class="col-span-2"><span class="text-slate-400">ตัวแทน:</span> {{ ref0?.agentName || '—' }}<span v-if="ref0?.agentCode" class="text-slate-400"> ({{ ref0.agentCode }})</span></div>
          </div>
        </div>

        <!-- MAIN + OV legs -->
        <div v-for="leg in (['MAIN','OV'] as const)" :key="leg" class="mb-5 rounded-xl border border-slate-200 p-4">
          <template v-if="leg === 'MAIN' ? main : ov">
            <div class="mb-3 flex items-center justify-between">
              <h3 class="text-sm font-semibold text-slate-700">{{ leg === 'MAIN' ? 'ค่าคอมหลัก (Main)' : 'ค่าคอม OV' }}</h3>
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ STATUS_LABEL[(leg === 'MAIN' ? main! : ov!).status] }}</span>
            </div>
            <div class="grid grid-cols-3 gap-3 text-sm">
              <div>
                <label class="mb-1 block text-xs text-slate-500">ควรได้รับ</label>
                <div class="rounded-lg bg-slate-50 px-3 py-2 text-slate-700">฿{{ money((leg === 'MAIN' ? main! : ov!).expectedAmount) }}</div>
                <button
                  v-if="leg === 'MAIN' && legMismatch(main)" type="button" :disabled="busy"
                  class="mt-1 text-[11px] font-medium text-amber-700 hover:text-amber-800 disabled:opacity-50"
                  @click="act(() => resyncExpected(main!.id))">
                  <i class="pi pi-sync text-[9px]" /> sync จากกรมธรรม์ (฿{{ money(main!.policyCarrierAmount ?? 0) }})
                </button>
              </div>
              <div>
                <label class="mb-1 block text-xs text-slate-500">บริษัทแจ้ง</label>
                <input v-model.number="(leg === 'MAIN' ? mainEdit : ovEdit).statement" type="number" step="0.01" class="w-full rounded-lg border border-slate-200 px-3 py-2" />
              </div>
              <div>
                <label class="mb-1 block text-xs text-slate-500">ผลต่าง</label>
                <div class="px-3 py-2" :class="(diff(leg === 'MAIN' ? mainEdit : ovEdit, leg === 'MAIN' ? main : ov) ?? 0) !== 0 ? 'text-rose-600' : 'text-slate-400'">
                  {{ diff(leg === 'MAIN' ? mainEdit : ovEdit, leg === 'MAIN' ? main : ov) !== null ? '฿' + money(diff(leg === 'MAIN' ? mainEdit : ovEdit, leg === 'MAIN' ? main : ov)!) : '—' }}
                </div>
              </div>
            </div>
            <div class="mt-3">
              <label class="mb-1 block text-xs text-slate-500">หมายเหตุ (บังคับเมื่อยอดไม่ตรง / ไม่มีค่าคอม / reopen)</label>
              <input v-model="(leg === 'MAIN' ? mainEdit : ovEdit).note" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
            </div>

            <!-- Received date for confirm -->
            <div v-if="!['Received','No Commission'].includes((leg === 'MAIN' ? main! : ov!).status)" class="mt-3">
              <label class="mb-1 block text-xs text-slate-500">วันที่รับเงิน</label>
              <input v-model="(leg === 'MAIN' ? mainEdit : ovEdit).receivedDate" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
            </div>

            <!-- Actions -->
            <div class="mt-4 flex flex-wrap gap-2">
              <template v-if="!['Received','No Commission'].includes((leg === 'MAIN' ? main! : ov!).status)">
                <button type="button" :disabled="busy" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  @click="act(() => { const d = leg==='MAIN'?main!:ov!; const e = leg==='MAIN'?mainEdit:ovEdit; return reviewReceivable(d.id, e.statement ?? 0, e.note || undefined, d.version) })">
                  ตรวจยอด
                </button>
                <button type="button" :disabled="busy" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                  @click="act(() => { const d = leg==='MAIN'?main!:ov!; const e = leg==='MAIN'?mainEdit:ovEdit; return confirmReceived(d.id, e.received ?? e.statement ?? 0, e.receivedDate, d.version) })">
                  ยืนยันรับเงิน
                </button>
                <button type="button" :disabled="busy" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                  @click="act(() => { const d = leg==='MAIN'?main!:ov!; const e = leg==='MAIN'?mainEdit:ovEdit; return markNoCommission(d.id, e.note || 'ไม่มีค่าคอม', d.version) })">
                  ไม่มีค่าคอม
                </button>
              </template>
              <template v-else>
                <button type="button" :disabled="busy" class="rounded-lg border border-amber-300 px-3 py-1.5 text-sm text-amber-700 hover:bg-amber-50 disabled:opacity-50"
                  @click="act(() => { const d = leg==='MAIN'?main!:ov!; const e = leg==='MAIN'?mainEdit:ovEdit; return reopenReceivable(d.id, e.note || 'reopen', d.version) })">
                  Reopen
                </button>
              </template>
            </div>
          </template>
        </div>

        <!-- Audit timeline -->
        <div v-if="audit.length" class="mt-4">
          <h3 class="mb-2 text-sm font-semibold text-slate-700">ประวัติการทำรายการ</h3>
          <ul class="space-y-2 text-xs">
            <li v-for="(a, i) in audit" :key="i" class="flex gap-2">
              <span class="text-slate-400">{{ a.at ? fmtDate(a.at) : '' }}</span>
              <span class="font-medium text-slate-700">{{ a.action.replace('receivable.', '') }}</span>
              <span v-if="a.reason" class="text-slate-500">— {{ a.reason }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
