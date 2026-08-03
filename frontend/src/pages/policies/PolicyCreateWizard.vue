<script setup lang="ts">
// Create-policy wizard. 3-step flow that mirrors the Access application form:
//   Step 1 — Identity & Product (customer/product/agent, IDs, renewal source)
//   Step 2 — Coverage, dates, premium breakdown, installment plan
//   Step 3 — Type-specific block driven by product.productKind
//              motor    → motor fields
//              property → property fields
//              life     → beneficiaries (+ optional riders)
//              health   → notes + optional riders
//              other    → notes + optional riders
//
// The step-3 block is intentionally the ONLY branch on product type — steps 1
// and 2 are shared so the operator's data-entry muscle memory is consistent
// across product families. Auto-recalc rules for duty stamp / VAT / total /
// commission match PolicyEdit's Phase 9b behavior.

import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import FormField from '../../components/FormField.vue'
import { api, ApiError } from '../../api/client'
import { fetchCustomerList, type CustomerListRow } from '../../api/customers'
import { fetchProductList, type ProductListRow } from '../../api/products'
import { fetchAgentList, type AgentListRow } from '../../api/agents'
import { fetchPolicyList, fetchPolicy, type PolicyListRow } from '../../api/policies'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'

// Shape of the /policies/{id} response body — mirrors PolicyResource.
// Kept local to the wizard because the shared Policy type in stores/policies.ts
// omits the fields we prefill from (motor.*, property.*, premium.*).
interface PolicyDetail {
  customerId: string
  productId: string
  carrierId: string
  writingAgentId: string
  coverage: number
  annualPremium: number
  premiumMode: 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single'
  policyYear: number
  actYear: number
  premium?: {
    main: number | null
    net: number | null
    dutyStamp: number | null
    vat: number | null
    totalPaid: number | null
    netCustomerPaid: number | null
  } | null
  motor?: {
    vehicleBrand: string; vehicleModel: string; licenseNo: string
    engineNo: string; chassisNo: string; registerYear: string
    noPassenger: number; typeDriver: string; typeVehicle: string; notes: string
  } | null
  property?: {
    insuredName: string; insuredAddress: string
    buildingCoverage: number; furnitureCoverage: number
    stockCoverage: number; otherCoverage: number
    otherDetail: string; notes: string
  } | null
  beneficiaries?: Array<{ name: string; relation: string; share: number; slot?: number | null }>
}

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'created', row: Record<string, unknown>): void
}>()

const { t } = useI18n()
const router = useRouter()

/** Open the customer list in a new tab with the create modal auto-opened.
 *  Uses router.resolve so the vite base ("/insurehub/") is respected. */
function openNewCustomerTab(): void {
  const url = router.resolve({ name: 'customers', query: { new: '1' } }).href
  window.open(url, '_blank', 'noopener')
}
/** Same idea for products — opens the products page with the create modal
 *  auto-opened; on save the popup broadcasts `product:created` and closes. */
function openNewProductTab(): void {
  const url = router.resolve({ name: 'products', query: { new: '1' } }).href
  window.open(url, '_blank', 'noopener')
}

/** Same-origin cross-tab bridge. The popup ?new=1 tab broadcasts
 *  { type: 'customer:created' | 'product:created', row } after saving; here we
 *  hydrate the matching picker so the operator doesn't need to re-search. */
type CreatedKind = 'customer:created' | 'product:created'
type CreatedMessage = { type: CreatedKind; row: Record<string, unknown> }

let hubChannel: BroadcastChannel | null = null
if (typeof BroadcastChannel !== 'undefined') {
  hubChannel = new BroadcastChannel('insurehub')
  hubChannel.onmessage = (ev: MessageEvent) => {
    if (!props.open) return
    const data = ev.data as CreatedMessage | undefined
    if (!data?.type || !data.row) return
    const r = data.row
    if (data.type === 'customer:created') {
      // Coerce the CustomerResource payload into the CustomerListRow shape
      // pickCustomer expects. Only the fields it reads are meaningful.
      const row: CustomerListRow = {
        id: String(r.id ?? ''),
        customerCode: String(r.customerCode ?? ''),
        customerType: String(r.customerType ?? ''),
        firstName: String(r.firstName ?? ''),
        lastName: String(r.lastName ?? ''),
        nickname: String(r.nickname ?? ''),
        idCard: String(r.idCard ?? ''),
        phone: String(r.phone ?? ''),
        email: String(r.email ?? ''),
        province: String(r.province ?? ''),
        assignedAgentId: null,
        assignedAgentCode: null,
        assignedAgentName: '',
        activePolicyCount: 0,
        totalPolicyCount: 0,
        active: true,
        registeredAt: null,
      }
      pickCustomer(row)
    } else if (data.type === 'product:created') {
      // POST /products returns ProductResource — same field names as
      // ProductListRow except a few list-only display columns. Fill required
      // ProductListRow fields; unused list columns get safe defaults.
      const kind = String(r.productKind ?? 'other') as ProductListRow['productKind']
      const row: ProductListRow = {
        id: String(r.id ?? ''),
        code: String(r.code ?? ''),
        commissionCode: String(r.commissionCode ?? ''),
        name: String(r.name ?? ''),
        nameEn: String(r.nameEn ?? ''),
        carrierId: String(r.carrierId ?? ''),
        carrierCode: (r.carrierCode as string | null) ?? null,
        carrierName: (r.carrierName as string | null) ?? null,
        carrierInsureType: String(r.carrierInsureType ?? ''),
        type: String(r.type ?? ''),
        category: String(r.category ?? ''),
        subCategory: String(r.subCategory ?? ''),
        subCategory2: String(r.subCategory2 ?? ''),
        mainRider: String(r.mainRider ?? ''),
        productKind: kind,
        minAge: Number(r.minAge ?? 0),
        maxAge: Number(r.maxAge ?? 99),
        minSumAssure: r.minSumAssure !== undefined ? Number(r.minSumAssure) : null,
        maxSumAssure: r.maxSumAssure !== undefined ? Number(r.maxSumAssure) : null,
        validStart: (r.validStart as string | null) ?? null,
        validEnd: (r.validEnd as string | null) ?? null,
        active: r.active !== false,
      }
      // Sync the cascade upstream of the product: set insureType, load
      // that type's carriers, then pick the carrier the product belongs to.
      // We also need the products list populated so the dropdown displays
      // the newly-created product as its selected option (rather than an
      // orphan value the <select> can't render).
      const it = row.carrierInsureType as 'life' | 'non-life' | 'tax' | ''
      if (it === 'life' || it === 'non-life' || it === 'tax') {
        form.insureType = it
        loadCarriersForInsureType(it).then(() => {
          form.carrierId = row.carrierId
          carrierPicked.value = carriers.value.find((c) => c.id === row.carrierId) ?? null
          return loadProductsForCarrier(row.carrierId)
        }).then(() => {
          // The dropdown now has the new product because it's active + on
          // this carrier. Re-pick to sync all state.
          const fresh = products.value.find((p) => p.id === row.id) ?? row
          pickProduct(fresh)
        })
      } else {
        pickProduct(row)
      }
    }
  }
}
onBeforeUnmount(() => {
  hubChannel?.close()
  hubChannel = null
})

