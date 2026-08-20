<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  usePolicyStore,
  type Policy,
  type PolicyStatus,
  type PaymentMethod,
  type PolicyDocType,
  type MotorDetails,
  type PropertyDetails,
  type Rider,
  type Beneficiary,
  type NewOrRenew,
} from '../../stores/policies'
import { useCustomerStore } from '../../stores/customers'
import { useAgentStore } from '../../stores/agents'

const { t } = useI18n()
const policyStore = usePolicyStore()
const customerStore = useCustomerStore()
const agentStore = useAgentStore()

onMounted(async () => {
  await Promise.all([
    policyStore.load(),
    customerStore.load(),
    agentStore.load(),
  ])
  // Commission engine bootstrap removed with the MGM rewrite. The new
  // MgmCommissionEngine (PR-D) writes to commission_ledgers server-side;
  // the drawer's commission tab will re-render from that when it ships.
})

// Product/carrier catalog (mirrors Sections 3-4)
const carriers = [
  { id: 'c1', code: 'AIA', name: 'บริษัท เอไอเอ จำกัด' },
  { id: 'c2', code: 'TLI', name: 'บริษัท ไทยประกันชีวิต จำกัด (มหาชน)' },
  { id: 'c3', code: 'MTI', name: 'บริษัท เมืองไทยประกันชีวิต จำกัด (มหาชน)' },
  { id: 'c4', code: 'BLA', name: 'บริษัท กรุงเทพประกันชีวิต จำกัด (มหาชน)' },
  { id: 'c5', code: 'VIB', name: 'บริษัท วิริยะประกันภัย จำกัด (มหาชน)' },
  { id: 'c6', code: 'DHA', name: 'บริษัท ทิพยประกันภัย จำกัด (มหาชน)' },
  { id: 'c8', code: 'ALL', name: 'บริษัท อลิอันซ์ อยุธยา ประกันชีวิต จำกัด (มหาชน)' },
]
const products = [
  { id: 'p1', code: 'AIA-WL100', name: 'เอไอเอ ตลอดชีพ 100', carrierId: 'c1', defaultCoverage: 2_000_000, defaultPremium: 48_000 },
  { id: 'p2', code: 'AIA-EN20', name: 'เอไอเอ สะสมทรัพย์ 20/10', carrierId: 'c1', defaultCoverage: 1_000_000, defaultPremium: 28_000 },
  { id: 'p3', code: 'AIA-HEALTH+', name: 'เอไอเอ เฮลธ์ พลัส', carrierId: 'c1', defaultCoverage: 30_000_000, defaultPremium: 32_000 },
  { id: 'p4', code: 'TLI-RET65', name: 'บำนาญ มั่นคง 65', carrierId: 'c2', defaultCoverage: 3_000_000, defaultPremium: 45_000 },
  { id: 'p5', code: 'TLI-CI+', name: 'ไทยประกันชีวิต โรคร้ายแรง พรีเมียม', carrierId: 'c2', defaultCoverage: 5_000_000, defaultPremium: 65_000 },
  { id: 'p6', code: 'MTI-UL', name: 'เมืองไทย ยูนิตลิงก์', carrierId: 'c3', defaultCoverage: 5_000_000, defaultPremium: 60_000 },
  { id: 'p7', code: 'BLA-PA', name: 'กรุงเทพ PA สบายใจ', carrierId: 'c4', defaultCoverage: 1_000_000, defaultPremium: 4_500 },
  { id: 'p8', code: 'VIB-MOTOR1', name: 'วิริยะ ประกันรถยนต์ ชั้น 1', carrierId: 'c5', defaultCoverage: 5_000_000, defaultPremium: 28_500 },
  { id: 'p9', code: 'DHA-HOME', name: 'ทิพย โฮม เซฟ', carrierId: 'c6', defaultCoverage: 3_000_000, defaultPremium: 6_500 },
  { id: 'p10', code: 'DHA-TRAVEL', name: 'ทิพย ทราเวล พลัส', carrierId: 'c6', defaultCoverage: 5_000_000, defaultPremium: 1_200 },
  { id: 'p11', code: 'ALL-TERM10', name: 'อลิอันซ์ เทอม 10', carrierId: 'c8', defaultCoverage: 10_000_000, defaultPremium: 18_000 },
]

function productById(id: string) {
  return products.find((p) => p.id === id) ?? null
}
function carrierById(id: string) {
  return carriers.find((c) => c.id === id) ?? null
}

// ── Stats ─────────────────────────────────────────────────────────────────
const stats = computed(() => {
  const all = policyStore.policies
  const active = all.filter((p) => p.status === 'active')
  const monthlyPremium = active.reduce((sum, p) => sum + p.annualPremium / 12, 0)
  const pendingAction = all.filter((p) => ['quote', 'application', 'submitted', 'issued', 'lapsed'].includes(p.status)).length
  return {
    total: all.length,
    active: active.length,
    monthlyPremium,
    pendingAction,
  }
})

// ── Filters ───────────────────────────────────────────────────────────────
const search = ref('')
const statusFilter = ref<'all' | PolicyStatus>('all')
const agentFilter = ref<'all' | string>('all')
const carrierFilter = ref<'all' | string>('all')

const allStatuses: PolicyStatus[] = ['quote', 'application', 'submitted', 'issued', 'active', 'lapsed', 'cancelled', 'reinstated']

