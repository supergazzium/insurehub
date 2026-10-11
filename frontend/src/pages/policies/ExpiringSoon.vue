<script setup lang="ts">
import { onMounted, reactive, ref, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchExpiringSoon, sendRenewalNotice,
  type ExpiringPolicy, type ExpiringSoonMeta, type ExpiringSoonSummary,
} from '../../api/reports'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { fetchProductList, fetchProductTaxonomy, type ProductListRow, type ProductTaxonomyRow } from '../../api/products'
import { fetchAgentList, type AgentListRow } from '../../api/agents'
import SearchSelect, { type SearchOption } from '../../components/SearchSelect.vue'
import { ApiError } from '../../api/client'
import { toCsv, downloadCsv } from '../../util/csvExport'
import DateInput from '../../components/DateInput.vue'
import PolicyPeriodBadge from '../../components/PolicyPeriodBadge.vue'
import { fmtDate } from '../../util/dateFormat'
import { renewalStageMeta, RENEWAL_PIPELINE } from '../../utils/renewalStage'
import type { RenewalStage, ExpiringSoonQuery } from '../../api/reports'
import { followupResultMeta, followupChannelMeta, isFollowupOverdue } from '../../utils/renewalFollowup'

const router = useRouter()

/** Open a policy's renewal detail page (same tab). */
function openRenewalDetail(policyId: string): void {
  router.push({ name: 'policy-renewal', params: { id: policyId } })
}

const rows = ref<ExpiringPolicy[]>([])
const meta = ref<ExpiringSoonMeta | null>(null)
const summary = ref<ExpiringSoonSummary>({ totalInWindow: 0, urgentCount: 0, followupDueCount: 0 })
const loading = ref(false)
const error = ref<string | null>(null)

// #48 — rows after the client-side renewal-stage filters (stage dropdown +
// hide-renewed toggle). renewalStage is derived server-side per row, so the
// filtering happens here. Both the table and the board read displayRows.
const displayRows = computed<ExpiringPolicy[]>(() =>
  rows.value.filter((r) => {
    const stage = (r.renewalStage ?? 'not_started') as RenewalStage
    if (filters.renewalStage && stage !== filters.renewalStage) return false
    if (filters.hideRenewed && stage === 'renewed') return false
    return true
  }),
)

// ── View mode: table (default) or kanban board grouped by renewal stage ──
const viewMode = ref<'table' | 'board'>('table')

/** Rows grouped into the seven on-track pipeline columns. Off-track terminals
 *  (declined/expired) fold into the nearest column: expired→not_started,
 *  declined→renewed's column is wrong, so declined gets its own trailing note
 *  — simplest is to show declined under whatever it reached; here we drop
 *  declined/expired from the board's active columns and surface them via the
 *  stage pill inside each card. We map every row to one of RENEWAL_PIPELINE. */
const boardColumns = computed(() => {
  const buckets: Record<RenewalStage, ExpiringPolicy[]> = {
    not_started: [], contacted: [], quote_requested: [], quote_received: [],
    quote_prepared: [], quote_sent: [], renewed: [], declined: [], expired: [],
  }
  for (const r of displayRows.value) {
    const stage = (r.renewalStage ?? 'not_started') as RenewalStage
    ;(buckets[stage] ?? buckets.not_started).push(r)
  }
  // Board shows the 7 on-track columns; declined + expired collapse into the
  // first column so nothing is hidden (their pill still reads declined/expired).
  buckets.not_started = [...buckets.not_started, ...buckets.expired, ...buckets.declined]
  return RENEWAL_PIPELINE.map((stage) => ({
    stage,
    meta: renewalStageMeta(stage),
    rows: buckets[stage],
  }))
})

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
  writingAgentId: '',
  productCategory: '',
  productSubCategory: '',
  followupDue: false,
  insureType: '' as '' | 'life' | 'non-life' | 'tax',
  // #48 — renewal-stage filter. renewalStage is a derived field (not a stored
  // column), so this filters the loaded rows client-side. '' = every stage.
  renewalStage: '' as '' | RenewalStage,
  // #48 — "ต่ออายุไปแล้วน่าจะไม่ต้องแสดงแล้ว": default-hide rows already
  // renewed so the queue shows only what still needs work. Off shows them.
  hideRenewed: true,
})
const page = ref<number>(1)
const perPage = ref<number>(50)
const sortBy = ref<'expiryDate' | 'annualPremium' | 'customerName'>('expiryDate')
const sortDir = ref<'asc' | 'desc'>('asc')