// ── State ────────────────────────────────────────────────────────────────
type WizardStep = 1 | 2 | 3
const step = ref<WizardStep>(1)
const saving = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

interface RiderRow {
  name: string
  premium: number
  slot: number | null
  comRateInh: number | null
  comRateAg: number | null
  notes: string
}
interface BeneficiaryRow {
  name: string
  relation: string
  share: number
  slot: number | null
}

const form = reactive({
  // Step 1
  customerId: '',
  // 3-stage cascade before product: insureType → carrier → product.
  // insureType matches the carrier's `insure_type` column values.
  insureType: '' as 'life' | 'non-life' | 'tax' | '',
  carrierId: '',
  productId: '',
  writingAgentId: '',
  applicationNo: '',
  policyNo: '',
  notionNo: '',
  newOrRenew: 'new' as 'new' | 'renew',
  refAppToId: '' as string,
  // Step 2 — coverage & premium
  coverage: 0,
  effectiveDate: '',
  expiryDate: '',
  appDate: '',
  policyYear: 1,
  actYear: 1,
  netPremium: 0,
  mainPremium: 0,
  dutyStamp: 0,
  vat: 0,
  whtAmt: 0,
  totalPremiumPaid: 0,
  netCustomerPaid: 0,
  annualPremium: 0,
  premiumMode: 'annual' as 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single',
  installmentTerm: '' as string,
  firstDueInst: 0,
  firstDueInstDate: '',
  nextDueInst: 0,
  lastDueInstDate: '',
  // Step 3 — commission override (all product kinds)
  mainComRateInh: 0,
  mainComAmtInh: 0,
  mainComRateAg: 0,
  mainComAmtAg: 0,
  status: 'application' as 'quote' | 'application' | 'submitted' | 'issued' | 'active',
  notes: '',
  // Step 3 — motor
  motorTypeDriver: '',
  motorTypeVehicle: '',
  motorVehicleBrand: '',
  motorVehicleModel: '',
  motorLicenseNo: '',
  motorEngineNo: '',
  motorChassisNo: '',
  motorRegisterYear: '',
  motorNoPassenger: 0,
  motorNotes: '',
  // Step 3 — property
  propertyInsuredName: '',
  propertyInsuredAddress: '',
  propertyBuildingCov: 0,
  propertyFurnitureCov: 0,
  propertyStockCov: 0,
  propertyOtherCov: 0,
  propertyOtherDetail: '',
  propertyNotes: '',
  propertyPhone: '',
  // Step 3 — riders + beneficiaries. Access reserves 5 rider slots and 4
  // beneficiary slots; we preallocate the same shape so the operator sees a
  // grid, not an empty list they have to "add row" into. Empty rows are
  // filtered out at submit.
  riders: makeEmptyRiders(),
  beneficiaries: [] as BeneficiaryRow[],
})

/** 5 empty rider rows with pre-assigned slot numbers 1..5 (Access parity). */
function makeEmptyRiders(): RiderRow[] {
  return Array.from({ length: 5 }, (_, i) => ({
    name: '', premium: 0, slot: i + 1, comRateInh: null, comRateAg: null, notes: '',
  }))
}

// ── Picker state (customer/carrier/product/agent/renewal source) ─────────
const customerSearch = ref('')
const customerResults = ref<CustomerListRow[]>([])
const customerPicked = ref<CustomerListRow | null>(null)

// Carriers for the current insureType — small enough (≤100 per tenant) that
// we load the full list on insureType change and render a select instead of a
// search-pick. Simpler UX for a bounded set.
const carriers = ref<CarrierListRow[]>([])
const carriersLoading = ref(false)
const carrierPicked = ref<CarrierListRow | null>(null)

// Products for the currently-picked carrier. Bounded per carrier (largest
// seen ~112) so a plain <select> is fine. We keep productSearch/results
// around for the BroadcastChannel new-product flow and for renewal prefill.
const products = ref<ProductListRow[]>([])
const productsLoading = ref(false)
const productSearch = ref('')
const productResults = ref<ProductListRow[]>([])
const productPicked = ref<ProductListRow | null>(null)

const agentSearch = ref('')
const agentResults = ref<AgentListRow[]>([])
const agentPicked = ref<AgentListRow | null>(null)

const renewalSearch = ref('')
const renewalResults = ref<PolicyListRow[]>([])
const renewalPicked = ref<PolicyListRow | null>(null)
const renewalPrefilling = ref(false)

let cTimer: number | undefined
let aTimer: number | undefined
let rTimer: number | undefined

watch(customerSearch, (q) => {
  clearTimeout(cTimer)
  cTimer = window.setTimeout(async () => {
    if (!q || q.length < 2) { customerResults.value = []; return }
    const res = await fetchCustomerList({ q, perPage: 8 })
    customerResults.value = res.data
  }, 250)
})
watch(agentSearch, (q) => {
  clearTimeout(aTimer)
  aTimer = window.setTimeout(async () => {
    if (!q || q.length < 2) { agentResults.value = []; return }
    const res = await fetchAgentList({ q, perPage: 8 })
    agentResults.value = res.data
  }, 250)
})
watch(renewalSearch, (q) => {
  clearTimeout(rTimer)
  rTimer = window.setTimeout(async () => {
    if (!q || q.length < 2) { renewalResults.value = []; return }
    const res = await fetchPolicyList({ q, perPage: 8 })
    renewalResults.value = res.data
  }, 250)
})

function pickCustomer(c: CustomerListRow): void {
  customerPicked.value = c
  form.customerId = c.id
  customerSearch.value = `${c.customerCode} · ${c.firstName} ${c.lastName}`.trim()
  customerResults.value = []
}

/** Load carriers matching the picked insureType. Fresh call on every change
 *  so a stale list from the previous type is never shown. */
async function loadCarriersForInsureType(t: 'life' | 'non-life' | 'tax'): Promise<void> {
  carriersLoading.value = true
  try {
    const res = await fetchCarrierList({ insureType: t, activeOnly: true, perPage: 100 })
    carriers.value = res.data
  } catch {
    carriers.value = []
  } finally {
    carriersLoading.value = false
  }
}

/** Load all active products under the picked carrier — used to populate the
 *  product dropdown. perPage:200 comfortably covers the largest per-carrier
 *  count observed in the data (~112). */
async function loadProductsForCarrier(carrierId: string): Promise<void> {
  productsLoading.value = true
  try {
    const res = await fetchProductList({ carrierId, activeOnly: true, perPage: 200 })
    products.value = res.data
  } catch {
    products.value = []
  } finally {
    productsLoading.value = false
  }
}

/** Insure-type button click. Clears downstream (carrier + product) so a stale
 *  selection from the previous type can't survive. */
