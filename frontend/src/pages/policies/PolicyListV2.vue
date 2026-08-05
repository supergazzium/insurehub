<script setup lang="ts">
// Server-side paginated policy list. Reads from usePolicyStore().list which is
// backed by GET /api/v1/policies (returns the lean PolicyListRow shape with
// joined customer/agent/carrier/product display columns).

import { onMounted, reactive, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { usePolicyStore, type PolicyStatus, type PolicyDocument } from '../../stores/policies'
import { fetchPolicy, policyDocumentDownloadUrl } from '../../api/policies'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { fetchProductList, type ProductListRow } from '../../api/products'
import { getToken } from '../../api/client'
import PolicyDetailDrawer from './PolicyDetailDrawer.vue'
import PolicyCreateWizard from './PolicyCreateWizard.vue'
import AgentPicker from '../../components/AgentPicker.vue'
import DateInput from '../../components/DateInput.vue'

const { t } = useI18n()
const policyStore = usePolicyStore()

const detailId = ref<string | null>(null)
const showCreate = ref(false)

// Documents popup — click ปุ่มเรียกดูเอกสารแนบ opens a modal listing every
// attachment on the selected policy with per-file download buttons. We
// fetch the full policy detail on open because the list resource is
// lean and doesn't include the documents array.
const docsModalPolicyId = ref<string | null>(null)
const docsModalPolicyLabel = ref<string>('')
const docsList = ref<PolicyDocument[]>([])
const docsLoading = ref(false)
const docsError = ref<string | null>(null)
async function openDocsModal(policyId: string, label: string): Promise<void> {
  docsModalPolicyId.value = policyId
  docsModalPolicyLabel.value = label
  docsLoading.value = true
  docsError.value = null
  docsList.value = []
  try {
    const res = await fetchPolicy(policyId)
    docsList.value = res.data.documents ?? []
  } catch (e) {
    docsError.value = e instanceof Error ? e.message : 'Failed to load documents.'
  } finally {
    docsLoading.value = false
  }
}
function closeDocsModal(): void {
  docsModalPolicyId.value = null
  docsList.value = []
  docsError.value = null
}
/** Opens the streamed document in a new tab. The API is bearer-auth'd so
 *  we can't use a plain <a href>; fetch → blob → object URL → window.open. */
async function openDocument(policyId: string, docId: string): Promise<void> {
  const token = getToken()
  if (!token) { docsError.value = 'Not signed in.'; return }
  const url = policyDocumentDownloadUrl(policyId, docId)
  try {
    const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const blob = await res.blob()
    const objectUrl = URL.createObjectURL(blob)
    window.open(objectUrl, '_blank', 'noopener')
    // Release the blob URL after 30 s so the new tab has time to load.
    setTimeout(() => URL.revokeObjectURL(objectUrl), 30000)
  } catch (e) {
    docsError.value = e instanceof Error ? e.message : 'Failed to open document.'
  }
}

function docTypeLabel(t: string): string {
  const map: Record<string, string> = {
    application: 'ใบคำขอ', policy: 'กรมธรรม์', receipt: 'ใบเสร็จ',
    medical: 'เอกสารการแพทย์', endorsement: 'สลักหลัง',
    cancellation: 'ใบยกเลิก', other: 'อื่นๆ',
  }
  return map[t] ?? t
}

// Filter state — bound to loadPage() on change. Hydrated from URL query
// on mount and re-serialized on any change so filters are bookmarkable
// and browser back/forward works.
const route = useRoute()
const router = useRouter()

type CustomerType = '' | 'individual' | 'corporate' | 'foreign'
type InsureType = '' | 'life' | 'non-life' | 'tax'

const filters = reactive({
  q: '',
  customerType: '' as CustomerType,
  insureType: '' as InsureType,
  carrierId: '',
  productId: '',
  writingAgentId: '',
  createdFrom: '',
  createdTo: '',
  status: '' as '' | PolicyStatus,
  newOrRenew: '' as '' | 'new' | 'renew',
  perPage: 25,
})

// Advanced-panel expansion state, remembered in the URL too so it survives refresh.
const advancedOpen = ref(false)

function hydrateFromRoute(): void {
  const q = route.query
  const asStr = (k: string): string => typeof q[k] === 'string' ? q[k] as string : ''
  filters.q = asStr('q')
  filters.customerType = (asStr('customerType') as CustomerType) || ''
  filters.insureType = (asStr('insureType') as InsureType) || ''
  filters.carrierId = asStr('carrierId')
  filters.productId = asStr('productId')
  filters.writingAgentId = asStr('writingAgentId')
  filters.createdFrom = asStr('createdFrom')
  filters.createdTo = asStr('createdTo')
  filters.status = (asStr('status') as '' | PolicyStatus) || ''
  filters.newOrRenew = (asStr('newOrRenew') as '' | 'new' | 'renew') || ''
  const pp = Number(asStr('perPage'))
  if ([10, 25, 50, 100, 200].includes(pp)) filters.perPage = pp
  const p = Number(asStr('page'))
  if (Number.isFinite(p) && p > 0) page.value = p
  advancedOpen.value = asStr('advanced') === '1'
}

function serializeToRoute(): void {
  const q: Record<string, string> = {}
  if (filters.q) q.q = filters.q
  if (filters.customerType) q.customerType = filters.customerType
  if (filters.insureType) q.insureType = filters.insureType
  if (filters.carrierId) q.carrierId = filters.carrierId
  if (filters.productId) q.productId = filters.productId
  if (filters.writingAgentId) q.writingAgentId = filters.writingAgentId
  if (filters.createdFrom) q.createdFrom = filters.createdFrom
  if (filters.createdTo) q.createdTo = filters.createdTo
  if (filters.status) q.status = filters.status
  if (filters.newOrRenew) q.newOrRenew = filters.newOrRenew
  if (filters.perPage !== 25) q.perPage = String(filters.perPage)
  if (page.value !== 1) q.page = String(page.value)
  if (advancedOpen.value) q.advanced = '1'
  router.replace({ query: q })
}

const page = ref(1)

async function load(): Promise<void> {
  await policyStore.loadPage({
    q: filters.q || undefined,
    customerType: filters.customerType || undefined,
    insureType: filters.insureType || undefined,
    carrierId: filters.carrierId || undefined,
    productId: filters.productId || undefined,
    writingAgentId: filters.writingAgentId || undefined,
    createdFrom: filters.createdFrom || undefined,
    createdTo: filters.createdTo || undefined,
    status: filters.status || undefined,
    newOrRenew: filters.newOrRenew || undefined,
    page: page.value,
    perPage: filters.perPage,
  })
  serializeToRoute()
}

// Carrier + product options (product list cascades from carrier).
const carriers = ref<CarrierListRow[]>([])
const products = ref<ProductListRow[]>([])
const productsLoading = ref(false)
async function loadCarriers(): Promise<void> {
  try {
    const res = await fetchCarrierList({ perPage: 200, activeOnly: true })
    carriers.value = res.data
  } catch { /* silent */ }
}
async function loadProducts(carrierId: string): Promise<void> {
  if (!carrierId) { products.value = []; return }
  productsLoading.value = true
  try {
    const res = await fetchProductList({ carrierId, perPage: 200, activeOnly: true })
    products.value = res.data
  } finally {
    productsLoading.value = false
  }
}

onMounted(async () => {
  hydrateFromRoute()
  await loadCarriers()
  if (filters.carrierId) await loadProducts(filters.carrierId)
  await load()
  // Cross-page deep-link — /policies?open=<id> opens that policy's drawer
  // automatically. Used by the customer detail drawer to link to a policy.
  const openId = route.query.open
  if (typeof openId === 'string' && openId.trim() !== '') {
    detailId.value = openId.trim()
  }
})

let debounceTimer: number | undefined
watch(
  () => filters.q,
  () => {
    window.clearTimeout(debounceTimer)
    debounceTimer = window.setTimeout(() => {
      page.value = 1
      void load()
    }, 300)
  },
)

// When carrier changes, clear product + reload the product options.
watch(() => filters.carrierId, async (id) => {
  filters.productId = ''
  await loadProducts(id)
  page.value = 1
  void load()
})

watch(
  () => [
    filters.customerType, filters.insureType, filters.productId,
    filters.writingAgentId, filters.createdFrom, filters.createdTo,
    filters.status, filters.newOrRenew, filters.perPage,
  ],
  () => { page.value = 1; void load() },
)

// ── Smart search — parse `q` to detect intent and label it ───────────
type SearchIntent = 'auto' | 'idCard' | 'passport' | 'phone' | 'plate' | 'appNo' | 'name'
const detectedIntent = computed<SearchIntent>(() => {
  const raw = filters.q.trim()
  if (raw === '') return 'auto'
  const digits = raw.replace(/\D/g, '')
  // 13-digit numeric → Thai national ID
  if (/^\d{13}$/.test(digits) && digits === raw.replace(/[\s-]/g, '')) return 'idCard'
  // Thai mobile: 9-10 digits starting 0[6|8|9]
  if (/^0[689]\d{7,8}$/.test(digits)) return 'phone'
  // App / Policy / Quote number pattern
  if (/^(A|APP|POL|POLICY|Q|QUOTE)[A-Z0-9-]{3,}$/i.test(raw)) return 'appNo'
  // Thai license plate — mix of Thai letters + digits, no spaces
  if (/[฀-๿]/.test(raw) && /\d/.test(raw)) return 'plate'
  // Passport — usually starts with 1-2 letters then digits, no Thai
  if (/^[A-Z]{1,2}\d{6,9}$/i.test(raw)) return 'passport'
  return 'name'
})
const intentLabel: Record<SearchIntent, string> = {
  auto: '',
  idCard: 'เลขบัตรประชาชน',
  passport: 'Passport',
  phone: 'เบอร์โทร',
  plate: 'เลขทะเบียนรถ',
  appNo: 'Application no.',
  name: 'ชื่อ-สกุล',
}

// ── Create-date preset buttons ────────────────────────────────────────
function ymd(d: Date): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}
function setCreatePreset(preset: 'today' | '7d' | 'thisMonth' | 'lastMonth'): void {
  const now = new Date()
  if (preset === 'today') {
    const s = ymd(now); filters.createdFrom = s; filters.createdTo = s; return
  }
  if (preset === '7d') {
    const from = new Date(now); from.setDate(from.getDate() - 6)
    filters.createdFrom = ymd(from); filters.createdTo = ymd(now); return
  }
  if (preset === 'thisMonth') {
    filters.createdFrom = ymd(new Date(now.getFullYear(), now.getMonth(), 1))
    filters.createdTo = ymd(now); return
  }
  if (preset === 'lastMonth') {
    const start = new Date(now.getFullYear(), now.getMonth() - 1, 1)
    const end = new Date(now.getFullYear(), now.getMonth(), 0)
    filters.createdFrom = ymd(start); filters.createdTo = ymd(end)
  }
}

