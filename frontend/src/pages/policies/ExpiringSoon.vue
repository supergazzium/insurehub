<script setup lang="ts">
import { onMounted, reactive, ref, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchExpiringSoon, markRenewalContacted, markRenewalStarted, sendRenewalNotice,
  type ExpiringPolicy, type ExpiringSoonMeta, type ExpiringSoonSummary,
} from '../../api/reports'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { fetchProductList, type ProductListRow } from '../../api/products'
import { ApiError } from '../../api/client'
import { toCsv, downloadCsv } from '../../util/csvExport'
import DateInput from '../../components/DateInput.vue'
import PolicyDetailDrawer from './PolicyDetailDrawer.vue'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()

const rows = ref<ExpiringPolicy[]>([])
const meta = ref<ExpiringSoonMeta | null>(null)
// ID of the row whose detail drawer is open. null when the drawer is closed.
const detailId = ref<string | null>(null)
const summary = ref<ExpiringSoonSummary>({ totalInWindow: 0, urgentCount: 0 })
const loading = ref(false)
const error = ref<string | null>(null)

// ── Filters (server-side — every change triggers a debounced re-fetch) ──
// Date range drives the SQL query directly; picking a future range returns
// real future data. The `preset` chip is a UX shortcut that just sets
// from/to; picking dates by hand flips preset to 'custom' but does NOT
// override the user's typed values.
type Preset = 30 | 60 | 90 | 180 | 'custom'
const preset = ref<Preset>(60)
const filters = reactive({
  q: '',
  fromDate: '',
  toDate: '',
  carrierId: '',
  productId: '',
  productType: '',
  insureType: '' as '' | 'life' | 'non-life' | 'tax',
})
const page = ref<number>(1)
const perPage = ref<number>(50)
const sortBy = ref<'expiryDate' | 'annualPremium' | 'customerName'>('expiryDate')
const sortDir = ref<'asc' | 'desc'>('asc')

// ── Persisted filter state ──────────────────────────────────────────────
// Everything the user tunes (filters / preset / sort / page-size) is
// persisted to localStorage so the next session picks up where they left
// off. Bumped version resets the schema on breaking changes.
const STORAGE_KEY = 'renewal-pipeline:v1'
interface PersistedState {
  filters: typeof filters
  preset: Preset
  sortBy: typeof sortBy.value
  sortDir: typeof sortDir.value
  perPage: number
}
function saveState(): void {
  try {
    const state: PersistedState = {
      filters: { ...filters },
      preset: preset.value,
      sortBy: sortBy.value,
      sortDir: sortDir.value,
      perPage: perPage.value,
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
  } catch { /* quota exceeded / private mode — ignore */ }
}
function restoreState(): boolean {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return false
    const s = JSON.parse(raw) as Partial<PersistedState>
    if (s.filters) Object.assign(filters, s.filters)
    if (s.preset !== undefined) preset.value = s.preset
    if (s.sortBy) sortBy.value = s.sortBy
    if (s.sortDir) sortDir.value = s.sortDir
    if (s.perPage) perPage.value = s.perPage
    return true
  } catch { return false }
}

function applyPreset(days: 30 | 60 | 90 | 180): void {
  preset.value = days
  const today = new Date()
  const to = new Date(today.getTime() + days * 86_400_000)
  filters.fromDate = today.toISOString().slice(0, 10)
  filters.toDate = to.toISOString().slice(0, 10)
}

// Calendar-aware presets — pin to the start/end of a named period rather
// than a rolling N-day window from today.
function iso(d: Date): string { return d.toISOString().slice(0, 10) }
function applyUrgent(): void {
  // Everything expiring in the next 7 days.
  preset.value = 'custom'
  const today = new Date()
  const week = new Date(today.getTime() + 7 * 86_400_000)
  filters.fromDate = iso(today)
  filters.toDate = iso(week)
}
function applyThisMonth(): void {
  preset.value = 'custom'
  const now = new Date()
  filters.fromDate = iso(new Date(now.getFullYear(), now.getMonth(), 1))
  filters.toDate = iso(new Date(now.getFullYear(), now.getMonth() + 1, 0))
}
function applyNextMonth(): void {
  preset.value = 'custom'
  const now = new Date()
  filters.fromDate = iso(new Date(now.getFullYear(), now.getMonth() + 1, 1))
  filters.toDate = iso(new Date(now.getFullYear(), now.getMonth() + 2, 0))
}
function applyNextQuarter(): void {
  preset.value = 'custom'
  const now = new Date()
  const currentQ = Math.floor(now.getMonth() / 3)
  const startMonth = (currentQ + 1) * 3
  filters.fromDate = iso(new Date(now.getFullYear(), startMonth, 1))
  filters.toDate = iso(new Date(now.getFullYear(), startMonth + 3, 0))
}

