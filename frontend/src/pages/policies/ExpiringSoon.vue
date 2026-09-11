<script setup lang="ts">
import { onMounted, onUnmounted, reactive, ref, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchExpiringSoon, markRenewalContacted, markRenewalStarted, sendRenewalNotice,
  requestRenewalQuote, sendRenewalQuote, declineRenewal, markRenewalStage,
  type ExpiringPolicy, type ExpiringSoonMeta, type ExpiringSoonSummary,
  type ManualStage,
} from '../../api/reports'
import {
  fetchCarrierList, fetchCarrierContacts, createCarrierContact, updateCarrierContact, deleteCarrierContact,
  type CarrierListRow, type CarrierContact,
} from '../../api/carriers'
import { fetchProductList, type ProductListRow } from '../../api/products'
import {
  uploadPolicyDocument, fetchPolicyDocuments, downloadPolicyDocument, deletePolicyDocument,
  type PolicyDocumentRow,
} from '../../api/policies'
import { useRenewalQuote, emptyRenewalQuoteForm, type RenewalQuoteForm } from '../../composables/useRenewalQuote'
import { ApiError } from '../../api/client'
import { toCsv, downloadCsv } from '../../util/csvExport'
import DateInput from '../../components/DateInput.vue'
import { fmtDate } from '../../util/dateFormat'
import { renewalStageMeta, renewalNextAction, RENEWAL_PIPELINE, type RenewalNextAction } from '../../utils/renewalStage'
import type { RenewalStage } from '../../api/reports'

const router = useRouter()

/** Open a policy's full-page edit in a NEW TAB (/policies/:id/edit) so the
 *  operator keeps the renewal pipeline they're working in. Resolved through
 *  the router so the /insurehub base path is applied. */
function openPolicyInNewTab(policyId: string): void {
  const href = router.resolve({ name: 'policy-edit', params: { id: policyId } }).href
  window.open(href, '_blank', 'noopener')
}

const rows = ref<ExpiringPolicy[]>([])
const meta = ref<ExpiringSoonMeta | null>(null)
const summary = ref<ExpiringSoonSummary>({ totalInWindow: 0, urgentCount: 0 })
const loading = ref(false)
const error = ref<string | null>(null)

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
  for (const r of rows.value) {
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
  // The two fields are independent now (no min/max caps), so a user can enter
  // a reversed range. Auto-swap so the query stays valid instead of returning
  // nothing. Guarded by the inequality check so this never re-triggers itself.
  if (from && to && from > to) {
    filters.fromDate = to
    filters.toDate = from
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
  document.addEventListener('click', closeMoreMenu)
})
onUnmounted(() => {
  document.removeEventListener('click', closeMoreMenu)
})
// Close the row "⋯ more" popover on any outside click. The buttons that open
// it stop propagation, so this only fires for genuine outside clicks.
function closeMoreMenu(): void {
  moreMenuFor.value = null
}

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
    { header: 'Renewal stage', value: (r) => renewalStageMeta(r.renewalStage).label },
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
// Renewal quotation pipeline — Phase B: "request quote" modal. Recipient
// toggles between the insurer (carrier) and the writing agent; the message
// is a preset the operator can edit before sending.
// Quotes only ever come FROM the insurance company, so this popup is
// carrier-only (the "ตัวแทน" recipient option was removed). The operator picks
// the destination from the carrier's editable email list and can add / edit /
// delete those emails inline.
const quoteRequestModal = ref<{
  row: ExpiringPolicy
  subject: string
  message: string
  to: string
} | null>(null)
const quoteCopied = ref(false)
// The carrier's contact list for the open modal, plus loading + inline-editor state.
const quoteContacts = ref<CarrierContact[]>([])
const quoteContactsLoading = ref(false)
const quoteContactEdit = ref<{ id: string | null; email: string; name: string } | null>(null)

/** Default subject, prefilled from the policy row. */
function defaultQuoteSubject(r: ExpiringPolicy): string {
  const ref = r.policyNo || r.applicationNo || '—'
  return `ขอใบเสนอราคาต่ออายุกรมธรรม์ ${ref} — InsureHub`
}

/** Default preset body (always addressed to the insurer). */
function defaultQuoteMessage(r: ExpiringPolicy): string {
  const ref = r.policyNo || r.applicationNo || '—'
  return `เรียน ${r.carrierName || 'บริษัทประกัน'}\n\n`
    + `InsureHub ขอความอนุเคราะห์ใบเสนอราคาต่ออายุกรมธรรม์เลขที่ ${ref} `
    + `ของลูกค้า ${r.customerName || '—'} สำหรับระยะเวลาความคุ้มครองปีถัดไป\n\n`
    + `ขอบคุณครับ/ค่ะ`
}

// A `mailto:` link prefilled with the chosen destination, subject, and body —
// for the "send it myself" path.
const quoteMailtoHref = computed<string>(() => {
  const m = quoteRequestModal.value
  if (!m) return '#'
  const params = new URLSearchParams({ subject: m.subject, body: m.message })
  return `mailto:${encodeURIComponent(m.to)}?${params.toString()}`
})

/** Load the carrier's email list and default the destination to the primary
 *  (or first) contact, falling back to the carrier's own email field. */
async function loadQuoteContacts(r: ExpiringPolicy): Promise<void> {
  quoteContacts.value = []
  if (!r.carrierId) return
  quoteContactsLoading.value = true
  try {
    const res = await fetchCarrierContacts(r.carrierId)
    quoteContacts.value = res.data.filter((c) => c.email)
    if (quoteRequestModal.value && !quoteRequestModal.value.to) {
      const primary = quoteContacts.value.find((c) => c.isPrimary) ?? quoteContacts.value[0]
      quoteRequestModal.value.to = primary?.email ?? r.carrierEmail ?? ''
    }
  } catch { /* list stays empty — operator can still type/add an email */ }
  finally { quoteContactsLoading.value = false }
}

function openQuoteRequest(r: ExpiringPolicy): void {
  quoteCopied.value = false
  quoteContactEdit.value = null
  quoteRequestModal.value = {
    row: r,
    subject: defaultQuoteSubject(r),
    message: defaultQuoteMessage(r),
    to: r.carrierEmail ?? '',
  }
  void loadQuoteContacts(r)
}