// ── Active filter chips + "Clear all" ────────────────────────────────
interface FilterChip { key: string; label: string; clear: () => void }
const carrierLabel = computed(() => carriers.value.find((c) => c.id === filters.carrierId)?.name ?? '')
const productLabel = computed(() => products.value.find((p) => p.id === filters.productId)?.name ?? '')
const agentLabel = ref('') // set from AgentPicker's `picked` event
const customerTypeLabel: Record<CustomerType, string> = {
  '': '', individual: 'บุคคล', corporate: 'นิติบุคคล', foreign: 'ชาวต่างชาติ',
}
const insureTypeLabel: Record<InsureType, string> = {
  '': '', life: 'ชีวิต', 'non-life': 'วินาศ', tax: 'ภาษี',
}
const activeChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (filters.q) chips.push({ key: 'q', label: `ค้นหา: "${filters.q}"`, clear: () => { filters.q = '' } })
  if (filters.customerType) chips.push({
    key: 'customerType',
    label: `ประเภทลูกค้า: ${customerTypeLabel[filters.customerType]}`,
    clear: () => { filters.customerType = '' },
  })
  if (filters.insureType) chips.push({
    key: 'insureType',
    label: `ประเภทประกัน: ${insureTypeLabel[filters.insureType]}`,
    clear: () => { filters.insureType = '' },
  })
  if (filters.carrierId) chips.push({
    key: 'carrierId',
    label: `บริษัท: ${carrierLabel.value || filters.carrierId}`,
    clear: () => { filters.carrierId = '' },
  })
  if (filters.productId) chips.push({
    key: 'productId',
    label: `แผน: ${productLabel.value || filters.productId}`,
    clear: () => { filters.productId = '' },
  })
  if (filters.writingAgentId) chips.push({
    key: 'writingAgentId',
    label: `ตัวแทน: ${agentLabel.value || filters.writingAgentId}`,
    clear: () => { filters.writingAgentId = ''; agentLabel.value = '' },
  })
  if (filters.createdFrom || filters.createdTo) chips.push({
    key: 'createdDate',
    label: `สร้าง: ${filters.createdFrom || '…'} → ${filters.createdTo || '…'}`,
    clear: () => { filters.createdFrom = ''; filters.createdTo = '' },
  })
  if (filters.status) chips.push({
    key: 'status',
    label: `สถานะ: ${filters.status}`,
    clear: () => { filters.status = '' },
  })
  if (filters.newOrRenew) chips.push({
    key: 'newOrRenew',
    label: `${filters.newOrRenew === 'new' ? 'New' : 'Renew'}`,
    clear: () => { filters.newOrRenew = '' },
  })
  return chips
})
const hasActiveFilters = computed(() => activeChips.value.length > 0)
function clearAll(): void {
  filters.q = ''
  filters.customerType = ''; filters.insureType = ''
  filters.carrierId = ''; filters.productId = ''
  filters.writingAgentId = ''; agentLabel.value = ''
  filters.createdFrom = ''; filters.createdTo = ''
  filters.status = ''; filters.newOrRenew = ''
}

