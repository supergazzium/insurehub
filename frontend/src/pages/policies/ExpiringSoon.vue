<script setup lang="ts">
import { onMounted, reactive, ref, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchExpiringSoon, markRenewalContacted, markRenewalStarted, sendRenewalNotice,
  type ExpiringPolicy, type ExpiringSoonMeta,
} from '../../api/reports'
import { ApiError } from '../../api/client'
import { toCsv, downloadCsv } from '../../util/csvExport'

const router = useRouter()

const days = ref<30 | 60 | 90 | 180>(60)
const rows = ref<ExpiringPolicy[]>([])
const meta = ref<ExpiringSoonMeta | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

// ── Filters (client-side over the loaded window) ────────────────────
// `days` still drives what the backend returns; the filters below narrow
// that set in the browser. Users can also pin a specific date range with
// the calendar inputs — when set, `days` is essentially widened to 365 so
// the range isn't accidentally clipped by the window.
const filters = reactive({
  q: '',
  fromDate: '',
  toDate: '',
  carrierId: '',
  productId: '',
  productType: '',   // "life" (Life-Main), "Motor", "PA", etc.
  insureType: '' as '' | 'life' | 'non-life' | 'tax',
})

// If either date is set, force the fetch to cover the full year so we
// have everything available to filter on the client.
watch(
  () => [filters.fromDate, filters.toDate] as const,
  ([from, to]) => {
    if ((from || to) && days.value !== 180) days.value = 180
  },
)

// Detect intent for the "ค้นหา" chip (name / phone / plate / policy no).
type SearchIntent = 'auto' | 'name' | 'phone' | 'plate' | 'policyNo'
const detectedIntent = computed<SearchIntent>(() => {
  const raw = filters.q.trim()
  if (raw === '') return 'auto'
  const digits = raw.replace(/\D/g, '')
  if (/^0[689]\d{7,8}$/.test(digits)) return 'phone'
  if (/^(A|APP|POL|POLICY|Q|QUOTE)[A-Z0-9-]{3,}$/i.test(raw)) return 'policyNo'
  if (/[฀-๿]/.test(raw) && /\d/.test(raw)) return 'plate'
  return 'name'
})
const intentLabel: Record<SearchIntent, string> = {
  auto: '', name: 'ชื่อ-สกุล', phone: 'เบอร์โทร', plate: 'ทะเบียนรถ', policyNo: 'เลขกรมธรรม์',
}

// Filtered rows — the client-side pipeline. Runs after fetch.
const filteredRows = computed<ExpiringPolicy[]>(() => {
  const q = filters.q.trim().toLowerCase()
  const qDigits = q.replace(/\D/g, '')
  return rows.value.filter((r) => {
    // Date range on expiry_date (both bounds inclusive).
    if (filters.fromDate && r.expiryDate < filters.fromDate) return false
    if (filters.toDate && r.expiryDate > filters.toDate) return false
    if (filters.carrierId && r.carrierId !== filters.carrierId) return false
    if (filters.productId && r.productId !== filters.productId) return false
    if (filters.productType && r.productType !== filters.productType) return false
    if (filters.insureType && r.carrierInsureType !== filters.insureType) return false
    if (q === '') return true
    // Free-text search across name / phone / plate / policy no / app no / customer code
    const hay = [
      r.customerName, r.policyNo, r.applicationNo,
      r.motorLicenseNo, r.customerCode,
    ].filter(Boolean).join(' ').toLowerCase()
    if (hay.includes(q)) return true
    const phone = (r.customerPhone ?? '').replace(/\D/g, '')
    if (qDigits.length >= 4 && phone.includes(qDigits)) return true
    return false
  })
})

// Client-side pagination over the filtered set.
const perPage = ref<number>(25)
const page = ref<number>(1)
const totalRows = computed(() => filteredRows.value.length)
const lastPage = computed(() => Math.max(1, Math.ceil(totalRows.value / perPage.value)))
const pagedRows = computed(() => {
  const start = (page.value - 1) * perPage.value
  return filteredRows.value.slice(start, start + perPage.value)
})
const rangeText = computed(() => {
  if (totalRows.value === 0) return '0 รายการ'
  const from = (page.value - 1) * perPage.value + 1
  const to = Math.min(totalRows.value, page.value * perPage.value)
  const suffix = totalRows.value !== rows.value.length
    ? ` (กรองจาก ${rows.value.length.toLocaleString()})`
    : ''
  return `${from.toLocaleString()}–${to.toLocaleString()} จาก ${totalRows.value.toLocaleString()}${suffix}`
})
function goPage(next: number): void {
  const target = Math.max(1, Math.min(lastPage.value, next))
  if (Number.isFinite(target)) page.value = target
}
// Reset to page 1 whenever the window/filters/page size change.
watch(
  () => [
    days.value, perPage.value, filters.q, filters.fromDate, filters.toDate,
    filters.carrierId, filters.productId, filters.productType, filters.insureType,
  ],
  () => { page.value = 1 },
)