async function pickInsureType(t: 'life' | 'non-life' | 'tax'): Promise<void> {
  if (form.insureType === t) return
  form.insureType = t
  // Clear downstream state.
  form.carrierId = ''
  form.productId = ''
  carrierPicked.value = null
  productPicked.value = null
  products.value = []
  productSearch.value = ''
  productResults.value = []
  await loadCarriersForInsureType(t)
}

async function pickCarrier(id: string): Promise<void> {
  form.carrierId = id
  carrierPicked.value = carriers.value.find((c) => c.id === id) ?? null
  // Clear the product so the operator picks one that belongs to the new carrier.
  form.productId = ''
  productPicked.value = null
  productSearch.value = ''
  productResults.value = []
  if (id) {
    await loadProductsForCarrier(id)
  } else {
    products.value = []
  }
}

/** Product-dropdown change handler. */
function selectProductById(id: string): void {
  const p = products.value.find((x) => x.id === id) ?? null
  if (p) pickProduct(p)
  else {
    form.productId = ''
    productPicked.value = null
  }
}

function pickProduct(p: ProductListRow): void {
  productPicked.value = p
  form.productId = p.id
  // Product's carrier must match the selected carrier — trust it since we
  // filtered the search by carrierId. Keep the assignment as a safety net
  // in case someone opens this via the broadcast channel with a mismatch.
  if (!form.carrierId) form.carrierId = p.carrierId
  productSearch.value = `${p.code} · ${p.name}`
  productResults.value = []
}
function pickAgent(a: AgentListRow): void {
  agentPicked.value = a
  form.writingAgentId = a.id
  agentSearch.value = `${a.agentCode} · ${a.firstName} ${a.lastName}`.trim()
  agentResults.value = []
}

// Renewal picker: on pick, fetch the source policy and prefill customer,
// product, agent, coverage, motor/property blocks, beneficiaries. Fresh
// application/policy numbers must be entered by the operator — every year
// gets new identifiers.
async function pickRenewalSource(r: PolicyListRow): Promise<void> {
  renewalPicked.value = r
  form.refAppToId = r.id
  renewalSearch.value = `${r.policyNo ?? r.applicationNo ?? r.quoteNo ?? '?'} · ${r.customerName}`
  renewalResults.value = []
  renewalPrefilling.value = true
  try {
    // Populate the picker labels from the list row (which has the joined display
    // columns — PolicyResource doesn't).
    form.customerId = r.customerId
    form.productId = r.productId
    form.carrierId = r.carrierId
    form.writingAgentId = r.writingAgentId
    customerSearch.value = `${r.customerCode ?? ''} · ${r.customerName ?? ''}`.trim()
    productSearch.value = `${r.productCode ?? ''} · ${r.productName ?? ''}`.trim()
    agentSearch.value = `${r.agentCode ?? ''} · ${r.agentName ?? ''}`.trim()
    // Rehydrate productPicked so productKind (drives step 3) is available.
    if (r.productCode) {
      const prod = await fetchProductList({ q: r.productCode, perPage: 1 })
      if (prod.data.length > 0) productPicked.value = prod.data[0]
    }
    // Derive the 3-stage cascade state from the source policy's carrier so
    // the insureType button, carrier dropdown, and product dropdown all
    // display the correct picks (dropdown needs the product row loaded in
    // its options, else it can't render the selected value).
    if (productPicked.value?.carrierInsureType) {
      const it = productPicked.value.carrierInsureType as 'life' | 'non-life' | 'tax'
      form.insureType = it
      await loadCarriersForInsureType(it)
      carrierPicked.value = carriers.value.find((c) => c.id === form.carrierId) ?? null
      await loadProductsForCarrier(form.carrierId)
    }
    // Detail fetch for premium breakdown / motor / property / beneficiaries.
    const res = await fetchPolicy(r.id)
    const p = (res as unknown as { data: PolicyDetail }).data
    form.coverage = Number(p.coverage ?? 0)
    form.netPremium = Number(p.premium?.net ?? p.annualPremium ?? 0)
    form.mainPremium = Number(p.premium?.main ?? 0)
    form.annualPremium = Number(p.annualPremium ?? 0)
    form.premiumMode = p.premiumMode ?? 'annual'
    form.actYear = Number(p.actYear ?? 1) + 1
    form.policyYear = Number(p.policyYear ?? 1) + 1
    if (p.motor) {
      form.motorVehicleBrand = p.motor.vehicleBrand
      form.motorVehicleModel = p.motor.vehicleModel
      form.motorLicenseNo = p.motor.licenseNo
      form.motorEngineNo = p.motor.engineNo
      form.motorChassisNo = p.motor.chassisNo
      form.motorRegisterYear = p.motor.registerYear
      form.motorTypeDriver = p.motor.typeDriver
      form.motorTypeVehicle = p.motor.typeVehicle
      form.motorNoPassenger = p.motor.noPassenger
    }
    if (p.property) {
      form.propertyInsuredName = p.property.insuredName
      form.propertyInsuredAddress = p.property.insuredAddress
      form.propertyBuildingCov = p.property.buildingCoverage
      form.propertyFurnitureCov = p.property.furnitureCoverage
      form.propertyStockCov = p.property.stockCoverage
      form.propertyOtherCov = p.property.otherCoverage
    }
    if (Array.isArray(p.beneficiaries)) {
      form.beneficiaries = p.beneficiaries.map((b, i) => ({
        name: b.name, relation: b.relation ?? '', share: Number(b.share), slot: b.slot ?? i + 1,
      }))
    }
  } catch {
    // Prefill is best-effort — silent failure leaves the form blank.
  } finally {
    renewalPrefilling.value = false
  }
}

// ── Auto-recalc premium (mirrors PolicyEdit Phase 9b) ────────────────────
const autoRecalcPremium = ref(true)

function computeDutyStamp(net: number): number { return Math.ceil((net * 0.004) * 100) / 100 }
function computeVat(net: number, duty: number): number { return Math.round((net + duty) * 0.07 * 100) / 100 }

watch(() => form.netPremium, (n) => {
  if (!autoRecalcPremium.value) return
  const net = Number(n) || 0
  const duty = computeDutyStamp(net)
  const vat = computeVat(net, duty)
  form.dutyStamp = duty
  form.vat = vat
  form.totalPremiumPaid = Math.round((net + duty + vat) * 100) / 100
  form.netCustomerPaid = Math.round((form.totalPremiumPaid - (Number(form.whtAmt) || 0)) * 100) / 100
  if (!form.annualPremium || form.annualPremium === 0) form.annualPremium = net
  if (!form.mainPremium || form.mainPremium === 0) form.mainPremium = net
})
watch(() => form.whtAmt, (w) => {
  form.netCustomerPaid = Math.round(((Number(form.totalPremiumPaid) || 0) - (Number(w) || 0)) * 100) / 100
})