// ── Inline email-list management for the carrier ───────────────────────────
function startAddContact(): void {
  quoteContactEdit.value = { id: null, email: '', name: '' }
}
function startEditContact(c: CarrierContact): void {
  quoteContactEdit.value = { id: c.id, email: c.email, name: [c.firstName, c.lastName].filter(Boolean).join(' ') }
}
async function saveContactEdit(): Promise<void> {
  const m = quoteRequestModal.value
  const e = quoteContactEdit.value
  if (!m || !e || !m.row.carrierId) return
  const email = e.email.trim()
  if (!email) return
  const [firstName, ...rest] = e.name.trim().split(/\s+/)
  const payload = { email, firstName: firstName || '', lastName: rest.join(' ') }
  try {
    if (e.id) {
      await updateCarrierContact(m.row.carrierId, e.id, payload)
    } else {
      await createCarrierContact(m.row.carrierId, payload)
    }
    quoteContactEdit.value = null
    await loadQuoteContacts(m.row)
    m.to = email // select the just-saved address
  } catch (err: unknown) {
    flash(m.row.policyId, false, err instanceof ApiError ? err.message : 'บันทึกอีเมลล้มเหลว')
  }
}
async function removeContact(c: CarrierContact): Promise<void> {
  const m = quoteRequestModal.value
  if (!m || !m.row.carrierId) return
  try {
    await deleteCarrierContact(m.row.carrierId, c.id)
    if (m.to === c.email) m.to = ''
    await loadQuoteContacts(m.row)
  } catch (err: unknown) {
    flash(m.row.policyId, false, err instanceof ApiError ? err.message : 'ลบอีเมลล้มเหลว')
  }
}

/** Copy the full email (to / subject / body) to the clipboard. */
async function copyQuoteToClipboard(): Promise<void> {
  const m = quoteRequestModal.value
  if (!m) return
  const text = `ถึง: ${m.to || '(ยังไม่เลือกอีเมล)'}\nหัวข้อ: ${m.subject}\n\n${m.message}`
  try {
    await navigator.clipboard.writeText(text)
    quoteCopied.value = true
    setTimeout(() => { quoteCopied.value = false }, 2000)
  } catch { /* clipboard blocked — user can still select the text manually */ }
}

/** Submit — `mode` decides whether the APP sends the email ('system') or the
 *  operator sent it themselves and we just log it ('manual'). */
async function submitQuoteRequest(mode: 'system' | 'manual'): Promise<void> {
  if (!quoteRequestModal.value) return
  const { row: r, subject, message } = quoteRequestModal.value
  actionSaving.value = r.policyId
  try {
    await requestRenewalQuote(r.policyId, {
      recipient: 'carrier', mode,
      subject: subject.trim() || undefined,
      message: message.trim() || undefined,
    })
    r.quoteRequestedAt = new Date().toISOString()
    r.renewalStage = 'quote_requested'
    flash(r.policyId, true, mode === 'manual' ? 'บันทึกว่าส่งเองแล้ว' : 'ส่งคำขอแล้ว')
    quoteRequestModal.value = null
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'ดำเนินการล้มเหลว')
  } finally { actionSaving.value = null }
}

// ── Manual stage marker ────────────────────────────────────────────────────
// Every popup has a "just mark this step done" escape hatch. This calls the
// mark-stage endpoint (no email/upload side-effect), updates the row's stage +
// the matching timestamp optimistically, and closes whatever modal is open.
const STAGE_TS_FIELD: Record<ManualStage, keyof ExpiringPolicy> = {
  contacted: 'lastContactedAt',
  quote_requested: 'quoteRequestedAt',
  quote_received: 'quoteReceivedAt',
  quote_prepared: 'quotePreparedAt',
  quote_sent: 'quoteSentAt',
  renewed: 'renewalStartedAt',
  declined: 'renewalDeclinedAt',
}
async function manualMark(r: ExpiringPolicy, stage: ManualStage, closeModal?: () => void): Promise<void> {
  actionSaving.value = r.policyId
  try {
    await markRenewalStage(r.policyId, stage)
    ;(r as unknown as Record<string, unknown>)[STAGE_TS_FIELD[stage]] = new Date().toISOString()
    r.renewalStage = stage
    flash(r.policyId, true, 'บันทึกสถานะแล้ว')
    closeModal?.()
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'บันทึกสถานะล้มเหลว')
  } finally { actionSaving.value = null }
}

// ── Phase C — upload the carrier's returned quotation ──────────────────────
// A hidden <input type="file"> is triggered per row. We stash which row asked
// so the change handler knows where to attach the file. On success the backend
// logs `renewalQuoteReceived`, advancing the stage to `quote_received`.
const quoteUploadInput = ref<HTMLInputElement | null>(null)
const quoteUploadRow = ref<ExpiringPolicy | null>(null)

function triggerQuoteUpload(r: ExpiringPolicy): void {
  quoteUploadRow.value = r
  quoteUploadInput.value?.click()
}

async function onQuoteFileSelected(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  const r = quoteUploadRow.value
  // Reset the input immediately so re-selecting the same file re-fires change.
  input.value = ''
  if (!file || !r) return
  actionSaving.value = r.policyId
  try {
    await uploadPolicyDocument(r.policyId, 'renewal_quote_carrier', file)
    r.quoteReceivedAt = new Date().toISOString()
    r.renewalStage = 'quote_received'
    flash(r.policyId, true, 'อัปโหลดใบเสนอราคาแล้ว')
  } catch (err: unknown) {
    flash(r.policyId, false, err instanceof ApiError ? err.message : 'อัปโหลดล้มเหลว')
  } finally {
    actionSaving.value = null
    quoteUploadRow.value = null
  }
}

// ── View uploaded quotation files ──────────────────────────────────────────
// Lists the stored quote documents for a policy (carrier + InsureHub) so the
// operator can open/download them when revisiting the case later.
const filesModal = ref<{ row: ExpiringPolicy; docs: PolicyDocumentRow[] } | null>(null)
const filesLoading = ref(false)