const filteredPolicies = computed(() =>
  policyStore.policies.filter((p) => {
    if (statusFilter.value !== 'all' && p.status !== statusFilter.value) return false
    if (agentFilter.value !== 'all' && p.writingAgentId !== agentFilter.value) return false
    if (carrierFilter.value !== 'all' && p.carrierId !== carrierFilter.value) return false
    if (search.value) {
      const q = search.value.toLowerCase()
      const customer = customerStore.getCustomer(p.customerId)
      const agent = agentStore.getAgent(p.writingAgentId)
      const hay = `${p.policyNo ?? ''} ${p.quoteNo} ${p.applicationNo ?? ''} ${customer?.firstName ?? ''} ${customer?.lastName ?? ''} ${agent?.firstName ?? ''} ${agent?.lastName ?? ''}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  }),
)

function statusBadgeClass(s: PolicyStatus): string {
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

function statusDot(s: PolicyStatus): string {
  return {
    quote: 'bg-slate-400',
    application: 'bg-amber-500',
    submitted: 'bg-amber-500',
    issued: 'bg-sky-500',
    active: 'bg-emerald-500',
    lapsed: 'bg-rose-500',
    cancelled: 'bg-slate-400',
    reinstated: 'bg-violet-500',
    expired: 'bg-slate-400',
  }[s]
}

function eventIcon(type: string): string {
  return {
    created: 'pi pi-plus-circle',
    convertedToApplication: 'pi pi-file-edit',
    submittedToCarrier: 'pi pi-send',
    issued: 'pi pi-check-circle',
    premiumPaid: 'pi pi-wallet',
    renewed: 'pi pi-refresh',
    lapsed: 'pi pi-exclamation-triangle',
    cancelled: 'pi pi-ban',
    reinstated: 'pi pi-replay',
    detailsUpdated: 'pi pi-pencil',
    documentUploaded: 'pi pi-upload',
  }[type] ?? 'pi pi-circle'
}

function eventDot(type: string): string {
  return {
    created: 'bg-slate-400',
    convertedToApplication: 'bg-amber-500',
    submittedToCarrier: 'bg-amber-500',
    issued: 'bg-sky-500',
    premiumPaid: 'bg-emerald-500',
    renewed: 'bg-violet-500',
    lapsed: 'bg-rose-500',
    cancelled: 'bg-rose-600',
    reinstated: 'bg-violet-500',
    detailsUpdated: 'bg-slate-400',
    documentUploaded: 'bg-slate-400',
  }[type] ?? 'bg-slate-400'
}

const fmtTHB = (n: number) => n.toLocaleString('th-TH')

function fmtShortTHB(n: number) {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 1) + ' ล้าน'
  if (n >= 1_000) return (n / 1_000).toFixed(0) + ' พัน'
  return String(n)
}

// ── Create quote dialog ───────────────────────────────────────────────────
const showQuote = ref(false)
const quoteStep = ref<1 | 2 | 3>(1)
const quoteSubmitting = ref(false)

const quoteForm = reactive({
  customerId: '',
  productId: '',
  writingAgentId: 'a4',
  coverage: 0,
  annualPremium: 0,
  premiumMode: 'annual' as Policy['premiumMode'],
  notes: '',
})

function openQuote() {
  Object.assign(quoteForm, {
    customerId: '',
    productId: '',
    writingAgentId: 'a4',
    coverage: 0,
    annualPremium: 0,
    premiumMode: 'annual',
    notes: '',
  })
  quoteStep.value = 1
  showQuote.value = true
}

function onQuoteProductChange() {
  const p = productById(quoteForm.productId)
  if (p) {
    quoteForm.coverage = p.defaultCoverage
    quoteForm.annualPremium = p.defaultPremium
  }
}

const step1Valid = computed(() => !!quoteForm.customerId)
const step2Valid = computed(() => !!quoteForm.productId)
const step3Valid = computed(
  () =>
    !!quoteForm.writingAgentId &&
    quoteForm.coverage > 0 &&
    quoteForm.annualPremium > 0,
)

async function submitQuote() {
  if (!step1Valid.value || !step2Valid.value || !step3Valid.value) return
  const product = productById(quoteForm.productId)
  if (!product) return
  quoteSubmitting.value = true
  await new Promise((r) => setTimeout(r, 400))
  policyStore.createQuote({
    customerId: quoteForm.customerId,
    productId: quoteForm.productId,
    carrierId: product.carrierId,
    writingAgentId: quoteForm.writingAgentId,
    coverage: quoteForm.coverage,
    annualPremium: quoteForm.annualPremium,
    premiumMode: quoteForm.premiumMode,
    notes: quoteForm.notes,
  })
  quoteSubmitting.value = false
  showQuote.value = false
}

// ── Direct-create policy dialog ───────────────────────────────────────────
const showDirectCreate = ref(false)
const directSubmitting = ref(false)

const directForm = reactive({
  customerId: '',
  productId: '',
  writingAgentId: 'a4',
  coverage: 0,
  annualPremium: 0,
  premiumMode: 'annual' as Policy['premiumMode'],
  policyNo: '',
  applicationNo: '',
  effectiveDate: '',
  expiryDate: '',
  issueDate: '',
  nextPremiumDue: '',
  status: 'active' as PolicyStatus,
  newOrRenew: 'new' as NewOrRenew,
  policyYear: 1,
  actYear: 1,
  notes: '',
})

function openDirectCreate() {
  Object.assign(directForm, {
    customerId: '',
    productId: '',
    writingAgentId: 'a4',
    coverage: 0,
    annualPremium: 0,
    premiumMode: 'annual',
    policyNo: '',
    applicationNo: '',
    effectiveDate: '',
    expiryDate: '',
    issueDate: '',
    nextPremiumDue: '',
    status: 'active',
    newOrRenew: 'new',
    policyYear: 1,
    actYear: 1,
    notes: '',
  })
  showDirectCreate.value = true
}

function closeDirectCreate() {
  showDirectCreate.value = false
}

function onDirectProductChange() {
  const p = productById(directForm.productId)
  if (p) {
    if (directForm.coverage === 0) directForm.coverage = p.defaultCoverage
    if (directForm.annualPremium === 0) directForm.annualPremium = p.defaultPremium
  }
}

const directValid = computed(
  () =>
    !!directForm.customerId &&
    !!directForm.productId &&
    !!directForm.writingAgentId &&
    directForm.coverage > 0 &&
    directForm.annualPremium > 0,
)

async function submitDirectCreate() {
  if (!directValid.value) return
  const product = productById(directForm.productId)
  if (!product) return
  directSubmitting.value = true
  await new Promise((r) => setTimeout(r, 300))
  policyStore.createPolicyDirect({
    customerId: directForm.customerId,
    productId: directForm.productId,
    carrierId: product.carrierId,
    writingAgentId: directForm.writingAgentId,
    coverage: directForm.coverage,
    annualPremium: directForm.annualPremium,
    premiumMode: directForm.premiumMode,
    policyNo: directForm.policyNo.trim() || null,
    applicationNo: directForm.applicationNo.trim() || null,
    effectiveDate: directForm.effectiveDate || null,
    expiryDate: directForm.expiryDate || null,
    issueDate: directForm.issueDate || null,
    nextPremiumDue: directForm.nextPremiumDue || null,
    status: directForm.status,
    newOrRenew: directForm.newOrRenew,
    policyYear: directForm.policyYear,
    actYear: directForm.actYear,
    notes: directForm.notes,
  })
  directSubmitting.value = false
  showDirectCreate.value = false
}

// ── Detail drawer ─────────────────────────────────────────────────────────
const detailId = ref<string | null>(null)
const detailTab = ref<'overview' | 'events' | 'payments' | 'documents' | 'commission'>('overview')
const detail = computed(() => (detailId.value ? policyStore.getPolicy(detailId.value) : null))

function openDetail(p: Policy) {
  detailId.value = p.id
  detailTab.value = 'overview'
}
function closeDetail() {
  detailId.value = null
}

// ── Edit policy details dialog ───────────────────────────────────────────
type EditTab = 'general' | 'motor' | 'property' | 'riders' | 'beneficiaries'

const showEditPolicy = ref(false)
const editPolicyId = ref<string | null>(null)
const editTab = ref<EditTab>('general')

interface EditPolicyForm {
  policyYear: number
  actYear: number
  newOrRenew: NewOrRenew
  freelookActive: boolean
  notes: string
  motorEnabled: boolean
  motor: MotorDetails
  propertyEnabled: boolean
  property: PropertyDetails
  riders: Rider[]
  beneficiaries: Beneficiary[]
}

const blankMotor = (): MotorDetails => ({
  vehicleBrand: '',
  vehicleModel: '',
  licenseNo: '',
  engineNo: '',
  chassisNo: '',
  registerYear: '',
  noPassenger: 0,
  typeDriver: '',
  typeVehicle: '',
  notes: '',
})
const blankProperty = (): PropertyDetails => ({
  insuredName: '',
  insuredAddress: '',
  buildingCoverage: 0,
  furnitureCoverage: 0,
  stockCoverage: 0,
  otherCoverage: 0,
  otherDetail: '',
  notes: '',
})

const editForm = ref<EditPolicyForm>({
  policyYear: 1,
  actYear: 1,
  newOrRenew: 'new',
  freelookActive: false,
  notes: '',
  motorEnabled: false,
  motor: blankMotor(),
  propertyEnabled: false,
  property: blankProperty(),
  riders: [],
  beneficiaries: [],
})

function openEditPolicy(p: Policy) {
  editPolicyId.value = p.id
  editTab.value = 'general'
  editForm.value = {
    policyYear: p.policyYear,
    actYear: p.actYear,
    newOrRenew: p.newOrRenew,
    freelookActive: p.freelookActive,
    notes: p.notes,
    motorEnabled: !!p.motor,
    motor: p.motor ? { ...p.motor } : blankMotor(),
    propertyEnabled: !!p.property,
    property: p.property ? { ...p.property } : blankProperty(),
    riders: p.riders.map((r) => ({ ...r })),
    beneficiaries: p.beneficiaries.map((b) => ({ ...b })),
  }
  showEditPolicy.value = true
}

function closeEditPolicy() {
  showEditPolicy.value = false
  editPolicyId.value = null
}

function addRider() {
  editForm.value.riders = [...editForm.value.riders, { name: '', premium: 0, notes: '' }]
}
function removeRider(idx: number) {
  editForm.value.riders = editForm.value.riders.filter((_, i) => i !== idx)
}

function addBeneficiary() {
  editForm.value.beneficiaries = [
    ...editForm.value.beneficiaries,
    { name: '', relation: '', share: 0 },
  ]
}
function removeBeneficiary(idx: number) {
  editForm.value.beneficiaries = editForm.value.beneficiaries.filter((_, i) => i !== idx)
}

const propertyTotal = computed(() => {
  const p = editForm.value.property
  return p.buildingCoverage + p.furnitureCoverage + p.stockCoverage + p.otherCoverage
})
const beneficiariesTotal = computed(() =>
  editForm.value.beneficiaries.reduce((sum, b) => sum + (b.share || 0), 0),
)

async function saveEditPolicy() {
  if (!editPolicyId.value) return
  const f = editForm.value
  // Filter out empty riders/beneficiaries so blank rows don't pollute the store.
  const cleanRiders = f.riders.filter((r) => r.name.trim().length > 0)
  const cleanBeneficiaries = f.beneficiaries.filter((b) => b.name.trim().length > 0)
  await policyStore.updatePolicyDetails(editPolicyId.value, {
    policyYear: f.policyYear,
    actYear: f.actYear,
    newOrRenew: f.newOrRenew,
    freelookActive: f.freelookActive,
    notes: f.notes,
    motor: f.motorEnabled ? f.motor : null,
    property: f.propertyEnabled ? f.property : null,
    riders: cleanRiders,
    beneficiaries: cleanBeneficiaries,
  })
  closeEditPolicy()
}

// ── Action dialogs ────────────────────────────────────────────────────────
type ActionType = 'issue' | 'payment' | 'renew' | 'cancel' | 'lapse' | 'reinstate'
const actionDialog = ref<{ type: ActionType; policy: Policy } | null>(null)

const actionForm = reactive({
  policyNo: '',
  effectiveDate: '',
  paymentDate: '',
  paymentAmount: 0,
  paymentMethod: 'bankTransfer' as PaymentMethod,
  paymentReference: '',
  newExpiry: '',
  newPremium: 0,
  cancelReason: '',
  cancelDate: '',
  lapseDate: '',
  reinstateDate: '',
})

function openAction(policy: Policy, type: ActionType) {
  Object.assign(actionForm, {
    policyNo: policy.policyNo ?? '',
    effectiveDate: policy.effectiveDate ?? '2569-06-06',
    paymentDate: '2569-06-06',
    paymentAmount: policy.annualPremium,
    paymentMethod: 'bankTransfer',
    paymentReference: '',
    newExpiry: '',
    newPremium: policy.annualPremium,
    cancelReason: '',
    cancelDate: '2569-06-06',
    lapseDate: '2569-06-06',
    reinstateDate: '2569-06-06',
  })
  actionDialog.value = { type, policy }
}

function closeAction() {
  actionDialog.value = null
}

async function runAction() {
  if (!actionDialog.value) return
  const { type, policy } = actionDialog.value
  switch (type) {
    case 'issue':
      if (!actionForm.policyNo.trim() || !actionForm.effectiveDate) return
      await policyStore.issuePolicy(policy.id, actionForm.policyNo, actionForm.effectiveDate)
      break
    case 'payment':
      if (actionForm.paymentAmount <= 0 || !actionForm.paymentDate) return
      await policyStore.recordPremiumPayment({
        policyId: policy.id,
        paymentDate: actionForm.paymentDate,
        amount: actionForm.paymentAmount,
        method: actionForm.paymentMethod,
        reference: actionForm.paymentReference,
      })
      break
    case 'renew':
      if (!actionForm.newExpiry || actionForm.newPremium <= 0) return
      await policyStore.renewPolicy(policy.id, actionForm.newExpiry, actionForm.newPremium)
      break
    case 'cancel':
      if (!actionForm.cancelReason.trim() || !actionForm.cancelDate) return
      await policyStore.cancelPolicy(policy.id, actionForm.cancelReason, actionForm.cancelDate)
      break
    case 'lapse':
      if (!actionForm.lapseDate) return
      await policyStore.lapsePolicy(policy.id, actionForm.lapseDate)
      break
    case 'reinstate':
      if (!actionForm.reinstateDate) return
      await policyStore.reinstatePolicy(policy.id, actionForm.reinstateDate)
      break
  }
  // Commission event processing removed with the MGM rewrite — server-side
  // observer on PolicyPayment will re-fire once PR-D lands.
  actionDialog.value = null
}

// Quick lifecycle helpers (no dialog needed)
async function quickConvertToApplication(p: Policy) {
  await policyStore.convertToApplication(p.id)
}
async function quickSubmitToCarrier(p: Policy) {
  await policyStore.submitToCarrier(p.id)
}

// ── Document upload ───────────────────────────────────────────────────────
const docFileInput = ref<HTMLInputElement | null>(null)
const docTypeSelect = ref<PolicyDocType>('policy')
function triggerDocUpload() {
  docFileInput.value?.click()
}
async function onDocFileChange(e: Event) {
  if (!detail.value) return
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  try {
    await policyStore.uploadDocument(detail.value.id, {
      type: docTypeSelect.value,
      fileName: file.name,
    })
  } finally {
    input.value = ''
  }
}

function customerNameById(id: string) {
  const c = customerStore.getCustomer(id)
  return c ? `${c.firstName} ${c.lastName}` : '–'
}

function agentNameById(id: string) {
  const a = agentStore.getAgent(id)
  return a ? `${a.firstName} ${a.lastName}` : '–'
}

function policyDisplayNo(p: Policy) {
  return p.policyNo ?? p.applicationNo ?? p.quoteNo
}

function policyDisplayLabel(p: Policy) {
  if (p.policyNo) return t('policies.fields.policyNo')
  if (p.applicationNo) return t('policies.fields.applicationNo')
  return t('policies.fields.quoteNo')
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.policies.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.policies.description') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button
          type="button"
          @click="openQuote"
          class="px-3 py-2.5 border border-slate-300 text-slate-700 rounded-lg font-medium hover:bg-slate-50 transition flex items-center gap-2"
          title="เริ่มจากใบเสนอราคา แล้วค่อยแปลงเป็นใบสมัคร / กรมธรรม์ภายหลัง"
        >
          <i class="pi pi-file-edit" />
          <span class="hidden sm:inline">{{ t('policies.list.addQuote') }}</span>
        </button>
        <button
          type="button"
          @click="openDirectCreate"
          class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2"
          title="กรอกเลขกรมธรรม์ที่ออกแล้ว / นำเข้าจากระบบเดิม"
        >
          <i class="pi pi-plus" />
          <span class="hidden sm:inline">เพิ่มกรมธรรม์</span>
        </button>
      </div>
    </header>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('policies.list.total') }}</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ stats.total }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('policies.list.active') }}</div>
        <div class="text-2xl font-semibold text-emerald-600 mt-1">{{ stats.active }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">{{ t('policies.list.monthlyPremium') }}</div>
        <div class="text-2xl font-semibold text-brand-600 mt-1">฿{{ fmtShortTHB(Math.round(stats.monthlyPremium)) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500 flex items-center gap-1.5">
          <i class="pi pi-clock text-amber-500" />
          {{ t('policies.list.pendingAction') }}
        </div>
        <div class="text-2xl font-semibold text-amber-600 mt-1">{{ stats.pendingAction }}</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-[240px]">
        <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
        <input
          v-model="search"
          type="search"
          :placeholder="t('policies.list.searchPlaceholder')"
          class="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-brand-400"
        />
      </div>
      <select
        v-model="statusFilter"
        class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400"
      >
        <option value="all">{{ t('policies.cols.status') }}: {{ t('common.all') }}</option>
        <option v-for="s in allStatuses" :key="s" :value="s">{{ t(`policies.status.${s}`) }}</option>
      </select>
      <select
        v-model="agentFilter"
        class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400 max-w-[200px]"
      >
        <option value="all">{{ t('policies.fields.writingAgent') }}: {{ t('common.all') }}</option>
        <option v-for="a in agentStore.agents" :key="a.id" :value="a.id">
          {{ a.firstName }} {{ a.lastName }} ({{ a.agentCode }})
        </option>
      </select>
      <select
        v-model="carrierFilter"
        class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400 max-w-[180px]"
      >
        <option value="all">{{ t('policies.fields.carrier') }}: {{ t('common.all') }}</option>
        <option v-for="c in carriers" :key="c.id" :value="c.id">{{ c.code }}</option>
      </select>
    </div>

    <!-- Table -->
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="text-left px-4 py-3 font-medium">{{ t('policies.cols.policy') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('policies.cols.customer') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('policies.cols.product') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('policies.cols.agent') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('policies.cols.coverage') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('policies.cols.premium') }}</th>
              <th class="text-left px-4 py-3 font-medium">{{ t('policies.cols.status') }}</th>
              <th class="text-right px-4 py-3 font-medium">{{ t('policies.cols.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="p in filteredPolicies"
              :key="p.id"
              class="hover:bg-slate-50/50 cursor-pointer"
              @click="openDetail(p)"
            >
              <td class="px-4 py-3">
                <div class="text-xs text-slate-400 font-mono">{{ policyDisplayLabel(p) }}</div>
                <div class="font-mono text-sm text-slate-900">{{ policyDisplayNo(p) }}</div>
              </td>
              <td class="px-4 py-3 text-xs">
                <div class="text-slate-900">{{ customerNameById(p.customerId) }}</div>
                <div class="text-slate-400 font-mono">{{ customerStore.getCustomer(p.customerId)?.customerCode }}</div>
              </td>
              <td class="px-4 py-3 max-w-xs">
                <div class="text-slate-900 text-sm truncate">{{ productById(p.productId)?.name }}</div>
                <div class="text-xs text-slate-400">{{ carrierById(p.carrierId)?.code }}</div>
              </td>
              <td class="px-4 py-3 text-xs">
                <div class="text-slate-900">{{ agentNameById(p.writingAgentId) }}</div>
                <div class="text-slate-400 font-mono">{{ agentStore.getAgent(p.writingAgentId)?.agentCode }}</div>
              </td>
              <td class="px-4 py-3 text-right font-medium text-slate-900 whitespace-nowrap">{{ fmtShortTHB(p.coverage) }}</td>
              <td class="px-4 py-3 text-right text-xs">
                <div class="text-slate-900 font-medium">฿{{ fmtTHB(p.annualPremium) }}</div>
                <div class="text-slate-400">{{ t(`products.premiumModes.${p.premiumMode}`) }}</div>
              </td>
              <td class="px-4 py-3">
                <span :class="['inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium', statusBadgeClass(p.status)]">
                  <span :class="['w-1.5 h-1.5 rounded-full', statusDot(p.status)]" />
                  {{ t(`policies.status.${p.status}`) }}
                </span>
              </td>
              <td class="px-4 py-3" @click.stop>
                <div class="flex items-center justify-end gap-1">
                  <!-- Status-aware quick actions -->
                  <button
                    v-if="p.status === 'quote'"
                    @click="quickConvertToApplication(p)"
                    class="px-2 py-1 text-xs text-amber-700 hover:bg-amber-50 rounded transition"
                    :title="t('policies.actions.convertToApplication')"
                  >
                    <i class="pi pi-file-edit" />
                  </button>
                  <button
                    v-if="p.status === 'application'"
                    @click="quickSubmitToCarrier(p)"
                    class="px-2 py-1 text-xs text-amber-700 hover:bg-amber-50 rounded transition"
                    :title="t('policies.actions.submitToCarrier')"
                  >
                    <i class="pi pi-send" />
                  </button>
                  <button
                    v-if="p.status === 'submitted' || p.status === 'application'"
                    @click="openAction(p, 'issue')"
                    class="px-2 py-1 text-xs text-sky-700 hover:bg-sky-50 rounded transition"
                    :title="t('policies.actions.issuePolicy')"
                  >
                    <i class="pi pi-check-circle" />
                  </button>
                  <button
                    v-if="p.status === 'issued' || p.status === 'active'"
                    @click="openAction(p, 'payment')"
                    class="px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 rounded transition"
                    :title="t('policies.actions.recordPayment')"
                  >
                    <i class="pi pi-wallet" />
                  </button>
                  <button
                    v-if="p.status === 'active'"
                    @click="openAction(p, 'renew')"
                    class="px-2 py-1 text-xs text-violet-700 hover:bg-violet-50 rounded transition"
                    :title="t('policies.actions.renewPolicy')"
                  >
                    <i class="pi pi-refresh" />
                  </button>
                  <button
                    v-if="p.status === 'active'"
                    @click="openAction(p, 'lapse')"
                    class="px-2 py-1 text-xs text-amber-700 hover:bg-amber-50 rounded transition"
                    :title="t('policies.actions.markLapsed')"
                  >
                    <i class="pi pi-exclamation-triangle" />
                  </button>
                  <button
                    v-if="p.status === 'lapsed'"
                    @click="openAction(p, 'reinstate')"
                    class="px-2 py-1 text-xs text-violet-700 hover:bg-violet-50 rounded transition"
                    :title="t('policies.actions.reinstate')"
                  >
                    <i class="pi pi-replay" />
                  </button>
                  <button
                    v-if="!['cancelled', 'expired'].includes(p.status)"
                    @click="openAction(p, 'cancel')"
                    class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded transition"
                    :title="t('policies.actions.cancelPolicy')"
                  >
                    <i class="pi pi-ban" />
                  </button>
                  <button
                    @click="openDetail(p)"
                    class="px-2 py-1 text-xs text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded transition"
                    :title="t('policies.actions.view')"
                  >
                    <i class="pi pi-eye" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredPolicies.length">
              <td colspan="8" class="px-4 py-10 text-center text-slate-400 text-sm">{{ t('common.noData') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create quote dialog -->
    <div v-if="showQuote" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[92vh] flex flex-col">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <h3 class="font-semibold text-slate-900">{{ t('policies.quote.createTitle') }}</h3>
          <button @click="showQuote = false" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>

        <!-- Stepper -->
        <div class="px-5 py-3 border-b border-slate-100 shrink-0">
          <ol class="flex items-center gap-2">
            <li v-for="(label, idx) in [t('policies.quote.step1'), t('policies.quote.step2'), t('policies.quote.step3')]" :key="idx" class="flex items-center gap-2 flex-1">
              <div
                :class="[
                  'w-7 h-7 rounded-full text-xs font-semibold flex items-center justify-center shrink-0',
                  quoteStep >= (idx + 1) ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-400 border border-slate-200',
                ]"
              >
                <i v-if="quoteStep > (idx + 1)" class="pi pi-check text-[10px]" />
                <span v-else>{{ idx + 1 }}</span>
              </div>
              <span :class="['text-xs hidden sm:inline', quoteStep === (idx + 1) ? 'text-slate-900 font-medium' : 'text-slate-500']">{{ label }}</span>
              <div v-if="idx < 2" class="flex-1 h-px bg-slate-200" />
            </li>
          </ol>
        </div>

        <div class="px-5 py-5 overflow-y-auto flex-1 space-y-4">
          <!-- Step 1: customer -->
          <section v-if="quoteStep === 1">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.quote.pickCustomer') }} <span class="text-rose-500">*</span></label>
            <select
              v-model="quoteForm.customerId"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            >
              <option value="" disabled>— เลือกลูกค้า —</option>
              <option v-for="c in customerStore.customers.filter(x => x.active)" :key="c.id" :value="c.id">
                {{ c.firstName }} {{ c.lastName }} ({{ c.customerCode }}) — {{ c.phone }}
              </option>
            </select>
            <div v-if="quoteForm.customerId" class="mt-4 card p-3 bg-slate-50 border-slate-100">
              <div class="text-xs text-slate-500">ลูกค้าที่เลือก</div>
              <div class="font-medium text-slate-900">{{ customerNameById(quoteForm.customerId) }}</div>
              <div class="text-xs text-slate-500">{{ customerStore.getCustomer(quoteForm.customerId)?.occupation }}</div>
            </div>
          </section>

          <!-- Step 2: product -->
          <section v-if="quoteStep === 2">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.quote.pickProduct') }} <span class="text-rose-500">*</span></label>
            <select
              v-model="quoteForm.productId"
              @change="onQuoteProductChange"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            >
              <option value="" disabled>— เลือกผลิตภัณฑ์ —</option>
              <option v-for="p in products" :key="p.id" :value="p.id">
                [{{ carrierById(p.carrierId)?.code }}] {{ p.code }} · {{ p.name }}
              </option>
            </select>
            <div v-if="quoteForm.productId" class="mt-4 card p-3 bg-slate-50 border-slate-100">
              <div class="text-xs text-slate-500">ค่าเริ่มต้น (แก้ไขได้ในขั้นถัดไป)</div>
              <div class="text-sm text-slate-900 mt-1">
                ทุน {{ fmtTHB(quoteForm.coverage) }} บาท · เบี้ย {{ fmtTHB(quoteForm.annualPremium) }} บาท/ปี
              </div>
            </div>
          </section>

          <!-- Step 3: details + agent -->
          <section v-if="quoteStep === 3" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.quote.pickWritingAgent') }} <span class="text-rose-500">*</span></label>
              <select
                v-model="quoteForm.writingAgentId"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              >
                <option v-for="a in agentStore.agents.filter(x => x.active)" :key="a.id" :value="a.id">
                  {{ a.firstName }} {{ a.lastName }} ({{ a.agentCode }}) — {{ t(`agents.levelShort.${a.level}`) }}
                </option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.fields.coverage') }}</label>
                <input
                  v-model.number="quoteForm.coverage"
                  type="number"
                  min="0"
                  step="100000"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.fields.annualPremium') }}</label>
                <input
                  v-model.number="quoteForm.annualPremium"
                  type="number"
                  min="0"
                  step="1000"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.fields.premiumMode') }}</label>
              <select
                v-model="quoteForm.premiumMode"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              >
                <option v-for="m in (['monthly', 'quarterly', 'semiannual', 'annual', 'single'] as const)" :key="m" :value="m">
                  {{ t(`products.premiumModes.${m}`) }}
                </option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.fields.notes') }}</label>
              <textarea v-model="quoteForm.notes" rows="2" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none" />
            </div>

            <div class="card p-3 bg-brand-50/40 border-brand-200">
              <div class="text-xs text-brand-700 mb-1">{{ t('policies.quote.reviewQuote') }}</div>
              <div class="text-sm text-slate-900">
                {{ customerNameById(quoteForm.customerId) }} · {{ productById(quoteForm.productId)?.name }} · ทุน {{ fmtTHB(quoteForm.coverage) }} บาท · เบี้ย {{ fmtTHB(quoteForm.annualPremium) }} บาท/ปี
              </div>
              <div class="text-xs text-slate-500 mt-1">ขายโดย {{ agentNameById(quoteForm.writingAgentId) }}</div>
            </div>
          </section>
        </div>

        <footer class="px-5 py-4 border-t border-slate-100 flex justify-between gap-2 bg-white rounded-b-xl shrink-0">
          <button
            v-if="quoteStep > 1"
            @click="quoteStep = (quoteStep - 1) as 1 | 2 | 3"
            class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50"
          >
            {{ t('common.previous') }}
          </button>
          <div v-else />

          <div class="flex items-center gap-2">
            <button @click="showQuote = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
              {{ t('common.cancel') }}
            </button>
            <button
              v-if="quoteStep < 3"
              @click="quoteStep = (quoteStep + 1) as 1 | 2 | 3"
              :disabled="(quoteStep === 1 && !step1Valid) || (quoteStep === 2 && !step2Valid)"
              class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-50"
            >
              {{ t('common.next') }}
            </button>
            <button
              v-else
              @click="submitQuote"
              :disabled="!step3Valid || quoteSubmitting"
              class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-50 flex items-center gap-2"
            >
              <i v-if="quoteSubmitting" class="pi pi-spin pi-spinner" />
              <span>{{ t('common.create') }}</span>
            </button>
          </div>
        </footer>
      </div>
    </div>

    <!-- Direct-create policy dialog -->
    <div
      v-if="showDirectCreate"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40"
    >
      <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[92vh] flex flex-col">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <div>
            <h3 class="font-semibold text-slate-900 flex items-center gap-2">
              <i class="pi pi-plus-circle text-brand-600" />
              เพิ่มกรมธรรม์
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">
              กรอกข้อมูลกรมธรรม์ที่ออกแล้ว หรือนำเข้าข้อมูลจากระบบเดิม — รายละเอียดเพิ่มเติม (รถยนต์ / ทรัพย์สิน / ผู้รับประโยชน์) เพิ่มภายหลังได้
            </p>
          </div>
          <button @click="closeDirectCreate" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>

        <div class="overflow-y-auto flex-1 px-5 py-5 space-y-5">
          <!-- Linkage -->
          <section class="space-y-3">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">ผู้เกี่ยวข้อง</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">
                  ลูกค้า <span class="text-rose-500">*</span>
                </label>
                <select
                  v-model="directForm.customerId"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option value="">— เลือก —</option>
                  <option v-for="c in customerStore.customers" :key="c.id" :value="c.id">
                    {{ c.firstName }} {{ c.lastName }} ({{ c.customerCode }})
                  </option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">
                  ตัวแทนผู้ขาย <span class="text-rose-500">*</span>
                </label>
                <select
                  v-model="directForm.writingAgentId"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option v-for="a in agentStore.agents" :key="a.id" :value="a.id">
                    {{ a.firstName }} {{ a.lastName }} ({{ a.agentCode }})
                  </option>
                </select>
              </div>
            </div>
          </section>

          <!-- Product + coverage -->
          <section class="space-y-3 pt-3 border-t border-slate-100">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">ผลิตภัณฑ์และทุน</h4>
            <div>
              <label class="block text-xs font-medium text-slate-600 mb-1">
                ผลิตภัณฑ์ / บริษัทประกัน <span class="text-rose-500">*</span>
              </label>
              <select
                v-model="directForm.productId"
                @change="onDirectProductChange"
                class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              >
                <option value="">— เลือก —</option>
                <option v-for="p in products" :key="p.id" :value="p.id">
                  [{{ carrierById(p.carrierId)?.code }}] {{ p.name }}
                </option>
              </select>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">
                  ทุนประกัน (บาท) <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="directForm.coverage"
                  type="number"
                  min="0"
                  step="100000"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">
                  เบี้ยรายปี (บาท) <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="directForm.annualPremium"
                  type="number"
                  min="0"
                  step="100"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">งวดชำระ</label>
                <select
                  v-model="directForm.premiumMode"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option value="monthly">รายเดือน</option>
                  <option value="quarterly">รายไตรมาส</option>
                  <option value="semiannual">ราย 6 เดือน</option>
                  <option value="annual">รายปี</option>
                  <option value="single">ชำระครั้งเดียว</option>
                </select>
              </div>
            </div>
          </section>

          <!-- Identifiers + status -->
          <section class="space-y-3 pt-3 border-t border-slate-100">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">เลขที่กรมธรรม์และสถานะ</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">เลขใบสมัคร (Application No.)</label>
                <input
                  v-model="directForm.applicationNo"
                  type="text"
                  placeholder="ปล่อยว่าง = ระบบจะตั้งให้"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">เลขกรมธรรม์ (Policy No.)</label>
                <input
                  v-model="directForm.policyNo"
                  type="text"
                  placeholder="ปล่อยว่าง = ยังไม่ออกเลข"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">สถานะ</label>
                <select
                  v-model="directForm.status"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option value="active">{{ t('policies.status.active') }}</option>
                  <option value="issued">{{ t('policies.status.issued') }}</option>
                  <option value="submitted">{{ t('policies.status.submitted') }}</option>
                  <option value="application">{{ t('policies.status.application') }}</option>
                  <option value="expired">{{ t('policies.status.expired') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">ประเภท</label>
                <div class="inline-flex border border-slate-300 bg-white rounded-lg p-0.5 w-full">
                  <button
                    type="button"
                    @click="directForm.newOrRenew = 'new'"
                    :class="[
                      'flex-1 px-3 py-1.5 text-xs font-medium rounded transition',
                      directForm.newOrRenew === 'new' ? 'bg-emerald-50 text-emerald-700' : 'text-slate-500 hover:text-slate-900',
                    ]"
                  >
                    งานใหม่
                  </button>
                  <button
                    type="button"
                    @click="directForm.newOrRenew = 'renew'"
                    :class="[
                      'flex-1 px-3 py-1.5 text-xs font-medium rounded transition',
                      directForm.newOrRenew === 'renew' ? 'bg-blue-50 text-blue-700' : 'text-slate-500 hover:text-slate-900',
                    ]"
                  >
                    ต่ออายุ
                  </button>
                </div>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">ปีกรมธรรม์</label>
                <input
                  v-model.number="directForm.policyYear"
                  type="number"
                  min="1"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
          </section>

          <!-- Dates -->
          <section class="space-y-3 pt-3 border-t border-slate-100">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">วันที่</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">วันออกกรมธรรม์</label>
                <input
                  v-model="directForm.issueDate"
                  type="text"
                  placeholder="25xx-mm-dd"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">เริ่มคุ้มครอง</label>
                <input
                  v-model="directForm.effectiveDate"
                  type="text"
                  placeholder="25xx-mm-dd"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">สิ้นสุดคุ้มครอง</label>
                <input
                  v-model="directForm.expiryDate"
                  type="text"
                  placeholder="25xx-mm-dd"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">เบี้ยถัดไป</label>
                <input
                  v-model="directForm.nextPremiumDue"
                  type="text"
                  placeholder="25xx-mm-dd"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
          </section>

          <!-- Notes -->
          <section class="pt-3 border-t border-slate-100">
            <label class="block text-xs font-medium text-slate-600 mb-1">หมายเหตุ</label>
            <textarea
              v-model="directForm.notes"
              rows="2"
              class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
            />
          </section>
        </div>

        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl shrink-0">
          <button
            type="button"
            @click="closeDirectCreate"
            class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            @click="submitDirectCreate"
            :disabled="!directValid || directSubmitting"
            class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-50 flex items-center gap-1.5"
          >
            <i v-if="directSubmitting" class="pi pi-spin pi-spinner" />
            <i v-else class="pi pi-check text-xs" />
            <span>สร้างกรมธรรม์</span>
          </button>
        </footer>
      </div>
    </div>

    <!-- Detail drawer -->
    <div v-if="detail" class="fixed inset-0 z-40 flex" @click.self="closeDetail">
      <div class="flex-1 bg-slate-900/40" @click="closeDetail" />
      <aside class="w-full max-w-2xl bg-white shadow-2xl flex flex-col overflow-hidden">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <div class="flex items-start gap-3 min-w-0">
            <div class="w-10 h-10 rounded-lg bg-brand-100 text-brand-700 flex items-center justify-center text-sm shrink-0">
              <i class="pi pi-file" />
            </div>
            <div class="min-w-0">
              <div class="font-mono text-sm text-slate-900 truncate">{{ policyDisplayNo(detail) }}</div>
              <div class="text-xs text-slate-500 mt-0.5">
                <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-medium', statusBadgeClass(detail.status)]">
                  <span :class="['w-1 h-1 rounded-full', statusDot(detail.status)]" />
                  {{ t(`policies.status.${detail.status}`) }}
                </span>
                <span class="ml-2">{{ t(`policies.statusDesc.${detail.status}`) }}</span>
              </div>
            </div>
          </div>
          <div class="flex items-center gap-1.5 shrink-0">
            <button
              @click="openEditPolicy(detail)"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-50 text-brand-700 hover:bg-brand-100 rounded-md text-xs font-medium transition"
            >
              <i class="pi pi-pencil text-[10px]" />
              แก้ไขรายละเอียด
            </button>
            <button @click="closeDetail" class="text-slate-400 hover:text-slate-700 p-1">
              <i class="pi pi-times" />
            </button>
          </div>
        </header>

        <div class="border-b border-slate-100 px-5 flex items-center gap-1 overflow-x-auto shrink-0">
          <button
            v-for="tk in (['overview', 'events', 'payments', 'documents', 'commission'] as const)"
            :key="tk"
            type="button"
            @click="detailTab = tk"
            :class="[
              'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition whitespace-nowrap',
              detailTab === tk ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
            ]"
          >
            {{ t(`policies.detail.tabs.${tk}`) }}
            <span v-if="tk === 'events'" class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-slate-100 text-slate-500">{{ detail.events.length }}</span>
            <span v-else-if="tk === 'payments'" class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-slate-100 text-slate-500">{{ detail.payments.length }}</span>
            <span v-else-if="tk === 'documents'" class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-slate-100 text-slate-500">{{ detail.documents.length }}</span>
          </button>
        </div>

        <div class="px-5 py-5 overflow-y-auto flex-1">
          <!-- Overview -->
          <div v-if="detailTab === 'overview'" class="space-y-5">
            <section>
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">{{ t('policies.detail.summary') }}</h4>
              <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">{{ t('policies.fields.quoteNo') }}</dt>
                <dd class="col-span-2 font-mono text-slate-900">{{ detail.quoteNo }}</dd>
                <dt class="text-slate-500">{{ t('policies.fields.applicationNo') }}</dt>
                <dd class="col-span-2 font-mono text-slate-900">{{ detail.applicationNo ?? '–' }}</dd>
                <dt class="text-slate-500">{{ t('policies.fields.policyNo') }}</dt>
                <dd class="col-span-2 font-mono text-slate-900">{{ detail.policyNo ?? '–' }}</dd>
              </dl>
            </section>

            <section class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">{{ t('policies.detail.coverageDetails') }}</h4>
              <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">{{ t('policies.fields.product') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ productById(detail.productId)?.name }}</dd>
                <dt class="text-slate-500">{{ t('policies.fields.carrier') }}</dt>
                <dd class="col-span-2 text-slate-900">{{ carrierById(detail.carrierId)?.name }}</dd>
                <dt class="text-slate-500">{{ t('policies.fields.coverage') }}</dt>
                <dd class="col-span-2 text-slate-900 font-medium">฿{{ fmtTHB(detail.coverage) }}</dd>
                <dt class="text-slate-500">{{ t('policies.fields.annualPremium') }}</dt>
                <dd class="col-span-2 text-slate-900 font-medium">฿{{ fmtTHB(detail.annualPremium) }} ({{ t(`products.premiumModes.${detail.premiumMode}`) }})</dd>
                <dt class="text-slate-500">{{ t('policies.fields.effectiveDate') }}</dt>
                <dd class="col-span-2 font-mono text-slate-900">{{ detail.effectiveDate ?? '–' }}</dd>
                <dt class="text-slate-500">{{ t('policies.fields.expiryDate') }}</dt>
                <dd class="col-span-2 font-mono text-slate-900">{{ detail.expiryDate ?? '–' }}</dd>
                <dt class="text-slate-500">{{ t('policies.fields.nextPremiumDue') }}</dt>
                <dd class="col-span-2 font-mono text-slate-900">{{ detail.nextPremiumDue ?? '–' }}</dd>
              </dl>
            </section>

            <section class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">{{ t('policies.detail.attribution') }}</h4>
              <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">{{ t('policies.fields.customer') }}</dt>
                <dd class="col-span-2">
                  <div class="text-slate-900">{{ customerNameById(detail.customerId) }}</div>
                  <div class="text-xs text-slate-400 font-mono">{{ customerStore.getCustomer(detail.customerId)?.customerCode }}</div>
                </dd>
                <dt class="text-slate-500">{{ t('policies.fields.writingAgent') }}</dt>
                <dd class="col-span-2">
                  <div class="text-slate-900">{{ agentNameById(detail.writingAgentId) }}</div>
                  <div class="text-xs text-slate-400 font-mono">{{ agentStore.getAgent(detail.writingAgentId)?.agentCode }} · {{ t(`agents.levelShort.${agentStore.getAgent(detail.writingAgentId)?.level}`) }}</div>
                </dd>
              </dl>
            </section>

            <!-- Year tracking + renewal indicator -->
            <section class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">การต่ออายุ</h4>
              <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">ปีกรมธรรม์</dt>
                <dd class="col-span-2 text-slate-900">ปีที่ {{ detail.policyYear }}</dd>
                <dt class="text-slate-500">ปีที่บันทึก</dt>
                <dd class="col-span-2 text-slate-900">ปี {{ detail.actYear }}</dd>
                <dt class="text-slate-500">ประเภท</dt>
                <dd class="col-span-2">
                  <span
                    :class="[
                      'inline-flex px-2 py-0.5 rounded text-xs font-medium',
                      detail.newOrRenew === 'renew' ? 'bg-blue-50 text-blue-700' : 'bg-emerald-50 text-emerald-700',
                    ]"
                  >
                    {{ detail.newOrRenew === 'renew' ? 'ต่ออายุ' : 'งานใหม่' }}
                  </span>
                  <span v-if="detail.freelookActive" class="ml-2 inline-flex px-2 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-700">
                    อยู่ในระยะ freelook
                  </span>
                </dd>
              </dl>
            </section>

            <!-- Motor block -->
            <section v-if="detail.motor" class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1.5">
                <i class="pi pi-car text-blue-500" />
                รายละเอียดรถยนต์
              </h4>
              <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">ยี่ห้อ / รุ่น</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.motor.vehicleBrand }} {{ detail.motor.vehicleModel }}</dd>
                <dt class="text-slate-500">ทะเบียน</dt>
                <dd class="col-span-2 font-mono text-slate-900">{{ detail.motor.licenseNo || '–' }}</dd>
                <dt class="text-slate-500">เลขเครื่อง / เลขตัวถัง</dt>
                <dd class="col-span-2 font-mono text-slate-900 text-xs">{{ detail.motor.engineNo || '–' }} / {{ detail.motor.chassisNo || '–' }}</dd>
                <dt class="text-slate-500">ปีจดทะเบียน</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.motor.registerYear || '–' }}</dd>
                <dt class="text-slate-500">ผู้โดยสาร</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.motor.noPassenger || '–' }} ที่นั่ง</dd>
                <dt class="text-slate-500">ประเภทผู้ขับขี่</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.motor.typeDriver || '–' }}</dd>
                <template v-if="detail.motor.notes">
                  <dt class="text-slate-500">หมายเหตุ</dt>
                  <dd class="col-span-2 text-slate-700 whitespace-pre-line">{{ detail.motor.notes }}</dd>
                </template>
              </dl>
            </section>

            <!-- Property (fire / IAR / CAR) block -->
            <section v-if="detail.property" class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1.5">
                <i class="pi pi-building text-amber-500" />
                รายละเอียดทรัพย์สิน
              </h4>
              <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">ผู้เอาประกัน</dt>
                <dd class="col-span-2 text-slate-900">{{ detail.property.insuredName || '–' }}</dd>
                <dt class="text-slate-500">ที่ตั้งทรัพย์สิน</dt>
                <dd class="col-span-2 text-slate-700 whitespace-pre-line">{{ detail.property.insuredAddress || '–' }}</dd>
                <dt class="text-slate-500">สิ่งปลูกสร้าง</dt>
                <dd class="col-span-2 font-mono text-slate-900">฿{{ fmtTHB(detail.property.buildingCoverage) }}</dd>
                <dt class="text-slate-500">เฟอร์นิเจอร์ / อุปกรณ์</dt>
                <dd class="col-span-2 font-mono text-slate-900">฿{{ fmtTHB(detail.property.furnitureCoverage) }}</dd>
                <dt class="text-slate-500">สต๊อก / วัตถุดิบ</dt>
                <dd class="col-span-2 font-mono text-slate-900">฿{{ fmtTHB(detail.property.stockCoverage) }}</dd>
                <template v-if="detail.property.otherCoverage > 0">
                  <dt class="text-slate-500">{{ detail.property.otherDetail || 'อื่น ๆ' }}</dt>
                  <dd class="col-span-2 font-mono text-slate-900">฿{{ fmtTHB(detail.property.otherCoverage) }}</dd>
                </template>
                <dt class="text-slate-500 font-semibold pt-1 border-t border-slate-100">รวมทุนประกัน</dt>
                <dd class="col-span-2 font-mono font-semibold text-slate-900 pt-1 border-t border-slate-100">
                  ฿{{ fmtTHB(detail.property.buildingCoverage + detail.property.furnitureCoverage + detail.property.stockCoverage + detail.property.otherCoverage) }}
                </dd>
                <template v-if="detail.property.notes">
                  <dt class="text-slate-500">หมายเหตุ</dt>
                  <dd class="col-span-2 text-slate-700 whitespace-pre-line">{{ detail.property.notes }}</dd>
                </template>
              </dl>
            </section>

            <!-- Riders -->
            <section v-if="detail.riders.length" class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1.5">
                <i class="pi pi-plus-circle text-violet-500" />
                สัญญาเพิ่มเติม ({{ detail.riders.length }})
              </h4>
              <ul class="space-y-1.5">
                <li
                  v-for="(rd, i) in detail.riders"
                  :key="i"
                  class="flex items-center justify-between text-sm border border-slate-200 rounded-lg px-3 py-2"
                >
                  <div>
                    <div class="text-slate-900">{{ rd.name }}</div>
                    <div v-if="rd.notes" class="text-xs text-slate-500">{{ rd.notes }}</div>
                  </div>
                  <div class="font-mono text-slate-700">฿{{ fmtTHB(rd.premium) }}</div>
                </li>
              </ul>
            </section>

            <!-- Beneficiaries -->
            <section v-if="detail.beneficiaries.length" class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1.5">
                <i class="pi pi-users text-emerald-500" />
                ผู้รับประโยชน์ ({{ detail.beneficiaries.length }})
              </h4>
              <ul class="space-y-1.5">
                <li
                  v-for="(b, i) in detail.beneficiaries"
                  :key="i"
                  class="flex items-center justify-between text-sm border border-slate-200 rounded-lg px-3 py-2"
                >
                  <div>
                    <div class="text-slate-900">{{ b.name }}</div>
                    <div class="text-xs text-slate-500">{{ b.relation }}</div>
                  </div>
                  <div v-if="b.share > 0" class="font-mono text-slate-700">{{ b.share }}%</div>
                </li>
              </ul>
            </section>

            <section v-if="detail.notes" class="pt-4 border-t border-slate-100">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">{{ t('policies.fields.notes') }}</h4>
              <p class="text-sm text-slate-700 whitespace-pre-line">{{ detail.notes }}</p>
            </section>
          </div>

          <!-- Events timeline (append-only audit) -->
          <div v-if="detailTab === 'events'">
            <div v-if="!detail.events.length" class="text-center py-12 text-slate-400">
              <i class="pi pi-history text-3xl block mb-2" />
              <p class="text-sm">{{ t('policies.detail.noEvents') }}</p>
            </div>
            <ol v-else class="relative border-l-2 border-slate-200 ml-3 space-y-4">
              <li v-for="(ev, i) in [...detail.events].reverse()" :key="ev.id" class="ml-6">
                <span :class="['absolute -left-[9px] w-4 h-4 rounded-full border-4 border-white', eventDot(ev.type)]" />
                <div class="card p-3">
                  <div class="flex items-center gap-2">
                    <i :class="eventIcon(ev.type) + ' text-slate-400 text-xs'" />
                    <div class="text-sm font-medium text-slate-900">{{ t(`policies.events.${ev.type}`) }}</div>
                    <div class="ml-auto text-xs text-slate-400 font-mono">{{ ev.at }}</div>
                  </div>
                  <div v-if="Object.keys(ev.payload).length" class="mt-2 text-xs text-slate-600 grid grid-cols-2 gap-x-3 gap-y-1">
                    <template v-for="(v, k) in ev.payload" :key="k">
                      <div class="text-slate-400">{{ k }}</div>
                      <div class="font-mono text-slate-700 truncate">{{ v }}</div>
                    </template>
                  </div>
                  <div class="text-[10px] text-slate-400 mt-2">เหตุการณ์ #{{ detail.events.length - i }}</div>
                </div>
              </li>
            </ol>
          </div>

          <!-- Payments -->
          <div v-if="detailTab === 'payments'">
            <div v-if="!detail.payments.length" class="text-center py-12 text-slate-400">
              <i class="pi pi-wallet text-3xl block mb-2" />
              <p class="text-sm">{{ t('policies.detail.noPayments') }}</p>
            </div>
            <div v-else class="space-y-2">
              <div v-for="pmt in [...detail.payments].reverse()" :key="pmt.id" class="card p-3 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                  <i class="pi pi-wallet" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium text-slate-900">฿{{ fmtTHB(pmt.amount) }}</div>
                  <div class="text-xs text-slate-500">
                    {{ pmt.paymentDate }} · {{ t(`policies.paymentMethods.${pmt.method}`) }}
                  </div>
                  <div class="text-[10px] text-slate-400 font-mono mt-0.5">อ้างอิง: {{ pmt.reference || '–' }}</div>
                </div>
              </div>
              <div class="card p-3 bg-slate-50 border-slate-100 mt-4">
                <div class="text-xs text-slate-500">ยอดชำระสะสม</div>
                <div class="text-lg font-semibold text-emerald-600 mt-1">
                  ฿{{ fmtTHB(detail.payments.reduce((s, p) => s + p.amount, 0)) }}
                </div>
              </div>
            </div>
          </div>

          <!-- Documents -->
          <div v-if="detailTab === 'documents'" class="space-y-3">
            <div class="flex items-center gap-2 mb-2">
              <select v-model="docTypeSelect" class="flex-1 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500">
                <option v-for="dt in (['application', 'policy', 'receipt', 'medical', 'endorsement', 'cancellation', 'other'] as PolicyDocType[])" :key="dt" :value="dt">
                  {{ t(`policies.docTypes.${dt}`) }}
                </option>
              </select>
              <input ref="docFileInput" type="file" class="hidden" @change="onDocFileChange" />
              <button @click="triggerDocUpload" class="px-3 py-2 bg-brand-600 text-white rounded-lg text-sm font-medium hover:bg-brand-700 transition flex items-center gap-1.5">
                <i class="pi pi-upload" />
                <span class="hidden sm:inline">{{ t('policies.actions.uploadDocument') }}</span>
              </button>
            </div>

            <div v-if="!detail.documents.length" class="text-center py-10 text-slate-400">
              <i class="pi pi-folder-open text-3xl block mb-2" />
              <p class="text-sm">{{ t('policies.detail.noDocuments') }}</p>
            </div>
            <div v-else class="space-y-2">
              <div v-for="d in detail.documents" :key="d.id" class="border border-slate-200 rounded-lg p-3 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center shrink-0">
                  <i class="pi pi-file" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium text-slate-900 truncate">{{ t(`policies.docTypes.${d.type}`) }}</div>
                  <div class="text-xs text-slate-500 truncate">{{ d.fileName }}</div>
                  <div class="text-[10px] text-slate-400 mt-0.5">อัปโหลด {{ d.uploadedAt }}</div>
                </div>
                <button @click="policyStore.removeDocument(detail.id, d.id)" class="px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 rounded transition">
                  <i class="pi pi-trash" />
                </button>
              </div>
            </div>
          </div>

          <!-- Commission tab — placeholder during the MGM engine rewrite.
               The live preview + ledger view will be rebuilt from
               commission_ledgers once PR-D lands. -->
          <div v-if="detailTab === 'commission'" class="card p-6 text-center text-sm text-slate-400">
            ค่าคอมมิชชั่นกำลังถูกออกแบบใหม่ในระบบ MGM — ดูรายการจริงในหน้า Rebate หรือ Reports ระหว่างนี้
          </div>
        </div>
      </aside>
    </div>

    <!-- Action dialogs (unified) -->
    <div v-if="actionDialog" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" @click.self="closeAction">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <h3 class="font-semibold text-slate-900">
            {{ {
              issue: t('policies.issue.title'),
              payment: t('policies.payment.title'),
              renew: t('policies.renew.title'),
              cancel: t('policies.cancel.title'),
              lapse: t('policies.lapse.title'),
              reinstate: t('policies.reinstate.title'),
            }[actionDialog.type] }}
          </h3>
          <button @click="closeAction" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>
        <div class="px-5 py-5 space-y-4">
          <div class="card p-3 bg-slate-50 border-slate-100">
            <div class="font-mono text-xs text-slate-500">{{ policyDisplayNo(actionDialog.policy) }}</div>
            <div class="text-sm text-slate-900">{{ customerNameById(actionDialog.policy.customerId) }} · {{ productById(actionDialog.policy.productId)?.name }}</div>
          </div>

          <!-- Issue -->
          <template v-if="actionDialog.type === 'issue'">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.issue.policyNoLabel') }} <span class="text-rose-500">*</span></label>
              <input v-model="actionForm.policyNo" type="text" :placeholder="t('policies.issue.policyNoPlaceholder')" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.issue.effectiveDateLabel') }} <span class="text-rose-500">*</span></label>
              <input v-model="actionForm.effectiveDate" type="text" placeholder="25xx-mm-dd" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            </div>
            <div class="bg-sky-50 border border-sky-200 text-sky-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              <span>{{ t('policies.issue.warning') }}</span>
            </div>
          </template>

          <!-- Payment -->
          <template v-if="actionDialog.type === 'payment'">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.fields.paymentDate') }}</label>
                <input v-model="actionForm.paymentDate" type="text" placeholder="25xx-mm-dd" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.fields.paymentAmount') }}</label>
                <input v-model.number="actionForm.paymentAmount" type="number" min="0" step="1000" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.fields.paymentMethod') }}</label>
              <select v-model="actionForm.paymentMethod" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500">
                <option v-for="m in (['bankTransfer', 'creditCard', 'cash', 'cheque', 'directDebit'] as PaymentMethod[])" :key="m" :value="m">
                  {{ t(`policies.paymentMethods.${m}`) }}
                </option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.fields.paymentReference') }}</label>
              <input v-model="actionForm.paymentReference" type="text" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500" />
            </div>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              <span>{{ t('policies.payment.warning') }}</span>
            </div>
          </template>

          <!-- Renew -->
          <template v-if="actionDialog.type === 'renew'">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.renew.newExpiryDate') }}</label>
              <input v-model="actionForm.newExpiry" type="text" placeholder="25xx-mm-dd" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.renew.newPremium') }}</label>
              <input v-model.number="actionForm.newPremium" type="number" min="0" step="1000" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500" />
            </div>
            <div class="bg-violet-50 border border-violet-200 text-violet-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              <span>{{ t('policies.renew.warning') }}</span>
            </div>
          </template>

          <!-- Cancel -->
          <template v-if="actionDialog.type === 'cancel'">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.cancel.cancelDate') }}</label>
              <input v-model="actionForm.cancelDate" type="text" placeholder="25xx-mm-dd" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.fields.cancelReason') }} <span class="text-rose-500">*</span></label>
              <textarea v-model="actionForm.cancelReason" rows="2" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 resize-none" />
            </div>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-exclamation-triangle mt-0.5" />
              <span>{{ t('policies.cancel.warning') }}</span>
            </div>
          </template>

          <!-- Lapse -->
          <template v-if="actionDialog.type === 'lapse'">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.lapse.lapseDate') }}</label>
              <input v-model="actionForm.lapseDate" type="text" placeholder="25xx-mm-dd" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500" />
            </div>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-exclamation-triangle mt-0.5" />
              <span>{{ t('policies.lapse.warning') }}</span>
            </div>
          </template>

          <!-- Reinstate -->
          <template v-if="actionDialog.type === 'reinstate'">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('policies.reinstate.reinstateDate') }}</label>
              <input v-model="actionForm.reinstateDate" type="text" placeholder="25xx-mm-dd" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500" />
            </div>
            <div class="bg-violet-50 border border-violet-200 text-violet-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              <span>{{ t('policies.reinstate.warning') }}</span>
            </div>
          </template>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="closeAction" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">{{ t('common.cancel') }}</button>
          <button
            @click="runAction"
            :class="[
              'px-4 py-2 text-sm rounded-lg text-white font-medium',
              actionDialog.type === 'cancel' ? 'bg-rose-600 hover:bg-rose-700' :
              actionDialog.type === 'lapse' ? 'bg-amber-600 hover:bg-amber-700' :
              actionDialog.type === 'reinstate' ? 'bg-violet-600 hover:bg-violet-700' :
              actionDialog.type === 'renew' ? 'bg-violet-600 hover:bg-violet-700' :
              actionDialog.type === 'payment' ? 'bg-emerald-600 hover:bg-emerald-700' :
              'bg-brand-600 hover:bg-brand-700'
            ]"
          >
            {{ t('common.confirm') }}
          </button>
        </footer>
      </div>
    </div>

    <!-- Edit policy details modal -->
    <div
      v-if="showEditPolicy"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50"
    >
      <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[92vh] flex flex-col">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
          <h3 class="font-semibold text-slate-900 flex items-center gap-2">
            <i class="pi pi-pencil text-brand-600" />
            แก้ไขรายละเอียดกรมธรรม์
          </h3>
          <button @click="closeEditPolicy" class="text-slate-400 hover:text-slate-700">
            <i class="pi pi-times" />
          </button>
        </header>

        <!-- Tabs -->
        <div class="border-b border-slate-100 px-5 flex items-center gap-1 overflow-x-auto shrink-0">
          <button
            v-for="tk in (['general', 'motor', 'property', 'riders', 'beneficiaries'] as const)"
            :key="tk"
            type="button"
            @click="editTab = tk"
            :class="[
              'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition whitespace-nowrap flex items-center gap-1.5',
              editTab === tk ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
            ]"
          >
            <i
              :class="[
                'text-[10px]',
                tk === 'general' ? 'pi pi-info-circle' :
                tk === 'motor' ? 'pi pi-car' :
                tk === 'property' ? 'pi pi-building' :
                tk === 'riders' ? 'pi pi-plus-circle' : 'pi pi-users',
              ]"
            />
            {{
              tk === 'general' ? 'ทั่วไป' :
              tk === 'motor' ? 'รถยนต์' :
              tk === 'property' ? 'ทรัพย์สิน' :
              tk === 'riders' ? `สัญญาเพิ่มเติม (${editForm.riders.length})` :
              `ผู้รับประโยชน์ (${editForm.beneficiaries.length})`
            }}
          </button>
        </div>

        <div class="overflow-y-auto flex-1 px-5 py-5 space-y-5">
          <!-- General -->
          <section v-if="editTab === 'general'" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium uppercase tracking-wider text-slate-500 mb-1">ปีกรมธรรม์</label>
                <input
                  v-model.number="editForm.policyYear"
                  type="number"
                  min="1"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-xs font-medium uppercase tracking-wider text-slate-500 mb-1">ปีที่บันทึก</label>
                <input
                  v-model.number="editForm.actYear"
                  type="number"
                  min="1"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium uppercase tracking-wider text-slate-500 mb-1">ประเภท</label>
              <div class="inline-flex border border-slate-200 bg-white rounded-lg p-0.5">
                <button
                  type="button"
                  @click="editForm.newOrRenew = 'new'"
                  :class="[
                    'px-3 py-1.5 text-xs font-medium rounded transition',
                    editForm.newOrRenew === 'new' ? 'bg-emerald-50 text-emerald-700' : 'text-slate-500 hover:text-slate-900',
                  ]"
                >
                  งานใหม่
                </button>
                <button
                  type="button"
                  @click="editForm.newOrRenew = 'renew'"
                  :class="[
                    'px-3 py-1.5 text-xs font-medium rounded transition',
                    editForm.newOrRenew === 'renew' ? 'bg-blue-50 text-blue-700' : 'text-slate-500 hover:text-slate-900',
                  ]"
                >
                  ต่ออายุ
                </button>
              </div>
            </div>
            <label class="inline-flex items-center gap-2 cursor-pointer">
              <input
                v-model="editForm.freelookActive"
                type="checkbox"
                class="w-4 h-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500"
              />
              <span class="text-sm text-slate-700">อยู่ในระยะ freelook</span>
            </label>
            <div>
              <label class="block text-xs font-medium uppercase tracking-wider text-slate-500 mb-1">หมายเหตุ</label>
              <textarea
                v-model="editForm.notes"
                rows="4"
                class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none"
              />
            </div>
          </section>

          <!-- Motor -->
          <section v-if="editTab === 'motor'" class="space-y-4">
            <label class="inline-flex items-center gap-2 cursor-pointer">
              <input
                v-model="editForm.motorEnabled"
                type="checkbox"
                class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
              />
              <span class="text-sm text-slate-700">กรมธรรม์นี้มีรายละเอียดรถยนต์</span>
            </label>
            <template v-if="editForm.motorEnabled">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">ยี่ห้อ</label>
                  <input v-model="editForm.motor.vehicleBrand" type="text" placeholder="TOYOTA" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">รุ่น</label>
                  <input v-model="editForm.motor.vehicleModel" type="text" placeholder="COROLLA ALTIS 1.8" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">ทะเบียน</label>
                  <input v-model="editForm.motor.licenseNo" type="text" placeholder="กข-1234 กรุงเทพมหานคร" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">ประเภทรถ</label>
                  <input v-model="editForm.motor.typeVehicle" type="text" placeholder="รถยนต์นั่ง / กระบะ / SUV" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">เลขเครื่อง</label>
                  <input v-model="editForm.motor.engineNo" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">เลขตัวถัง</label>
                  <input v-model="editForm.motor.chassisNo" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">ปีจดทะเบียน</label>
                  <input v-model="editForm.motor.registerYear" type="text" placeholder="25xx" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">จำนวนที่นั่ง</label>
                  <input v-model.number="editForm.motor.noPassenger" type="number" min="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">ประเภทผู้ขับขี่</label>
                  <input v-model="editForm.motor.typeDriver" type="text" placeholder="ระบุชื่อ / ไม่ระบุชื่อ" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">หมายเหตุ</label>
                <textarea v-model="editForm.motor.notes" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none" />
              </div>
            </template>
          </section>

          <!-- Property -->
          <section v-if="editTab === 'property'" class="space-y-4">
            <label class="inline-flex items-center gap-2 cursor-pointer">
              <input
                v-model="editForm.propertyEnabled"
                type="checkbox"
                class="w-4 h-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500"
              />
              <span class="text-sm text-slate-700">กรมธรรม์นี้มีรายละเอียดทรัพย์สิน (Fire / IAR / CAR)</span>
            </label>
            <template v-if="editForm.propertyEnabled">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">ผู้เอาประกัน</label>
                <input v-model="editForm.property.insuredName" type="text" placeholder="เช่น บจก. พัฒนชัย เคมิคอล" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">ที่ตั้งทรัพย์สิน</label>
                <textarea v-model="editForm.property.insuredAddress" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none" />
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">สิ่งปลูกสร้าง (บาท)</label>
                  <input v-model.number="editForm.property.buildingCoverage" type="number" min="0" step="100000" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">เฟอร์นิเจอร์ / อุปกรณ์ (บาท)</label>
                  <input v-model.number="editForm.property.furnitureCoverage" type="number" min="0" step="100000" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">สต๊อก / วัตถุดิบ (บาท)</label>
                  <input v-model.number="editForm.property.stockCoverage" type="number" min="0" step="100000" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-600 mb-1">อื่น ๆ (บาท)</label>
                  <input v-model.number="editForm.property.otherCoverage" type="number" min="0" step="100000" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
                </div>
              </div>
              <div v-if="editForm.property.otherCoverage > 0">
                <label class="block text-xs font-medium text-slate-600 mb-1">รายละเอียด "อื่น ๆ"</label>
                <input v-model="editForm.property.otherDetail" type="text" placeholder="เช่น โซล่าร์รูฟท็อป" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              </div>
              <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 flex items-center justify-between">
                <span>รวมทุนทั้งหมด</span>
                <strong class="font-mono">฿{{ propertyTotal.toLocaleString('th-TH') }}</strong>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">หมายเหตุ</label>
                <textarea v-model="editForm.property.notes" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none" />
              </div>
            </template>
          </section>

          <!-- Riders -->
          <section v-if="editTab === 'riders'" class="space-y-3">
            <div class="flex items-center justify-between">
              <p class="text-xs text-slate-500">สัญญาเพิ่มเติม (Rider) — ใช้กับประกันชีวิตหรือสุขภาพ</p>
              <button
                type="button"
                @click="addRider"
                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg hover:bg-slate-50 transition"
              >
                <i class="pi pi-plus text-[10px]" />
                เพิ่มสัญญาเพิ่มเติม
              </button>
            </div>
            <div v-if="!editForm.riders.length" class="border border-dashed border-slate-200 rounded-lg px-4 py-6 text-center">
              <i class="pi pi-plus-circle text-slate-300 text-xl block mb-1" />
              <p class="text-xs text-slate-500">ยังไม่มีสัญญาเพิ่มเติม</p>
            </div>
            <div v-else class="space-y-2.5">
              <div
                v-for="(rd, idx) in editForm.riders"
                :key="idx"
                class="border border-slate-200 rounded-lg p-3 bg-slate-50/30 space-y-2"
              >
                <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                  <div class="md:col-span-2">
                    <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">ชื่อสัญญาเพิ่มเติม</label>
                    <input v-model="rd.name" type="text" placeholder="เช่น AIA Health Plus (อ.7)" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-100" />
                  </div>
                  <div>
                    <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">เบี้ย (บาท)</label>
                    <input v-model.number="rd.premium" type="number" min="0" step="100" class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-100" />
                  </div>
                </div>
                <div>
                  <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">หมายเหตุ</label>
                  <input v-model="rd.notes" type="text" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-100" />
                </div>
                <div class="text-right">
                  <button
                    type="button"
                    @click="removeRider(idx)"
                    class="text-[10px] text-rose-600 hover:text-rose-700 hover:underline"
                  >
                    <i class="pi pi-trash text-[9px] mr-1" />
                    ลบ
                  </button>
                </div>
              </div>
            </div>
          </section>

          <!-- Beneficiaries -->
          <section v-if="editTab === 'beneficiaries'" class="space-y-3">
            <div class="flex items-center justify-between">
              <p class="text-xs text-slate-500">ผู้รับประโยชน์ — รวมส่วนแบ่งควรเท่ากับ 100%</p>
              <button
                type="button"
                @click="addBeneficiary"
                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs border border-slate-300 rounded-lg hover:bg-slate-50 transition"
              >
                <i class="pi pi-plus text-[10px]" />
                เพิ่มผู้รับประโยชน์
              </button>
            </div>
            <div v-if="!editForm.beneficiaries.length" class="border border-dashed border-slate-200 rounded-lg px-4 py-6 text-center">
              <i class="pi pi-users text-slate-300 text-xl block mb-1" />
              <p class="text-xs text-slate-500">ยังไม่มีผู้รับประโยชน์</p>
            </div>
            <div v-else class="space-y-2.5">
              <div
                v-for="(b, idx) in editForm.beneficiaries"
                :key="idx"
                class="border border-slate-200 rounded-lg p-3 bg-slate-50/30 space-y-2"
              >
                <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                  <div class="md:col-span-2">
                    <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">ชื่อ-นามสกุล</label>
                    <input v-model="b.name" type="text" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-100" />
                  </div>
                  <div>
                    <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">ส่วนแบ่ง (%)</label>
                    <input v-model.number="b.share" type="number" min="0" max="100" step="5" class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-100" />
                  </div>
                </div>
                <div>
                  <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">ความสัมพันธ์</label>
                  <input v-model="b.relation" type="text" placeholder="เช่น บิดา / มารดา / คู่สมรส / บุตร" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-100" />
                </div>
                <div class="text-right">
                  <button
                    type="button"
                    @click="removeBeneficiary(idx)"
                    class="text-[10px] text-rose-600 hover:text-rose-700 hover:underline"
                  >
                    <i class="pi pi-trash text-[9px] mr-1" />
                    ลบ
                  </button>
                </div>
              </div>
              <div
                :class="[
                  'border rounded-lg px-3 py-2 flex items-center justify-between text-xs',
                  beneficiariesTotal === 100 ? 'bg-emerald-50 border-emerald-200 text-emerald-800' :
                  beneficiariesTotal === 0 ? 'bg-slate-50 border-slate-200 text-slate-600' :
                  'bg-amber-50 border-amber-200 text-amber-800',
                ]"
              >
                <span>รวมส่วนแบ่ง</span>
                <strong class="font-mono">{{ beneficiariesTotal }}%</strong>
              </div>
            </div>
          </section>
        </div>

        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl shrink-0">
          <button
            type="button"
            @click="closeEditPolicy"
            class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white"
          >
            ยกเลิก
          </button>
          <button
            type="button"
            @click="saveEditPolicy"
            class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 flex items-center gap-1.5"
          >
            <i class="pi pi-check text-xs" />
            บันทึก
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>