// Commission amount auto-derives from rate × net premium.
watch(() => form.mainComRateInh, (rate) => {
  const net = Number(form.netPremium) || 0
  if (net > 0) form.mainComAmtInh = Math.round(net * Number(rate) * 100) / 100
})
watch(() => form.mainComRateAg, (rate) => {
  const net = Number(form.netPremium) || 0
  if (net > 0) form.mainComAmtAg = Math.round(net * Number(rate) * 100) / 100
})

// ── Beneficiaries helpers ────────────────────────────────────────────────
// Riders use fixed 5-slot preallocation (see makeEmptyRiders); no add/remove.
function addBeneficiary(): void {
  form.beneficiaries.push({ name: '', relation: '', share: 100, slot: null })
}
function removeBeneficiary(i: number): void { form.beneficiaries.splice(i, 1) }

const beneficiaryShareTotal = computed(() =>
  form.beneficiaries.reduce((sum, b) => sum + (Number(b.share) || 0), 0),
)

// ── Step gating ──────────────────────────────────────────────────────────
const canNextStep1 = computed(() =>
  form.customerId !== '' && form.insureType !== '' && form.carrierId !== ''
  && form.productId !== '' && form.writingAgentId !== '',
)
const canNextStep2 = computed(() =>
  !!form.effectiveDate && !!form.expiryDate && (Number(form.netPremium) > 0 || Number(form.annualPremium) > 0),
)
const canSubmit = computed(() => {
  if (!canNextStep1.value || !canNextStep2.value) return false
  // Life products: if beneficiaries are provided, their shares must sum to 100.
  if (productPicked.value?.productKind === 'life' && form.beneficiaries.length > 0) {
    return Math.abs(beneficiaryShareTotal.value - 100) < 0.01
  }
  return true
})

const kind = computed(() => productPicked.value?.productKind ?? 'other')

// ── Payload construction ─────────────────────────────────────────────────
function trim<T>(v: T): T | null {
  if (typeof v === 'string') { const s = v.trim(); return (s === '' ? null : s) as unknown as T }
  return v
}

const payload = computed(() => {
  const base: Record<string, unknown> = {
    customerId: Number(form.customerId),
    productId: Number(form.productId),
    carrierId: Number(form.carrierId),
    writingAgentId: Number(form.writingAgentId),
    applicationNo: trim(form.applicationNo),
    policyNo: trim(form.policyNo),
    notionNo: trim(form.notionNo),
    refAppToId: form.refAppToId ? Number(form.refAppToId) : null,
    coverage: form.coverage,
    // Premium.
    netPremium: form.netPremium,
    mainPremium: form.mainPremium,
    dutyStamp: form.dutyStamp,
    vat: form.vat,
    whtAmt: form.whtAmt,
    totalPremiumPaid: form.totalPremiumPaid,
    netCustomerPaid: form.netCustomerPaid,
    annualPremium: form.annualPremium || form.netPremium,
    premiumMode: form.premiumMode,
    // Installment.
    installmentTerm: trim(form.installmentTerm),
    firstDueInst: form.firstDueInst || null,
    firstDueInstDate: form.firstDueInstDate || null,
    nextDueInst: form.nextDueInst || null,
    lastDueInstDate: form.lastDueInstDate || null,
    // Dates.
    appDate: form.appDate || null,
    effectiveDate: form.effectiveDate || null,
    expiryDate: form.expiryDate || null,
    policyYear: form.policyYear,
    actYear: form.actYear,
    newOrRenew: form.newOrRenew,
    status: form.status,
    notes: trim(form.notes),
    // Commission override.
    mainComRateInh: form.mainComRateInh || null,
    mainComAmtInh: form.mainComAmtInh || null,
    mainComRateAg: form.mainComRateAg || null,
    mainComAmtAg: form.mainComAmtAg || null,
  }

  // Motor block — only send when the product is motor.
  if (kind.value === 'motor') {
    Object.assign(base, {
      motorTypeDriver: trim(form.motorTypeDriver),
      motorTypeVehicle: trim(form.motorTypeVehicle),
      motorVehicleBrand: trim(form.motorVehicleBrand),
      motorVehicleModel: trim(form.motorVehicleModel),
      motorLicenseNo: trim(form.motorLicenseNo),
      motorEngineNo: trim(form.motorEngineNo),
      motorChassisNo: trim(form.motorChassisNo),
      motorRegisterYear: trim(form.motorRegisterYear),
      motorNoPassenger: form.motorNoPassenger || null,
      motorNotes: trim(form.motorNotes),
    })
  }
  // Property block — for fire/property products.
  if (kind.value === 'property') {
    Object.assign(base, {
      propertyInsuredName: trim(form.propertyInsuredName),
      propertyInsuredAddress: trim(form.propertyInsuredAddress),
      propertyBuildingCov: form.propertyBuildingCov || null,
      propertyFurnitureCov: form.propertyFurnitureCov || null,
      propertyStockCov: form.propertyStockCov || null,
      propertyOtherCov: form.propertyOtherCov || null,
      propertyOtherDetail: trim(form.propertyOtherDetail),
      propertyNotes: trim(form.propertyNotes),
      propertyPhone: trim(form.propertyPhone),
    })
  }
  // Riders — Access parity: riders only ride on life main policies. Skip
  // sending them for motor/property/health/other so we don't persist stale
  // rows if the operator toggled between product kinds during entry.
  if (kind.value === 'life') {
    const nonEmpty = form.riders.filter((r) => r.name.trim() !== '')
    if (nonEmpty.length > 0) {
      base.riders = nonEmpty.map((r, i) => ({
        name: r.name.trim(),
        premium: Number(r.premium) || 0,
        slot: r.slot ?? i + 1,
        comRateInh: r.comRateInh,
        comRateAg: r.comRateAg,
        notes: r.notes.trim() || null,
      }))
    }
  }
  // Beneficiaries — for life products.
  if (kind.value === 'life' && form.beneficiaries.length > 0) {
    base.beneficiaries = form.beneficiaries
      .filter((b) => b.name.trim() !== '')
      .map((b, i) => ({
        name: b.name.trim(),
        relation: b.relation.trim() || null,
        share: Number(b.share) || 0,
        slot: b.slot ?? i + 1,
      }))
  }
  return base
})

// ── Submit ───────────────────────────────────────────────────────────────
async function submit(): Promise<void> {
  if (!canSubmit.value || saving.value) return
  saving.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    const res = await api.post<{ data: Record<string, unknown> }>('policies', payload.value)
    emit('created', res.data ?? res)
    emit('close')
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      error.value = e.body?.message ?? `HTTP ${e.status}`
      if (e.body?.errors) {
        fieldErrors.value = e.body.errors
        // Jump to the earliest step containing an error so the operator sees it.
        const keys = Object.keys(fieldErrors.value)
        if (keys.some((k) => ['customerId', 'productId', 'writingAgentId', 'applicationNo', 'policyNo', 'notionNo', 'refAppToId'].includes(k))) {
          step.value = 1
        } else if (keys.some((k) => k.startsWith('motor') || k.startsWith('property') || k.startsWith('rider') || k.startsWith('beneficiar') || k.startsWith('mainCom'))) {
          step.value = 3
        } else {
          step.value = 2
        }
      }
    } else {
      error.value = e instanceof Error ? e.message : 'Save failed'
    }
  } finally {
    saving.value = false
  }
}