async function openFiles(r: ExpiringPolicy): Promise<void> {
  filesModal.value = { row: r, docs: [] }
  filesLoading.value = true
  try {
    // Pull both quote kinds; sorted newest-first by the backend.
    const [carrier, insurehub] = await Promise.all([
      fetchPolicyDocuments(r.policyId, 'renewal_quote_carrier'),
      fetchPolicyDocuments(r.policyId, 'renewal_quote_insurehub'),
    ])
    if (filesModal.value) filesModal.value.docs = [...carrier.data, ...insurehub.data]
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'โหลดเอกสารล้มเหลว')
  } finally { filesLoading.value = false }
}

// Authenticated open / download — the API needs the Bearer token, which a plain
// link can't send, so route through downloadPolicyDocument.
const docBusy = ref<string | null>(null)  // docId currently opening/downloading
async function openDoc(policyId: string, d: PolicyDocumentRow, mode: 'open' | 'download'): Promise<void> {
  docBusy.value = d.id
  try {
    await downloadPolicyDocument(policyId, d.id, d.fileName, mode)
  } catch (e: unknown) {
    flash(policyId, false, e instanceof Error ? e.message : 'ดาวน์โหลดไม่สำเร็จ')
  } finally { docBusy.value = null }
}
function docTypeLabel(type: string): string {
  if (type === 'renewal_quote_carrier') return 'ใบเสนอราคาจากบริษัทประกัน'
  if (type === 'renewal_quote_insurehub') return 'ใบเสนอราคา InsureHub'
  return type
}
/** True when a row has any quote file worth showing (received or later). */
function hasQuoteFiles(r: ExpiringPolicy): boolean {
  return !!(r.quoteReceivedAt || r.quotePreparedAt || r.quoteSentAt)
}

// Delete a stored document (with confirm) and refresh the files list. Removing
// the file doesn't rewind the stage — the milestone still happened; regenerate
// to attach a fresh one.
async function deleteDoc(policyId: string, d: PolicyDocumentRow): Promise<void> {
  if (!window.confirm(`ลบไฟล์ "${d.fileName}"?`)) return
  docBusy.value = d.id
  try {
    await deletePolicyDocument(policyId, d.id)
    if (filesModal.value) filesModal.value.docs = filesModal.value.docs.filter((x) => x.id !== d.id)
    flash(policyId, true, 'ลบไฟล์แล้ว')
  } catch (e: unknown) {
    flash(policyId, false, e instanceof ApiError ? e.message : 'ลบไฟล์ล้มเหลว')
  } finally { docBusy.value = null }
}

// "Edit" an InsureHub quote = delete the old file then open the generator to
// make a new one. Closes the files modal and opens the prepare modal.
async function regenerateInsurehubQuote(r: ExpiringPolicy, d: PolicyDocumentRow): Promise<void> {
  if (!window.confirm('สร้างใบเสนอราคา InsureHub ใหม่ และลบฉบับเดิม?')) return
  docBusy.value = d.id
  try {
    await deletePolicyDocument(r.policyId, d.id)
    flash(r.policyId, true, 'ลบฉบับเดิมแล้ว — กรอกข้อมูลเพื่อสร้างใหม่')
    filesModal.value = null
    openPrepareQuote(r)
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'ดำเนินการล้มเหลว')
  } finally { docBusy.value = null }
}

// ── Phase D — generate the InsureHub-branded quotation ─────────────────────
// A manual-entry form (prefilled from the policy) → renders a PDF via the
// shared quotation renderer → uploads it as `renewal_quote_insurehub`, which
// the backend turns into a `renewalQuotePrepared` event (stage quote_prepared).
const renewalQuote = useRenewalQuote()
const prepareModal = ref<{ row: ExpiringPolicy; form: RenewalQuoteForm } | null>(null)
const preparing = ref(false)

function openPrepareQuote(r: ExpiringPolicy): void {
  prepareModal.value = { row: r, form: emptyRenewalQuoteForm(r) }
}

/** Generate the PDF, optionally download it, then upload + advance the stage. */
async function submitPrepareQuote(alsoDownload: boolean): Promise<void> {
  if (!prepareModal.value) return
  const { row: r, form } = prepareModal.value
  preparing.value = true
  actionSaving.value = r.policyId
  try {
    const quotation = renewalQuote.buildQuotation(r, form)
    const file = await renewalQuote.renderToFile(quotation)
    if (alsoDownload) {
      // Reuse the same blob the File wraps, no re-render. Append the anchor to
      // the DOM and defer the revoke so the browser has time to start the
      // download — revoking synchronously right after click() cancels it in
      // several browsers.
      const url = URL.createObjectURL(file)
      const a = document.createElement('a')
      a.href = url
      a.download = file.name
      a.rel = 'noopener'
      document.body.appendChild(a)
      a.click()
      a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 10_000)
    }
    await uploadPolicyDocument(r.policyId, 'renewal_quote_insurehub', file)
    r.quotePreparedAt = new Date().toISOString()
    r.renewalStage = 'quote_prepared'
    flash(r.policyId, true, 'สร้างใบเสนอราคา InsureHub แล้ว')
    prepareModal.value = null
  } catch (err: unknown) {
    // Surface the real reason (jsPDF render error, upload error, etc.) instead
    // of a generic message so failures are diagnosable.
    console.error('[renewal] prepare-quote failed', err)
    const msg = err instanceof ApiError ? err.message : (err instanceof Error ? err.message : 'สร้างใบเสนอราคาล้มเหลว')
    flash(r.policyId, false, msg)
  } finally {
    preparing.value = false
    actionSaving.value = null
  }
}

// ── Phase E — send the generated quote to the customer + won/lost ──────────
const sendQuoteModal = ref<{ row: ExpiringPolicy; message: string } | null>(null)
const declineModal = ref<{ row: ExpiringPolicy; reason: string } | null>(null)

function openSendQuote(r: ExpiringPolicy): void {
  sendQuoteModal.value = {
    row: r,
    message: `เรียน คุณ${r.customerName || 'ลูกค้า'}\n\n`
      + `InsureHub ได้จัดทำใบเสนอราคาสำหรับการต่ออายุกรมธรรม์ของท่านเรียบร้อยแล้ว รายละเอียดตามเอกสารแนบ\n\n`
      + `หากมีข้อสงสัยหรือต้องการยืนยันการต่ออายุ กรุณาติดต่อกลับได้ที่อีเมลนี้`,
  }
}

