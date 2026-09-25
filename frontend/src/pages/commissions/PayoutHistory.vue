<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchPayoutHistory, fetchPayoutSummary, fetchPayoutAuditLog, findPayoutByReference,
  generatePayoutPdfs, exportCsvUrl,
  type BatchRow, type BatchStatus, type PayoutSummary, type AuditEntryRow, type ReferenceHit,
} from '../../api/commissionPayouts'
import { ApiError, getToken } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const STATUS_LABEL: Record<BatchStatus, string> = {
  DRAFT: 'ร่าง', GENERATED: 'สร้างเอกสารแล้ว', APPROVED: 'อนุมัติแล้ว', PAID: 'จ่ายแล้ว', CANCELLED: 'ยกเลิก', REVERSED: 'ย้อนกลับ',
}
const STATUS_BADGE: Record<BatchStatus, string> = {
  DRAFT: 'bg-slate-100 text-slate-600',
  GENERATED: 'bg-sky-50 text-sky-700',
  APPROVED: 'bg-violet-50 text-violet-700',
  PAID: 'bg-emerald-50 text-emerald-700',
  CANCELLED: 'bg-rose-50 text-rose-700',
  REVERSED: 'bg-amber-50 text-amber-700',
}
// Human-readable audit action labels.
const ACTION_LABEL: Record<string, string> = {
  create: 'สร้างรอบจ่าย', approve: 'อนุมัติ', unapprove: 'ยกเลิกอนุมัติ',
  mark_paid: 'บันทึกจ่ายทั้งรอบ', mark_agent_paid: 'บันทึกจ่ายรายตัวแทน',
  adjust: 'ปรับปรุงยอด', item_exclude: 'ตัดรายการออก', item_include: 'นำรายการกลับ',
  reverse: 'ทำรายการย้อนกลับ', reverse_agent: 'ย้อนกลับรายตัวแทน',
  reissue: 'ออกใหม่', cancel: 'ยกเลิก',
}

// ── Filters ─────────────────────────────────────────────────────────────
const fromDate = ref('')
const toDate = ref('')
const status = ref<BatchStatus | 'all'>('all')
const search = ref('')
const page = ref(1)
const perPage = 20

const rows = ref<BatchRow[]>([])
const total = ref(0)
const loading = ref(true)
const error = ref<string | null>(null)

const summary = ref<PayoutSummary | null>(null)

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [h, s] = await Promise.all([
      fetchPayoutHistory({
        fromDate: fromDate.value || undefined,
        toDate: toDate.value || undefined,
        status: status.value === 'all' ? undefined : status.value,
        q: search.value.trim() || undefined,
        page: page.value,
        perPage,
      }),
      fetchPayoutSummary(fromDate.value || undefined, toDate.value || undefined),
    ])
    rows.value = h.data
    total.value = h.meta.total
    summary.value = s.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดประวัติไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function applyFilters(): void { page.value = 1; void load() }
function clearFilters(): void {
  fromDate.value = ''; toDate.value = ''; status.value = 'all'; search.value = ''; page.value = 1
  void load()
}
function goPage(p: number): void {
  if (p < 1 || p > totalPages.value) return
  page.value = p
  void load()
}
function openBatch(b: BatchRow): void {
  router.push({ name: 'commission-payout-detail', params: { id: b.id } })
}

// ── Expandable audit timeline ───────────────────────────────────────────
const expanded = ref<string | null>(null)
const auditCache = ref<Record<string, AuditEntryRow[]>>({})
const auditLoading = ref(false)
async function toggleTimeline(b: BatchRow): Promise<void> {
  if (expanded.value === b.id) { expanded.value = null; return }
  expanded.value = b.id
  if (!auditCache.value[b.id]) {
    auditLoading.value = true
    try {
      const res = await fetchPayoutAuditLog(b.id)
      auditCache.value = { ...auditCache.value, [b.id]: res.data }
    } catch { /* best-effort */ }
    finally { auditLoading.value = false }
  }
}

// ── Reference lookup (bank reconciliation) ──────────────────────────────
const refQuery = ref('')
const refHits = ref<ReferenceHit[] | null>(null)
const refLoading = ref(false)
async function lookupReference(): Promise<void> {
  const r = refQuery.value.trim()
  if (!r) { refHits.value = null; return }
  refLoading.value = true
  try {
    const res = await findPayoutByReference(r)
    refHits.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'ค้นหาเลขอ้างอิงไม่สำเร็จ'
  } finally {
    refLoading.value = false
  }
}