// Distinct carrier + product options derived from the loaded window.
// (Cheaper than a separate carriers/products fetch and only shows the
// carriers that actually have expiring policies right now.)
interface Option { id: string; label: string }
const carrierOptions = computed<Option[]>(() => {
  const map = new Map<string, string>()
  for (const r of rows.value) {
    if (r.carrierId && !map.has(r.carrierId)) {
      map.set(r.carrierId, r.carrierName ?? r.carrierCode ?? r.carrierId)
    }
  }
  return [...map.entries()].map(([id, label]) => ({ id, label })).sort((a, b) => a.label.localeCompare(b.label, 'th'))
})
const productOptions = computed<Option[]>(() => {
  const map = new Map<string, string>()
  for (const r of rows.value) {
    // Cascading: when a carrier is picked, only that carrier's products.
    if (filters.carrierId && r.carrierId !== filters.carrierId) continue
    if (r.productId && !map.has(r.productId)) {
      map.set(r.productId, r.productName ?? r.productCode ?? r.productId)
    }
  }
  return [...map.entries()].map(([id, label]) => ({ id, label })).sort((a, b) => a.label.localeCompare(b.label, 'th'))
})
const productTypeOptions = computed<Option[]>(() => {
  const seen = new Set<string>()
  for (const r of rows.value) if (r.productType) seen.add(r.productType)
  return [...seen].sort().map((t) => ({ id: t, label: t }))
})

// Clear-filters UX
const hasActiveFilters = computed(() =>
  filters.q !== '' || filters.fromDate !== '' || filters.toDate !== '' ||
  filters.carrierId !== '' || filters.productId !== '' ||
  filters.productType !== '' || filters.insureType !== '',
)
function clearFilters(): void {
  filters.q = ''; filters.fromDate = ''; filters.toDate = ''
  filters.carrierId = ''; filters.productId = ''
  filters.productType = ''; filters.insureType = ''
}
// When carrier changes, clear the product to avoid stale selection.
watch(() => filters.carrierId, () => { filters.productId = '' })

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchExpiringSoon(days.value, 200)
    rows.value = res.data
    meta.value = res.meta
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Unable to load renewal queue.'
    rows.value = []
    meta.value = null
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(days, load)

// ── Phase 8b — renewal actions ──────────────────────────────────────────
const actionSaving = ref<string | null>(null)
const actionMsg = ref<{ id: string; ok: boolean; text: string } | null>(null)

function flash(id: string, ok: boolean, text: string): void {
  actionMsg.value = { id, ok, text }
  setTimeout(() => { actionMsg.value = null }, 3000)
}

async function doContacted(r: ExpiringPolicy): Promise<void> {
  const note = window.prompt('บันทึกการติดต่อ (ไม่จำเป็น):') ?? ''
  actionSaving.value = r.policyId
  try {
    const res = await markRenewalContacted(r.policyId, { channel: 'phone', note: note.trim() || undefined })
    r.lastContactedAt = res.event.occurredAt
    flash(r.policyId, true, 'บันทึกการติดต่อแล้ว')
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'บันทึกล้มเหลว')
  } finally { actionSaving.value = null }
}

async function doSendNotice(r: ExpiringPolicy): Promise<void> {
  if (!window.confirm(`ส่งอีเมลแจ้งเตือนต่ออายุไปยัง ${r.customerEmail || r.agentEmail || '(?)'} ?`)) return
  actionSaving.value = r.policyId
  try {
    const res = await sendRenewalNotice(r.policyId)
    r.lastNoticeSentAt = new Date().toISOString()
    flash(r.policyId, true, res.sentToAgent ? 'ส่งถึงตัวแทน (ลูกค้าไม่มีอีเมล)' : 'ส่งอีเมลแล้ว')
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'ส่งอีเมลล้มเหลว')
  } finally { actionSaving.value = null }
}