// Initial window — 60 days from today. Populated before first load.
{
  applyPreset(60)
}

// Column header click → toggle direction; new column resets to asc
// (or desc for premium since "high value first" is a more useful default).
function toggleSort(col: 'expiryDate' | 'annualPremium'): void {
  if (sortBy.value === col) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value = col
    sortDir.value = col === 'annualPremium' ? 'desc' : 'asc'
  }
  page.value = 1
  void load()
}

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

// Server pagination — meta.total is authoritative, no more "filtered from N".
const lastPage = computed(() => meta.value?.lastPage ?? 1)
const rangeText = computed(() => {
  const total = meta.value?.total ?? 0
  if (total === 0) return '0 รายการ'
  const from = (page.value - 1) * perPage.value + 1
  const to = Math.min(total, page.value * perPage.value)
  return `${from.toLocaleString()}–${to.toLocaleString()} จาก ${total.toLocaleString()}`
})
function goPage(next: number): void {
  const target = Math.max(1, Math.min(lastPage.value, next))
  if (Number.isFinite(target) && target !== page.value) {
    page.value = target
    void load()
  }
}

// Real carrier + product lists — not derived from the current window (a
// small window would hide carriers that DO have data further out). Loaded
// once on mount, filtered client-side by the picked insureType/carrier.
interface Option { id: string; label: string }
const allCarriers = ref<CarrierListRow[]>([])
const allProducts = ref<ProductListRow[]>([])
async function loadCarriers(): Promise<void> {
  try {
    const res = await fetchCarrierList({ perPage: 200, activeOnly: true })
    allCarriers.value = res.data
  } catch { /* silent — dropdown just empty */ }
}
async function loadProducts(): Promise<void> {
  try {
    const res = await fetchProductList({ perPage: 500, activeOnly: true })
    allProducts.value = res.data
  } catch { /* silent — dropdown just empty */ }
}
const carrierOptions = computed<Option[]>(() => {
  const src = filters.insureType
    ? allCarriers.value.filter((c) => c.insureType === filters.insureType)
    : allCarriers.value
  return src
    .map((c) => ({ id: c.id, label: `${c.code} — ${c.nicknameTh || c.name}` }))
    .sort((a, b) => a.label.localeCompare(b.label, 'th'))
})
const productOptions = computed<Option[]>(() => {
  if (!filters.carrierId) return []
  return allProducts.value
    .filter((p) => p.carrierId === filters.carrierId)
    .map((p) => ({ id: p.id, label: `${p.code} — ${p.name}` }))
    .sort((a, b) => a.label.localeCompare(b.label, 'th'))
})
const productTypeOptions = computed<Option[]>(() => {
  // Same vocabulary as the create modal / product-list filter.
  const src = filters.insureType
  if (src === 'life') return ['Life', 'PA', 'Group-Life', 'Rider'].map((t) => ({ id: t, label: t }))
  if (src === 'non-life') return ['Group-NL', 'Motor', 'Non-Motor'].map((t) => ({ id: t, label: t }))
  if (src === 'tax') return [{ id: 'Tax', label: 'Tax' }]
  return ['Life', 'PA', 'Group-Life', 'Group-NL', 'Rider', 'Motor', 'Non-Motor', 'Tax'].map((t) => ({ id: t, label: t }))
})

// Clear-filters UX
const hasActiveFilters = computed(() =>
  filters.q !== '' || filters.carrierId !== '' || filters.productId !== '' ||
  filters.productType !== '' || filters.insureType !== '',
)
function clearFilters(): void {
  filters.q = ''
  filters.carrierId = ''; filters.productId = ''
  filters.productType = ''; filters.insureType = ''
  applyPreset(60)
  // A cleared filter shouldn't carry a stale multi-page selection.
  clearSelection()
}