async function submitSendQuote(): Promise<void> {
  if (!sendQuoteModal.value) return
  const { row: r, message } = sendQuoteModal.value
  actionSaving.value = r.policyId
  try {
    await sendRenewalQuote(r.policyId, message.trim() || undefined)
    r.quoteSentAt = new Date().toISOString()
    r.renewalStage = 'quote_sent'
    flash(r.policyId, true, 'ส่งใบเสนอราคาถึงลูกค้าแล้ว')
    sendQuoteModal.value = null
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'ส่งใบเสนอราคาล้มเหลว')
  } finally { actionSaving.value = null }
}

function openDecline(r: ExpiringPolicy): void {
  declineModal.value = { row: r, reason: '' }
}

async function submitDecline(): Promise<void> {
  if (!declineModal.value) return
  const { row: r, reason } = declineModal.value
  actionSaving.value = r.policyId
  try {
    await declineRenewal(r.policyId, reason.trim() || undefined)
    r.renewalDeclinedAt = new Date().toISOString()
    r.renewalStage = 'declined'
    flash(r.policyId, true, 'บันทึกว่าลูกค้าปฏิเสธแล้ว')
    declineModal.value = null
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'บันทึกล้มเหลว')
  } finally { actionSaving.value = null }
}

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

// ── Stage-aware action dispatch ────────────────────────────────────────────
// The table + board show ONE "next step" button per row, driven by the stage.
// This dispatcher maps a next-action token to the handler, so the button label
// (from renewalNextAction) and its behaviour never drift apart.
function nextActionFor(r: ExpiringPolicy): RenewalNextAction | null {
  return renewalNextAction(r.renewalStage)
}
function runAction(action: RenewalNextAction['action'], r: ExpiringPolicy): void {
  moreMenuFor.value = null
  switch (action) {
    case 'contact': openContact(r); break
    case 'request_quote': openQuoteRequest(r); break
    case 'upload_quote': triggerQuoteUpload(r); break
    case 'prepare_quote': openPrepareQuote(r); break
    case 'send_quote': openSendQuote(r); break
    case 'start_renewal': void doStartRenewal(r); break
  }
}