async function doStartRenewal(r: ExpiringPolicy): Promise<void> {
  actionSaving.value = r.policyId
  try {
    const res = await markRenewalStarted(r.policyId)
    r.renewalStartedAt = new Date().toISOString()
    const q = res.quoteHint
    // Jump to /quotes/new with pre-fill query params. The quote page reads
    // ?customer= etc. — for Phase 5 the page doesn't consume these yet, but
    // we pass them so a future turn can wire it up. For now this at least
    // opens the new-quote page so the agent proceeds naturally.
    void router.push({
      name: 'quote-new',
      query: {
        customerId: q.customerId ?? '',
        productId: q.productId ?? '',
        carrierId: q.carrierId ?? '',
        writingAgentId: q.writingAgentId ?? '',
        newOrRenew: 'renew',
        refAppToId: q.refAppToId,
      },
    })
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'ล้มเหลว')
  } finally { actionSaving.value = null }
}

/** "3 days ago" — compact display for last-contacted timestamps. */
function relativeDays(iso: string | null | undefined): string {
  if (!iso) return ''
  const ms = Date.now() - new Date(iso).getTime()
  const days = Math.floor(ms / 86_400_000)
  if (days < 1) return 'วันนี้'
  if (days === 1) return 'เมื่อวาน'
  return days + ' วันก่อน'
}

function exportCsv(): void {
  // Export the currently filtered rows (what the user sees), not the whole
  // window — matches expectations after they've narrowed the list.
  const csv = toCsv(filteredRows.value, [
    { header: 'Application', value: (r) => r.applicationNo },
    { header: 'Policy', value: (r) => r.policyNo },
    { header: 'Expiry', value: (r) => r.expiryDate },
    { header: 'Days remaining', value: (r) => r.daysRemaining },
    { header: 'Customer', value: (r) => `${r.customerCode ?? ''} ${r.customerName ?? ''}`.trim() },
    { header: 'Customer email', value: (r) => r.customerEmail ?? '' },
    { header: 'Agent', value: (r) => `${r.agentCode ?? ''} ${r.agentName ?? ''}`.trim() },
    { header: 'Carrier', value: (r) => r.carrierCode },
    { header: 'Product', value: (r) => r.productCode },
    { header: 'Annual premium', value: (r) => r.annualPremium.toFixed(2) },
    { header: 'Last contacted', value: (r) => r.lastContactedAt ?? '' },
    { header: 'Notice sent', value: (r) => r.lastNoticeSentAt ?? '' },
    { header: 'Renewal started', value: (r) => r.renewalStartedAt ?? '' },
  ])
  downloadCsv(csv, `renewals-${days.value}d-${new Date().toISOString().slice(0, 10)}.csv`)
}

function badge(dr: number): { cls: string; label: string } {
  if (dr <= 7) return { cls: 'bg-rose-100 text-rose-700', label: 'ด่วน' }
  if (dr <= 30) return { cls: 'bg-amber-100 text-amber-700', label: 'ใกล้ครบ' }
  return { cls: 'bg-slate-100 text-slate-600', label: '' }
}

function fmtBaht(n: number): string {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 0 }).format(n)
}

