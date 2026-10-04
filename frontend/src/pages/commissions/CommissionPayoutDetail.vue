<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchPayoutBatch, addPayoutAdjustment, markPayoutBatchPaid, cancelPayoutBatch,
  approvePayoutBatch, unapprovePayoutBatch, generatePayoutPdfs, exportCsvUrl,
  agentStatementPath, certificatePath, bankCsvPath,
  type BatchDetail, type BatchStatus, type BatchAgentRow, type BatchMeta,
} from '../../api/commissionPayouts'
import { ApiError, API_BASE_URL } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'
import { downloadAuthed, openPdf } from '../../util/fileDownload'

const route = useRoute()
const router = useRouter()
const id = String(route.params.id)

const batch = ref<BatchDetail | null>(null)
const meta = ref<BatchMeta>({ canApprove: false, canPay: false })
const loading = ref(true)
const error = ref<string | null>(null)
const busy = ref<string | null>(null)

const money = (n: number | null | undefined) => (n ?? 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const STATUS_LABEL: Record<BatchStatus, string> = {
  DRAFT: 'ร่าง', GENERATED: 'สร้างเอกสารแล้ว', APPROVED: 'อนุมัติแล้ว', PAID: 'จ่ายแล้ว', CANCELLED: 'ยกเลิก',
}
const STATUS_BADGE: Record<BatchStatus, string> = {
  DRAFT: 'bg-slate-100 text-slate-600',
  GENERATED: 'bg-sky-50 text-sky-700',
  APPROVED: 'bg-violet-50 text-violet-700',
  PAID: 'bg-emerald-50 text-emerald-700',
  CANCELLED: 'bg-rose-50 text-rose-700',
}
const VAT_LABEL: Record<string, string> = { '1': 'ไม่มี VAT', '2': 'บวก VAT', '3': 'รวม VAT' }
const LINE_CLS: Record<string, string> = {
  base: 'bg-sky-50 text-sky-700', self_mgmt: 'bg-violet-50 text-violet-700',
  referral: 'bg-amber-50 text-amber-700', override: 'bg-emerald-50 text-emerald-700',
}

const isV2 = computed(() => (batch.value?.calcVersion ?? 1) >= 2)
const isEditable = computed(() => !!batch.value && ['DRAFT', 'GENERATED'].includes(batch.value.status))
const canApprove = computed(() => meta.value.canApprove && !!batch.value && ['DRAFT', 'GENERATED'].includes(batch.value.status))
const isApproved = computed(() => batch.value?.status === 'APPROVED')
const netTotal = computed(() => (batch.value?.agents ?? []).reduce((s, a) => s + (a.tax ? a.tax.net : a.net), 0))
const whtTotal = computed(() => (batch.value?.agents ?? []).reduce((s, a) => s + (a.tax?.wht ?? 0), 0))
const grossTotal = computed(() => (batch.value?.agents ?? []).reduce((s, a) => s + a.commission, 0))

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchPayoutBatch(id)
    batch.value = res.data
    meta.value = res.meta ?? { canApprove: false, canPay: false }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

const expanded = ref<Set<string>>(new Set())
function toggle(key: string): void {
  const s = new Set(expanded.value)
  if (s.has(key)) s.delete(key)
  else s.add(key)
  expanded.value = s
}
const rowKey = (a: BatchAgentRow) => a.agentId ?? a.agentCode ?? 'none'

const adjFor = ref<BatchAgentRow | null>(null)
const adjAmount = ref<number>(0)
const adjReason = ref('')
const adjSaving = ref(false)
const adjError = ref<string | null>(null)
function openAdj(a: BatchAgentRow): void {
  adjFor.value = a; adjAmount.value = 0; adjReason.value = ''; adjError.value = null
}
async function saveAdj(): Promise<void> {
  if (!adjFor.value || !adjReason.value.trim()) { adjError.value = 'กรุณาระบุเหตุผล'; return }
  adjSaving.value = true; adjError.value = null
  try {
    await addPayoutAdjustment(id, { agentId: adjFor.value.agentId, agentCode: adjFor.value.agentCode, amount: adjAmount.value, reason: adjReason.value.trim() })
    adjFor.value = null
    await load()
  } catch (e: unknown) {
    adjError.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
  } finally {
    adjSaving.value = false
  }
}

const showPay = ref(false)
const payDate = ref(new Date().toLocaleDateString('sv-SE'))
const payRef = ref('')
const paySaving = ref(false)
const payError = ref<string | null>(null)
async function confirmPay(): Promise<void> {
  if (!payDate.value) { payError.value = 'กรุณาระบุวันที่จ่าย'; return }
  paySaving.value = true; payError.value = null
  try {
    await markPayoutBatchPaid(id, payDate.value, payRef.value || undefined)
    showPay.value = false
    await load()
  } catch (e: unknown) {
    payError.value = e instanceof ApiError ? e.message : 'ยืนยันการจ่ายไม่สำเร็จ'
    await load()
  } finally {
    paySaving.value = false
  }
}

async function run(key: string, fn: () => Promise<void>, fallback: string): Promise<void> {
  busy.value = key; error.value = null
  try { await fn() } catch (e: unknown) { error.value = e instanceof Error ? e.message : fallback } finally { busy.value = null }
}

const zipAll = () => run('zip', async () => {
  const blob = await generatePayoutPdfs(id)
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `CommissionPayout-${batch.value?.fromDate ?? ''}-${batch.value?.toDate ?? ''}.zip`
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}, 'สร้าง PDF ไม่สำเร็จ')
const statement = (a: BatchAgentRow) => run(`pdf-${rowKey(a)}`, () => openPdf(agentStatementPath(id, a.agentCode ?? '')), 'เปิด PDF ไม่สำเร็จ')
const certificate = (a: BatchAgentRow) => run(`cert-${rowKey(a)}`, () => openPdf(certificatePath(id, a.payeeId ?? '')), 'เปิดหนังสือรับรองไม่สำเร็จ')
const accountingCsv = () => run('csv', () => downloadAuthed(exportCsvUrl(id).replace(`${API_BASE_URL}/`, ''), `CommissionPayout-${id}.csv`), 'ส่งออก CSV ไม่สำเร็จ')
const bankCsv = () => run('bank', () => downloadAuthed(bankCsvPath(id), `BankTransfer-${id}.csv`), 'ส่งออกไฟล์โอนไม่สำเร็จ')

async function doApprove(): Promise<void> {
  error.value = null
  try { await approvePayoutBatch(id); await load() } catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'อนุมัติไม่สำเร็จ' }
}
async function doUnapprove(): Promise<void> {
  error.value = null
  try { await unapprovePayoutBatch(id); await load() } catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'ยกเลิกอนุมัติไม่สำเร็จ' }
}
const confirmCancel = ref(false)
async function doCancel(): Promise<void> {
  confirmCancel.value = false
  try { await cancelPayoutBatch(id); await load() } catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'ยกเลิกไม่สำเร็จ' }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-6">
    <button type="button" class="mb-3 text-sm text-slate-500 hover:text-slate-700" @click="router.push({ name: 'commission-payouts' })">
      <i class="pi pi-arrow-left mr-1" /> กลับ
    </button>

    <div v-if="loading" class="py-10 text-center text-slate-400">กำลังโหลด…</div>
    <div v-else-if="!batch" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <template v-else>
      <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold text-slate-800">รอบจ่ายค่าคอม #{{ batch.id }} · {{ fmtDate(batch.fromDate) }} – {{ fmtDate(batch.toDate) }}</h1>
          <div class="mt-1 flex flex-wrap items-center gap-3 text-sm text-slate-500">
            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[batch.status]]" data-testid="batch-status">{{ STATUS_LABEL[batch.status] }}</span>
            <span>{{ batch.totalAgents }} ผู้รับ · {{ batch.totalItems }} รายการ</span>
            <span v-if="!isV2" class="rounded bg-slate-100 px-1.5 text-[11px] text-slate-500">รอบจ่ายแบบเดิม (ไม่มีหัก ณ ที่จ่าย)</span>
            <span v-if="batch.note">· {{ batch.note }}</span>
          </div>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" :disabled="busy !== null" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="zipAll">
            <i :class="['pi mr-1', busy === 'zip' ? 'pi-spin pi-spinner' : 'pi-file-pdf']" /> PDF ทั้งหมด (ZIP)
          </button>
          <button type="button" :disabled="busy !== null" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="accountingCsv">
            <i :class="['pi mr-1', busy === 'csv' ? 'pi-spin pi-spinner' : 'pi-file-excel']" /> CSV บัญชี
          </button>
          <button v-if="isV2" type="button" data-testid="bank-csv" :disabled="busy !== null" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="bankCsv">
            <i :class="['pi mr-1', busy === 'bank' ? 'pi-spin pi-spinner' : 'pi-building-columns']" /> ไฟล์โอนเงิน (CSV)
          </button>
          <button v-if="canApprove" type="button" data-testid="approve-batch" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700" @click="doApprove">
            <i class="pi pi-verified mr-1" /> อนุมัติ
          </button>
          <button v-if="isApproved && meta.canApprove" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50" @click="doUnapprove">ยกเลิกอนุมัติ</button>
          <button v-if="isApproved && meta.canPay" type="button" data-testid="mark-paid" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700" @click="showPay = true">
            <i class="pi pi-check-circle mr-1" /> ยืนยันการจ่าย
          </button>
          <button v-if="meta.canApprove && batch.status !== 'PAID' && batch.status !== 'CANCELLED'" type="button" class="rounded-lg border border-rose-200 px-4 py-2 text-sm text-rose-600 hover:bg-rose-50" @click="confirmCancel = true">ยกเลิกรอบ</button>
        </div>
      </header>

      <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>
      <div v-if="batch.status === 'PAID'" class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
        จ่ายแล้วเมื่อ {{ fmtDate(batch.paymentDate) }}<span v-if="batch.paymentReference"> · อ้างอิง {{ batch.paymentReference }}</span>
        <span v-if="isV2"> · ออกหนังสือรับรองการหักภาษี ณ ที่จ่าย (50 ทวิ) ให้ผู้รับที่มียอดหักแล้ว</span>
      </div>
      <div v-if="confirmCancel" class="mb-4 flex items-center justify-between rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        <span>ยกเลิกรอบนี้? รายการทั้งหมดจะกลับไปรอจ่าย และยอดเรียกคืนกลับไปรอหักรอบหน้า</span>
        <span class="flex gap-2">
          <button type="button" class="rounded border border-slate-300 bg-white px-3 py-1 text-xs" @click="confirmCancel = false">ไม่</button>
          <button type="button" class="rounded bg-rose-600 px-3 py-1 text-xs text-white" @click="doCancel">ยืนยันยกเลิก</button>
        </span>
      </div>

      <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4"><div class="text-xs text-slate-500">ยอดค่าคอมรวม</div><div class="mt-1 text-xl font-semibold text-slate-800">฿{{ money(grossTotal) }}</div></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><div class="text-xs text-slate-500">หักคืน</div><div class="mt-1 text-xl font-semibold text-rose-600">฿{{ money(batch.adjustments.reduce((s, a) => s + a.amount, 0)) }}</div></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><div class="text-xs text-slate-500">ภาษีหัก ณ ที่จ่าย</div><div class="mt-1 text-xl font-semibold text-slate-800">฿{{ money(whtTotal) }}</div></div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><div class="text-xs text-emerald-700">ยอดโอนสุทธิ</div><div class="mt-1 text-xl font-semibold text-emerald-800">฿{{ money(netTotal) }}</div></div>
      </div>

      <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm" data-testid="payee-table">
          <thead class="bg-slate-50 text-left text-xs text-slate-500">
            <tr>
              <th class="px-3 py-3">ผู้รับเงิน</th>
              <th class="px-3 py-3 text-right">ค่าคอมพื้นฐาน</th>
              <th class="px-3 py-3 text-right">ค่าแนะนำ / สายงาน</th>
              <th class="px-3 py-3 text-right">หัก</th>
              <th class="px-3 py-3 text-right">ก่อน VAT</th>
              <th class="px-3 py-3 text-right">VAT</th>
              <th class="px-3 py-3 text-right">หัก ณ ที่จ่าย</th>
              <th class="px-3 py-3 text-right">โอนสุทธิ</th>
              <th class="px-3 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <template v-for="a in batch.agents" :key="rowKey(a)">
              <tr class="hover:bg-slate-50">
                <td class="px-3 py-3">
                  <button type="button" class="text-left" @click="toggle(rowKey(a))">
                    <span class="font-medium text-slate-800">{{ a.agentName || '—' }}</span>
                    <span class="ml-1 text-xs text-slate-400">{{ a.agentCode }}</span>
                    <i :class="['pi ml-1 text-xs text-slate-400', expanded.has(rowKey(a)) ? 'pi-chevron-down' : 'pi-chevron-right']" />
                  </button>
                  <div class="text-[11px] text-slate-400">
                    {{ a.payeeKind === 'corporate' ? 'นิติบุคคล' : 'บุคคลธรรมดา' }} · {{ a.vatType ? (VAT_LABEL[a.vatType] ?? a.vatType) : '—' }} · {{ a.itemCount }} รายการ
                    <template v-if="a.tax"> · {{ a.tax.whtForm }} {{ a.tax.whtRate }}%</template>
                    <span v-if="a.tax && !a.tax.hasBankAccount" class="text-rose-500"> · ไม่มีเลขบัญชี</span>
                  </div>
                </td>
                <td class="px-3 py-3 text-right text-slate-700">฿{{ money(a.tax ? a.tax.base + a.tax.selfMgmt : a.commission) }}</td>
                <td class="px-3 py-3 text-right text-slate-700">{{ a.tax ? `฿${money(a.tax.referral + a.tax.override)}` : '—' }}</td>
                <td class="px-3 py-3 text-right" :class="a.deduct > 0 ? 'text-rose-600' : 'text-slate-300'">{{ a.deduct > 0 ? '−฿' + money(a.deduct) : '—' }}</td>
                <td class="px-3 py-3 text-right text-slate-600">{{ a.tax ? `฿${money(a.tax.taxable)}` : '—' }}</td>
                <td class="px-3 py-3 text-right text-slate-600">{{ a.tax && a.tax.vat > 0 ? `฿${money(a.tax.vat)}` : '—' }}</td>
                <td class="px-3 py-3 text-right text-rose-600">{{ a.tax ? `−฿${money(a.tax.wht)}` : '—' }}</td>
                <td class="px-3 py-3 text-right font-semibold text-emerald-700">฿{{ money(a.tax ? a.tax.net : a.net) }}</td>
                <td class="whitespace-nowrap px-3 py-3 text-right">
                  <button type="button" :data-testid="`statement-${a.agentCode}`" class="mr-2 text-xs text-slate-500 hover:text-brand-700" @click.stop="statement(a)">
                    <i :class="['pi', busy === `pdf-${rowKey(a)}` ? 'pi-spin pi-spinner' : 'pi-file-pdf']" /> ใบแจ้งจ่าย
                  </button>
                  <button v-if="a.tax?.certificate" type="button" :data-testid="`cert-${a.agentCode}`" class="mr-2 text-xs text-slate-500 hover:text-brand-700" @click.stop="certificate(a)">
                    <i :class="['pi', busy === `cert-${rowKey(a)}` ? 'pi-spin pi-spinner' : 'pi-verified']" /> 50 ทวิ {{ a.tax.certificate }}
                  </button>
                  <button v-if="isEditable && meta.canApprove" type="button" class="text-xs text-brand-600 hover:text-brand-700" @click="openAdj(a)">หักพิเศษ</button>
                </td>
              </tr>
              <tr v-if="expanded.has(rowKey(a))" class="bg-slate-50/50">
                <td colspan="9" class="px-6 py-2">
                  <table class="min-w-full text-xs">
                    <thead class="text-left text-slate-400">
                      <tr><th class="py-1 pr-4">ประเภท</th><th class="py-1 pr-4">เลขกรมธรรม์</th><th class="py-1 pr-4">ใบคำขอ</th><th class="py-1 pr-4">รายละเอียด</th><th class="py-1 pr-4 text-right">ยอด</th><th class="py-1">สถานะ</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="(it, i) in a.items" :key="i" :class="it.status === 'excluded' ? 'text-slate-300 line-through' : ''">
                        <td class="py-1 pr-4"><span :class="['rounded px-1.5 py-0.5', LINE_CLS[it.lineType ?? 'base']]">{{ it.lineLabel || 'ค่าคอมพื้นฐาน' }}</span></td>
                        <td class="py-1 pr-4 text-slate-600">{{ it.policyNo || '—' }}</td>
                        <td class="py-1 pr-4 text-slate-500">{{ it.applicationNo || '—' }}</td>
                        <td class="py-1 pr-4 text-slate-500">
                          {{ it.sourceAgent ? `จาก ${it.sourceAgent}` : (it.ratioLabel || it.source) }}
                          <span v-if="it.override" class="ml-1 rounded bg-orange-50 px-1 text-orange-700">ยกเว้นเงื่อนไข</span>
                        </td>
                        <td class="py-1 pr-4 text-right text-slate-700">฿{{ money(it.amount) }}</td>
                        <td class="py-1 text-slate-400">{{ it.status }}</td>
                      </tr>
                    </tbody>
                  </table>
                </td>
              </tr>
            </template>
            <tr class="bg-slate-50 font-medium">
              <td class="px-3 py-3" colspan="6">รวม</td>
              <td class="px-3 py-3 text-right text-rose-600">−฿{{ money(whtTotal) }}</td>
              <td class="px-3 py-3 text-right text-emerald-800">฿{{ money(netTotal) }}</td>
              <td></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="batch.adjustments.length" class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="mb-2 text-sm font-semibold text-slate-700">รายการหัก</h3>
        <ul class="space-y-1 text-sm">
          <li v-for="adj in batch.adjustments" :key="adj.id" class="flex justify-between gap-3">
            <span class="text-slate-600">{{ adj.agentCode }} — {{ adj.typeLabel || adj.type }}<span v-if="adj.policyNo"> · {{ adj.policyNo }}</span> <span class="text-slate-400">{{ adj.reason }}</span></span>
            <span class="text-rose-600">−฿{{ money(adj.amount) }}</span>
          </li>
        </ul>
      </div>
    </template>

    <div v-if="adjFor" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="adjFor = null">
      <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-1 font-semibold text-slate-800">หักพิเศษ — {{ adjFor.agentName }}</h3>
        <p class="mb-3 text-xs text-slate-500">{{ adjFor.agentCode }} · ค่าคอมในรอบนี้ ฿{{ money(adjFor.commission) }} · หักก่อนคำนวณภาษี</p>
        <label class="mb-1 block text-xs text-slate-500">ยอดหัก (บาท)</label>
        <input v-model.number="adjAmount" type="number" min="0" step="0.01" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <label class="mb-1 block text-xs text-slate-500">เหตุผล *</label>
        <textarea v-model="adjReason" rows="2" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <div v-if="adjError" class="mb-2 text-sm text-rose-600">{{ adjError }}</div>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600" @click="adjFor = null">ยกเลิก</button>
          <button type="button" :disabled="adjSaving" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="saveAdj">บันทึก</button>
        </div>
      </div>
    </div>

    <div v-if="showPay" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="showPay = false">
      <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-1 font-semibold text-slate-800">ยืนยันการจ่ายค่าคอม</h3>
        <p class="mb-3 text-xs text-slate-500">ยอดโอนสุทธิรวม ฿{{ money(netTotal) }} · หัก ณ ที่จ่าย ฿{{ money(whtTotal) }} · {{ batch?.totalItems }} รายการ</p>
        <label class="mb-1 block text-xs text-slate-500">วันที่จ่าย *</label>
        <input v-model="payDate" type="date" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <label class="mb-1 block text-xs text-slate-500">เลขอ้างอิงการโอน (ถ้ามี)</label>
        <input v-model="payRef" type="text" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <div v-if="payError" class="mb-2 text-sm text-rose-600">{{ payError }}</div>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600" @click="showPay = false">ยกเลิก</button>
          <button type="button" data-testid="confirm-mark-paid" :disabled="paySaving" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="confirmPay">
            <i v-if="paySaving" class="pi pi-spin pi-spinner mr-1" /> ยืนยันจ่าย
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