// ── Reset when opening ───────────────────────────────────────────────────
watch(() => props.open, (v) => {
  if (!v) return
  step.value = 1
  error.value = null
  fieldErrors.value = {}
  Object.assign(form, {
    customerId: '', insureType: '', carrierId: '', productId: '', writingAgentId: '',
    applicationNo: '', policyNo: '', notionNo: '',
    newOrRenew: 'new', refAppToId: '',
    coverage: 0, effectiveDate: '', expiryDate: '', appDate: '',
    policyYear: 1, actYear: 1,
    netPremium: 0, mainPremium: 0, dutyStamp: 0, vat: 0, whtAmt: 0,
    totalPremiumPaid: 0, netCustomerPaid: 0, annualPremium: 0,
    premiumMode: 'annual', installmentTerm: '',
    firstDueInst: 0, firstDueInstDate: '', nextDueInst: 0, lastDueInstDate: '',
    mainComRateInh: 0, mainComAmtInh: 0, mainComRateAg: 0, mainComAmtAg: 0,
    status: 'application', notes: '',
    motorTypeDriver: '', motorTypeVehicle: '',
    motorVehicleBrand: '', motorVehicleModel: '', motorLicenseNo: '',
    motorEngineNo: '', motorChassisNo: '', motorRegisterYear: '',
    motorNoPassenger: 0, motorNotes: '',
    propertyInsuredName: '', propertyInsuredAddress: '',
    propertyBuildingCov: 0, propertyFurnitureCov: 0, propertyStockCov: 0,
    propertyOtherCov: 0, propertyOtherDetail: '', propertyNotes: '', propertyPhone: '',
    riders: makeEmptyRiders(), beneficiaries: [],
  })
  customerSearch.value = ''; productSearch.value = ''; agentSearch.value = ''; renewalSearch.value = ''
  customerPicked.value = null; productPicked.value = null; agentPicked.value = null; renewalPicked.value = null
  carrierPicked.value = null; carriers.value = []; products.value = []
  customerResults.value = []; productResults.value = []; agentResults.value = []; renewalResults.value = []
})

function next(): void {
  if (step.value === 1 && canNextStep1.value) step.value = 2
  else if (step.value === 2 && canNextStep2.value) step.value = 3
}
function back(): void {
  if (step.value === 3) step.value = 2
  else if (step.value === 2) step.value = 1
}
</script>