// ── Persisted filter state ──────────────────────────────────────────────
// Everything the user tunes (filters / preset / sort / page-size) is
// persisted to localStorage so the next session picks up where they left
// off. Bumped version resets the schema on breaking changes.
const STORAGE_KEY = 'renewal-pipeline:v2'
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
// Load the plan dropdown's products for ONE carrier on demand. Loading all
// 900 products up front (a) exceeded the old perPage:500 cap — silently
// dropping 400 products so the plan filter was incomplete (#47) — and (b)
// was wasteful since the dropdown only ever shows the selected carrier's
// plans. Empty carrier → clear the list (productOptions returns [] anyway).
async function loadProducts(carrierId: string): Promise<void> {
  if (!carrierId) { allProducts.value = []; return }
  try {
    const res = await fetchProductList({ carrierId, perPage: 500, activeOnly: true })
    allProducts.value = res.data
  } catch { /* silent — dropdown just empty */ }
}
// Agents for the writing-agent filter (searchable). Loaded once; filtered
// client-side by SearchSelect's own type-to-search.
const allAgents = ref<AgentListRow[]>([])
async function loadAgents(): Promise<void> {
  // The filter should offer EVERY writing agent — including inactive ones,
  // since expiring policies were often written by agents no longer active.
  // The API caps perPage at 100, so page through until all rows are collected.
  try {
    const rows: AgentListRow[] = []
    let page = 1
    for (;;) {
      const res = await fetchAgentList({ perPage: 100, page })
      rows.push(...res.data)
      const m = res.meta
      if (!m || page >= m.last_page) break
      page += 1
    }
    allAgents.value = rows
  } catch { /* silent — dropdown just empty */ }
}
const agentOptions = computed<SearchOption[]>(() =>
  allAgents.value
    .map((a) => ({
      value: a.id,
      label: `${a.agentCode} — ${[a.firstName, a.lastName].filter(Boolean).join(' ') || a.nickname}`.trim(),
    }))
    .sort((x, y) => x.label.localeCompare(y.label, 'th')),
)
const carrierOptions = computed<Option[]>(() => {
  // Carrier API returns insureType capitalised ("Life"/"Non-Life"/"Tax")
  // while the filter value is lowercase ("life"/…), so compare case-
  // insensitively — otherwise the carrier dropdown comes up empty whenever
  // an insure-type is selected (the #47 "filter ใช้ไม่ได้" bug).
  const src = filters.insureType
    ? allCarriers.value.filter((c) => (c.insureType ?? '').toLowerCase() === filters.insureType)
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
const taxonomy = ref<ProductTaxonomyRow[]>([])
async function loadTaxonomy(): Promise<void> {
  try {
    taxonomy.value = (await fetchProductTaxonomy()).data
  } catch { taxonomy.value = [] }
}
const categoryOptions = computed<Option[]>(() => {
  const seen = new Set<string>()
  const out: Option[] = []
  for (const t of taxonomy.value) {
    if (seen.has(t.category)) continue
    seen.add(t.category)
    out.push({ id: t.category, label: t.category })
  }
  return out
})
const subCategoryOptions = computed<Option[]>(() => {
  if (!filters.productCategory) return []
  const seen = new Set<string>()
  const out: Option[] = []
  for (const t of taxonomy.value) {
    if (t.category !== filters.productCategory || !t.subcategory || seen.has(t.subcategory)) continue
    seen.add(t.subcategory)
    out.push({ id: t.subcategory, label: t.subcategory })
  }
  out.push({ id: '__none', label: 'ยังไม่ระบุหมวดย่อย' })
  return out
})

// Clear-filters UX
const hasActiveFilters = computed(() =>
  filters.q !== '' || filters.carrierId !== '' || filters.productId !== '' ||
  filters.writingAgentId !== '' || filters.productCategory !== '' || filters.productSubCategory !== '' ||
  filters.followupDue || filters.insureType !== '' ||
  // #48 — a stage pick, or un-hiding renewed (non-default), counts as active.
  filters.renewalStage !== '' || filters.hideRenewed !== true,
)
function clearFilters(): void {
  filters.q = ''
  filters.carrierId = ''; filters.productId = ''
  filters.writingAgentId = ''
  filters.productCategory = ''; filters.productSubCategory = ''
  filters.followupDue = false
  filters.insureType = ''
  filters.renewalStage = ''; filters.hideRenewed = true
  applyPreset(60)
  // A cleared filter shouldn't carry a stale multi-page selection.
  clearSelection()
}

// Cascading resets — carrier switch clears plan; insureType switch clears
// carrier + plan if they no longer make sense.
watch(() => filters.carrierId, (cid) => {
  filters.productId = ''
  void loadProducts(cid)   // #47 — reload the plan dropdown for the new carrier
})
watch(() => filters.productCategory, () => { filters.productSubCategory = '' })
watch(() => filters.insureType, () => {
  if (filters.carrierId && !carrierOptions.value.some((c) => c.id === filters.carrierId)) {
    filters.carrierId = ''
    filters.productId = ''
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
  // The two fields are independent now (no min/max caps), so a user can enter
  // a reversed range. Auto-swap so the query stays valid instead of returning
  // nothing. Guarded by the inequality check so this never re-triggers itself.
  if (from && to && from > to) {
    filters.fromDate = to
    filters.toDate = from
  }
})

function queryParams(): ExpiringSoonQuery {
  return {
    from: filters.fromDate || undefined,
    to: filters.toDate || undefined,
    q: filters.q || undefined,
    carrierId: filters.carrierId || undefined,
    productId: filters.productId || undefined,
    writingAgentId: filters.writingAgentId || undefined,
    productCategory: filters.productCategory || undefined,
    productSubCategory: filters.productSubCategory || undefined,
    followupDue: filters.followupDue || undefined,
    insureType: filters.insureType || undefined,
  }
}

async function fetchAllRows(): Promise<ExpiringPolicy[]> {
  const all: ExpiringPolicy[] = []
  for (let p = 1; ; p += 1) {
    const res = await fetchExpiringSoon({ ...queryParams(), page: p, perPage: 500, sortBy: sortBy.value, sortDir: sortDir.value })
    all.push(...res.data)
    if (p >= (res.meta?.lastPage ?? 1)) break
  }
  return all
}

function toggleFollowupDue(): void {
  filters.followupDue = !filters.followupDue
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchExpiringSoon({
      ...queryParams(),
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
    summary.value = { totalInWindow: 0, urgentCount: 0, followupDueCount: 0 }
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
    filters.carrierId, filters.productId, filters.writingAgentId, filters.productCategory, filters.productSubCategory,
    filters.followupDue, filters.insureType,
    perPage.value,
  ],
  scheduleReload,
)

// Persist every user-tunable knob so the next session resumes here.
watch(
  () => [
    filters.q, filters.fromDate, filters.toDate,
    filters.carrierId, filters.productId, filters.writingAgentId, filters.productCategory, filters.productSubCategory,
    filters.followupDue, filters.insureType,
    preset.value, sortBy.value, sortDir.value, perPage.value,
  ],
  saveState,
  { deep: true },
)

onMounted(() => {
  restoreState()
  void loadCarriers()
  // Restore the plan list for a persisted carrier so the dropdown isn't
  // empty on first paint; otherwise it stays empty until a carrier is picked.
  void loadProducts(filters.carrierId)
  void loadAgents()
  void loadTaxonomy()
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
// header-row "select all on page" checkbox tri-state. Operates on the
// VISIBLE (filtered) rows so hidden/renewed rows are never bulk-selected (#48).
const allOnPageSelected = computed(() =>
  displayRows.value.length > 0 && displayRows.value.every((r) => selected.value.has(r.policyId)),
)
const someOnPageSelected = computed(() =>
  !allOnPageSelected.value && displayRows.value.some((r) => selected.value.has(r.policyId)),
)
function toggleAllOnPage(): void {
  const next = new Set(selected.value)
  if (allOnPageSelected.value) {
    for (const r of displayRows.value) next.delete(r.policyId)
  } else {
    for (const r of displayRows.value) next.add(r.policyId)
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
  const all = await fetchAllRows()
  const picked = all.filter((r) => selected.value.has(r.policyId))
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
    { header: 'Product name', value: (r) => r.productName ?? '' },
    { header: 'Category', value: (r) => [r.productCategory, r.productSubCategory].filter(Boolean).join(' / ') },
    { header: 'License plate', value: (r) => r.motorLicenseNo ?? '' },
    { header: 'Annual premium', value: (r) => r.annualPremium.toFixed(2) },
    { header: 'Renewal stage', value: (r) => renewalStageMeta(r.renewalStage).label },
    { header: 'Last follow-up', value: (r) => followupCsv(r) },
    { header: 'Next follow-up', value: (r) => r.lastFollowup?.nextAt ?? '' },
  ])
  downloadCsv(csv, `renewals-selected-${new Date().toISOString().slice(0, 10)}.csv`)
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

// Only the MOST RECENT step for the status cell — keeps the column clean
// instead of stacking every historical timestamp. Picks the furthest-reached
// action that has a timestamp (matching the stage order), returns its label +
// "how long ago", or null when nothing has happened yet.
function latestStatusLine(r: ExpiringPolicy): { label: string; when: string } | null {
  const steps: Array<[string | null | undefined, string]> = [
    [r.renewalStage === 'renewed' ? r.renewalStartedAt : null, 'เริ่มต่ออายุ'],
    [r.quoteSentAt, 'ส่งใบเสนอราคา'],
    [r.quotePreparedAt, 'จัดทำใบเสนอราคา'],
    [r.quoteReceivedAt, 'รับใบเสนอราคา'],
    [r.quoteRequestedAt, 'ขอใบเสนอราคา'],
    [r.lastNoticeSentAt, 'ส่งอีเมล'],
    [r.lastContactedAt, 'ติดต่อ'],
  ]
  for (const [ts, label] of steps) {
    if (ts) return { label, when: relativeDays(ts) }
  }
  return null
}

function followupCsv(r: ExpiringPolicy): string {
  const f = r.lastFollowup
  if (!f) return ''
  return [f.at?.slice(0, 10), followupChannelMeta(f.channel).label, followupResultMeta(f.result).label, f.reasonLabel, f.note]
    .filter(Boolean).join(' · ')
}

// CSV export — fetch the entire filtered set (up to the server cap) with
// one extra request instead of only what's on the current page. Uses the
// same filter set as the visible table.
const exportingCsv = ref(false)
async function exportCsv(): Promise<void> {
  if (exportingCsv.value) return
  exportingCsv.value = true
  try {
    const all = await fetchAllRows()
    const csv = toCsv(all, [
      { header: 'Application', value: (r) => r.applicationNo },
      { header: 'Policy', value: (r) => r.policyNo },
      { header: 'Expiry', value: (r) => r.expiryDate },
      { header: 'Days remaining', value: (r) => r.daysRemaining },
      { header: 'Customer', value: (r) => `${r.customerCode ?? ''} ${r.customerName ?? ''}`.trim() },
      { header: 'Customer email', value: (r) => r.customerEmail ?? '' },
      { header: 'Agent', value: (r) => `${r.agentCode ?? ''} ${r.agentName ?? ''}`.trim() },
      { header: 'Carrier', value: (r) => r.carrierCode },
      { header: 'Product', value: (r) => r.productCode },
      { header: 'Product name', value: (r) => r.productName ?? '' },
      { header: 'Category', value: (r) => [r.productCategory, r.productSubCategory].filter(Boolean).join(' / ') },
      { header: 'License plate', value: (r) => r.motorLicenseNo ?? '' },
      { header: 'Annual premium', value: (r) => r.annualPremium.toFixed(2) },
      { header: 'Renewal stage', value: (r) => renewalStageMeta(r.renewalStage).label },
      { header: 'Last follow-up', value: (r) => followupCsv(r) },
      { header: 'Next follow-up', value: (r) => r.lastFollowup?.nextAt ?? '' },
      { header: 'Quote requested', value: (r) => r.quoteRequestedAt ?? '' },
      { header: 'Quote received', value: (r) => r.quoteReceivedAt ?? '' },
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
    if (filters.writingAgentId) qs.set('writingAgentId', filters.writingAgentId)
    if (filters.productCategory) qs.set('productCategory', filters.productCategory)
    if (filters.productSubCategory) qs.set('productSubCategory', filters.productSubCategory)
    if (filters.followupDue) qs.set('followupDue', '1')
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
        <h1 class="text-2xl font-semibold text-slate-900">งานต่ออายุกรมธรรม์</h1>
        <p class="text-slate-500 mt-1 text-sm">
          กรมธรรม์ที่ยังคุ้มครองอยู่และจะครบกำหนดในช่วงเวลาที่เลือก
        </p>
      </div>
      <div class="flex items-center gap-2">
        <!-- Table / board view toggle -->
        <div class="inline-flex rounded-lg border border-slate-200 overflow-hidden text-sm">
          <button type="button"
            :class="['px-3 py-1.5 flex items-center gap-1', viewMode === 'table' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50']"
            @click="viewMode = 'table'">
            <i class="pi pi-list text-xs" /> ตาราง
          </button>
          <button type="button"
            :class="['px-3 py-1.5 flex items-center gap-1', viewMode === 'board' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50']"
            @click="viewMode = 'board'">
            <i class="pi pi-th-large text-xs" /> บอร์ด
          </button>
        </div>
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

    <!-- Workflow legend — the renewal steps in order, so a new worker sees the
         whole flow at a glance and knows what each status means. -->
    <div class="flex items-center gap-1 flex-wrap text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
      <span class="text-slate-400 mr-1">ขั้นตอน:</span>
      <template v-for="(stage, i) in RENEWAL_PIPELINE" :key="stage">
        <span :class="['inline-flex items-center gap-1 px-1.5 py-0.5 rounded', renewalStageMeta(stage).badgeClass]">
          <i :class="['pi', renewalStageMeta(stage).icon, 'text-[9px]']" />
          {{ renewalStageMeta(stage).label }}
        </span>
        <i v-if="i < RENEWAL_PIPELINE.length - 1" class="pi pi-angle-right text-[9px] text-slate-300" />
      </template>
    </div>

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
        <span class="mx-1 text-slate-300">·</span>
        <button type="button" @click="toggleFollowupDue" data-testid="followup-due-chip"
          :class="[
            'px-3 py-1 rounded-full border text-xs transition-colors flex items-center gap-1',
            filters.followupDue
              ? 'border-amber-500 bg-amber-50 text-amber-800 font-medium'
              : 'border-slate-200 text-slate-600 hover:bg-amber-50 hover:border-amber-200',
          ]">
          <i class="pi pi-bell text-[10px]" /> ถึงกำหนดติดตาม ({{ (summary.followupDueCount ?? 0).toLocaleString() }})
        </button>
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
              <!-- No max cap — this is a FUTURE window picker (what expires ahead). -->
              <DateInput v-model="filters.fromDate" />
            </div>
            <span class="text-slate-400 text-xs">ถึง</span>
            <div class="flex-1">
              <DateInput v-model="filters.toDate" />
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
          <label class="text-xs font-medium text-slate-500 mb-1 block">หมวดผลิตภัณฑ์</label>
          <select v-model="filters.productCategory" data-testid="filter-product-category"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
            <option value="">ทั้งหมด</option>
            <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
          </select>
        </div>
        <div class="md:col-span-1 flex md:justify-end">
          <button v-if="hasActiveFilters" type="button" @click="clearFilters"
            class="w-full md:w-auto px-3 py-1.5 rounded-lg border border-rose-200 text-xs text-rose-600 hover:bg-rose-50">
            ล้าง
          </button>
        </div>

        <div class="md:col-span-4">
          <label class="text-xs font-medium text-slate-500 mb-1 block">
            หมวดย่อย
            <span v-if="!filters.productCategory" class="text-slate-400 ml-1">(เลือกหมวดก่อน)</span>
          </label>
          <select v-model="filters.productSubCategory" :disabled="!filters.productCategory" data-testid="filter-product-subcategory"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white disabled:bg-slate-50">
            <option value="">ทั้งหมด</option>
            <option v-for="c in subCategoryOptions" :key="c.id" :value="c.id">{{ c.label }}</option>
          </select>
        </div>

        <!-- Writing-agent filter (searchable) -->
        <div class="md:col-span-2">
          <label class="text-xs font-medium text-slate-500 mb-1 block">ตัวแทน (ผู้เขียนกรมธรรม์)</label>
          <SearchSelect v-model="filters.writingAgentId" :options="agentOptions"
            placeholder="พิมพ์เพื่อค้นหาตัวแทน…" />
        </div>

        <!-- #48 — renewal-stage filter + hide-renewed toggle -->
        <div class="md:col-span-1">
          <label class="text-xs font-medium text-slate-500 mb-1 block">สถานะต่ออายุ</label>
          <select v-model="filters.renewalStage" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
            <option value="">ทั้งหมด</option>
            <option v-for="s in RENEWAL_PIPELINE" :key="s" :value="s">{{ renewalStageMeta(s).label }}</option>
            <option value="declined">{{ renewalStageMeta('declined').label }}</option>
            <option value="expired">{{ renewalStageMeta('expired').label }}</option>
          </select>
        </div>
        <div class="md:col-span-1 flex items-end">
          <label class="flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer select-none pb-1.5">
            <input type="checkbox" v-model="filters.hideRenewed"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-400" />
            ซ่อนที่ต่ออายุแล้ว
          </label>
        </div>
      </div>
    </section>

    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">รวมทั้งหมด (ตามฟิลเตอร์)</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ summary.totalInWindow.toLocaleString() }}</div>
        <!-- #48 — when a stage filter hides some loaded rows, show what's visible. -->
        <div v-if="displayRows.length !== rows.length" class="text-xs text-brand-600 mt-0.5">
          แสดง {{ displayRows.length.toLocaleString() }} รายการ (กรองตามสถานะ)
        </div>
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
      <button type="button" @click="toggleFollowupDue" data-testid="followup-due-card"
        :class="['card p-4 text-left hover:ring-2 hover:ring-amber-200 transition-shadow cursor-pointer', filters.followupDue ? 'ring-2 ring-amber-300' : '']"
        title="คลิกเพื่อดูเฉพาะรายการที่ถึงกำหนดติดตามแล้ว (ไม่ขึ้นกับช่วงวันหมดอายุ)">
        <div class="text-xs uppercase tracking-wider text-slate-400 flex items-center justify-between">
          <span>ถึงกำหนดติดตาม</span>
          <i class="pi pi-bell text-[10px] text-slate-300" />
        </div>
        <div class="text-2xl font-semibold text-amber-600 mt-1">{{ (summary.followupDueCount ?? 0).toLocaleString() }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ filters.followupDue ? 'กำลังกรองเฉพาะรายการที่ถึงกำหนด' : 'นัดติดตามวันนี้หรือเลยกำหนด' }}</div>
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

    <section v-if="viewMode === 'table'" class="card overflow-hidden">
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
              <th class="px-4 py-2 text-left">ทะเบียน / ผลิตภัณฑ์</th>
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
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in displayRows" :key="r.policyId"
              :class="['hover:bg-slate-50 cursor-pointer', isSelected(r.policyId) ? 'bg-brand-50/40' : '']"
              @click="openRenewalDetail(r.policyId)">
              <td class="px-3 py-2 w-8" @click.stop>
                <input type="checkbox" :checked="isSelected(r.policyId)"
                  @change="toggleRow(r.policyId)"
                  class="accent-brand-500 cursor-pointer" />
              </td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.applicationNo ?? '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">
                <div>{{ r.policyNo ?? '—' }}</div>
                <PolicyPeriodBadge v-if="r.periodLabel" :label="r.periodLabel" class="mt-0.5" />
              </td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ r.customerName || r.customerCode }}</div>
                <div class="text-xs text-slate-500">
                  <span>{{ r.customerCode }}</span>
                  <span v-if="r.customerEmail" class="ml-1">· {{ r.customerEmail }}</span>
                </div>
              </td>
              <td class="px-4 py-2 max-w-[240px]" data-testid="col-product">
                <template v-if="r.motorLicenseNo">
                  <div class="text-slate-900 font-medium whitespace-nowrap">{{ r.motorLicenseNo }}</div>
                  <div class="text-xs text-slate-500 truncate" :title="r.productName ?? ''">{{ r.productName || r.productCode || '—' }}</div>
                </template>
                <template v-else>
                  <div class="text-slate-900 truncate" :title="r.productName ?? ''">{{ r.productName || r.productCode || '—' }}</div>
                  <div class="text-xs text-slate-500 truncate">{{ r.productSubCategory || r.productCategory || '' }}</div>
                </template>
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
                <!-- Derived pipeline stage — the single source of "where is this
                     renewal" for the operator. Detail timestamps sit below. -->
                <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium', renewalStageMeta(r.renewalStage).badgeClass]">
                  <i :class="['pi', renewalStageMeta(r.renewalStage).icon, 'text-[10px]']" />
                  {{ renewalStageMeta(r.renewalStage).label }}
                </span>
                <!-- Only the latest step, so the column stays clean -->
                <div v-if="r.lastFollowup" class="mt-1 flex items-center gap-1 flex-wrap" data-testid="col-last-followup">
                  <span :class="['inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium', followupResultMeta(r.lastFollowup.result).badgeClass]">
                    <i :class="['pi', followupChannelMeta(r.lastFollowup.channel).icon, 'text-[9px]']" />
                    {{ followupResultMeta(r.lastFollowup.result).label }}
                  </span>
                  <span class="text-slate-500">{{ relativeDays(r.lastFollowup.at) }}</span>
                </div>
                <div v-else-if="latestStatusLine(r)" class="mt-1 text-slate-500">
                  {{ latestStatusLine(r)!.label }} · {{ latestStatusLine(r)!.when }}
                </div>
                <div v-if="r.lastFollowup?.nextAt" :class="['mt-0.5', isFollowupOverdue(r.lastFollowup.nextAt) ? 'text-rose-600 font-medium' : 'text-slate-500']">
                  <i class="pi pi-calendar text-[9px]" /> นัด {{ fmtDate(r.lastFollowup.nextAt) }}
                </div>
              </td>
            </tr>
            <tr v-if="!loading && displayRows.length === 0">
              <td colspan="10" class="px-4 py-6 text-center text-slate-500">
                <template v-if="rows.length > 0">
                  ไม่มีกรมธรรม์ตรงกับตัวกรองสถานะ — ลองล้างตัวกรอง หรือเปิด "ต่ออายุแล้ว"
                </template>
                <template v-else>{{ filters.followupDue ? 'ไม่มีรายการที่ถึงกำหนดติดตาม' : 'ไม่พบกรมธรรม์ที่จะครบกำหนดในช่วงเวลานี้' }}</template>
              </td>
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

    <!-- Board view — pipeline columns grouped by renewal stage -->
    <section v-else class="overflow-x-auto pb-2">
      <div class="flex gap-3 min-w-max">
        <div v-for="col in boardColumns" :key="col.stage" class="w-64 shrink-0">
          <div class="flex items-center justify-between mb-2 px-1">
            <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium', col.meta.badgeClass]">
              <i :class="['pi', col.meta.icon, 'text-[10px]']" />
              {{ col.meta.label }}
            </span>
            <span class="text-xs text-slate-400">{{ col.rows.length }}</span>
          </div>
          <div class="space-y-2">
            <div v-for="r in col.rows" :key="r.policyId"
              class="card p-3 text-sm cursor-pointer hover:ring-1 hover:ring-brand-200"
              @click="openRenewalDetail(r.policyId)">
              <div class="font-medium text-slate-900 truncate">{{ r.customerName || r.customerCode || '—' }}</div>
              <div class="text-xs text-slate-500 font-mono mt-0.5 truncate flex items-center gap-1">
                {{ r.policyNo || r.applicationNo || '—' }}
                <PolicyPeriodBadge v-if="r.periodLabel" :label="r.periodLabel" />
              </div>
              <div class="text-xs text-slate-500 mt-1 truncate">{{ r.carrierName || r.carrierCode || '—' }}</div>
              <div class="text-xs text-slate-700 mt-0.5 truncate">
                <span v-if="r.motorLicenseNo" class="font-medium">{{ r.motorLicenseNo }} · </span>{{ r.productName || r.productCode || '—' }}
              </div>
              <div v-if="r.lastFollowup?.nextAt" :class="['text-[11px] mt-1', isFollowupOverdue(r.lastFollowup.nextAt) ? 'text-rose-600 font-medium' : 'text-slate-500']">
                <i class="pi pi-calendar text-[9px]" /> นัด {{ fmtDate(r.lastFollowup.nextAt) }}
              </div>
              <div class="flex items-center justify-between mt-2">
                <span :class="['inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium', badge(r.daysRemaining).cls]">
                  {{ r.daysRemaining }} วัน
                </span>
                <span class="text-xs text-slate-600">{{ fmtBaht(r.annualPremium) }}</span>
              </div>
            </div>
            <div v-if="!col.rows.length" class="text-center text-xs text-slate-300 py-4 border border-dashed border-slate-200 rounded-lg">
              —
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