// Cascading resets — carrier switch clears plan; insureType switch clears
// carrier + plan + productType if they no longer make sense.
watch(() => filters.carrierId, () => { filters.productId = '' })
watch(() => filters.insureType, () => {
  if (filters.carrierId && !carrierOptions.value.some((c) => c.id === filters.carrierId)) {
    filters.carrierId = ''
    filters.productId = ''
  }
  if (filters.productType && !productTypeOptions.value.some((t) => t.id === filters.productType)) {
    filters.productType = ''
  }
})

// When the user picks dates manually, flip preset to 'custom' but keep
// their dates untouched. Presets only mutate from/to when clicked.
watch(() => [filters.fromDate, filters.toDate] as const, ([from, to]) => {
  if (preset.value !== 'custom') {
    const today = new Date().toISOString().slice(0, 10)
    const expectedTo = new Date(new Date().getTime() + (preset.value as number) * 86_400_000)
      .toISOString().slice(0, 10)
    if (from !== today || to !== expectedTo) preset.value = 'custom'
  }
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchExpiringSoon({
      from: filters.fromDate || undefined,
      to: filters.toDate || undefined,
      q: filters.q || undefined,
      carrierId: filters.carrierId || undefined,
      productId: filters.productId || undefined,
      productType: filters.productType || undefined,
      insureType: filters.insureType || undefined,
      page: page.value,
      perPage: perPage.value,
      sortBy: sortBy.value,
      sortDir: sortDir.value,
    })
    rows.value = res.data
    meta.value = res.meta
    summary.value = res.summary
    // Clamp current page if the server has fewer pages than expected
    // (e.g. after a filter change).
    if (page.value > (res.meta?.lastPage ?? 1)) {
      page.value = Math.max(1, res.meta.lastPage)
      await load()
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Unable to load renewal queue.'
    rows.value = []
    meta.value = null
    summary.value = { totalInWindow: 0, urgentCount: 0 }
  } finally {
    loading.value = false
  }
}

// Debounced re-load on any filter change. Reset to page 1 first so a
// narrower filter never leaves the user staring at page 12 of a 3-page
// result set.
let debounceTimer: number | undefined
function scheduleReload(): void {
  window.clearTimeout(debounceTimer)
  debounceTimer = window.setTimeout(() => {
    page.value = 1
    void load()
  }, 250)
}
watch(
  () => [
    filters.q, filters.fromDate, filters.toDate,
    filters.carrierId, filters.productId, filters.productType, filters.insureType,
    perPage.value,
  ],
  scheduleReload,
)

// Persist every user-tunable knob so the next session resumes here.
watch(
  () => [
    filters.q, filters.fromDate, filters.toDate,
    filters.carrierId, filters.productId, filters.productType, filters.insureType,
    preset.value, sortBy.value, sortDir.value, perPage.value,
  ],
  saveState,
  { deep: true },
)

onMounted(() => {
  restoreState()
  void loadCarriers()
  void loadProducts()
  void load()
})

// ── Bulk selection ──────────────────────────────────────────────────────
// A Set of selected policy IDs. Persists across pagination (the user can
// select 12 from page 1, flip to page 2, add another 5, then bulk-email).
const selected = ref<Set<string>>(new Set())
function toggleRow(id: string): void {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id); else next.add(id)
  selected.value = next
}
function isSelected(id: string): boolean {
  return selected.value.has(id)
}
// Are all rows on the current page in the selection? Used for the
// header-row "select all on page" checkbox tri-state.
const allOnPageSelected = computed(() =>
  rows.value.length > 0 && rows.value.every((r) => selected.value.has(r.policyId)),
)
const someOnPageSelected = computed(() =>
  !allOnPageSelected.value && rows.value.some((r) => selected.value.has(r.policyId)),
)
function toggleAllOnPage(): void {
  const next = new Set(selected.value)
  if (allOnPageSelected.value) {
    for (const r of rows.value) next.delete(r.policyId)
  } else {
    for (const r of rows.value) next.add(r.policyId)
  }
  selected.value = next
}
function clearSelection(): void { selected.value = new Set() }