<template>
  <!-- No @click.self on the backdrop — closing is intentional-only via the X button in the header.
       This prevents accidental data loss when users click outside the modal. -->
  <div v-if="open" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[92vh] overflow-hidden flex flex-col">
      <!-- Header + stepper -->
      <header class="px-5 py-3 border-b border-slate-200">
        <div class="flex items-center justify-between mb-3">
          <div class="text-lg font-semibold text-slate-900">{{ t('policyCreate.title') }}</div>
          <button type="button" class="text-slate-400 hover:text-slate-700 p-1" @click="emit('close')">
            <i class="pi pi-times" />
          </button>
        </div>
        <div class="flex items-center gap-2 text-xs">
          <div v-for="n in [1, 2, 3]" :key="n"
            class="flex items-center gap-2"
            :class="n < 3 ? 'flex-1' : ''">
            <div class="flex items-center gap-2 shrink-0">
              <div class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-semibold"
                :class="step === n
                  ? 'bg-brand-600 text-white'
                  : step > n ? 'bg-brand-100 text-brand-700' : 'bg-slate-100 text-slate-400'">
                {{ n }}
              </div>
              <span :class="step === n ? 'text-slate-900 font-medium' : 'text-slate-500'">
                {{ t(`policyCreate.step.${n}`) }}
              </span>
            </div>
            <div v-if="n < 3" class="flex-1 h-px bg-slate-200 mx-1" />
          </div>
        </div>
      </header>

      <!-- Body -->
      <div class="flex-1 overflow-y-auto p-5 space-y-4">
        <!-- ─── Step 1: identity & product ─────────────────────────────── -->
        <section v-show="step === 1" class="space-y-4">
          <FormField :label="t('policyCreate.newOrRenew')" class="col-span-2" error-key="newOrRenew" :errors="fieldErrors">
            <div class="flex gap-2">
              <button type="button"
                class="px-4 py-1.5 rounded-lg text-sm border"
                :class="form.newOrRenew === 'new'
                  ? 'bg-brand-600 text-white border-brand-600'
                  : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'"
                @click="form.newOrRenew = 'new'">
                {{ t('policyCreate.new') }}
              </button>
              <button type="button"
                class="px-4 py-1.5 rounded-lg text-sm border"
                :class="form.newOrRenew === 'renew'
                  ? 'bg-brand-600 text-white border-brand-600'
                  : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'"
                @click="form.newOrRenew = 'renew'">
                {{ t('policyCreate.renew') }}
              </button>
            </div>
          </FormField>

          <!-- Renewal source picker — only when renewing. -->
          <FormField v-if="form.newOrRenew === 'renew'"
            :label="t('policyCreate.renewalSource')"
            :hint="t('policyCreate.renewalSourceHint')"
            class="col-span-2 relative"
            error-key="refAppToId" :errors="fieldErrors">
            <input v-model="renewalSearch"
              :placeholder="t('policyCreate.renewalSourcePlaceholder')"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            <div v-if="renewalResults.length"
              class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-56 overflow-auto z-10">
              <button v-for="r in renewalResults" :key="r.id" type="button"
                class="w-full text-left px-3 py-2 text-sm hover:bg-slate-50"
                @click="pickRenewalSource(r)">
                <div class="flex items-center gap-2">
                  <span class="font-mono text-xs text-slate-500">{{ r.policyNo ?? r.applicationNo ?? r.quoteNo }}</span>
                  <span class="text-xs text-slate-400">{{ r.productCode }}</span>
                </div>
                <div class="text-slate-900 text-sm">{{ r.customerName }}</div>
              </button>
            </div>
            <div v-if="renewalPrefilling" class="text-[10px] text-slate-400 mt-1">
              <i class="pi pi-spin pi-spinner text-[10px]" /> {{ t('policyCreate.prefilling') }}
            </div>
          </FormField>

          <div class="grid grid-cols-2 gap-4">
            <!-- Customer -->
            <FormField :label="t('policyCreate.customer')" required class="col-span-2 relative" error-key="customerId" :errors="fieldErrors">
              <div class="flex gap-2">
                <input v-model="customerSearch"
                  :placeholder="t('policyCreate.customerPlaceholder')"
                  class="flex-1 border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
                <button type="button"
                  class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm inline-flex items-center gap-1 shrink-0"
                  :title="t('policyCreate.newCustomerHint')"
                  @click="openNewCustomerTab">
                  <i class="pi pi-user-plus text-xs" />
                  {{ t('policyCreate.newCustomer') }}
                </button>
              </div>
              <div v-if="customerResults.length"
                class="absolute left-0 right-[112px] top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-56 overflow-auto z-10">
                <button v-for="c in customerResults" :key="c.id" type="button"
                  class="w-full text-left px-3 py-2 text-sm hover:bg-slate-50 flex items-center gap-2"
                  @click="pickCustomer(c)">
                  <span class="font-mono text-xs text-slate-500">{{ c.customerCode }}</span>
                  <span class="text-slate-900">{{ c.firstName }} {{ c.lastName }}</span>
                  <span v-if="c.phone" class="text-xs text-slate-400 ml-auto">{{ c.phone }}</span>
                </button>
              </div>
            </FormField>
            <!-- Insure type — first step of the 3-stage cascade. -->
            <FormField :label="t('policyCreate.insureType')" required class="col-span-2" error-key="carrierId" :errors="fieldErrors"
              :hint="t('policyCreate.insureTypeHint')">
              <div class="flex gap-2">
                <button v-for="opt in [
                  { v: 'life', label: t('policyCreate.insureTypeOpt.life') },
                  { v: 'non-life', label: t('policyCreate.insureTypeOpt.nonLife') },
                  { v: 'tax', label: t('policyCreate.insureTypeOpt.tax') },
                ] as const" :key="opt.v" type="button"
                  class="px-4 py-1.5 rounded-lg text-sm border"
                  :class="form.insureType === opt.v
                    ? 'bg-brand-600 text-white border-brand-600'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'"
                  @click="pickInsureType(opt.v)">
                  {{ opt.label }}
                </button>
              </div>
            </FormField>

            <!-- Carrier — second step; select from carriers matching insureType. -->
            <FormField v-if="form.insureType" :label="t('policyCreate.carrier')" required class="col-span-2" error-key="carrierId" :errors="fieldErrors"
              :hint="carriersLoading
                ? t('policyCreate.carrierLoading')
                : (carriers.length === 0 ? t('policyCreate.carrierEmpty') : undefined)">
              <select :value="form.carrierId"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400 disabled:bg-slate-50 disabled:text-slate-400"
                :disabled="carriersLoading || carriers.length === 0"
                @change="pickCarrier(($event.target as HTMLSelectElement).value)">
                <option value="">{{ t('policyCreate.carrierPlaceholder') }}</option>
                <option v-for="c in carriers" :key="c.id" :value="c.id">
                  {{ c.code }} — {{ c.nicknameTh || c.name }}
                  <template v-if="c.productCount"> ({{ c.productCount }})</template>
                </option>
              </select>
            </FormField>

            <!-- Product — third step of the cascade; dropdown of the picked
                 carrier's active products. Disabled until a carrier is chosen. -->
            <FormField :label="t('policyCreate.product')" required class="col-span-2" error-key="productId" :errors="fieldErrors"
              :hint="!form.carrierId
                ? t('policyCreate.productBlockedHint')
                : (productsLoading
                    ? t('policyCreate.productLoading')
                    : (products.length === 0 ? t('policyCreate.productEmpty') : undefined))">
              <div class="flex gap-2">
                <select :value="form.productId"
                  :disabled="!form.carrierId || productsLoading || products.length === 0"
                  class="flex-1 border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400 disabled:bg-slate-50 disabled:text-slate-400"
                  @change="selectProductById(($event.target as HTMLSelectElement).value)">
                  <option value="">
                    {{ form.carrierId ? t('policyCreate.productPlaceholder') : t('policyCreate.productBlockedPlaceholder') }}
                  </option>
                  <option v-for="p in products" :key="p.id" :value="p.id">
                    {{ p.code }} — {{ p.name }}
                  </option>
                </select>
                <button type="button"
                  class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm inline-flex items-center gap-1 shrink-0"
                  :title="t('policyCreate.newProductHint')"
                  @click="openNewProductTab">
                  <i class="pi pi-plus text-xs" />
                  {{ t('policyCreate.newProduct') }}
                </button>
              </div>
              <div v-if="productPicked" class="text-[10px] text-slate-400 mt-1">
                {{ t('policyCreate.productKindLabel') }}:
                <span class="font-semibold text-slate-600">{{ t(`policyCreate.kind.${productPicked.productKind}`) }}</span>
              </div>
            </FormField>
            <!-- Agent -->
            <FormField :label="t('policyCreate.agent')" required class="col-span-2 relative" error-key="writingAgentId" :errors="fieldErrors">
              <input v-model="agentSearch"
                :placeholder="t('policyCreate.agentPlaceholder')"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              <div v-if="agentResults.length"
                class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-56 overflow-auto z-10">
                <button v-for="a in agentResults" :key="a.id" type="button"
                  class="w-full text-left px-3 py-2 text-sm hover:bg-slate-50 flex items-center gap-2"
                  @click="pickAgent(a)">
                  <span class="font-mono text-xs text-slate-500">{{ a.agentCode }}</span>
                  <span class="text-slate-900">{{ a.firstName }} {{ a.lastName }}</span>
                  <span class="text-[10px] uppercase text-slate-400 ml-auto">{{ a.level }}</span>
                </button>
              </div>
            </FormField>

            <FormField :label="t('policyCreate.applicationNo')" error-key="applicationNo" :errors="fieldErrors">
              <input v-model.trim="form.applicationNo"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
            </FormField>
            <FormField :label="t('policyCreate.policyNo')" error-key="policyNo" :errors="fieldErrors">
              <input v-model.trim="form.policyNo"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
            </FormField>
            <FormField :label="t('policyCreate.notionNo')" error-key="notionNo" :errors="fieldErrors" class="col-span-2">
              <input v-model.trim="form.notionNo"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
            </FormField>
          </div>
        </section>

        <!-- ─── Step 2: coverage / dates / premium ──────────────────────── -->
        <section v-show="step === 2" class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <FormField :label="t('policyCreate.appDate')" error-key="appDate" :errors="fieldErrors">
              <input v-model="form.appDate" type="date"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField :label="t('policyCreate.coverage')" error-key="coverage" :errors="fieldErrors">
              <input v-model.number="form.coverage" type="number" min="0"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
            </FormField>
            <FormField :label="t('policyCreate.effectiveDate')" required error-key="effectiveDate" :errors="fieldErrors">
              <input v-model="form.effectiveDate" type="date"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField :label="t('policyCreate.expiryDate')" required error-key="expiryDate" :errors="fieldErrors">
              <input v-model="form.expiryDate" type="date"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField :label="t('policyCreate.policyYear')" error-key="policyYear" :errors="fieldErrors">
              <input v-model.number="form.policyYear" type="number" min="1"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
            </FormField>
            <FormField :label="t('policyCreate.actYear')" error-key="actYear" :errors="fieldErrors">
              <input v-model.number="form.actYear" type="number" min="1"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
            </FormField>
          </div>

          <!-- Premium block -->
          <div class="mt-4 pt-3 border-t border-slate-100">
            <div class="flex items-center justify-between mb-2">
              <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">
                {{ t('policyCreate.premiumBreakdown') }}
              </div>
              <label class="inline-flex items-center gap-1.5 text-[10px] text-slate-500">
                <input v-model="autoRecalcPremium" type="checkbox" class="rounded border-slate-300" />
                {{ t('policyCreate.autoRecalc') }}
              </label>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <FormField :label="t('policyCreate.netPremium')" error-key="netPremium" :errors="fieldErrors">
                <input v-model.number="form.netPremium" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.mainPremium')" error-key="mainPremium" :errors="fieldErrors">
                <input v-model.number="form.mainPremium" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.dutyStamp')" error-key="dutyStamp" :errors="fieldErrors">
                <input v-model.number="form.dutyStamp" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.vat')" error-key="vat" :errors="fieldErrors">
                <input v-model.number="form.vat" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.totalPremiumPaid')" error-key="totalPremiumPaid" :errors="fieldErrors">
                <input v-model.number="form.totalPremiumPaid" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.whtAmt')" error-key="whtAmt" :errors="fieldErrors">
                <input v-model.number="form.whtAmt" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.netCustomerPaid')" error-key="netCustomerPaid" :errors="fieldErrors" class="col-span-2">
                <input v-model.number="form.netCustomerPaid" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
            </div>
          </div>

          <!-- Installment block -->
          <div class="mt-4 pt-3 border-t border-slate-100">
            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
              {{ t('policyCreate.installment') }}
            </div>
            <div class="grid grid-cols-2 gap-4">
              <FormField :label="t('policyCreate.premiumMode')" error-key="premiumMode" :errors="fieldErrors">
                <select v-model="form.premiumMode"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
                  <option value="monthly">Monthly</option>
                  <option value="quarterly">Quarterly</option>
                  <option value="semiannual">Semiannual</option>
                  <option value="annual">Annual</option>
                  <option value="single">Single</option>
                </select>
              </FormField>
              <FormField :label="t('policyCreate.installmentTerm')" error-key="installmentTerm" :errors="fieldErrors">
                <input v-model.trim="form.installmentTerm"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.firstDueInst')" error-key="firstDueInst" :errors="fieldErrors">
                <input v-model.number="form.firstDueInst" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.firstDueInstDate')" error-key="firstDueInstDate" :errors="fieldErrors">
                <input v-model="form.firstDueInstDate" type="date"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.nextDueInst')" error-key="nextDueInst" :errors="fieldErrors">
                <input v-model.number="form.nextDueInst" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.lastDueInstDate')" error-key="lastDueInstDate" :errors="fieldErrors">
                <input v-model="form.lastDueInstDate" type="date"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
            </div>
          </div>
        </section>

        <!-- ─── Step 3: type-specific block + commission + notes ────────── -->
        <section v-show="step === 3" class="space-y-4">
          <div class="text-xs text-slate-500 -mt-1">
            {{ t('policyCreate.step3IntroFor', { kind: t(`policyCreate.kind.${kind}`) }) }}
          </div>

          <!-- Motor block -->
          <div v-if="kind === 'motor'">
            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
              {{ t('policyCreate.motor.title') }}
            </div>
            <div class="grid grid-cols-2 gap-4">
              <FormField :label="t('policyCreate.motor.brand')" error-key="motorVehicleBrand" :errors="fieldErrors">
                <input v-model.trim="form.motorVehicleBrand"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.motor.model')" error-key="motorVehicleModel" :errors="fieldErrors">
                <input v-model.trim="form.motorVehicleModel"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.motor.licenseNo')" error-key="motorLicenseNo" :errors="fieldErrors">
                <input v-model.trim="form.motorLicenseNo"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
              </FormField>
              <FormField :label="t('policyCreate.motor.registerYear')" error-key="motorRegisterYear" :errors="fieldErrors">
                <input v-model.trim="form.motorRegisterYear"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
              </FormField>
              <FormField :label="t('policyCreate.motor.engineNo')" error-key="motorEngineNo" :errors="fieldErrors">
                <input v-model.trim="form.motorEngineNo"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
              </FormField>
              <FormField :label="t('policyCreate.motor.chassisNo')" error-key="motorChassisNo" :errors="fieldErrors">
                <input v-model.trim="form.motorChassisNo"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono" />
              </FormField>
              <FormField :label="t('policyCreate.motor.driverType')" error-key="motorTypeDriver" :errors="fieldErrors">
                <input v-model.trim="form.motorTypeDriver"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.motor.vehicleType')" error-key="motorTypeVehicle" :errors="fieldErrors">
                <input v-model.trim="form.motorTypeVehicle"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.motor.passengerCount')" error-key="motorNoPassenger" :errors="fieldErrors">
                <input v-model.number="form.motorNoPassenger" type="number" min="0" max="200"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.motor.notes')" error-key="motorNotes" :errors="fieldErrors" class="col-span-2">
                <textarea v-model="form.motorNotes" rows="2"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
            </div>
          </div>

          <!-- Property block -->
          <div v-if="kind === 'property'">
            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
              {{ t('policyCreate.property.title') }}
            </div>
            <div class="grid grid-cols-2 gap-4">
              <FormField :label="t('policyCreate.property.insuredName')" error-key="propertyInsuredName" :errors="fieldErrors" class="col-span-2">
                <input v-model.trim="form.propertyInsuredName"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.property.insuredAddress')" error-key="propertyInsuredAddress" :errors="fieldErrors" class="col-span-2">
                <input v-model.trim="form.propertyInsuredAddress"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.property.phone')" error-key="propertyPhone" :errors="fieldErrors">
                <input v-model.trim="form.propertyPhone"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <div />
              <FormField :label="t('policyCreate.property.buildingCov')" error-key="propertyBuildingCov" :errors="fieldErrors">
                <input v-model.number="form.propertyBuildingCov" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.property.furnitureCov')" error-key="propertyFurnitureCov" :errors="fieldErrors">
                <input v-model.number="form.propertyFurnitureCov" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.property.stockCov')" error-key="propertyStockCov" :errors="fieldErrors">
                <input v-model.number="form.propertyStockCov" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.property.otherCov')" error-key="propertyOtherCov" :errors="fieldErrors">
                <input v-model.number="form.propertyOtherCov" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.property.otherDetail')" error-key="propertyOtherDetail" :errors="fieldErrors" class="col-span-2">
                <textarea v-model="form.propertyOtherDetail" rows="2"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
            </div>
          </div>

          <!-- Beneficiaries — required for life products -->
          <div v-if="kind === 'life'">
            <div class="flex items-center justify-between mb-2">
              <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">
                {{ t('policyCreate.beneficiaries.title') }}
              </div>
              <button type="button" class="text-xs text-brand-600 hover:text-brand-700"
                :disabled="form.beneficiaries.length >= 4"
                @click="addBeneficiary">
                <i class="pi pi-plus text-[10px] mr-1" /> {{ t('policyCreate.addRow') }}
              </button>
            </div>
            <div v-if="form.beneficiaries.length === 0" class="text-[11px] text-slate-400 italic py-2">
              {{ t('policyCreate.beneficiaries.empty') }}
            </div>
            <div v-else class="space-y-2">
              <div v-for="(b, i) in form.beneficiaries" :key="i"
                class="grid grid-cols-[1fr,120px,80px,32px] gap-2 items-start">
                <input v-model.trim="b.name"
                  :placeholder="t('policyCreate.beneficiaries.name')"
                  class="border border-slate-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
                <input v-model.trim="b.relation"
                  :placeholder="t('policyCreate.beneficiaries.relation')"
                  class="border border-slate-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
                <input v-model.number="b.share" type="number" min="0" max="100" step="0.01"
                  :placeholder="t('policyCreate.beneficiaries.share')"
                  class="border border-slate-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
                <button type="button"
                  class="text-slate-400 hover:text-rose-600 h-8 flex items-center justify-center"
                  @click="removeBeneficiary(i)">
                  <i class="pi pi-times text-xs" />
                </button>
              </div>
              <div class="text-[10px]"
                :class="Math.abs(beneficiaryShareTotal - 100) < 0.01 ? 'text-emerald-600' : 'text-rose-600'">
                {{ t('policyCreate.beneficiaries.total') }}: {{ beneficiaryShareTotal.toFixed(2) }}%
              </div>
            </div>
          </div>

          <!-- Riders — Access parity: 5 fixed slots, life products only.
               Empty rows are skipped at submit. Columns include per-rider
               commission overrides so the operator can enter everything
               inline without opening PolicyEdit afterwards. -->
          <div v-if="kind === 'life'">
            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
              {{ t('policyCreate.riders.title') }}
            </div>
            <div class="grid grid-cols-[28px,1fr,110px,90px,90px] gap-2 text-[10px] font-medium text-slate-400 uppercase tracking-wide mb-1 px-1">
              <div>#</div>
              <div>{{ t('policyCreate.riders.name') }}</div>
              <div class="text-right">{{ t('policyCreate.riders.premium') }}</div>
              <div class="text-right">{{ t('policyCreate.riders.rateInh') }}</div>
              <div class="text-right">{{ t('policyCreate.riders.rateAg') }}</div>
            </div>
            <div class="space-y-1.5">
              <div v-for="(r, i) in form.riders" :key="i"
                class="grid grid-cols-[28px,1fr,110px,90px,90px] gap-2 items-center">
                <div class="text-xs text-slate-400 font-mono text-center">{{ i + 1 }}</div>
                <input v-model.trim="r.name"
                  :placeholder="t('policyCreate.riders.namePlaceholder')"
                  class="border border-slate-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
                <input v-model.number="r.premium" type="number" min="0" step="0.01"
                  class="border border-slate-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
                <input v-model.number="r.comRateInh" type="number" min="0" max="1" step="0.0001"
                  class="border border-slate-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
                <input v-model.number="r.comRateAg" type="number" min="0" max="1" step="0.0001"
                  class="border border-slate-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </div>
            </div>
          </div>

          <!-- Commission override -->
          <div class="mt-4 pt-3 border-t border-slate-100">
            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
              {{ t('policyCreate.commission.title') }}
            </div>
            <div class="text-[10px] text-slate-400 mb-2">{{ t('policyCreate.commission.hint') }}</div>
            <div class="grid grid-cols-2 gap-4">
              <FormField :label="t('policyCreate.commission.rateInh')" error-key="mainComRateInh" :errors="fieldErrors">
                <input v-model.number="form.mainComRateInh" type="number" min="0" max="1" step="0.0001"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.commission.amtInh')" error-key="mainComAmtInh" :errors="fieldErrors">
                <input v-model.number="form.mainComAmtInh" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.commission.rateAg')" error-key="mainComRateAg" :errors="fieldErrors">
                <input v-model.number="form.mainComRateAg" type="number" min="0" max="1" step="0.0001"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
              <FormField :label="t('policyCreate.commission.amtAg')" error-key="mainComAmtAg" :errors="fieldErrors">
                <input v-model.number="form.mainComAmtAg" type="number" min="0" step="0.01"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 text-right" />
              </FormField>
            </div>
          </div>

          <!-- Status + notes -->
          <div class="mt-4 pt-3 border-t border-slate-100 grid grid-cols-2 gap-4">
            <FormField :label="t('policyCreate.status')" error-key="status" :errors="fieldErrors" class="col-span-2">
              <select v-model="form.status"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
                <option value="quote">ใบเสนอราคา</option>
                <option value="application">รอตรวจรถ</option>
                <option value="submitted">รอพิจารณา</option>
                <option value="issued">ออกกรมธรรม์แล้ว</option>
                <option value="active">อนุมัติแล้ว</option>
              </select>
            </FormField>
            <FormField :label="t('policyCreate.notes')" class="col-span-2" error-key="notes" :errors="fieldErrors">
              <textarea v-model="form.notes" rows="2"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </section>

        <!-- General error banner (only when there's no per-field breakdown) -->
        <div v-if="error && Object.keys(fieldErrors).length === 0"
          class="mt-3 p-3 bg-rose-50 border border-rose-200 rounded-lg text-rose-700 text-sm">
          {{ error }}
        </div>
      </div>

      <!-- Footer: back / next / create -->
      <footer class="px-5 py-3 border-t border-slate-200 flex items-center justify-between">
        <div class="text-xs text-slate-400">
          {{ t('policyCreate.footerHint') }}
        </div>
        <div class="flex items-center gap-2">
          <button type="button"
            class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm"
            :disabled="saving" @click="emit('close')">
            {{ t('policyCreate.cancel') }}
          </button>
          <button type="button" v-if="step > 1"
            class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm"
            :disabled="saving" @click="back">
            <i class="pi pi-arrow-left text-xs mr-1" />{{ t('policyCreate.back') }}
          </button>
          <button type="button" v-if="step < 3"
            class="px-4 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed"
            :disabled="(step === 1 && !canNextStep1) || (step === 2 && !canNextStep2)"
            @click="next">
            {{ t('policyCreate.next') }} <i class="pi pi-arrow-right text-xs ml-1" />
          </button>
          <button type="button" v-else
            class="px-4 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5"
            :disabled="!canSubmit || saving" @click="submit">
            <i class="pi pi-check text-xs" v-if="!saving" />
            <i class="pi pi-spin pi-spinner text-xs" v-else />
            {{ saving ? t('policyCreate.saving') : t('policyCreate.create') }}
          </button>
        </div>
      </footer>
    </div>
  </div>
</template>