function goPage(next: number): void {
  const meta = policyStore.listMeta
  if (!meta) return
  const target = Math.max(1, Math.min(meta.lastPage, next))
  if (target === page.value) return
  page.value = target
  void load()
}

const allStatuses: { value: PolicyStatus | ''; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'active', label: 'อนุมัติแล้ว' },
  { value: 'submitted', label: 'รอพิจารณา' },
  { value: 'application', label: 'รอตรวจรถ' },
  { value: 'cancelled', label: 'Cancel / Reject' },
  { value: 'quote', label: 'ใบเสนอราคา' },
  { value: 'issued', label: 'ออกกรมธรรม์แล้ว' },
  { value: 'lapsed', label: 'ขาดต่ออายุ' },
  { value: 'reinstated', label: 'กลับมาคุ้มครองใหม่' },
  { value: 'expired', label: 'หมดอายุ' },
]

function statusBadge(s: PolicyStatus): string {
  return {
    quote: 'bg-slate-100 text-slate-600',
    application: 'bg-amber-50 text-amber-700',
    submitted: 'bg-amber-50 text-amber-700',
    issued: 'bg-sky-50 text-sky-700',
    active: 'bg-emerald-50 text-emerald-700',
    lapsed: 'bg-rose-50 text-rose-700',
    cancelled: 'bg-slate-100 text-slate-500',
    reinstated: 'bg-violet-50 text-violet-700',
    expired: 'bg-slate-100 text-slate-500',
  }[s]
}