// ── Bulk actions ────────────────────────────────────────────────────────
// Bulk-send fires the same POST /policies/{id}/renewal/send-notice per
// row, sequentially so we don't hammer the mailer. Progress + summary
// surface in a small banner.
const bulkRunning = ref(false)
const bulkProgress = ref<{ done: number; total: number; ok: number; failed: number } | null>(null)

async function bulkSendNotices(): Promise<void> {
  if (bulkRunning.value || selected.value.size === 0) return
  const ids = [...selected.value]
  bulkRunning.value = true
  bulkProgress.value = { done: 0, total: ids.length, ok: 0, failed: 0 }
  for (const id of ids) {
    try {
      await sendRenewalNotice(id)
      bulkProgress.value.ok++
      // Update in-memory row so the "notice sent" status pill shows up
      // without a full refetch.
      const r = rows.value.find((x) => x.policyId === id)
      if (r) r.lastNoticeSentAt = new Date().toISOString()
    } catch {
      bulkProgress.value.failed++
    }
    bulkProgress.value.done++
  }
  bulkRunning.value = false
  // Clear the selection once the run completes so a second click doesn't
  // double-send by accident.
  clearSelection()
  // Leave the summary visible for a few seconds.
  setTimeout(() => { bulkProgress.value = null }, 6000)
}

async function bulkExportCsv(): Promise<void> {
  if (selected.value.size === 0) return
  // Pull the current filtered set (up to server cap), then narrow to the
  // selected IDs. Simpler + more correct than trying to POST an ID list.
  const res = await fetchExpiringSoon({
    from: filters.fromDate || undefined,
    to: filters.toDate || undefined,
    q: filters.q || undefined,
    carrierId: filters.carrierId || undefined,
    productId: filters.productId || undefined,
    productType: filters.productType || undefined,
    insureType: filters.insureType || undefined,
    page: 1, perPage: 500,
  })
  const picked = res.data.filter((r) => selected.value.has(r.policyId))
  const csv = toCsv(picked, [
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
  ])
  downloadCsv(csv, `renewals-selected-${new Date().toISOString().slice(0, 10)}.csv`)
}

// ── Phase 8b — renewal actions ──────────────────────────────────────────
const actionSaving = ref<string | null>(null)
const actionMsg = ref<{ id: string; ok: boolean; text: string } | null>(null)

function flash(id: string, ok: boolean, text: string): void {
  actionMsg.value = { id, ok, text }
  setTimeout(() => { actionMsg.value = null }, 3000)
}

// ── Inline modals (replace blocking window.prompt / window.confirm) ────
// One "log contact" modal + one "send notice" confirm — both scoped to a
// single policy row. Kept inline (not extracted to a component) so the
// state stays local and closes cleanly when the row action completes.
const contactModal = ref<{ row: ExpiringPolicy; channel: string; note: string } | null>(null)
const noticeModal = ref<{ row: ExpiringPolicy } | null>(null)

function openContact(r: ExpiringPolicy): void {
  contactModal.value = { row: r, channel: 'phone', note: '' }
}
function openNotice(r: ExpiringPolicy): void {
  noticeModal.value = { row: r }
}

async function submitContact(): Promise<void> {
  if (!contactModal.value) return
  const { row: r, channel, note } = contactModal.value
  actionSaving.value = r.policyId
  try {
    const res = await markRenewalContacted(r.policyId, {
      channel: channel as 'phone' | 'line' | 'email' | 'inperson' | 'other',
      note: note.trim() || undefined,
    })
    r.lastContactedAt = res.event.occurredAt
    flash(r.policyId, true, 'บันทึกการติดต่อแล้ว')
    contactModal.value = null
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'บันทึกล้มเหลว')
  } finally { actionSaving.value = null }
}