// ── Re-download past statements ─────────────────────────────────────────
const busyId = ref<string | null>(null)
async function downloadZip(b: BatchRow): Promise<void> {
  busyId.value = b.id + ':zip'
  error.value = null
  try {
    const blob = await generatePayoutPdfs(b.id)
    triggerDownload(blob, `CommissionPayout-${b.fromDate ?? ''}-${b.toDate ?? ''}.zip`)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'สร้าง PDF ไม่สำเร็จ'
  } finally { busyId.value = null }
}
async function downloadCsv(b: BatchRow): Promise<void> {
  busyId.value = b.id + ':csv'
  error.value = null
  try {
    const res = await fetch(exportCsvUrl(b.id), {
      headers: { Authorization: `Bearer ${getToken() ?? ''}`, Accept: 'text/csv' },
    })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    triggerDownload(await res.blob(), `CommissionPayout-${b.fromDate ?? ''}-${b.toDate ?? ''}.csv`)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'ส่งออก CSV ไม่สำเร็จ'
  } finally { busyId.value = null }
}
function triggerDownload(blob: Blob, name: string): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url; a.download = name
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-slate-800">ประวัติการจ่ายค่าคอม</h1>
      <p class="mt-1 text-sm text-slate-500">ประวัติรอบจ่ายทั้งหมด · ไทม์ไลน์การทำงาน · ค้นหาเลขอ้างอิงโอน · ดาวน์โหลดเอกสารย้อนหลัง</p>
    </header>

    <!-- KPI strip -->
    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">จ่ายแล้ว (รอบ)</div>
        <div class="mt-1 text-xl font-semibold text-emerald-700">{{ summary?.paidBatchCount ?? 0 }}</div>
        <div class="text-xs text-slate-400">฿{{ money(summary?.paidAmount ?? 0) }}</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">รอดำเนินการ</div>
        <div class="mt-1 text-xl font-semibold text-slate-700">{{ summary?.pendingBatchCount ?? 0 }}</div>
        <div class="text-xs text-slate-400">฿{{ money(summary?.pendingAmount ?? 0) }}</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ย้อนกลับ</div>
        <div class="mt-1 text-xl font-semibold text-amber-700">{{ summary?.reversedBatchCount ?? 0 }}</div>
        <div class="text-xs text-slate-400">฿{{ money(summary?.reversedAmount ?? 0) }}</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ตัวแทนที่จ่ายแล้ว</div>
        <div class="mt-1 text-xl font-semibold text-slate-800">{{ summary?.totalAgentsPaid ?? 0 }}</div>
      </div>
    </div>

    <!-- Reference lookup -->
    <section class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
      <label class="mb-1 block text-xs font-medium text-slate-500">ค้นหาด้วยเลขอ้างอิงการโอน (กระทบยอดกับ statement ธนาคาร)</label>
      <div class="flex gap-2">
        <input
          v-model="refQuery" type="text" placeholder="เลขที่โอน / เช็ค เช่น TRX-001"
          class="w-72 rounded-lg border border-slate-200 px-3 py-2 text-sm"
          @keyup.enter="lookupReference"
        />
        <button type="button" :disabled="refLoading" class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50" @click="lookupReference">
          <i :class="['pi mr-1', refLoading ? 'pi-spin pi-spinner' : 'pi-search']" /> ค้นหา
        </button>
        <button v-if="refHits" type="button" class="text-xs text-slate-500 hover:text-slate-700" @click="refHits = null; refQuery = ''">ล้าง</button>
      </div>
      <div v-if="refHits" class="mt-3">
        <div v-if="refHits.length === 0" class="text-sm text-slate-400">ไม่พบเลขอ้างอิงนี้</div>
        <ul v-else class="divide-y divide-slate-100 text-sm">
          <li v-for="(h, i) in refHits" :key="i" class="flex items-center justify-between py-2">
            <span>
              <button type="button" class="font-medium text-brand-600 hover:underline" @click="router.push({ name: 'commission-payout-detail', params: { id: h.batchId } })">
                รอบ #{{ h.batchId }}
              </button>
              <span class="ml-2 text-slate-500">{{ h.periodLabel }}</span>
              <span v-if="h.agentCode" class="ml-2 text-slate-600">· {{ h.agentName }} ({{ h.agentCode }})</span>
              <span class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">{{ h.level === 'agent' ? 'รายตัวแทน' : 'ทั้งรอบ' }}</span>
              <span class="ml-2 text-xs text-slate-400">{{ h.reference }}</span>
            </span>
            <span class="font-medium text-slate-800">฿{{ money(h.amount) }}</span>
          </li>
        </ul>
      </div>
    </section>

    <!-- Filters -->
    <section class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-xs text-slate-500">จาก</label>
          <input v-model="fromDate" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">ถึง</label>
          <input v-model="toDate" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">สถานะ</label>
          <select v-model="status" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
            <option value="all">ทั้งหมด</option>
            <option value="DRAFT">ร่าง</option>
            <option value="GENERATED">สร้างเอกสารแล้ว</option>
            <option value="APPROVED">อนุมัติแล้ว</option>
            <option value="PAID">จ่ายแล้ว</option>
            <option value="REVERSED">ย้อนกลับ</option>
            <option value="CANCELLED">ยกเลิก</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">ค้นหา (งวด / หมายเหตุ / อ้างอิง)</label>
          <input v-model="search" type="text" class="w-56 rounded-lg border border-slate-200 px-3 py-2 text-sm" @keyup.enter="applyFilters" />
        </div>
        <button type="button" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="applyFilters">
          <i class="pi pi-filter mr-1" /> กรอง
        </button>
        <button v-if="fromDate || toDate || status !== 'all' || search" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50" @click="clearFilters">
          ล้างตัวกรอง
        </button>
      </div>
    </section>

    <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <!-- History table -->
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">งวด / รอบ</th>
            <th class="px-4 py-3">สถานะ</th>
            <th class="px-4 py-3 text-right">ตัวแทน</th>
            <th class="px-4 py-3 text-right">ยอดรวม</th>
            <th class="px-4 py-3">วันที่จ่าย</th>
            <th class="px-4 py-3">เอกสาร</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="7" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
          <tr v-else-if="rows.length === 0"><td colspan="7" class="px-4 py-10 text-center text-slate-400">ไม่พบประวัติรอบจ่าย</td></tr>
          <template v-for="b in rows" :key="b.id">
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <button type="button" class="text-left font-medium text-slate-800 hover:text-brand-700" @click="openBatch(b)">
                  {{ b.periodLabel || ('รอบ #' + b.id) }}
                </button>
                <div class="text-xs text-slate-400">{{ fmtDate(b.fromDate) }} – {{ fmtDate(b.toDate) }}</div>
              </td>
              <td class="px-4 py-3"><span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[b.status]]">{{ STATUS_LABEL[b.status] }}</span></td>
              <td class="px-4 py-3 text-right text-slate-600">{{ b.totalAgents }}</td>
              <td class="px-4 py-3 text-right font-medium text-slate-800">฿{{ money(b.totalAmount) }}</td>
              <td class="px-4 py-3 text-slate-600">
                {{ b.paymentDate ? fmtDate(b.paymentDate) : '—' }}
                <div v-if="b.paymentReference" class="text-xs text-slate-400">{{ b.paymentReference }}</div>
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                <button type="button" :disabled="busyId === b.id + ':zip'" class="mr-2 text-xs text-slate-500 hover:text-brand-700 disabled:opacity-50" @click="downloadZip(b)">
                  <i :class="['pi', busyId === b.id + ':zip' ? 'pi-spin pi-spinner' : 'pi-file-pdf']" /> PDF
                </button>
                <button type="button" :disabled="busyId === b.id + ':csv'" class="text-xs text-slate-500 hover:text-brand-700 disabled:opacity-50" @click="downloadCsv(b)">
                  <i :class="['pi', busyId === b.id + ':csv' ? 'pi-spin pi-spinner' : 'pi-file-excel']" /> CSV
                </button>
              </td>
              <td class="px-4 py-3 text-right">
                <button type="button" class="text-xs text-slate-500 hover:text-slate-700" @click="toggleTimeline(b)">
                  <i :class="['pi mr-1', expanded === b.id ? 'pi-chevron-down' : 'pi-history']" /> ไทม์ไลน์
                </button>
              </td>
            </tr>
            <tr v-if="expanded === b.id" class="bg-slate-50/60">
              <td colspan="7" class="px-6 py-3">
                <div v-if="auditLoading && !auditCache[b.id]" class="text-xs text-slate-400">กำลังโหลดไทม์ไลน์…</div>
                <div v-else-if="(auditCache[b.id] ?? []).length === 0" class="text-xs text-slate-400">ไม่มีบันทึกการทำงาน</div>
                <ol v-else class="relative ml-2 space-y-3 border-l border-slate-200 pl-4 text-xs">
                  <li v-for="(e, i) in auditCache[b.id]" :key="i" class="relative">
                    <span class="absolute -left-[21px] top-1 h-2 w-2 rounded-full bg-brand-500" />
                    <span class="font-medium text-slate-700">{{ ACTION_LABEL[e.action] ?? e.action }}</span>
                    <span class="ml-2 text-slate-400">{{ e.at ? fmtDate(e.at) : '' }} {{ e.at ? new Date(e.at).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : '' }}</span>
                    <span class="ml-2 text-slate-500">โดย {{ e.actor }}</span>
                    <span v-if="e.metadata && (e.metadata.reference || e.metadata.reason || e.metadata.agent)" class="ml-2 text-slate-400">
                      <template v-if="e.metadata.agent">· {{ e.metadata.agent }}</template>
                      <template v-if="e.metadata.reference">· อ้างอิง {{ e.metadata.reference }}</template>
                      <template v-if="e.metadata.reason">· {{ e.metadata.reason }}</template>
                    </span>
                  </li>
                </ol>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="!loading && total > perPage" class="mt-4 flex items-center justify-between text-sm text-slate-500">
      <span>ทั้งหมด {{ total }} รอบ · หน้า {{ page }}/{{ totalPages }}</span>
      <div class="flex gap-2">
        <button type="button" :disabled="page <= 1" class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-40" @click="goPage(page - 1)">ก่อนหน้า</button>
        <button type="button" :disabled="page >= totalPages" class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-40" @click="goPage(page + 1)">ถัดไป</button>
      </div>
    </div>
  </div>
</template>