const summary = computed(() => ({
  total: meta.value?.total ?? rows.value.length,
  urgent: rows.value.filter((r) => r.daysRemaining <= 7).length,
  window: meta.value ? `${meta.value.from} → ${meta.value.to}` : '',
}))
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Renewal Pipeline</h1>
        <p class="text-slate-500 mt-1 text-sm">
          กรมธรรม์ที่ยังคุ้มครองอยู่และจะครบกำหนดในช่วงเวลาที่เลือก
        </p>
      </div>
      <div class="flex items-center gap-2">
        <label class="text-sm text-slate-500">ระยะเวลา</label>
        <select v-model.number="days" class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option :value="30">30 วัน</option>
          <option :value="60">60 วัน</option>
          <option :value="90">90 วัน</option>
          <option :value="180">180 วัน</option>
        </select>
        <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50 flex items-center gap-1"
          :disabled="!filteredRows.length" @click="exportCsv">
          <i class="pi pi-download text-xs" /> Export CSV
        </button>
      </div>
    </header>

    <!-- Filter card -->
    <section class="card p-4 space-y-3">
      <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
        <div class="md:col-span-5">
          <label class="text-xs font-medium text-slate-500 mb-1 block">
            ค้นหา
            <span v-if="detectedIntent !== 'auto'" class="ml-1 inline-block px-1.5 py-0.5 rounded bg-brand-50 text-brand-700 text-[10px] font-medium">
              {{ intentLabel[detectedIntent] }}
            </span>
          </label>
          <div class="relative">
            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
            <input v-model.trim="filters.q"
              placeholder="ชื่อ / เบอร์โทร / ทะเบียนรถ / เลขกรมธรรม์"
              class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400" />
          </div>
        </div>
        <div class="md:col-span-4">
          <label class="text-xs font-medium text-slate-500 mb-1 block">วันหมดอายุ (Expiry date)</label>
          <div class="flex items-center gap-2">
            <input v-model="filters.fromDate" type="date"
              class="flex-1 border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400" />
            <span class="text-slate-400 text-xs">ถึง</span>
            <input v-model="filters.toDate" type="date"
              class="flex-1 border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400" />
          </div>
        </div>
        <div class="md:col-span-3">
          <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภทประกัน</label>
          <select v-model="filters.insureType" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
            <option value="">ทั้งหมด</option>
            <option value="life">Life (ชีวิต)</option>
            <option value="non-life">Non-Life (วินาศ)</option>
            <option value="tax">Tax (ภาษี)</option>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
        <div class="md:col-span-4">
          <label class="text-xs font-medium text-slate-500 mb-1 block">บริษัทประกัน</label>
          <select v-model="filters.carrierId" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
            <option value="">ทั้งหมด</option>
            <option v-for="c in carrierOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
          </select>
        </div>
        <div class="md:col-span-4">
          <label class="text-xs font-medium text-slate-500 mb-1 block">
            แผนประกัน
            <span v-if="!filters.carrierId" class="text-slate-400 ml-1">(เลือกบริษัทก่อน)</span>
          </label>
          <select v-model="filters.productId" :disabled="!filters.carrierId"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white disabled:bg-slate-50">
            <option value="">ทั้งหมด</option>
            <option v-for="p in productOptions" :key="p.id" :value="p.id">{{ p.label }}</option>
          </select>
        </div>
        <div class="md:col-span-3">
          <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภทผลิตภัณฑ์</label>
          <select v-model="filters.productType" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
            <option value="">ทั้งหมด</option>
            <option v-for="t in productTypeOptions" :key="t.id" :value="t.id">{{ t.label }}</option>
          </select>
        </div>
        <div class="md:col-span-1 flex md:justify-end">
          <button v-if="hasActiveFilters" type="button" @click="clearFilters"
            class="w-full md:w-auto px-3 py-1.5 rounded-lg border border-rose-200 text-xs text-rose-600 hover:bg-rose-50">
            ล้าง
          </button>
        </div>
      </div>
    </section>

    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Total in window</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ summary.total.toLocaleString() }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ summary.window }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">ด่วน (≤7 วัน)</div>
        <div class="text-2xl font-semibold text-rose-600 mt-1">{{ summary.urgent.toLocaleString() }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Status</div>
        <div class="text-sm text-slate-700 mt-2">
          <span v-if="loading" class="text-slate-500">Loading…</span>
          <span v-else-if="error" class="text-rose-600">{{ error }}</span>
          <span v-else>เรียลไทม์จาก Laravel</span>
        </div>
      </div>
    </section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">Application</th>
              <th class="px-4 py-2 text-left">Policy no</th>
              <th class="px-4 py-2 text-left">ลูกค้า</th>
              <th class="px-4 py-2 text-left">ตัวแทน</th>
              <th class="px-4 py-2 text-right">Premium</th>
              <th class="px-4 py-2 text-left">Expiry</th>
              <th class="px-4 py-2 text-right">วัน</th>
              <th class="px-4 py-2 text-left">สถานะติดตาม</th>
              <th class="px-4 py-2 text-right w-72">การดำเนินการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in pagedRows" :key="r.policyId" class="hover:bg-slate-50">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.applicationNo ?? '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.policyNo ?? '—' }}</td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ r.customerName || r.customerCode }}</div>
                <div class="text-xs text-slate-500">
                  <span>{{ r.customerCode }}</span>
                  <span v-if="r.customerEmail" class="ml-1">· {{ r.customerEmail }}</span>
                </div>
              </td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ r.agentName || r.agentCode }}</div>
                <div class="text-xs text-slate-500">{{ r.agentCode }}</div>
              </td>
              <td class="px-4 py-2 text-right font-medium text-slate-900">{{ fmtBaht(r.annualPremium) }}</td>
              <td class="px-4 py-2">{{ r.expiryDate }}</td>
              <td class="px-4 py-2 text-right">
                <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium', badge(r.daysRemaining).cls]">
                  {{ r.daysRemaining }} วัน
                  <span v-if="badge(r.daysRemaining).label" class="text-[10px]">{{ badge(r.daysRemaining).label }}</span>
                </span>
              </td>
              <td class="px-4 py-2 text-xs text-slate-500">
                <div v-if="r.renewalStartedAt" class="text-brand-700">
                  <i class="pi pi-arrow-right text-[10px] mr-0.5" /> เริ่มต่ออายุ · {{ relativeDays(r.renewalStartedAt) }}
                </div>
                <div v-if="r.lastNoticeSentAt" class="text-emerald-700">
                  <i class="pi pi-envelope text-[10px] mr-0.5" /> ส่งอีเมล · {{ relativeDays(r.lastNoticeSentAt) }}
                </div>
                <div v-if="r.lastContactedAt" class="text-slate-600">
                  <i class="pi pi-phone text-[10px] mr-0.5" /> ติดต่อ · {{ relativeDays(r.lastContactedAt) }}
                </div>
                <div v-if="!r.lastContactedAt && !r.lastNoticeSentAt && !r.renewalStartedAt" class="text-slate-300">—</div>
                <div v-if="actionMsg?.id === r.policyId"
                  :class="actionMsg.ok ? 'text-emerald-700' : 'text-rose-700'"
                  class="text-[10px] mt-1">{{ actionMsg.text }}</div>
              </td>
              <td class="px-4 py-2 text-right">
                <div class="inline-flex items-center gap-1">
                  <button type="button" title="บันทึกการติดต่อ"
                    class="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-brand-600 disabled:opacity-50"
                    :disabled="actionSaving === r.policyId" @click="doContacted(r)">
                    <i class="pi pi-phone text-xs" />
                  </button>
                  <button type="button" title="ส่งอีเมลแจ้งต่ออายุ"
                    class="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-brand-600 disabled:opacity-50"
                    :disabled="actionSaving === r.policyId" @click="doSendNotice(r)">
                    <i class="pi pi-envelope text-xs" />
                  </button>
                  <button type="button"
                    class="ml-1 px-2 py-1 rounded bg-brand-600 text-white text-xs hover:bg-brand-700 disabled:opacity-50"
                    :disabled="actionSaving === r.policyId" @click="doStartRenewal(r)">
                    <i class="pi pi-arrow-right text-[10px] mr-1" /> ต่ออายุ
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td colspan="9" class="px-4 py-6 text-center text-slate-500">ไม่พบกรมธรรม์ที่จะครบกำหนดในช่วงเวลานี้</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination footer -->
      <div v-if="rows.length > 0" class="flex items-center justify-between gap-3 px-4 py-3 border-t border-slate-100 text-sm flex-wrap">
        <div class="flex items-center gap-2 text-slate-500">
          <span>แสดง</span>
          <select v-model.number="perPage"
            class="border border-slate-200 rounded-md px-2 py-1 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option :value="10">10</option>
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
            <option :value="200">200</option>
          </select>
          <span>ต่อหน้า · {{ rangeText }}</span>
        </div>
        <div class="flex items-center gap-2">
          <button class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="page <= 1" @click="goPage(1)" title="หน้าแรก">
            <i class="pi pi-angle-double-left text-xs" />
          </button>
          <button class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="page <= 1" @click="goPage(page - 1)" title="ก่อนหน้า">
            <i class="pi pi-angle-left text-xs" />
          </button>
          <span class="text-slate-600 px-2">
            หน้า
            <input type="number" min="1" :max="lastPage" :value="page"
              @change="e => goPage(Number((e.target as HTMLInputElement).value))"
              class="w-14 border border-slate-200 rounded-md px-2 py-0.5 text-sm text-center focus:outline-none focus:border-brand-400" />
            / {{ lastPage.toLocaleString() }}
          </span>
          <button class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="page >= lastPage" @click="goPage(page + 1)" title="ถัดไป">
            <i class="pi pi-angle-right text-xs" />
          </button>
          <button class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="page >= lastPage" @click="goPage(lastPage)" title="หน้าสุดท้าย">
            <i class="pi pi-angle-double-right text-xs" />
          </button>
        </div>
      </div>
    </section>
  </div>
</template>