async function submitNotice(): Promise<void> {
  if (!noticeModal.value) return
  const r = noticeModal.value.row
  actionSaving.value = r.policyId
  try {
    const res = await sendRenewalNotice(r.policyId)
    r.lastNoticeSentAt = new Date().toISOString()
    flash(r.policyId, true, res.sentToAgent ? 'ส่งถึงตัวแทน (ลูกค้าไม่มีอีเมล)' : 'ส่งอีเมลแล้ว')
    noticeModal.value = null
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

// CSV export — fetch the entire filtered set (up to the server cap) with
// one extra request instead of only what's on the current page. Uses the
// same filter set as the visible table.
const exportingCsv = ref(false)
async function exportCsv(): Promise<void> {
  if (exportingCsv.value) return
  exportingCsv.value = true
  try {
    const res = await fetchExpiringSoon({
      from: filters.fromDate || undefined,
      to: filters.toDate || undefined,
      q: filters.q || undefined,
      carrierId: filters.carrierId || undefined,
      productId: filters.productId || undefined,
      productType: filters.productType || undefined,
      insureType: filters.insureType || undefined,
      page: 1,
      perPage: 500,
    })
    const csv = toCsv(res.data, [
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
    const label = `${filters.fromDate || 'today'}-to-${filters.toDate || ''}`
    downloadCsv(csv, `renewals-${label}.csv`)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'CSV export failed.'
  } finally {
    exportingCsv.value = false
  }
}

// PDF export — server-side render via dompdf. We pass every filter the
// user has applied so the PDF matches what's on screen exactly.
const exportingPdf = ref(false)
async function exportPdf(): Promise<void> {
  if (exportingPdf.value) return
  exportingPdf.value = true
  try {
    const token = (await import('../../api/client')).getToken()
    if (!token) return
    const base = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.replace(/\/+$/, '')
      ?? 'http://127.0.0.1:8000/api/v1'
    const qs = new URLSearchParams()
    if (filters.q) qs.set('q', filters.q)
    if (filters.fromDate) qs.set('from', filters.fromDate)
    if (filters.toDate) qs.set('to', filters.toDate)
    if (filters.carrierId) qs.set('carrierId', filters.carrierId)
    if (filters.productId) qs.set('productId', filters.productId)
    if (filters.productType) qs.set('productType', filters.productType)
    if (filters.insureType) qs.set('insureType', filters.insureType)
    const url = `${base}/reports/expiring-soon/pdf?${qs.toString()}`
    const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const blob = await res.blob()
    // Use server-suggested filename if present; otherwise derive locally.
    const disp = res.headers.get('Content-Disposition') ?? ''
    const match = disp.match(/filename="?([^"]+)"?/)
    const fileName = match?.[1]
      ?? `Renewals-${filters.fromDate || new Date().toISOString().slice(0, 10)}-to-${filters.toDate || ''}.pdf`
    const a = document.createElement('a')
    const objectUrl = URL.createObjectURL(blob)
    a.href = objectUrl
    a.download = fileName
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    setTimeout(() => URL.revokeObjectURL(objectUrl), 30000)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'PDF export failed.'
  } finally {
    exportingPdf.value = false
  }
}

function badge(dr: number): { cls: string; label: string } {
  if (dr <= 7) return { cls: 'bg-rose-100 text-rose-700', label: 'ด่วน' }
  if (dr <= 30) return { cls: 'bg-amber-100 text-amber-700', label: 'ใกล้ครบ' }
  return { cls: 'bg-slate-100 text-slate-600', label: '' }
}

function fmtBaht(n: number): string {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 0 }).format(n)
}

// Window label for the summary card — derived from meta so it reflects
// exactly what the server queried.
const windowLabel = computed(() => meta.value ? `${fmtDate(meta.value.from)} → ${fmtDate(meta.value.to)}` : '')
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
        <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50 flex items-center gap-1"
          :disabled="!rows.length || exportingCsv" @click="exportCsv">
          <i :class="exportingCsv ? 'pi pi-spin pi-spinner' : 'pi pi-download'" class="text-xs" />
          {{ exportingCsv ? 'กำลังสร้าง...' : 'Export CSV' }}
        </button>
        <button type="button" class="px-3 py-1.5 rounded-lg bg-rose-600 text-white hover:bg-rose-700 text-sm disabled:opacity-50 flex items-center gap-1"
          :disabled="!rows.length || exportingPdf" @click="exportPdf"
          title="ส่งออกเป็น PDF สำหรับตัวแทนใช้ติดต่อลูกค้า">
          <i :class="exportingPdf ? 'pi pi-spin pi-spinner' : 'pi pi-file-pdf'" class="text-xs" />
          {{ exportingPdf ? 'กำลังสร้าง...' : 'Export PDF' }}
        </button>
      </div>
    </header>

    <!-- Filter card -->
    <section class="card p-4 space-y-3">
      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs text-slate-500">ช่วงเวลา:</span>
        <button v-for="d in [30, 60, 90, 180] as const" :key="d" type="button"
          @click="applyPreset(d)"
          :class="[
            'px-3 py-1 rounded-full border text-xs transition-colors',
            preset === d
              ? 'border-brand-500 bg-brand-50 text-brand-700 font-medium'
              : 'border-slate-200 text-slate-600 hover:bg-slate-50',
          ]">{{ d }} วัน</button>
        <span class="mx-1 text-slate-300">·</span>
        <button type="button" @click="applyUrgent"
          class="px-3 py-1 rounded-full border border-slate-200 text-xs text-slate-600 hover:bg-rose-50 hover:border-rose-200 hover:text-rose-700 transition-colors">
          ด่วน (7 วัน)
        </button>
        <button type="button" @click="applyThisMonth"
          class="px-3 py-1 rounded-full border border-slate-200 text-xs text-slate-600 hover:bg-slate-50">เดือนนี้</button>
        <button type="button" @click="applyNextMonth"
          class="px-3 py-1 rounded-full border border-slate-200 text-xs text-slate-600 hover:bg-slate-50">เดือนหน้า</button>
        <button type="button" @click="applyNextQuarter"
          class="px-3 py-1 rounded-full border border-slate-200 text-xs text-slate-600 hover:bg-slate-50">ไตรมาสหน้า</button>
        <span v-if="preset === 'custom'" class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] uppercase tracking-wider">custom</span>
      </div>

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
            <div class="flex-1">
              <DateInput v-model="filters.fromDate" :max="filters.toDate || undefined" />
            </div>
            <span class="text-slate-400 text-xs">ถึง</span>
            <div class="flex-1">
              <DateInput v-model="filters.toDate" :min="filters.fromDate || undefined" />
            </div>
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

    <section class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">รวมทั้งหมด (ตามฟิลเตอร์)</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ summary.totalInWindow.toLocaleString() }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ windowLabel }}</div>
      </div>
      <button type="button" @click="applyUrgent"
        class="card p-4 text-left hover:ring-2 hover:ring-rose-200 transition-shadow cursor-pointer"
        title="คลิกเพื่อดูเฉพาะที่ครบกำหนดใน 7 วัน">
        <div class="text-xs uppercase tracking-wider text-slate-400 flex items-center justify-between">
          <span>ด่วน (≤7 วัน)</span>
          <i class="pi pi-filter text-[10px] text-slate-300" />
        </div>
        <div class="text-2xl font-semibold text-rose-600 mt-1">{{ summary.urgentCount.toLocaleString() }}</div>
        <div v-if="error" class="text-xs text-rose-600 mt-1">{{ error }}</div>
      </button>
    </section>

    <!-- Bulk action bar — visible only when the operator has selected ≥1 row -->
    <div v-if="selected.size > 0"
      class="sticky top-2 z-40 flex items-center gap-3 px-4 py-2.5 rounded-xl bg-brand-600 text-white shadow-lg">
      <div class="text-sm font-medium">
        <i class="pi pi-check-square mr-1" />
        เลือกแล้ว {{ selected.size.toLocaleString() }} รายการ
      </div>
      <div class="flex-1" />
      <button type="button" @click="bulkSendNotices"
        :disabled="bulkRunning"
        class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-sm flex items-center gap-1.5 disabled:opacity-50">
        <i :class="bulkRunning ? 'pi pi-spin pi-spinner' : 'pi pi-envelope'" class="text-xs" />
        {{ bulkRunning
          ? `กำลังส่ง ${bulkProgress?.done ?? 0}/${bulkProgress?.total ?? 0}...`
          : `ส่งอีเมลแจ้งต่ออายุ (${selected.size})` }}
      </button>
      <button type="button" @click="bulkExportCsv"
        class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-sm flex items-center gap-1.5">
        <i class="pi pi-download text-xs" /> Export CSV
      </button>
      <button type="button" @click="clearSelection"
        class="px-3 py-1.5 rounded-lg hover:bg-white/15 text-sm flex items-center gap-1.5">
        <i class="pi pi-times text-xs" /> ล้าง
      </button>
    </div>

    <!-- Bulk-send result banner — lingers a few seconds after the run finishes. -->
    <div v-if="bulkProgress && !bulkRunning"
      class="px-4 py-2 rounded-lg text-sm flex items-center gap-2"
      :class="bulkProgress.failed > 0 ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'">
      <i :class="bulkProgress.failed > 0 ? 'pi pi-exclamation-triangle' : 'pi pi-check-circle'" />
      <span>
        ส่งอีเมลเสร็จสิ้น — สำเร็จ {{ bulkProgress.ok }} รายการ<span v-if="bulkProgress.failed > 0">, ล้มเหลว {{ bulkProgress.failed }} รายการ</span>
      </span>
    </div>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-3 py-2 text-left w-8">
                <input type="checkbox"
                  :checked="allOnPageSelected"
                  :indeterminate.prop="someOnPageSelected"
                  @change="toggleAllOnPage"
                  class="accent-brand-500 cursor-pointer"
                  title="เลือกทั้งหน้า" />
              </th>
              <th class="px-4 py-2 text-left">Application</th>
              <th class="px-4 py-2 text-left">Policy no</th>
              <th class="px-4 py-2 text-left">ลูกค้า</th>
              <th class="px-4 py-2 text-left">ตัวแทน</th>
              <th class="px-4 py-2 text-right cursor-pointer select-none hover:text-slate-800"
                @click="toggleSort('annualPremium')">
                Premium
                <i v-if="sortBy === 'annualPremium'"
                  :class="sortDir === 'asc' ? 'pi-sort-amount-up-alt' : 'pi-sort-amount-down'"
                  class="pi text-[10px] ml-1" />
                <i v-else class="pi pi-sort text-[10px] ml-1 text-slate-300" />
              </th>
              <th class="px-4 py-2 text-left cursor-pointer select-none hover:text-slate-800"
                @click="toggleSort('expiryDate')">
                Expiry
                <i v-if="sortBy === 'expiryDate'"
                  :class="sortDir === 'asc' ? 'pi-sort-amount-up-alt' : 'pi-sort-amount-down'"
                  class="pi text-[10px] ml-1" />
                <i v-else class="pi pi-sort text-[10px] ml-1 text-slate-300" />
              </th>
              <th class="px-4 py-2 text-right">วัน</th>
              <th class="px-4 py-2 text-left">สถานะติดตาม</th>
              <th class="px-4 py-2 text-right w-72">การดำเนินการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in rows" :key="r.policyId"
              :class="['hover:bg-slate-50 cursor-pointer', isSelected(r.policyId) ? 'bg-brand-50/40' : '']"
              @click="detailId = r.policyId">
              <td class="px-3 py-2 w-8" @click.stop>
                <input type="checkbox" :checked="isSelected(r.policyId)"
                  @change="toggleRow(r.policyId)"
                  class="accent-brand-500 cursor-pointer" />
              </td>
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
              <td class="px-4 py-2">{{ fmtDate(r.expiryDate) }}</td>
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
              <td class="px-4 py-2 text-right" @click.stop>
                <div class="inline-flex items-center gap-1">
                  <button type="button" title="บันทึกการติดต่อ"
                    class="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-brand-600 disabled:opacity-50"
                    :disabled="actionSaving === r.policyId" @click="openContact(r)">
                    <i class="pi pi-phone text-xs" />
                  </button>
                  <button type="button" title="ส่งอีเมลแจ้งต่ออายุ"
                    class="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-brand-600 disabled:opacity-50"
                    :disabled="actionSaving === r.policyId" @click="openNotice(r)">
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
              <td colspan="10" class="px-4 py-6 text-center text-slate-500">ไม่พบกรมธรรม์ที่จะครบกำหนดในช่วงเวลานี้</td>
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

    <!-- Policy detail drawer — opens when a row is clicked -->
    <PolicyDetailDrawer :policy-id="detailId" @close="detailId = null" />

    <!-- Log-contact modal — replaces window.prompt for a proper form -->
    <div v-if="contactModal" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4"
      @click.self="contactModal = null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="text-lg font-semibold text-slate-900">บันทึกการติดต่อ</div>
          <button type="button" class="text-slate-400 hover:text-slate-700 p-1" @click="contactModal = null">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="p-5 space-y-3">
          <div class="text-xs text-slate-500">
            <div>{{ contactModal.row.customerName || contactModal.row.customerCode }}</div>
            <div class="font-mono">{{ contactModal.row.policyNo }} · หมดอายุ {{ fmtDate(contactModal.row.expiryDate) }}</div>
          </div>
          <div>
            <label class="text-xs font-medium text-slate-500 mb-1 block">ช่องทาง</label>
            <div class="flex gap-1.5 flex-wrap">
              <label v-for="opt in [
                { value: 'phone', label: 'โทร', icon: 'pi-phone' },
                { value: 'line', label: 'LINE', icon: 'pi-comments' },
                { value: 'email', label: 'อีเมล', icon: 'pi-envelope' },
                { value: 'inperson', label: 'พบหน้า', icon: 'pi-user' },
                { value: 'other', label: 'อื่นๆ', icon: 'pi-ellipsis-h' },
              ]" :key="opt.value"
                :class="[
                  'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border cursor-pointer text-xs transition-colors',
                  contactModal.channel === opt.value
                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                    : 'border-slate-200 hover:bg-slate-50 text-slate-700',
                ]">
                <input type="radio" :value="opt.value" v-model="contactModal.channel" class="hidden" />
                <i :class="`pi ${opt.icon} text-[10px]`" />
                <span class="font-medium">{{ opt.label }}</span>
              </label>
            </div>
          </div>
          <div>
            <label class="text-xs font-medium text-slate-500 mb-1 block">บันทึก (ไม่จำเป็น)</label>
            <textarea v-model="contactModal.note" rows="3"
              placeholder="เช่น: ลูกค้ารับสาย ยืนยันต่ออายุ นัดโทรกลับพรุ่งนี้"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-400 resize-none" />
          </div>
        </div>
        <footer class="px-5 py-3 border-t border-slate-200 flex items-center justify-end gap-2">
          <button type="button"
            class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm"
            :disabled="actionSaving === contactModal.row.policyId" @click="contactModal = null">
            ยกเลิก
          </button>
          <button type="button"
            class="px-4 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5"
            :disabled="actionSaving === contactModal.row.policyId" @click="submitContact">
            <i class="pi pi-check text-xs" v-if="actionSaving !== contactModal.row.policyId" />
            <i class="pi pi-spin pi-spinner text-xs" v-else />
            บันทึก
          </button>
        </footer>
      </div>
    </div>

    <!-- Send-notice confirm modal -->
    <div v-if="noticeModal" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4"
      @click.self="noticeModal = null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="text-lg font-semibold text-slate-900">ส่งอีเมลแจ้งต่ออายุ</div>
          <button type="button" class="text-slate-400 hover:text-slate-700 p-1" @click="noticeModal = null">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="p-5 space-y-3">
          <div class="text-sm text-slate-700">
            <div>{{ noticeModal.row.customerName || noticeModal.row.customerCode }}</div>
            <div class="text-xs text-slate-500 font-mono mt-0.5">{{ noticeModal.row.policyNo }}</div>
          </div>
          <div class="text-sm">
            <span class="text-slate-500">ปลายทาง:</span>
            <span class="ml-2 font-medium text-slate-900">
              {{ noticeModal.row.customerEmail || noticeModal.row.agentEmail || 'ไม่พบอีเมล' }}
            </span>
            <div v-if="!noticeModal.row.customerEmail && noticeModal.row.agentEmail"
              class="text-xs text-amber-600 mt-1">
              <i class="pi pi-info-circle text-[10px] mr-0.5" /> ลูกค้าไม่มีอีเมล — จะส่งถึงตัวแทนแทน
            </div>
          </div>
        </div>
        <footer class="px-5 py-3 border-t border-slate-200 flex items-center justify-end gap-2">
          <button type="button"
            class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm"
            :disabled="actionSaving === noticeModal.row.policyId" @click="noticeModal = null">
            ยกเลิก
          </button>
          <button type="button"
            class="px-4 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5"
            :disabled="actionSaving === noticeModal.row.policyId || (!noticeModal.row.customerEmail && !noticeModal.row.agentEmail)"
            @click="submitNotice">
            <i class="pi pi-send text-xs" v-if="actionSaving !== noticeModal.row.policyId" />
            <i class="pi pi-spin pi-spinner text-xs" v-else />
            ส่งอีเมล
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>