function fmtBaht(n: number): string {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 0 }).format(n)
}

const rangeText = computed(() => {
  const meta = policyStore.listMeta
  if (!meta) return ''
  const from = (meta.currentPage - 1) * meta.perPage + 1
  const to = Math.min(meta.total, meta.currentPage * meta.perPage)
  return `${from.toLocaleString()}–${to.toLocaleString()} จาก ${meta.total.toLocaleString()}`
})
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.policies.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">
          {{ t('modules.policies.description') }}
        </p>
      </div>
      <div class="flex items-center gap-3">
        <div v-if="policyStore.listMeta" class="text-sm text-slate-500">
          {{ rangeText }}
        </div>
        <button type="button"
          class="px-3 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm flex items-center gap-1.5"
          @click="showCreate = true">
          <i class="pi pi-plus text-xs" /> New Policy
        </button>
      </div>
    </header>

    <!-- Filter bar: compact primary row + advanced panel + active chips -->
    <section class="card p-4 space-y-3">
      <!-- Primary row: search (with intent chip) + carrier + agent + advanced toggle -->
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
              placeholder="เลขบัตร / passport / ชื่อ / เบอร์โทร / เลขทะเบียนรถ / App no."
              class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400" />
          </div>
        </div>
        <div class="md:col-span-3">
          <label class="text-xs font-medium text-slate-500 mb-1 block">บริษัทประกัน</label>
          <select v-model="filters.carrierId" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
            <option value="">ทั้งหมด</option>
            <option v-for="c in carriers" :key="c.id" :value="c.id">
              {{ c.code }} — {{ c.nicknameTh || c.name }}
            </option>
          </select>
        </div>
        <div class="md:col-span-3">
          <label class="text-xs font-medium text-slate-500 mb-1 block">รหัสตัวแทน</label>
          <AgentPicker v-model="filters.writingAgentId"
            @picked="(r) => { agentLabel = r ? `${r.agentCode} — ${r.firstName} ${r.lastName}`.trim() : '' }" />
        </div>
        <div class="md:col-span-1 flex md:justify-end">
          <button type="button" @click="advancedOpen = !advancedOpen; serializeToRoute()"
            class="w-full md:w-auto px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm">
            <i :class="advancedOpen ? 'pi pi-chevron-up' : 'pi pi-chevron-down'" class="text-xs mr-1" />
            ตัวกรอง
          </button>
        </div>
      </div>

      <!-- Advanced panel: customer type / insure type / plan / create-date / status / new-renew -->
      <div v-if="advancedOpen" class="pt-3 border-t border-slate-100">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
          <div class="md:col-span-3">
            <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภทลูกค้า</label>
            <select v-model="filters.customerType" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
              <option value="">ทั้งหมด</option>
              <option value="individual">บุคคล</option>
              <option value="corporate">นิติบุคคล</option>
              <option value="foreign">ชาวต่างชาติ</option>
            </select>
          </div>
          <div class="md:col-span-3">
            <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภทของประกัน</label>
            <select v-model="filters.insureType" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
              <option value="">ทั้งหมด</option>
              <option value="life">ชีวิต</option>
              <option value="non-life">วินาศ</option>
              <option value="tax">ภาษี</option>
            </select>
          </div>
          <div class="md:col-span-3">
            <label class="text-xs font-medium text-slate-500 mb-1 block">
              แผนประกัน
              <span v-if="!filters.carrierId" class="text-slate-400 ml-1">(เลือกบริษัทก่อน)</span>
            </label>
            <select v-model="filters.productId" :disabled="!filters.carrierId"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white disabled:bg-slate-50">
              <option value="">ทั้งหมด</option>
              <option v-for="p in products" :key="p.id" :value="p.id">
                {{ p.code }} — {{ p.name }}
              </option>
            </select>
            <div v-if="productsLoading" class="text-[10px] text-slate-400 mt-0.5"><i class="pi pi-spin pi-spinner" /> Loading…</div>
          </div>
          <div class="md:col-span-3">
            <label class="text-xs font-medium text-slate-500 mb-1 block">สถานะ</label>
            <select v-model="filters.status" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
              <option v-for="s in allStatuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>

          <div class="md:col-span-6">
            <label class="text-xs font-medium text-slate-500 mb-1 block">วันที่สร้างข้อมูล (Create date)</label>
            <div class="flex items-center gap-2">
              <div class="flex-1">
                <DateInput v-model="filters.createdFrom" :max="filters.createdTo || undefined" />
              </div>
              <span class="text-slate-400 text-xs">ถึง</span>
              <div class="flex-1">
                <DateInput v-model="filters.createdTo" :min="filters.createdFrom || undefined" />
              </div>
            </div>
            <div class="flex items-center gap-1 mt-1.5 flex-wrap">
              <button type="button" @click="setCreatePreset('today')"
                class="px-2 py-0.5 rounded border border-slate-200 text-xs text-slate-600 hover:bg-slate-50">วันนี้</button>
              <button type="button" @click="setCreatePreset('7d')"
                class="px-2 py-0.5 rounded border border-slate-200 text-xs text-slate-600 hover:bg-slate-50">7 วันที่ผ่านมา</button>
              <button type="button" @click="setCreatePreset('thisMonth')"
                class="px-2 py-0.5 rounded border border-slate-200 text-xs text-slate-600 hover:bg-slate-50">เดือนนี้</button>
              <button type="button" @click="setCreatePreset('lastMonth')"
                class="px-2 py-0.5 rounded border border-slate-200 text-xs text-slate-600 hover:bg-slate-50">เดือนที่แล้ว</button>
            </div>
          </div>
          <div class="md:col-span-3">
            <label class="text-xs font-medium text-slate-500 mb-1 block">New / Renew</label>
            <select v-model="filters.newOrRenew" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
              <option value="">ทั้งหมด</option>
              <option value="new">New</option>
              <option value="renew">Renew</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Active-filter chips + Clear all -->
      <div v-if="hasActiveFilters" class="flex items-center gap-2 flex-wrap pt-2 border-t border-slate-100">
        <span class="text-xs text-slate-500">ตัวกรองที่ใช้:</span>
        <span v-for="chip in activeChips" :key="chip.key"
          class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-brand-50 border border-brand-200 text-brand-800 text-xs">
          {{ chip.label }}
          <button type="button" @click="chip.clear" class="ml-0.5 text-brand-600 hover:text-brand-800">
            <i class="pi pi-times text-[9px]" />
          </button>
        </span>
        <button type="button" @click="clearAll"
          class="ml-auto text-xs text-rose-600 hover:text-rose-800 underline underline-offset-2">
          ล้างทั้งหมด
        </button>
      </div>
    </section>

    <section v-if="policyStore.listError" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ policyStore.listError }}
    </section>

    <!-- Results table -->
    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">ใบคำขอ</th>
              <th class="px-4 py-2 text-left">ชื่อ-สกุล</th>
              <th class="px-4 py-2 text-left">ชื่อผลิตภัณฑ์</th>
              <th class="px-4 py-2 text-left">เลขทะเบียนรถ</th>
              <th class="px-4 py-2 text-left">ยี่ห้อ / รุ่น</th>
              <th class="px-4 py-2 text-left">ชื่อบ.ประกัน</th>
              <th class="px-4 py-2 text-right">Premium</th>
              <th class="px-4 py-2 text-left">วันคุ้มครอง</th>
              <th class="px-4 py-2 text-left">สถานะ</th>
              <th class="px-4 py-2 text-center">เอกสารแนบ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="p in policyStore.list" :key="p.id" class="hover:bg-slate-50 cursor-pointer" @click="detailId = p.id">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">
                <div>{{ p.applicationNo ?? '—' }}</div>
                <div v-if="p.policyNo" class="text-[10px] text-slate-400">{{ p.policyNo }}</div>
              </td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ p.customerName || p.customerCode }}</div>
                <div class="text-xs text-slate-500">{{ p.customerCode }}</div>
              </td>
              <td class="px-4 py-2">
                <div class="text-slate-900 truncate max-w-[240px]" :title="p.productName ?? p.productCode ?? ''">
                  {{ p.productName ?? p.productCode ?? '—' }}
                </div>
                <div v-if="p.productCode && p.productName" class="text-[10px] text-slate-400 font-mono">{{ p.productCode }}</div>
              </td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ p.motorLicenseNo ?? '—' }}</td>
              <td class="px-4 py-2 text-slate-700">
                <div v-if="p.motorVehicleBrand || p.motorVehicleModel">
                  <span>{{ p.motorVehicleBrand ?? '' }}</span>
                  <span v-if="p.motorVehicleBrand && p.motorVehicleModel" class="text-slate-400"> / </span>
                  <span>{{ p.motorVehicleModel ?? '' }}</span>
                </div>
                <span v-else class="text-slate-400">—</span>
              </td>
              <td class="px-4 py-2">
                <div class="text-slate-900 truncate max-w-[160px]" :title="p.carrierName ?? ''">
                  {{ p.carrierName ?? p.carrierCode ?? '—' }}
                </div>
                <div v-if="p.carrierCode && p.carrierName" class="text-[10px] text-slate-400 font-mono">{{ p.carrierCode }}</div>
              </td>
              <td class="px-4 py-2 text-right font-medium text-slate-900">{{ fmtBaht(p.annualPremium) }}</td>
              <td class="px-4 py-2 text-slate-700">
                <div>{{ p.effectiveDate ?? '—' }}</div>
                <div class="text-xs text-slate-500">ถึง {{ p.expiryDate ?? '—' }}</div>
              </td>
              <td class="px-4 py-2">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', statusBadge(p.status)]"
                  :title="p.status">
                  {{ p.statusLabel || p.status }}
                </span>
                <div v-if="p.premiumCheck === 'mismatch'" class="text-[10px] text-amber-600 mt-0.5">Δ premium</div>
              </td>
              <td class="px-4 py-2 text-center" @click.stop>
                <button type="button"
                  class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-slate-200 text-slate-600 text-xs hover:bg-slate-50 hover:text-brand-600 hover:border-brand-200"
                  :title="'ดูเอกสารแนบ'"
                  @click="openDocsModal(p.id, p.policyNo || p.applicationNo || p.customerName || p.id)">
                  <i class="pi pi-paperclip text-[11px]" />
                  <span>ดู</span>
                </button>
              </td>
            </tr>
            <tr v-if="!policyStore.listLoading && policyStore.list.length === 0">
              <td colspan="10" class="px-4 py-6 text-center text-slate-500">ไม่พบกรมธรรม์</td>
            </tr>
            <tr v-if="policyStore.listLoading && policyStore.list.length === 0">
              <td colspan="10" class="px-4 py-6 text-center text-slate-500">Loading…</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination footer -->
      <div v-if="policyStore.listMeta" class="flex items-center justify-between gap-3 px-4 py-3 border-t border-slate-100 text-sm flex-wrap">
        <div class="flex items-center gap-2 text-slate-500">
          <span>แสดง</span>
          <select v-model.number="filters.perPage"
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
            :disabled="policyStore.listLoading || page <= 1" @click="goPage(1)"
            :title="'หน้าแรก'">
            <i class="pi pi-angle-double-left text-xs" />
          </button>
          <button class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="policyStore.listLoading || page <= 1" @click="goPage(page - 1)"
            :title="'ก่อนหน้า'">
            <i class="pi pi-angle-left text-xs" />
          </button>
          <span class="text-slate-600 px-2">
            หน้า
            <input type="number" min="1" :max="policyStore.listMeta.lastPage"
              :value="policyStore.listMeta.currentPage"
              @change="e => goPage(Number((e.target as HTMLInputElement).value))"
              class="w-14 border border-slate-200 rounded-md px-2 py-0.5 text-sm text-center focus:outline-none focus:border-brand-400" />
            / {{ policyStore.listMeta.lastPage.toLocaleString() }}
          </span>
          <button class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="policyStore.listLoading || page >= (policyStore.listMeta?.lastPage ?? 1)" @click="goPage(page + 1)"
            :title="'ถัดไป'">
            <i class="pi pi-angle-right text-xs" />
          </button>
          <button class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="policyStore.listLoading || page >= (policyStore.listMeta?.lastPage ?? 1)" @click="goPage(policyStore.listMeta?.lastPage ?? 1)"
            :title="'หน้าสุดท้าย'">
            <i class="pi pi-angle-double-right text-xs" />
          </button>
        </div>
      </div>
    </section>

    <PolicyDetailDrawer :policy-id="detailId" @close="detailId = null" />
    <PolicyCreateWizard :open="showCreate" @close="showCreate = false" @created="() => { page = 1; load() }" />

    <!-- Documents popup — list attachments with per-file open button -->
    <div v-if="docsModalPolicyId !== null" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
      @click.self="closeDocsModal">
      <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full max-h-[80vh] flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200">
          <div>
            <h3 class="text-base font-semibold text-slate-900">เอกสารแนบ</h3>
            <div class="text-xs text-slate-500 mt-0.5">{{ docsModalPolicyLabel }}</div>
          </div>
          <button type="button" @click="closeDocsModal" class="text-slate-400 hover:text-slate-800 p-1">
            <i class="pi pi-times" />
          </button>
        </div>
        <div class="flex-1 overflow-y-auto p-5">
          <div v-if="docsLoading" class="text-slate-500 text-sm text-center py-6">
            <i class="pi pi-spin pi-spinner mr-1" /> กำลังโหลด…
          </div>
          <div v-else-if="docsError" class="text-rose-700 text-sm px-3 py-2 rounded bg-rose-50 border border-rose-200">
            {{ docsError }}
          </div>
          <div v-else-if="docsList.length === 0" class="text-slate-500 text-sm text-center py-6">
            ไม่มีเอกสารแนบสำหรับกรมธรรม์นี้
          </div>
          <ul v-else class="space-y-2">
            <li v-for="d in docsList" :key="d.id"
              class="flex items-center gap-3 px-3 py-2 rounded-lg border border-slate-200 hover:bg-slate-50">
              <i class="pi pi-file text-brand-500 text-lg shrink-0" />
              <div class="flex-1 min-w-0">
                <div class="text-sm text-slate-900 truncate" :title="d.fileName">{{ d.fileName }}</div>
                <div class="text-xs text-slate-500">
                  <span class="inline-block px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 mr-1">{{ docTypeLabel(d.type) }}</span>
                  <span v-if="d.uploadedAt">{{ new Date(d.uploadedAt).toLocaleDateString('th-TH') }}</span>
                </div>
              </div>
              <button type="button" @click="openDocument(docsModalPolicyId!, d.id)"
                class="px-3 py-1.5 rounded-md border border-brand-200 text-brand-700 text-xs hover:bg-brand-50 shrink-0">
                <i class="pi pi-external-link mr-1 text-[10px]" /> เปิด
              </button>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