// Which row's "⋯ more actions" menu is open (policyId), or null.
const moreMenuFor = ref<string | null>(null)
function toggleMoreMenu(policyId: string): void {
  moreMenuFor.value = moreMenuFor.value === policyId ? null : policyId
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
    [r.renewalStartedAt, 'เริ่มต่ออายุ'],
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
      { header: 'Renewal stage', value: (r) => renewalStageMeta(r.renewalStage).label },
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
    <!-- Hidden file input for carrier-quote uploads (Phase C) — one shared
         picker; the target row is tracked in quoteUploadRow. -->
    <input ref="quoteUploadInput" type="file" class="hidden"
      accept=".pdf,.jpg,.jpeg,.png,.webp" @change="onQuoteFileSelected" />
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
              @click="openPolicyInNewTab(r.policyId)">
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
                <!-- Derived pipeline stage — the single source of "where is this
                     renewal" for the operator. Detail timestamps sit below. -->
                <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium', renewalStageMeta(r.renewalStage).badgeClass]">
                  <i :class="['pi', renewalStageMeta(r.renewalStage).icon, 'text-[10px]']" />
                  {{ renewalStageMeta(r.renewalStage).label }}
                </span>
                <!-- Only the latest step, so the column stays clean -->
                <div v-if="latestStatusLine(r)" class="mt-1 text-slate-500">
                  {{ latestStatusLine(r)!.label }} · {{ latestStatusLine(r)!.when }}
                </div>
                <div v-if="actionMsg?.id === r.policyId"
                  :class="actionMsg.ok ? 'text-emerald-700' : 'text-rose-700'"
                  class="text-[10px] mt-1">{{ actionMsg.text }}</div>
              </td>
              <td class="px-4 py-2 text-right" @click.stop>
                <div class="inline-flex items-center gap-1.5">
                  <!-- Primary "next step" — the ONE action this stage expects.
                       New workers just follow this button down each row. -->
                  <button v-if="nextActionFor(r)" type="button"
                    class="px-2.5 py-1.5 rounded-lg bg-brand-600 text-white text-xs hover:bg-brand-700 disabled:opacity-50 flex items-center gap-1 whitespace-nowrap"
                    :disabled="actionSaving === r.policyId"
                    @click="runAction(nextActionFor(r)!.action, r)">
                    <i v-if="actionSaving !== r.policyId" :class="['pi', nextActionFor(r)!.icon, 'text-[10px]']" />
                    <i v-else class="pi pi-spin pi-spinner text-[10px]" />
                    {{ nextActionFor(r)!.label }}
                  </button>
                  <span v-else class="text-xs text-slate-400 px-2">
                    {{ r.renewalStage === 'renewed' ? 'เสร็จสิ้น' : (r.renewalStage === 'declined' ? 'ปฏิเสธแล้ว' : '—') }}
                  </span>

                  <!-- View uploaded quote files — appears once a quote exists. -->
                  <button v-if="hasQuoteFiles(r)" type="button" title="ดูใบเสนอราคาที่อัปโหลด"
                    class="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-brand-600 disabled:opacity-50"
                    :disabled="actionSaving === r.policyId" @click="openFiles(r)">
                    <i class="pi pi-paperclip text-xs" />
                  </button>

                  <!-- Overflow: all other actions, so nothing is lost but the
                       row stays readable. Toggles a small popover. -->
                  <div class="relative">
                    <button type="button" title="การดำเนินการอื่น"
                      class="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700 disabled:opacity-50"
                      :disabled="actionSaving === r.policyId" @click="toggleMoreMenu(r.policyId)">
                      <i class="pi pi-ellipsis-v text-xs" />
                    </button>
                    <div v-if="moreMenuFor === r.policyId"
                      class="absolute right-0 top-full mt-1 w-52 bg-white border border-slate-200 rounded-lg shadow-lg z-20 py-1 text-left"
                      @click.stop>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; openContact(r)">
                        <i class="pi pi-phone text-[10px] text-slate-400" /> บันทึกการติดต่อ
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; openNotice(r)">
                        <i class="pi pi-envelope text-[10px] text-slate-400" /> ส่งอีเมลแจ้งต่ออายุ
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; openQuoteRequest(r)">
                        <i class="pi pi-send text-[10px] text-slate-400" /> ขอใบเสนอราคา
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; triggerQuoteUpload(r)">
                        <i class="pi pi-upload text-[10px] text-slate-400" /> อัปโหลดใบเสนอราคา
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; openPrepareQuote(r)">
                        <i class="pi pi-file-edit text-[10px] text-slate-400" /> สร้างใบเสนอราคา InsureHub
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; openSendQuote(r)">
                        <i class="pi pi-share-alt text-[10px] text-slate-400" /> ส่งใบเสนอราคาให้ลูกค้า
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; doStartRenewal(r)">
                        <i class="pi pi-arrow-right text-[10px] text-slate-400" /> ต่ออายุ
                      </button>

                      <!-- Manual stage markers — jump to any stage without the real
                           side-effect (email/upload). "I did this outside the system." -->
                      <div class="border-t border-slate-100 my-1"></div>
                      <div class="px-3 py-1 text-[10px] uppercase tracking-wide text-slate-400">บันทึกสถานะเอง (ไม่ส่ง/ไม่แนบไฟล์)</div>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; manualMark(r, 'contacted')">
                        <i class="pi pi-phone text-[10px] text-slate-300" /> ติดต่อลูกค้าแล้ว
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; manualMark(r, 'quote_requested')">
                        <i class="pi pi-send text-[10px] text-slate-300" /> ขอใบเสนอราคาแล้ว
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; manualMark(r, 'quote_received')">
                        <i class="pi pi-inbox text-[10px] text-slate-300" /> ได้รับใบเสนอราคาแล้ว
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; manualMark(r, 'quote_prepared')">
                        <i class="pi pi-file-edit text-[10px] text-slate-300" /> จัดทำใบเสนอราคาแล้ว
                      </button>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50 flex items-center gap-2" @click="moreMenuFor = null; manualMark(r, 'quote_sent')">
                        <i class="pi pi-share-alt text-[10px] text-slate-300" /> ส่งให้ลูกค้าแล้ว
                      </button>

                      <div class="border-t border-slate-100 my-1"></div>
                      <button type="button" class="w-full px-3 py-1.5 text-xs text-rose-600 hover:bg-rose-50 flex items-center gap-2" @click="moreMenuFor = null; openDecline(r)">
                        <i class="pi pi-times-circle text-[10px]" /> ลูกค้าปฏิเสธ
                      </button>
                    </div>
                  </div>
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
              @click="openPolicyInNewTab(r.policyId)">
              <div class="font-medium text-slate-900 truncate">{{ r.customerName || r.customerCode || '—' }}</div>
              <div class="text-xs text-slate-500 font-mono mt-0.5 truncate">
                {{ r.policyNo || r.applicationNo || '—' }}
              </div>
              <div class="text-xs text-slate-500 mt-1 truncate">{{ r.carrierName || r.carrierCode || '—' }}</div>
              <div class="flex items-center justify-between mt-2">
                <span :class="['inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium', badge(r.daysRemaining).cls]">
                  {{ r.daysRemaining }} วัน
                </span>
                <span class="text-xs text-slate-600">{{ fmtBaht(r.annualPremium) }}</span>
              </div>
              <!-- Quick next-action button — same stage-aware logic as the table
                   so labels + behaviour never diverge between the two views. -->
              <div v-if="nextActionFor(r)" class="mt-2" @click.stop>
                <button type="button"
                  class="w-full px-2 py-1.5 rounded bg-brand-600 text-white text-[11px] hover:bg-brand-700 disabled:opacity-50 flex items-center justify-center gap-1"
                  :disabled="actionSaving === r.policyId"
                  @click="runAction(nextActionFor(r)!.action, r)">
                  <i v-if="actionSaving !== r.policyId" :class="['pi', nextActionFor(r)!.icon, 'text-[9px]']" />
                  <i v-else class="pi pi-spin pi-spinner text-[9px]" />
                  {{ nextActionFor(r)!.label }}
                </button>
              </div>
              <!-- View uploaded quote files (board) -->
              <button v-if="hasQuoteFiles(r)" type="button"
                class="mt-1.5 w-full text-[10px] text-slate-500 hover:text-brand-600 flex items-center justify-center gap-1"
                @click.stop="openFiles(r)">
                <i class="pi pi-paperclip text-[9px]" /> ดูใบเสนอราคา
              </button>
            </div>
            <div v-if="!col.rows.length" class="text-center text-xs text-slate-300 py-4 border border-dashed border-slate-200 rounded-lg">
              —
            </div>
          </div>
        </div>
      </div>
    </section>

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

    <!-- Request-quote modal (Phase B) — pick recipient (insurer / agent) + edit preset -->
    <div v-if="quoteRequestModal" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4"
      @click.self="quoteRequestModal = null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden">
        <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="text-lg font-semibold text-slate-900">ขอใบเสนอราคาต่ออายุ</div>
          <button type="button" class="text-slate-400 hover:text-slate-700 p-1" @click="quoteRequestModal = null">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="p-5 space-y-4">
          <div class="text-sm text-slate-700">
            <div>{{ quoteRequestModal.row.customerName || quoteRequestModal.row.customerCode }}</div>
            <div class="text-xs text-slate-500 font-mono mt-0.5">
              {{ quoteRequestModal.row.policyNo || quoteRequestModal.row.applicationNo }}
              · {{ quoteRequestModal.row.carrierName || '—' }}
            </div>
          </div>

          <!-- Carrier email list — pick a destination + manage (add/edit/delete).
               Edits persist to the carrier so they're reusable next time. -->
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="text-xs font-medium text-slate-500">
                อีเมลบริษัทประกัน — {{ quoteRequestModal.row.carrierName || quoteRequestModal.row.carrierCode || '—' }}
              </label>
              <button v-if="quoteRequestModal.row.carrierId && !quoteContactEdit" type="button"
                class="text-xs text-brand-600 hover:text-brand-700 flex items-center gap-1"
                @click="startAddContact">
                <i class="pi pi-plus text-[10px]" /> เพิ่มอีเมล
              </button>
            </div>

            <div v-if="!quoteRequestModal.row.carrierId" class="text-xs text-amber-600 px-1">
              <i class="pi pi-info-circle text-[10px] mr-0.5" /> กรมธรรม์นี้ไม่มีบริษัทประกันในระบบ — พิมพ์อีเมลเองด้านล่างได้
            </div>

            <div v-else class="border border-slate-200 rounded-lg divide-y divide-slate-100 max-h-44 overflow-y-auto">
              <div v-if="quoteContactsLoading" class="px-3 py-2 text-xs text-slate-400">กำลังโหลด…</div>
              <div v-else-if="!quoteContacts.length && !quoteContactEdit" class="px-3 py-2 text-xs text-slate-400">
                ยังไม่มีอีเมล — กด “เพิ่มอีเมล”
              </div>
              <!-- Each saved email: radio to select + edit/delete -->
              <label v-for="c in quoteContacts" :key="c.id"
                class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-slate-50">
                <input type="radio" :value="c.email" v-model="quoteRequestModal.to" class="accent-brand-600" />
                <div class="flex-1 min-w-0">
                  <div class="font-mono text-slate-800 truncate">{{ c.email }}</div>
                  <div v-if="c.firstName || c.lastName" class="text-[11px] text-slate-400 truncate">
                    {{ [c.firstName, c.lastName].filter(Boolean).join(' ') }}
                    <span v-if="c.isPrimary" class="text-emerald-600">· หลัก</span>
                  </div>
                </div>
                <button type="button" class="p-1 text-slate-400 hover:text-brand-600" @click.prevent="startEditContact(c)">
                  <i class="pi pi-pencil text-[11px]" />
                </button>
                <button type="button" class="p-1 text-slate-400 hover:text-rose-600" @click.prevent="removeContact(c)">
                  <i class="pi pi-trash text-[11px]" />
                </button>
              </label>

              <!-- Inline add/edit row -->
              <div v-if="quoteContactEdit" class="px-3 py-2 space-y-2 bg-slate-50">
                <input v-model="quoteContactEdit.email" type="email" placeholder="อีเมล"
                  class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
                <input v-model="quoteContactEdit.name" type="text" placeholder="ชื่อผู้ติดต่อ (ไม่บังคับ)"
                  class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400" />
                <div class="flex justify-end gap-2">
                  <button type="button" class="px-2 py-1 text-xs text-slate-500 hover:text-slate-700" @click="quoteContactEdit = null">ยกเลิก</button>
                  <button type="button" class="px-2.5 py-1 text-xs rounded bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50"
                    :disabled="!quoteContactEdit.email.trim()" @click="saveContactEdit">บันทึก</button>
                </div>
              </div>
            </div>

            <!-- Free-type override (also the only input when carrier is unknown) -->
            <div class="mt-2">
              <label class="text-[11px] text-slate-400 mb-0.5 block">อีเมลปลายทางที่จะส่ง</label>
              <input v-model="quoteRequestModal.to" type="email" placeholder="เลือกจากรายการ หรือพิมพ์อีเมล"
                class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:border-brand-400" />
            </div>
          </div>

          <!-- Subject -->
          <div>
            <label class="text-xs font-medium text-slate-500 mb-1 block">หัวข้อ (Subject)</label>
            <input v-model="quoteRequestModal.subject" type="text"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-400" />
          </div>

          <!-- Editable preset message -->
          <div>
            <label class="text-xs font-medium text-slate-500 mb-1 block">ข้อความ (แก้ไขได้)</label>
            <textarea v-model="quoteRequestModal.message" rows="6"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-400 resize-none" />
          </div>
        </div>

        <!-- Two clear paths: let the app send it, OR send it yourself. -->
        <footer class="px-5 py-4 border-t border-slate-200 space-y-3">
          <!-- Path 1: app sends -->
          <div class="flex items-center justify-between gap-3">
            <div class="text-xs text-slate-500">
              <div class="font-medium text-slate-700">ส่งอัตโนมัติจากระบบ</div>
              ระบบส่งอีเมลให้ทันที (ต้องมีอีเมลปลายทาง)
            </div>
            <button type="button"
              class="px-4 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5 whitespace-nowrap"
              :disabled="actionSaving === quoteRequestModal.row.policyId || !quoteRequestModal.to.trim()"
              @click="submitQuoteRequest('system')">
              <i class="pi pi-send text-xs" v-if="actionSaving !== quoteRequestModal.row.policyId" />
              <i class="pi pi-spin pi-spinner text-xs" v-else />
              ส่งอีเมลเลย
            </button>
          </div>

          <div class="border-t border-dashed border-slate-200"></div>

          <!-- Path 2: operator sends via their own mail app -->
          <div class="flex items-center justify-between gap-3">
            <div class="text-xs text-slate-500">
              <div class="font-medium text-slate-700">ส่งเองผ่านแอปอีเมล</div>
              คัดลอกข้อความหรือเปิด Gmail/Outlook แล้วค่อยกดยืนยันว่าส่งแล้ว
            </div>
            <div class="flex items-center gap-1.5 whitespace-nowrap">
              <button type="button"
                class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 text-xs flex items-center gap-1"
                @click="copyQuoteToClipboard">
                <i :class="quoteCopied ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" class="text-[11px]" />
                {{ quoteCopied ? 'คัดลอกแล้ว' : 'คัดลอก' }}
              </button>
              <a :href="quoteMailtoHref" target="_blank" rel="noopener"
                class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 text-xs flex items-center gap-1">
                <i class="pi pi-external-link text-[11px]" /> เปิดแอปอีเมล
              </a>
              <button type="button"
                class="px-2.5 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 text-xs disabled:bg-slate-300 flex items-center gap-1"
                :disabled="actionSaving === quoteRequestModal.row.policyId"
                @click="submitQuoteRequest('manual')">
                <i class="pi pi-check text-[11px]" /> ส่งแล้ว
              </button>
            </div>
          </div>

          <div class="text-right">
            <button type="button"
              class="px-3 py-1 text-slate-500 hover:text-slate-700 text-xs"
              :disabled="actionSaving === quoteRequestModal.row.policyId" @click="quoteRequestModal = null">
              ยกเลิก
            </button>
          </div>
        </footer>
      </div>
    </div>

    <!-- Prepare-quote modal (Phase D) — generate an InsureHub-branded quotation -->
    <div v-if="prepareModal" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4"
      @click.self="!preparing && (prepareModal = null)">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-xl overflow-hidden max-h-[90vh] flex flex-col">
        <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="text-lg font-semibold text-slate-900">สร้างใบเสนอราคา InsureHub</div>
          <button type="button" class="text-slate-400 hover:text-slate-700 p-1" :disabled="preparing" @click="prepareModal = null">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="p-5 space-y-4 overflow-y-auto">
          <div class="text-sm text-slate-700">
            <div class="font-medium">{{ prepareModal.row.customerName || prepareModal.row.customerCode }}</div>
            <div class="text-xs text-slate-500 mt-0.5">
              {{ prepareModal.row.productName || prepareModal.row.productCode }}
              · {{ prepareModal.row.carrierName || '—' }}
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs font-medium text-slate-500 mb-1 block">ทุนประกัน (บาท)</label>
              <input v-model.number="prepareModal.form.coverageAmount" type="number" min="0"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </div>
            <div>
              <label class="text-xs font-medium text-slate-500 mb-1 block">เบี้ยประกัน (บาท)</label>
              <input v-model.number="prepareModal.form.annualPremium" type="number" min="0"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </div>
            <div>
              <label class="text-xs font-medium text-slate-500 mb-1 block">งวดชำระ</label>
              <select v-model="prepareModal.form.premiumMode"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
                <option value="annual">รายปี</option>
                <option value="semiannual">ราย 6 เดือน</option>
                <option value="quarterly">รายไตรมาส</option>
                <option value="monthly">รายเดือน</option>
                <option value="single">จ่ายครั้งเดียว</option>
              </select>
            </div>
            <div>
              <label class="text-xs font-medium text-slate-500 mb-1 block">ระยะเวลาคุ้มครอง (ปี)</label>
              <input v-model.number="prepareModal.form.coveragePeriodYears" type="number" min="0"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </div>
          </div>

          <div>
            <label class="text-xs font-medium text-slate-500 mb-1 block">สรุปข้อเสนอ</label>
            <textarea v-model="prepareModal.form.proposalSummary" rows="2"
              placeholder="เช่น: ต่ออายุกรมธรรม์รถยนต์ชั้น 1 ทุนประกัน 500,000 บาท"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-400 resize-none" />
          </div>
          <div>
            <label class="text-xs font-medium text-slate-500 mb-1 block">ขั้นตอนถัดไป</label>
            <textarea v-model="prepareModal.form.nextSteps" rows="2"
              placeholder="เช่น: ยืนยันการต่ออายุและชำระเบี้ยภายในวันที่ครบกำหนด"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-400 resize-none" />
          </div>

          <p class="text-xs text-slate-400">
            <i class="pi pi-info-circle text-[10px] mr-0.5" />
            ใบเสนอราคาจะถูกบันทึกแนบกับกรมธรรม์และเปลี่ยนสถานะเป็น “จัดทำใบเสนอราคาแล้ว”
          </p>
        </div>
        <footer class="px-5 py-3 border-t border-slate-200 flex items-center justify-between gap-2 flex-wrap">
          <button type="button"
            class="text-xs text-slate-500 hover:text-slate-700 underline decoration-dotted disabled:opacity-50"
            :disabled="preparing" @click="manualMark(prepareModal.row, 'quote_prepared', () => prepareModal = null)">
            ข้ามขั้นตอนนี้ (บันทึกว่าจัดทำแล้ว)
          </button>
          <div class="flex items-center gap-2">
            <button type="button"
              class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm disabled:opacity-50"
              :disabled="preparing" @click="prepareModal = null">
              ยกเลิก
            </button>
            <button type="button"
              class="px-3 py-1.5 rounded-lg border border-brand-200 text-brand-700 hover:bg-brand-50 text-sm disabled:opacity-50 flex items-center gap-1.5"
              :disabled="preparing" @click="submitPrepareQuote(true)">
              <i class="pi pi-download text-xs" />
              สร้าง + ดาวน์โหลด
            </button>
            <button type="button"
              class="px-4 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5"
              :disabled="preparing" @click="submitPrepareQuote(false)">
              <i class="pi pi-check text-xs" v-if="!preparing" />
              <i class="pi pi-spin pi-spinner text-xs" v-else />
              สร้างและบันทึก
            </button>
          </div>
        </footer>
      </div>
    </div>

    <!-- Send-quote-to-customer modal (Phase E) -->
    <div v-if="sendQuoteModal" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4"
      @click.self="sendQuoteModal = null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden">
        <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="text-lg font-semibold text-slate-900">ส่งใบเสนอราคาให้ลูกค้า</div>
          <button type="button" class="text-slate-400 hover:text-slate-700 p-1" @click="sendQuoteModal = null">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="p-5 space-y-4">
          <div class="text-sm">
            <div class="text-slate-700">{{ sendQuoteModal.row.customerName || sendQuoteModal.row.customerCode }}</div>
            <div class="mt-1">
              <span class="text-slate-500">ปลายทาง:</span>
              <span class="ml-2 font-medium text-slate-900">{{ sendQuoteModal.row.customerEmail || 'ไม่พบอีเมล' }}</span>
            </div>
            <div v-if="!sendQuoteModal.row.customerEmail" class="text-xs text-amber-600 mt-1">
              <i class="pi pi-info-circle text-[10px] mr-0.5" /> ลูกค้าไม่มีอีเมล — ไม่สามารถส่งได้
            </div>
          </div>
          <div>
            <label class="text-xs font-medium text-slate-500 mb-1 block">ข้อความ (แก้ไขได้)</label>
            <textarea v-model="sendQuoteModal.message" rows="5"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-400 resize-none" />
          </div>
          <p class="text-xs text-slate-400">
            <i class="pi pi-paperclip text-[10px] mr-0.5" /> ใบเสนอราคา InsureHub ล่าสุดจะถูกแนบไปกับอีเมลอัตโนมัติ
          </p>
        </div>
        <footer class="px-5 py-3 border-t border-slate-200 flex items-center justify-between gap-2 flex-wrap">
          <button type="button"
            class="text-xs text-slate-500 hover:text-slate-700 underline decoration-dotted disabled:opacity-50"
            :disabled="actionSaving === sendQuoteModal.row.policyId"
            @click="manualMark(sendQuoteModal.row, 'quote_sent', () => sendQuoteModal = null)">
            ส่งเองแล้ว (บันทึกไม่ส่งเมล)
          </button>
          <div class="flex items-center gap-2">
            <button type="button"
              class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm"
              :disabled="actionSaving === sendQuoteModal.row.policyId" @click="sendQuoteModal = null">
              ยกเลิก
            </button>
            <button type="button"
              class="px-4 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5"
              :disabled="actionSaving === sendQuoteModal.row.policyId || !sendQuoteModal.row.customerEmail"
              @click="submitSendQuote">
              <i class="pi pi-send text-xs" v-if="actionSaving !== sendQuoteModal.row.policyId" />
              <i class="pi pi-spin pi-spinner text-xs" v-else />
              ส่งอีเมล
            </button>
          </div>
        </footer>
      </div>
    </div>

    <!-- Decline / lost modal (Phase E) -->
    <div v-if="declineModal" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4"
      @click.self="declineModal = null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="text-lg font-semibold text-slate-900">ลูกค้าปฏิเสธการต่ออายุ</div>
          <button type="button" class="text-slate-400 hover:text-slate-700 p-1" @click="declineModal = null">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="p-5 space-y-3">
          <div class="text-sm text-slate-700">
            {{ declineModal.row.customerName || declineModal.row.customerCode }}
            <span class="text-xs text-slate-500 font-mono ml-1">{{ declineModal.row.policyNo || declineModal.row.applicationNo }}</span>
          </div>
          <div>
            <label class="text-xs font-medium text-slate-500 mb-1 block">เหตุผล (ไม่จำเป็น)</label>
            <textarea v-model="declineModal.reason" rows="3"
              placeholder="เช่น: เบี้ยสูงเกินไป / เปลี่ยนบริษัทประกัน / ขายรถแล้ว"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-400 resize-none" />
          </div>
        </div>
        <footer class="px-5 py-3 border-t border-slate-200 flex items-center justify-end gap-2">
          <button type="button"
            class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm"
            :disabled="actionSaving === declineModal.row.policyId" @click="declineModal = null">
            ยกเลิก
          </button>
          <button type="button"
            class="px-4 py-1.5 rounded-lg bg-rose-600 text-white hover:bg-rose-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5"
            :disabled="actionSaving === declineModal.row.policyId" @click="submitDecline">
            <i class="pi pi-times-circle text-xs" v-if="actionSaving !== declineModal.row.policyId" />
            <i class="pi pi-spin pi-spinner text-xs" v-else />
            บันทึกการปฏิเสธ
          </button>
        </footer>
      </div>
    </div>

    <!-- View uploaded quote files — open/download stored quotations later -->
    <div v-if="filesModal" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4"
      @click.self="filesModal = null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="text-lg font-semibold text-slate-900">ใบเสนอราคาที่อัปโหลด</div>
          <button type="button" class="text-slate-400 hover:text-slate-700 p-1" @click="filesModal = null">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="p-5">
          <div class="text-sm text-slate-700 mb-3">
            {{ filesModal.row.customerName || filesModal.row.customerCode }}
            <span class="text-xs text-slate-500 font-mono ml-1">{{ filesModal.row.policyNo || filesModal.row.applicationNo }}</span>
          </div>

          <div v-if="filesLoading" class="text-sm text-slate-500 py-4 text-center">กำลังโหลด…</div>
          <div v-else-if="!filesModal.docs.length" class="text-sm text-slate-500 py-4 text-center">
            ยังไม่มีไฟล์ใบเสนอราคา
          </div>
          <ul v-else class="divide-y divide-slate-100 border border-slate-200 rounded-lg overflow-hidden">
            <li v-for="d in filesModal.docs" :key="d.id" class="flex items-start gap-3 px-3 py-2.5">
              <i class="pi pi-file-pdf text-rose-500 mt-0.5" />
              <div class="flex-1 min-w-0">
                <div class="text-sm text-slate-800 truncate">{{ d.fileName }}</div>
                <div class="text-[11px] text-slate-400">
                  {{ docTypeLabel(d.type) }}<span v-if="d.uploadedAt"> · {{ relativeDays(d.uploadedAt) }}</span>
                </div>
                <div class="flex items-center gap-1.5 flex-wrap mt-1.5">
                  <button type="button"
                    class="px-2 py-0.5 rounded border border-slate-200 text-slate-600 hover:bg-slate-50 text-[11px] flex items-center gap-1 disabled:opacity-50"
                    :disabled="docBusy === d.id" @click="openDoc(filesModal.row.policyId, d, 'open')">
                    <i class="pi pi-eye text-[10px]" /> เปิด
                  </button>
                  <button type="button"
                    class="px-2 py-0.5 rounded bg-brand-600 text-white hover:bg-brand-700 text-[11px] flex items-center gap-1 disabled:opacity-50"
                    :disabled="docBusy === d.id" @click="openDoc(filesModal.row.policyId, d, 'download')">
                    <i :class="docBusy === d.id ? 'pi pi-spin pi-spinner' : 'pi pi-download'" class="text-[10px]" /> ดาวน์โหลด
                  </button>
                  <!-- InsureHub quote: edit = regenerate (delete + recreate) -->
                  <button v-if="d.type === 'renewal_quote_insurehub'" type="button"
                    class="px-2 py-0.5 rounded border border-brand-200 text-brand-700 hover:bg-brand-50 text-[11px] flex items-center gap-1 disabled:opacity-50"
                    :disabled="docBusy === d.id" @click="regenerateInsurehubQuote(filesModal.row, d)">
                    <i class="pi pi-refresh text-[10px]" /> สร้างใหม่
                  </button>
                  <button type="button"
                    class="px-2 py-0.5 rounded border border-rose-200 text-rose-600 hover:bg-rose-50 text-[11px] flex items-center gap-1 disabled:opacity-50"
                    :disabled="docBusy === d.id" @click="deleteDoc(filesModal.row.policyId, d)">
                    <i class="pi pi-trash text-[10px]" /> ลบ
                  </button>
                </div>
              </div>
            </li>
          </ul>
        </div>
        <footer class="px-5 py-3 border-t border-slate-200 flex justify-end">
          <button type="button"
            class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm"
            @click="filesModal = null">
            ปิด
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>
