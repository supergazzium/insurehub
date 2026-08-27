<script setup lang="ts">
// C-14 (v2 layout) — Policy Application Wizard as a full-page route.
//
// Sections (rendered as vertically-stacked cards, matching PolicyEdit.vue):
//   1. Party           — customer + writing agent + new/renew + refAppToId
//   2. Product + Cov   — insureType → carrier → product; effective + duration chip
//   3. Risk (dynamic)  — RiskFieldRenderer against product.productType.riskSchema
//                        + "Reuse from prior policy" dropdown (C-12)
//   4. Premium         — net/main/duty/vat/total/wht + installment
//   5. Notes           — free-text notes + review summary
//
// Header (like PolicyEdit) carries the title, status badge, and three
// action buttons:
//     · บันทึกฉบับร่าง    → POST /policies/draft (or PATCH if resuming)
//     · บันทึกใบเสนอราคา  → promote-to-quotation
//     · ส่งพิจารณา        → promote-to-submitted
//
// Draft-safe autosave: once the operator picks a customer, the wizard
// POSTs /policies/draft and subsequent field changes PATCH the draft in
// place with a 800ms debounce. No serial numbers minted at this stage.
//
// Two routes point here (see router/index.ts):
//     /policies/new                  fresh wizard
//     /policies/:id/edit-draft       resume-mode, hydrates from GET /policies/{id}
//
// The legacy modal PolicyCreateWizard.vue stays available for rollback;
// removed in C-20.

import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import FormField from '../../components/FormField.vue'
import DateInput from '../../components/DateInput.vue'
import DurationChip from '../../components/DurationChip.vue'
import RiskFieldRenderer from '../../components/RiskFieldRenderer.vue'
import EntityPicker from '../../components/EntityPicker.vue'
import { ApiError } from '../../api/client'
import { fetchCustomerList, fetchPriorAssets, type CustomerListRow, type PriorAsset } from '../../api/customers'
import { fetchProduct, fetchProductList, type ProductDetail, type ProductListRow } from '../../api/products'
import { fetchAgent } from '../../api/agents'
import { fetchCustomer } from '../../api/customers'
import { fetchPolicy } from '../../api/policies'
import { hydrateSchemaValues } from '../../utils/riskSchema'
import { fetchAgentList, type AgentListRow } from '../../api/agents'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import {
  createDraftPolicy, updateDraftPolicy,
  promotePolicyToQuotation, promotePolicyToSubmitted,
} from '../../api/policies'
import { durationConfig } from '../../utils/durationPresets'
import { statusBadgeClass, type PolicyStatus } from '../../utils/policyStatus'
import {
  splitSchemaPayload, valueKey, validateSchemaValues, type RiskSchema,
} from '../../utils/riskSchema'

// Route-driven resume id — `/policies/:id/edit-draft` binds `id` prop.
const props = defineProps<{
  id?: string
}>()

const router = useRouter()

const { t } = useI18n()

// ── State ────────────────────────────────────────────────────────────────

// Full-page layout — no step gating. All 5 sections visible at once,
// matching PolicyEdit.vue. `draftId` still tracks the persisted row.
const draftId = ref<string | null>(props.id ?? null)
const saving = ref(false)
const error = ref<string | null>(null)
const flash = ref<string | null>(null)

// The single source of truth for every editable field. Kept as a flat
// reactive so v-model bindings are one-liners; the payload builder
// projects into the backend shape.
const form = reactive({
  // Step 1 — Party
  newOrRenew: 'new' as 'new' | 'renew',
  refAppToId: '' as string,
  customerId: '' as string,
  writingAgentId: '' as string,
  applicationNo: '' as string,
  notionNo: '' as string,

  // Step 2 — Product + Coverage
  // Backend carriers.insure_type stores exact strings "Life", "Non-Life",
  // "Tax". The dropdown / hydrate must send those exact values or the
  // case-sensitive WHERE clause in CarrierController returns 0 rows.
  insureType: '' as 'Life' | 'Non-Life' | 'Tax' | '',
  carrierId: '' as string,
  productId: '' as string,
  policyYear: 1,
  actYear: 1,
  appDate: '' as string,
  effectiveDate: '' as string,
  expiryDate: '' as string,
  durationChipKey: null as string | null,
  coverage: 0 as number,
  discountAmount: 0 as number,

  // Step 3 — Risk (flat "section.field" bag, split at submit)
  risk: {} as Record<string, unknown>,

  // Step 4 — Premium
  netPremium: 0 as number,
  mainPremium: 0 as number,
  dutyStamp: 0 as number,
  vat: 0 as number,
  totalPremiumPaid: 0 as number,
  whtAmt: 0 as number,
  netCustomerPaid: 0 as number,
  annualPremium: 0 as number,
  premiumMode: 'annual' as 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single',
  installmentTerm: '' as string,
  firstDueInst: 0 as number,
  firstDueInstDate: '' as string,
  nextDueInst: 0 as number,
  lastDueInstDate: '' as string,

  // C-21 — editable commission (both directions). Rate is a 0..1
  // fraction; amount is rate x netPremium (auto unless touched).
  commCarrierToHubRate: null as number | null,
  commCarrierToHubAmount: null as number | null,
  commHubToAgentRate: null as number | null,
  commHubToAgentAmount: null as number | null,
  // C-22: full per-year override vector (life). band form:
  // { hubToAgent: {yr_1..yr_6_up}, carrierToHub: {...} }.
  commOverride: null as Record<string, Record<string, number | null>> | null,
  commOverrideTouched: false as boolean,

  // Always
  notes: '' as string,
})

// Touched flags for auto-fills (mirrors legacy wizard L556-681).
const touched = reactive({
  expiryDate: false,
  mainPremium: false,
  dutyStamp: false,
  vat: false,
  totalPremiumPaid: false,
  netCustomerPaid: false,
  commCarrierToHubRate: false,
  commCarrierToHubAmount: false,
  commHubToAgentRate: false,
  commHubToAgentAmount: false,
})

// ── Picker cascades ──────────────────────────────────────────────────────

const carriers = ref<CarrierListRow[]>([])
const products = ref<ProductListRow[]>([])
const carriersLoading = ref(false)
const productsLoading = ref(false)

const customerPicked = ref<CustomerListRow | null>(null)
const agentPicked = ref<AgentListRow | null>(null)
/** Full ProductDetail (from GET /products/{id}) so productType.riskSchema
 *  is available. The picker returns a lean ProductListRow — we re-fetch
 *  on pick to hydrate the full shape. */
const productDetail = ref<ProductDetail | null>(null)
const productDetailLoading = ref(false)

/** C-20: commission frozen onto an existing policy (edit mode). Null in
 *  create mode — there we show the product's LIVE rate that WILL be frozen.
 *  `scheme` lets the UI label a life rate as the year-1 rate. */
const snapshotCommission = ref<{ rate: number | null; scheme: string | null; capturedAt: string | null } | null>(null)

/** Commission shown in the เบี้ย + การชำระ section.
 *  - edit mode: the rate frozen on the policy (immutable to product edits)
 *  - create mode: the product's current hub→agent rate, which will be locked
 *    onto the policy the moment it is saved.
 *  For life products the headline rate is the year-1 rate (life_years vector);
 *  for non-life it is the flat rate. `isLife` drives the "ปีที่ 1" hint. */
const commissionDisplay = computed<{ rate: number | null; frozen: boolean; isLife: boolean; capturedAt: string | null }>(() => {
  if (snapshotCommission.value) {
    return {
      rate: snapshotCommission.value.rate,
      frozen: true,
      isLife: snapshotCommission.value.scheme === 'life_years',
      capturedAt: snapshotCommission.value.capturedAt,
    }
  }
  const rates = productDetail.value?.commissionRates
  const isLife = rates?.scheme === 'life_years'
  const live = isLife
    ? (rates?.hubToAgent?.yr1 ?? null)
    : (rates?.hubToAgent?.flatRate ?? null)
  return { rate: live, frozen: false, isLife, capturedAt: null }
})

/** Context passed to RiskFieldRenderer's product_search sub-fields (rider
 *  picker): the main product's carrier + insure_type, so riders are filtered
 *  to the same carrier + type. insureType is lower-cased to match the API. */
const productSearchContext = computed(() => ({
  carrierId: form.carrierId || null,
  insureType: (form.insureType
    ? form.insureType.toLowerCase()
    : null) as 'life' | 'non-life' | 'tax' | null,
}))

// ── C-21: editable commission (both directions) ──────────────────────────
//
// Headline rate for a direction from the loaded product: the matching
// sum-assured band's year-1 rate (life banded), else the single rate row
// (yr1 for life_years, flatRate for flat). Mirrors the backend resolver so
// the wizard's default equals what the snapshot will freeze.
function productHeadlineRate(direction: 'carrierToHub' | 'hubToAgent'): number | null {
  const pd = productDetail.value
  if (!pd) return null
  const sa = Number(form.coverage) || 0

  // 1. banded (life) — commissionBands[direction] is an array of bands.
  const bands = (pd.commissionBands as Record<string, Array<Record<string, number | null>>> | undefined)?.[direction]
  if (Array.isArray(bands) && bands.length) {
    const match = bands.find((b) => {
      const min = b.sumAssuredMin, max = b.sumAssuredMax
      if (min != null && sa < Number(min)) return false
      if (max != null && sa > Number(max)) return false
      return true
    })
    if (match && match.yr1 != null) return Number(match.yr1)
  }

  // 2. single rate row
  const rates = pd.commissionRates
  const panel = direction === 'carrierToHub' ? rates?.carrierToHub : rates?.hubToAgent
  if (!panel) return null
  return rates?.scheme === 'life_years' ? (panel.yr1 ?? null) : (panel.flatRate ?? null)
}

/** Seed a direction's rate + amount from the product, unless the operator
 *  already touched it. Amount = rate x netPremium. */
function seedCommission(direction: 'carrierToHub' | 'hubToAgent'): void {
  const rateKey = direction === 'carrierToHub' ? 'commCarrierToHubRate' : 'commHubToAgentRate'
  const amtKey = direction === 'carrierToHub' ? 'commCarrierToHubAmount' : 'commHubToAgentAmount'
  const rate = productHeadlineRate(direction)
  if (!touched[rateKey]) form[rateKey] = rate
  if (!touched[amtKey]) form[amtKey] = rate != null ? Math.round(rate * (Number(form.netPremium) || 0) * 100) / 100 : null
}

// Re-seed both directions when the product or coverage changes (create mode).
// In edit mode the frozen values are hydrated from the policy and marked
// touched, so this won't overwrite them.
watch(
  () => [form.productId, form.coverage, productDetail.value] as const,
  () => { seedCommission('carrierToHub'); seedCommission('hubToAgent') },
)

// Recompute amounts when netPremium changes, unless the amount was edited.
watch(() => form.netPremium, () => {
  if (!touched.commCarrierToHubAmount && form.commCarrierToHubRate != null)
    form.commCarrierToHubAmount = Math.round(form.commCarrierToHubRate * (Number(form.netPremium) || 0) * 100) / 100
  if (!touched.commHubToAgentAmount && form.commHubToAgentRate != null)
    form.commHubToAgentAmount = Math.round(form.commHubToAgentRate * (Number(form.netPremium) || 0) * 100) / 100
})

// When the operator edits a rate, recompute its amount (unless amount touched).
watch(() => form.commCarrierToHubRate, (r) => {
  if (!touched.commCarrierToHubAmount)
    form.commCarrierToHubAmount = r != null ? Math.round(r * (Number(form.netPremium) || 0) * 100) / 100 : null
})
watch(() => form.commHubToAgentRate, (r) => {
  if (!touched.commHubToAgentAmount)
    form.commHubToAgentAmount = r != null ? Math.round(r * (Number(form.netPremium) || 0) * 100) / 100 : null
})

// ── C-22: full per-year commission vector (life products) ────────────────
//
// The band year columns shown in the grid.
const VECTOR_YEARS = ['yr_1', 'yr_2', 'yr_3', 'yr_4', 'yr_5', 'yr_6_up'] as const
const VECTOR_YEAR_LABELS: Record<string, string> = {
  yr_1: 'ปีที่ 1', yr_2: 'ปีที่ 2', yr_3: 'ปีที่ 3',
  yr_4: 'ปีที่ 4', yr_5: 'ปีที่ 5', yr_6_up: 'ปีที่ 6+',
}

/** True when the product uses a per-year (life) commission scheme — drives
 *  the vector grid vs the single rate+amount cards. */
const isVectorScheme = computed(() => productDetail.value?.commissionRates?.scheme === 'life_years')

/** C-23: live warning when the insured age / sum-assured falls outside every
 *  RATED hub→agent commission band — the policy would accrue no agent
 *  commission. Computed from the same product data the grid uses; shown as a
 *  non-blocking banner. Empty string = no warning. */
const commissionBandWarning = computed<string>(() => {
  const pd = productDetail.value
  const bands = (pd?.commissionBands as Record<string, Array<Record<string, number | null>>> | undefined)?.hubToAgent
  if (!Array.isArray(bands) || bands.length === 0) return ''  // not a banded product

  const sa = Number(form.coverage) || 0
  // entry age = effective year - birth year (from the life risk bag)
  const birthStr = form.risk['insured_person.birth_date'] as string | undefined
  const effStr = form.effectiveDate
  let age: number | null = null
  if (birthStr && effStr) {
    const by = new Date(birthStr).getFullYear(), ey = new Date(effStr).getFullYear()
    if (!Number.isNaN(by) && !Number.isNaN(ey)) age = Math.max(0, ey - by)
  }

  const hasRate = (b: Record<string, number | null>) =>
    ['yr1', 'yr2', 'yr3', 'yr4', 'yr5', 'yr6Up'].some((k) => b[k] != null)

  const matches = (b: Record<string, number | null>) => {
    if (b.sumAssuredMin != null && sa < Number(b.sumAssuredMin)) return false
    if (b.sumAssuredMax != null && sa > Number(b.sumAssuredMax)) return false
    if (b.entryAgeMin != null || b.entryAgeMax != null) {
      if (age == null) return false
      if (b.entryAgeMin != null && age < Number(b.entryAgeMin)) return false
      if (b.entryAgeMax != null && age > Number(b.entryAgeMax)) return false
    }
    return true
  }

  const rated = bands.filter(hasRate)
  if (rated.length === 0) return ''  // product has no rates configured — different concern
  if (rated.some((b) => matches(b))) return ''  // covered

  // Build a reason from the rated bands' ranges.
  const ageMins = rated.filter((b) => b.entryAgeMin != null).map((b) => Number(b.entryAgeMin))
  const ageMaxes = rated.filter((b) => b.entryAgeMax != null).map((b) => Number(b.entryAgeMax))
  const parts: string[] = []
  if ((ageMins.length || ageMaxes.length) && age == null) {
    parts.push(t('policyCreate.commissionBandNoAge'))
  } else if (ageMins.length || ageMaxes.length) {
    const lo = ageMins.length ? Math.min(...ageMins) : 0
    const hi = ageMaxes.length ? Math.max(...ageMaxes) : 999
    if (age != null && (age < lo || age > hi)) parts.push(t('policyCreate.commissionBandAge', { age, lo, hi }))
  }
  const saMins = rated.filter((b) => b.sumAssuredMin != null).map((b) => Number(b.sumAssuredMin))
  const saMaxes = rated.filter((b) => b.sumAssuredMax != null).map((b) => Number(b.sumAssuredMax))
  if (saMins.length || saMaxes.length) {
    const lo = saMins.length ? Math.min(...saMins) : 0
    const hi = saMaxes.length ? Math.max(...saMaxes) : null
    if (sa < lo || (hi != null && sa > hi)) {
      parts.push(t('policyCreate.commissionBandSa', { sa: sa.toLocaleString(), lo: lo.toLocaleString(), hi: hi != null ? hi.toLocaleString() : '∞' }))
    }
  }
  return parts.length ? parts.join('; ') : t('policyCreate.commissionBandGeneric')
})

/** Build the full per-year vector for a direction from the matching
 *  sum-assured band (life). Falls back to the single life_years rate row. */
function productVector(direction: 'carrierToHub' | 'hubToAgent'): Record<string, number | null> | null {
  const pd = productDetail.value
  if (!pd) return null
  const sa = Number(form.coverage) || 0

  const bands = (pd.commissionBands as Record<string, Array<Record<string, number | null>>> | undefined)?.[direction]
  if (Array.isArray(bands) && bands.length) {
    const match = bands.find((b) => {
      if (b.sumAssuredMin != null && sa < Number(b.sumAssuredMin)) return false
      if (b.sumAssuredMax != null && sa > Number(b.sumAssuredMax)) return false
      return true
    })
    if (match) {
      return {
        yr_1: match.yr1 ?? null, yr_2: match.yr2 ?? null, yr_3: match.yr3 ?? null,
        yr_4: match.yr4 ?? null, yr_5: match.yr5 ?? null, yr_6_up: match.yr6Up ?? null,
      }
    }
  }
  // fallback: single life_years rate row → band columns (yr_6_up ← yr6_10)
  const panel = direction === 'carrierToHub' ? pd.commissionRates?.carrierToHub : pd.commissionRates?.hubToAgent
  if (panel && pd.commissionRates?.scheme === 'life_years') {
    return {
      yr_1: panel.yr1 ?? null, yr_2: panel.yr2 ?? null, yr_3: panel.yr3 ?? null,
      yr_4: panel.yr4 ?? null, yr_5: panel.yr5 ?? null, yr_6_up: panel.yr6_10 ?? null,
    }
  }
  return null
}

/** Seed the whole vector (both directions) from the product, unless edited. */
function seedVector(): void {
  if (!isVectorScheme.value || form.commOverrideTouched) return
  const h2a = productVector('hubToAgent')
  const c2h = productVector('carrierToHub')
  if (h2a || c2h) {
    form.commOverride = {
      ...(h2a ? { hubToAgent: h2a } : {}),
      ...(c2h ? { carrierToHub: c2h } : {}),
    }
  } else {
    form.commOverride = null
  }
}

// Re-seed the vector when product/coverage changes (create mode).
watch(() => [form.productId, form.coverage, productDetail.value] as const, () => seedVector())

/** Grid cell input: write a 0..1 fraction from the displayed percent and
 *  mark the vector touched so seeding stops overwriting it. */
function onVectorInput(direction: 'carrierToHub' | 'hubToAgent', year: string, raw: string): void {
  const pct = raw === '' ? null : Number(raw)
  const val = pct == null || Number.isNaN(pct) ? null : Math.round((pct / 100) * 100000) / 100000
  const next = { ...(form.commOverride ?? {}) }
  next[direction] = { ...(next[direction] ?? {}), [year]: val }
  form.commOverride = next
  form.commOverrideTouched = true
}

/** Read a cell as a percent for display (0..100). */
function vectorPct(direction: 'carrierToHub' | 'hubToAgent', year: string): number | null {
  const v = form.commOverride?.[direction]?.[year]
  return v != null ? +(v * 100).toFixed(3) : null
}

/** The baht amount for a vector cell: rate x net premium. Computed for
 *  display alongside the %; the vector stores rates, amounts are derived. */
function vectorAmount(direction: 'carrierToHub' | 'hubToAgent', year: string): number | null {
  const v = form.commOverride?.[direction]?.[year]
  if (v == null) return null
  return Math.round(v * (Number(form.netPremium) || 0) * 100) / 100
}

/** Input handler for the "%" rate fields — converts the displayed percent
 *  (0..100) back to the stored 0..1 fraction and marks the rate touched. */
function onCommRatePct(key: 'commCarrierToHubRate' | 'commHubToAgentRate', raw: string): void {
  const pct = raw === '' ? null : Number(raw)
  form[key] = pct == null || Number.isNaN(pct) ? null : Math.round((pct / 100) * 100000) / 100000
  const touchedKey = key === 'commCarrierToHubRate' ? 'commCarrierToHubRate' : 'commHubToAgentRate'
  touched[touchedKey] = true
}

async function loadCarriersForInsureType(t: 'Life' | 'Non-Life' | 'Tax'): Promise<void> {
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

watch(() => form.insureType, (it) => {
  // Cascade clears run only on operator-initiated changes. During
  // hydrateFromDraft (C-15) `hydrating` is true — we call the loaders
  // directly there in the right order without wiping downstream picks.
  if (hydrating.value) return
  carriers.value = []; products.value = []
  form.carrierId = ''; form.productId = ''
  productDetail.value = null
  if (it) void loadCarriersForInsureType(it)
})

watch(() => form.carrierId, (cid) => {
  if (hydrating.value) return
  products.value = []
  form.productId = ''
  productDetail.value = null
  if (cid) void loadProductsForCarrier(cid)
})

watch(() => form.productId, async (pid) => {
  if (!pid) { productDetail.value = null; return }
  productDetailLoading.value = true
  try {
    const res = await fetchProduct(pid)
    productDetail.value = res.data
    // Kick off prior-assets fetch if a customer is already picked
    void loadPriorAssets()
  } catch {
    productDetail.value = null
  } finally {
    productDetailLoading.value = false
  }
})

// ── Duration chip (Step 2) ───────────────────────────────────────────────

/** Derives the wizard-branch kind from productType.kind. Fallback to
 *  ProductResource.productKind (runtime derivation) when the taxonomy
 *  row's kind isn't populated. */
const kind = computed<string>(() => {
  return productDetail.value?.productType?.kind
    ?? productDetail.value?.productKind
    ?? 'misc'
})

const durationCfg = computed(() => durationConfig(kind.value))

// Auto-apply the kind's default chip when the operator picks a product
// AND hasn't manually set an expiry yet.
watch(kind, (k) => {
  const cfg = durationConfig(k)
  if (cfg.defaultKey && !touched.expiryDate && !form.expiryDate) {
    form.durationChipKey = cfg.defaultKey
    // The chip's own watcher will compute expiryDate once effectiveDate is set.
  }
})

// Parent watches expiryDate → clears chip when the operator types manually.
watch(() => form.expiryDate, (v) => {
  if (v && form.durationChipKey) {
    // Only clear if the value diverges from what the chip would produce.
    // Simplification: any manual edit after chip pick is treated as manual.
    if (touched.expiryDate) form.durationChipKey = null
  }
})

// ── Prior-asset autofill (Step 3, C-12) ──────────────────────────────────

const priorAssets = ref<PriorAsset[]>([])
const priorAssetsLoading = ref(false)

async function loadPriorAssets(): Promise<void> {
  priorAssets.value = []
  if (!form.customerId || !kind.value) return
  priorAssetsLoading.value = true
  try {
    const res = await fetchPriorAssets(form.customerId, kind.value)
    priorAssets.value = res.assets ?? []
  } catch {
    priorAssets.value = []
  } finally {
    priorAssetsLoading.value = false
  }
}

watch(() => form.customerId, () => { void loadPriorAssets() })

/** Fill the Step-3 value bag from a picked prior asset. Walks the current
 *  schema so unknown keys in the asset are silently ignored. */
function applyPriorAsset(asset: PriorAsset): void {
  const schema = productDetail.value?.productType?.riskSchema as RiskSchema | null | undefined
  if (!schema) return
  const patch: Record<string, unknown> = {}
  for (const section of schema.sections) {
    for (const field of section.fields) {
      if (field.key in asset.fields) {
        patch[valueKey(section.key, field.key)] = asset.fields[field.key]
      }
    }
  }
  form.risk = { ...form.risk, ...patch }
}

// ── Payment frequency (งวดการชำระ) by product type ───────────────────────
// ประกันชีวิต (Life): monthly / quarterly / semiannual / annual / single.
// ประกันวินาศภัย (Non-Life) + ภาษี (Tax): annual only.
type PremiumMode = typeof form.premiumMode
const premiumModeOptions = computed<PremiumMode[]>(() =>
  form.insureType === 'Life'
    ? ['monthly', 'quarterly', 'semiannual', 'annual', 'single']
    : ['annual'],
)
// When the product type changes, snap premiumMode back to a valid option
// (defaults to annual, which is valid for every type).
watch(() => form.insureType, () => {
  if (!premiumModeOptions.value.includes(form.premiumMode)) form.premiumMode = 'annual'
})

// ── Premium recalc watchers (KEEP verbatim per B3 §9) ────────────────────
// The Access-parity math: duty = 0.4% net, vat = 7% (net + duty), total =
// net + duty + vat. Rounded to 2dp. Operator overrides win via touched flags.
watch(() => form.netPremium, (net) => {
  if (!net || net <= 0) return
  if (!touched.dutyStamp) form.dutyStamp = Math.round(net * 0.004 * 100) / 100
  if (!touched.vat) form.vat = Math.round((net + (form.dutyStamp ?? 0)) * 0.07 * 100) / 100
  if (!touched.totalPremiumPaid) form.totalPremiumPaid = Math.round((net + form.dutyStamp + form.vat) * 100) / 100
  if (!touched.netCustomerPaid) form.netCustomerPaid = Math.round((form.totalPremiumPaid - (form.whtAmt ?? 0)) * 100) / 100
  if (!touched.mainPremium) form.mainPremium = net
  if (!form.annualPremium) form.annualPremium = net
})
watch(() => form.whtAmt, () => {
  if (!touched.netCustomerPaid) form.netCustomerPaid = Math.round((form.totalPremiumPaid - form.whtAmt) * 100) / 100
})

// ── Tax formulas (สูตร 1–4, ported from the Access form) ─────────────────
// The gross VAT/duty-inclusive amount is เบี้ยรวม (form.totalPremiumPaid, the
// same field bound to Section 2). Each formula back-solves เบี้ยสุทธิ
// (netPremium), อากรแสตมป์ (dutyStamp) and VAT (vat) from that gross.
// Buttons set the touched flags on the fields they compute so the forward
// net→duty→vat→total watcher (L556) does NOT clobber the result; the operator
// can still override any single field afterwards.
const round2 = (x: number) => Math.round(x * 100) / 100

/** Access `-Int(-x)` on a non-negative amount = round UP to the next integer. */
const ceilInt = (x: number) => Math.ceil(x)

function applyFormula(net: number, duty: number, vat: number) {
  form.netPremium = round2(net)
  form.dutyStamp = round2(duty)
  form.vat = round2(vat)
  // Total is the gross the operator supplied; keep it as-is and protect it.
  form.totalPremiumPaid = round2(form.totalPremiumPaid)
  touched.dutyStamp = true
  touched.vat = true
  touched.totalPremiumPaid = true
  form.netCustomerPaid = round2(form.totalPremiumPaid - (form.whtAmt ?? 0))
}

function runFormula(n: 1 | 2 | 3 | 4) {
  const gross = Number(form.totalPremiumPaid) || 0
  if (n === 1) {
    // Iterative back-solve: duty = ceil(net*0.4%), vat = (duty+net)*7%,
    // net = gross - vat - duty. Converges to <0.01 baht.
    let premium = gross
    let duty = 0
    let vat = 0
    let prev: number
    let i = 0
    do {
      prev = premium
      duty = ceilInt(premium * 0.004)
      vat = (duty + premium) * 0.07
      premium = gross - vat - duty
      i++
    } while (Math.abs(premium - prev) >= 0.01 && i < 100)
    applyFormula(premium, duty, vat)
  } else if (n === 2) {
    applyFormula(gross / 1.07, 0, 0)
  } else if (n === 3) {
    applyFormula(gross - 20, 20, 0)
  } else {
    applyFormula(gross - 150, 150, 0)
  }
}

// ── BroadcastChannel bridge (KEEP verbatim per B3 §9) ────────────────────

type CreatedKind = 'customer:created' | 'product:created'
type CreatedMessage = { type: CreatedKind; row: Record<string, unknown> }
let hubChannel: BroadcastChannel | null = null
if (typeof BroadcastChannel !== 'undefined') {
  hubChannel = new BroadcastChannel('insurehub')
  hubChannel.onmessage = (ev: MessageEvent) => {
    const data = ev.data as CreatedMessage | undefined
    if (!data?.type || !data.row) return
    const r = data.row
    if (data.type === 'customer:created') {
      form.customerId = String(r.id ?? '')
      customerPicked.value = {
        id: String(r.id ?? ''),
        customerCode: String(r.customerCode ?? ''),
        customerType: String(r.customerType ?? ''),
        titleTh: String(r.titleTh ?? ''),
        firstName: String(r.firstName ?? ''),
        lastName: String(r.lastName ?? ''),
        nickname: String(r.nickname ?? ''),
        juristicName: String(r.juristicName ?? ''),
        idCard: String(r.idCard ?? ''),
        taxId: String(r.taxId ?? ''),
        passport: String(r.passport ?? ''),
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
    }
    // product:created omitted from this MVP — operator can pick from the
    // dropdown after the popup closes; C-15 will restore the full bridge.
  }
}
onBeforeUnmount(() => { hubChannel?.close(); hubChannel = null })

// ── Autosave (draft-safe) ────────────────────────────────────────────────

let autosaveTimer: number | undefined
const autosaving = ref(false)

function scheduleAutosave(): void {
  if (!form.customerId) return  // wait until step-1 has minimum content
  // Skip while hydrateFromDraft is populating the form — otherwise the
  // first PATCH re-writes the same values we just loaded, and worse,
  // the settling cascade watchers (insureType → carriers → productId
  // clearing) can wipe risk data mid-load.
  if (hydrating.value) return
  window.clearTimeout(autosaveTimer)
  autosaveTimer = window.setTimeout(() => { void doAutosave() }, 800)
}

async function doAutosave(): Promise<void> {
  if (autosaving.value) return
  autosaving.value = true
  try {
    const payload = buildDraftPayload()
    if (!draftId.value) {
      const res = await createDraftPolicy(payload)
      draftId.value = (res.data as unknown as { id: string }).id
      flash.value = t('policyCreate.action.draftSaved')
    } else {
      await updateDraftPolicy(draftId.value, payload)
    }
  } catch {
    // Silent failure — the operator sees a stale state banner via the
    // "Not saved online" indicator (rendered from `flash` state).
    flash.value = null
  } finally {
    autosaving.value = false
    window.setTimeout(() => { flash.value = null }, 2000)
  }
}

// Deep-watch the form to trigger autosave. Vue's reactive tracks nested
// changes so this fires on any input.
watch(form, scheduleAutosave, { deep: true })

// ── Payload builders ─────────────────────────────────────────────────────

/** Draft payload — permissive; sends any populated field. Backend accepts
 *  the same PolicyRequest shape as create/update so we route through the
 *  writer shim (C-4) for risk data. */
function buildDraftPayload(): Record<string, unknown> {
  const out: Record<string, unknown> = {
    customerId: form.customerId || null,
    writingAgentId: form.writingAgentId || null,
    productId: form.productId || null,
    carrierId: form.carrierId || null,
    newOrRenew: form.newOrRenew,
    refAppToId: form.refAppToId || null,
    applicationNo: form.applicationNo || null,
    notionNo: form.notionNo || null,
    appDate: form.appDate || null,
    effectiveDate: form.effectiveDate || null,
    expiryDate: form.expiryDate || null,
    policyYear: form.policyYear || 1,
    actYear: form.actYear || 1,
    coverage: form.coverage || 0,
    discountAmount: form.discountAmount || 0,
    netPremium: form.netPremium || 0,
    mainPremium: form.mainPremium || 0,
    dutyStamp: form.dutyStamp || 0,
    vat: form.vat || 0,
    totalPremiumPaid: form.totalPremiumPaid || 0,
    whtAmt: form.whtAmt || 0,
    netCustomerPaid: form.netCustomerPaid || 0,
    annualPremium: form.annualPremium || 0,
    premiumMode: form.premiumMode,
    installmentTerm: form.installmentTerm || null,
    firstDueInst: form.firstDueInst || 0,
    firstDueInstDate: form.firstDueInstDate || null,
    nextDueInst: form.nextDueInst || 0,
    lastDueInstDate: form.lastDueInstDate || null,
    // C-21: editable commission (both directions). null = use product default.
    commCarrierToHubRate: form.commCarrierToHubRate,
    commCarrierToHubAmount: form.commCarrierToHubAmount,
    commHubToAgentRate: form.commHubToAgentRate,
    commHubToAgentAmount: form.commHubToAgentAmount,
    commOverride: form.commOverride,
    notes: form.notes || null,
  }

  // Split the risk value bag into top-level columns + risk_data.<kind>.
  const schema = productDetail.value?.productType?.riskSchema as RiskSchema | null | undefined
  if (schema) {
    const { columns, riskData } = splitSchemaPayload(schema, form.risk)
    Object.assign(out, columns)
    if (Object.keys(riskData).length > 0) {
      out.risk = { kind: schema.kind, data: riskData }
    }
  }
  return out
}

// ── Submit-gate validation ───────────────────────────────────────────────

// Submit-gate = all Q gates + all S gates (schema required + premium).
function collectSubmitProblems(): string[] {
  const problems: string[] = []
  if (!form.customerId) problems.push('ลูกค้า: จำเป็น')
  if (!form.writingAgentId) problems.push('ตัวแทน: จำเป็น')
  if (!form.productId) problems.push('สินค้า: จำเป็น')
  if (!form.effectiveDate) problems.push('วันเริ่มคุ้มครอง: จำเป็น')
  if (!form.expiryDate) problems.push('วันสิ้นสุดคุ้มครอง: จำเป็น')
  if (!(form.netPremium > 0 || form.totalPremiumPaid > 0)) problems.push('เบี้ยประกัน: ต้องมากกว่า 0')
  const schema = productDetail.value?.productType?.riskSchema as RiskSchema | null | undefined
  if (schema) {
    const schemaProblems = validateSchemaValues(schema, form.risk, 'submit')
    for (const p of schemaProblems) problems.push(p.message)
  }
  return problems
}

// ── Action buttons ────────────────────────────────────────────────────────

async function saveDraftNow(): Promise<void> {
  // Ensure any pending autosave writes first, then acknowledge with a
  // toast; stay on the page so the operator can keep editing.
  window.clearTimeout(autosaveTimer)
  await doAutosave()
  flash.value = t('policyCreate.action.draftSaved')
  window.setTimeout(() => { flash.value = null }, 2000)
}

async function saveAsQuotation(): Promise<void> {
  if (saving.value) return
  saving.value = true
  error.value = null
  try {
    // Ensure a draft exists (autosave may not have fired yet).
    if (!draftId.value) {
      await doAutosave()
    }
    if (!draftId.value) throw new Error('Draft save failed — cannot promote.')
    await promotePolicyToQuotation(draftId.value)
    // Row is now a quotation — return the operator to the list.
    await router.push({ name: 'policies' })
  } catch (e) {
    error.value = e instanceof ApiError ? (e.body as { message?: string })?.message ?? e.message : (e instanceof Error ? e.message : 'Save failed.')
  } finally {
    saving.value = false
  }
}

async function submitToCarrier(): Promise<void> {
  if (saving.value) return
  const problems = collectSubmitProblems()
  if (problems.length > 0) {
    window.alert(`กรอกข้อมูลไม่ครบหรือไม่ถูกต้อง:\n\n• ${problems.join('\n• ')}`)
    return
  }
  saving.value = true
  error.value = null
  try {
    if (!draftId.value) {
      await doAutosave()
    }
    if (!draftId.value) throw new Error('Draft save failed — cannot submit.')
    await promotePolicyToSubmitted(draftId.value)
    await router.push({ name: 'policies' })
  } catch (e) {
    error.value = e instanceof ApiError ? (e.body as { message?: string })?.message ?? e.message : (e instanceof Error ? e.message : 'Submit failed.')
  } finally {
    saving.value = false
  }
}

// ── Resume from draft (C-15) ─────────────────────────────────────────────

/** Hydrate the wizard from a saved draft. Called on open when
 *  resumeDraftId is set. Fetches:
 *    1. GET /policies/{id}                → base scalar fields + risk_data
 *    2. GET /products/{id}                → productType.riskSchema
 *    3. GET /customers/{id}, /agents/{id} → EntityPicker labels
 *    4. If product picked → also loadCarriersForInsureType +
 *       loadProductsForCarrier so the cascade dropdowns render the
 *       selected values (a <select> can't display an orphan value).
 *
 *  Race condition: the autosave watcher fires on every form mutation.
 *  We suppress it during hydration by holding a `hydrating` flag and
 *  short-circuiting scheduleAutosave() when it's set.
 */
const hydrating = ref(false)

async function hydrateFromDraft(id: string): Promise<void> {
  hydrating.value = true
  error.value = null
  try {
    const res = await fetchPolicy(id)
    const p = res.data as unknown as Record<string, unknown>

    // Scalars — same field names as buildDraftPayload emits.
    form.customerId = String(p.customerId ?? '')
    form.writingAgentId = String(p.writingAgentId ?? '')
    form.productId = String(p.productId ?? '')
    form.carrierId = String(p.carrierId ?? '')
    form.newOrRenew = (p.newOrRenew as 'new' | 'renew' | null) ?? 'new'
    form.refAppToId = String(p.refAppToId ?? '')
    form.applicationNo = String(p.applicationNo ?? '')
    form.notionNo = String(p.notionNo ?? '')
    form.appDate = String(p.appDate ?? '')
    form.effectiveDate = String(p.effectiveDate ?? '')
    form.expiryDate = String(p.expiryDate ?? '')
    form.coverage = Number(p.coverage ?? 0)
    form.policyYear = Number(p.policyYear ?? 1)
    form.actYear = Number(p.actYear ?? 1)
    form.annualPremium = Number(p.annualPremium ?? 0)
    form.premiumMode = (p.premiumMode as typeof form.premiumMode) ?? 'annual'
    form.notes = String(p.notes ?? '')

    // PolicyResource nests premium/installment/wht fields; read them from the
    // nested blocks (with a flat fallback for any older draft-shaped response).
    // Previously these read flat top-level keys the resource doesn't emit, so
    // they never hydrated on edit-draft.
    const premium = (p.premium ?? {}) as Record<string, number | null>
    const installment = (p.installment ?? {}) as Record<string, number | string | null>
    const wht = (p.wht ?? {}) as Record<string, number | null>

    form.netPremium = Number(premium.net ?? p.netPremium ?? 0)
    form.mainPremium = Number(premium.main ?? p.mainPremium ?? 0)
    form.dutyStamp = Number(premium.dutyStamp ?? p.dutyStamp ?? 0)
    form.vat = Number(premium.vat ?? p.vat ?? 0)
    form.totalPremiumPaid = Number(premium.totalPaid ?? p.totalPremiumPaid ?? 0)
    form.netCustomerPaid = Number(premium.netCustomerPaid ?? p.netCustomerPaid ?? 0)
    form.whtAmt = Number(wht.amount ?? p.whtAmt ?? 0)
    form.discountAmount = Number(installment.discountAmount ?? p.discountAmount ?? 0)

    form.installmentTerm = String(installment.term ?? p.installmentTerm ?? '')
    form.firstDueInst = Number(installment.firstDueAmount ?? p.firstDueInst ?? 0)
    form.firstDueInstDate = String(installment.firstDueDate ?? p.firstDueInstDate ?? '')
    form.nextDueInst = Number(installment.nextDueAmount ?? p.nextDueInst ?? 0)
    form.lastDueInstDate = String(installment.lastDueDate ?? p.lastDueInstDate ?? '')

    // C-20: frozen commission (read-only display in the premium section).
    const cs = p.commissionSnapshot as { hubToAgentRate?: number | null; scheme?: string | null; capturedAt?: string | null; frozen?: boolean } | undefined
    snapshotCommission.value = cs?.frozen
      ? { rate: cs.hubToAgentRate ?? null, scheme: cs.scheme ?? null, capturedAt: cs.capturedAt ?? null }
      : null

    // C-21: hydrate the editable commission (both directions) from the policy
    // and mark touched so the create-mode seeding watcher won't overwrite it.
    const comm = p.commission as {
      carrierToHub?: { rate?: number | null; amount?: number | null }
      hubToAgent?: { rate?: number | null; amount?: number | null }
    } | undefined
    if (comm) {
      form.commCarrierToHubRate = comm.carrierToHub?.rate ?? null
      form.commCarrierToHubAmount = comm.carrierToHub?.amount ?? null
      form.commHubToAgentRate = comm.hubToAgent?.rate ?? null
      form.commHubToAgentAmount = comm.hubToAgent?.amount ?? null
      touched.commCarrierToHubRate = true
      touched.commCarrierToHubAmount = true
      touched.commHubToAgentRate = true
      touched.commHubToAgentAmount = true
    }

    // C-22: hydrate the per-year override vector; mark touched so create-mode
    // seeding won't overwrite it.
    const ov = p.commissionOverride as Record<string, Record<string, number | null>> | null | undefined
    if (ov && typeof ov === 'object') {
      form.commOverride = ov
      form.commOverrideTouched = true
    }

    // Every field is now touched so recalc watchers don't stomp saved values.
    touched.expiryDate = true
    touched.dutyStamp = true
    touched.vat = true
    touched.totalPremiumPaid = true
    touched.netCustomerPaid = true
    touched.mainPremium = true

    // Product + cascade — needed for kind + riskSchema + dropdown display.
    if (form.productId) {
      const pd = await fetchProduct(form.productId)
      productDetail.value = pd.data
      const it = pd.data.carrierInsureType as 'Life' | 'Non-Life' | 'Tax' | ''
      if (it === 'Life' || it === 'Non-Life' || it === 'Tax') {
        form.insureType = it
        await loadCarriersForInsureType(it)
        await loadProductsForCarrier(form.carrierId)
      }
    }

    // Hydrate the risk value bag from risk_data + top-level columns.
    const schema = productDetail.value?.productType?.riskSchema as RiskSchema | null | undefined
    if (schema) {
      // Prefer the canonical `risk.fields` block from PolicyResource
      // (populated by PolicyRiskShim::readerAll — merges JSON + columns).
      const riskBlock = p.risk as { kind?: string; fields?: Record<string, unknown> } | null | undefined
      const riskFields = riskBlock?.fields ?? {}
      form.risk = hydrateSchemaValues(schema, riskFields, p as Record<string, unknown>)
    }

    // Customer / agent labels for the EntityPicker chip. Fetched in
    // parallel — best-effort; picker input stays raw id string on failure.
    const labelFetches: Promise<void>[] = []
    if (form.customerId) {
      labelFetches.push(fetchCustomer(form.customerId).then((cr) => {
        const c = cr.data
        customerPicked.value = {
          id: String(c.id ?? ''),
          customerCode: String(c.customerCode ?? ''),
          customerType: String(c.customerType ?? ''),
          titleTh: String(c.titleTh ?? ''),
          firstName: String(c.firstName ?? ''),
          lastName: String(c.lastName ?? ''),
          nickname: String(c.nickname ?? ''),
          juristicName: String(c.juristicName ?? ''),
          idCard: String(c.idCard ?? ''),
          taxId: String(c.taxId ?? ''),
          passport: String(c.passport ?? ''),
          phone: String(c.phone ?? ''),
          email: String(c.email ?? ''),
          province: String(c.province ?? ''),
          assignedAgentId: null,
          assignedAgentCode: null,
          assignedAgentName: '',
          activePolicyCount: 0,
          totalPolicyCount: 0,
          active: true,
          registeredAt: null,
        }
      }).catch(() => { /* label hydration is best-effort */ }))
    }
    if (form.writingAgentId) {
      labelFetches.push(fetchAgent(form.writingAgentId).then((ar) => {
        const a = ar.data
        agentPicked.value = {
          id: String(a.id ?? ''),
          agentCode: String(a.agentCode ?? ''),
          firstName: String(a.firstName ?? ''),
          lastName: String(a.lastName ?? ''),
          email: String(a.email ?? ''),
          phone: String(a.phone ?? ''),
          agentType: String(a.agentType ?? ''),
          active: true,
          licenseStatus: (a.licenseStatus as 'valid' | 'expired' | 'expiring60d' | null) ?? null,
          licenseExpiryDate: (a.licenseExpiryDate as string | null) ?? null,
          rankId: (a.rankId as string | null) ?? null,
          rankCode: (a.rankCode as string | null) ?? null,
          rankNameTh: (a.rankNameTh as string | null) ?? null,
          parentAgentId: (a.parentAgentId as string | null) ?? null,
          parentAgentCode: (a.parentAgentCode as string | null) ?? null,
        } as unknown as AgentListRow
      }).catch(() => { /* best-effort */ }))
    }
    await Promise.all(labelFetches)

    // Trigger prior-assets load after customer + kind resolved.
    void loadPriorAssets()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Draft resume failed.'
  } finally {
    // Give Vue two ticks for cascade watchers to settle before
    // re-enabling autosave. Prevents the immediate PATCH from
    // shipping a stale scalar bag.
    setTimeout(() => { hydrating.value = false }, 100)
  }
}

// ── Route-driven init ────────────────────────────────────────────────────

// The page mounts fresh on every visit, so no reset-on-close watcher
// needed — the form's reactive() defaults do the reset. If `id` prop is
// present (resume path), fire hydrateFromDraft after mount.
onMounted(() => {
  if (props.id) {
    draftId.value = props.id
    void hydrateFromDraft(props.id)
  }
})

// ── Search closures for EntityPicker ─────────────────────────────────────

async function searchCustomers(q: string): Promise<CustomerListRow[]> {
  const res = await fetchCustomerList({ q: q || undefined, perPage: 10 })
  return res.data
}
async function searchAgents(q: string): Promise<AgentListRow[]> {
  const res = await fetchAgentList({ q: q || undefined, activeOnly: true, perPage: 10 })
  return res.data
}
</script>

<template>
  <div class="space-y-6 max-w-5xl">
    <!-- Header — mirrors PolicyEdit's format (title / status / actions) -->
    <header class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <div class="text-xs text-slate-400">
          <RouterLink :to="{ name: 'policies' }" class="hover:text-brand-600">{{ t('modules.policies.name') }}</RouterLink>
          <span class="mx-1">/</span>
          <span>{{ props.id ? t('policyCreate.resumeDraft.title') : t('policyCreate.title') }}</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900 font-mono">
          {{ draftId ? `#${draftId}` : t('policyCreate.title') }}
        </h1>
        <div class="mt-1 flex items-center gap-2 text-xs">
          <span :class="['inline-flex px-2 py-0.5 rounded', statusBadgeClass('draft' as PolicyStatus)]">
            {{ t('policies.status.draft') }}
          </span>
          <span v-if="autosaving" class="text-slate-400">
            <i class="pi pi-spin pi-spinner text-[10px] mr-1" /> {{ t('policyCreate.action.savingDraft') }}
          </span>
          <span v-else-if="flash" class="text-emerald-600">
            <i class="pi pi-check-circle text-[10px] mr-1" /> {{ flash }}
          </span>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" @click="router.push({ name: 'policies' })"
          class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100">
          {{ t('policyCreate.cancel') }}
        </button>
        <button type="button" @click="saveDraftNow" :disabled="saving || !form.customerId"
          class="px-3 py-1.5 rounded-lg text-sm text-slate-700 border border-slate-200 hover:bg-slate-50 disabled:opacity-50">
          <i class="pi pi-save text-xs mr-1" /> {{ t('policyCreate.action.saveDraft') }}
        </button>
        <button type="button" @click="saveAsQuotation" :disabled="saving || !form.customerId || !form.productId"
          class="px-3 py-1.5 rounded-lg text-sm bg-slate-700 text-white hover:bg-slate-800 disabled:opacity-50">
          <i class="pi pi-file text-xs mr-1" /> {{ t('policyCreate.action.saveQuotation') }}
        </button>
        <button type="button" @click="submitToCarrier" :disabled="saving"
          class="px-3 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
          <i class="pi pi-send text-xs mr-1" /> {{ t('policyCreate.action.submitToCarrier') }}
        </button>
      </div>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <!-- ── Section 1: Party ─────────────────────────────────────────────── -->
    <section class="card p-5">
      <h2 class="font-semibold text-slate-900 mb-3">{{ t('policyCreate.step.1') }}</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <FormField :label="t('policyCreate.newOrRenew')">
          <div class="flex gap-2">
            <button type="button" @click="form.newOrRenew = 'new'"
              :class="['flex-1 px-3 py-1.5 rounded-lg text-sm border',
                form.newOrRenew === 'new' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-slate-200 hover:border-brand-300']">
              {{ t('policyCreate.new') }}
            </button>
            <button type="button" @click="form.newOrRenew = 'renew'"
              :class="['flex-1 px-3 py-1.5 rounded-lg text-sm border',
                form.newOrRenew === 'renew' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-slate-200 hover:border-brand-300']">
              {{ t('policyCreate.renew') }}
            </button>
          </div>
        </FormField>

        <FormField :label="t('policyCreate.applicationNo')">
          <input v-model.trim="form.applicationNo" type="text" maxlength="32"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>

        <FormField :label="t('policyCreate.customer')" required>
          <EntityPicker
            v-model="form.customerId"
            :fetch="searchCustomers"
            :render-label="(r: CustomerListRow) => `${r.firstName} ${r.lastName}`.trim() || r.juristicName || r.customerCode"
            :render-primary="(r: CustomerListRow) => r.customerCode"
            :placeholder="t('policyCreate.customerPlaceholder')"
            icon-class="pi-user"
            :initial-label="customerPicked ? (`${customerPicked.firstName} ${customerPicked.lastName}`.trim() || customerPicked.juristicName || customerPicked.customerCode) : ''"
            @picked="(r) => customerPicked = r as CustomerListRow | null"
          />
        </FormField>

        <FormField :label="t('policyCreate.agent')" required>
          <EntityPicker
            v-model="form.writingAgentId"
            :fetch="searchAgents"
            :render-label="(r: AgentListRow) => `${r.firstName} ${r.lastName}`.trim() || r.agentCode"
            :render-primary="(r: AgentListRow) => r.agentCode"
            :placeholder="t('policyCreate.agentPlaceholder')"
            icon-class="pi-briefcase"
            :initial-label="agentPicked ? (`${agentPicked.firstName} ${agentPicked.lastName}`.trim() || agentPicked.agentCode) : ''"
            @picked="(r) => agentPicked = r as AgentListRow | null"
          />
        </FormField>
      </div>
    </section>

    <!-- ── Section 2: Product + Coverage ────────────────────────────────── -->
    <section class="card p-5">
      <h2 class="font-semibold text-slate-900 mb-3">{{ t('policyCreate.step.2') }}</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <FormField :label="t('policyCreate.insureType')" required>
          <div class="flex gap-2">
            <button v-for="opt in (['Non-Life', 'Life', 'Tax'] as const)" :key="opt" type="button"
              @click="form.insureType = opt"
              :class="['flex-1 px-2 py-1.5 rounded-lg text-xs border',
                form.insureType === opt ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-slate-200']">
              {{ t(`policyCreate.insureTypeOpt.${opt === 'Non-Life' ? 'nonLife' : opt.toLowerCase()}`) }}
            </button>
          </div>
        </FormField>

        <FormField :label="t('policyCreate.carrier')" required>
          <select v-model="form.carrierId"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">
              {{ carriersLoading ? t('policyCreate.carrierLoading') : t('policyCreate.carrierPlaceholder') }}
            </option>
            <option v-for="c in carriers" :key="c.id" :value="c.id">
              {{ c.code }} · {{ c.name }}
            </option>
          </select>
        </FormField>

        <FormField :label="t('policyCreate.product')" required>
          <select v-model="form.productId"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">
              {{ productsLoading ? t('policyCreate.productLoading') : t('policyCreate.productPlaceholder') }}
            </option>
            <option v-for="p in products" :key="p.id" :value="p.id">
              {{ p.code }} · {{ p.name }}
            </option>
          </select>
          <p v-if="productDetail" class="text-[10px] text-slate-500 mt-1">
            {{ t('policyCreate.productKindLabel') }}: <span class="font-medium">{{ kind }}</span>
          </p>
        </FormField>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
        <FormField :label="t('policyCreate.appDate')">
          <DateInput v-model="form.appDate" />
        </FormField>
        <FormField :label="t('policyCreate.effectiveDate')" required>
          <DateInput v-model="form.effectiveDate" />
        </FormField>
        <FormField :label="t('policyCreate.expiryDate')" required>
          <DateInput
            v-model="form.expiryDate"
            @update:model-value="() => { touched.expiryDate = true }"
            :min="form.effectiveDate || null"
          />
        </FormField>
        <FormField :label="t('policyCreate.coverage')">
          <input v-model.number="form.coverage" type="number" min="0" step="1"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
      </div>

      <!-- เบี้ยรวม = เบี้ยสุทธิ + อากรแสตมป์ + VAT. ผูกกับ totalPremiumPaid
           ตัวเดียวกับ Section 4 (เบี้ย + การชำระ) — แก้ที่ใดก็ sync กัน. -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
        <FormField :label="t('policyCreate.totalPremium')">
          <input v-model.number="form.totalPremiumPaid" type="number" min="0" step="0.01"
            @change="touched.totalPremiumPaid = true"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
          <p class="text-[10px] text-slate-500 mt-1">
            <i class="pi pi-info-circle mr-1" />{{ t('policyCreate.totalPremiumHint') }}
          </p>
        </FormField>
        <FormField :label="t('policyCreate.discountAmount')">
          <input v-model.number="form.discountAmount" type="number" min="0" step="0.01"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
      </div>

      <DurationChip
        v-if="durationCfg.presets.length > 0 || durationCfg.allowCustomYears"
        v-model="form.expiryDate"
        v-model:selected-key="form.durationChipKey"
        :effective-date="form.effectiveDate || null"
        :presets="durationCfg.presets"
        :allow-custom-years="durationCfg.allowCustomYears"
        :label="t('policyCreate.duration.expiryHint')"
        class="mt-3"
      />

      <!-- ผู้รับผลประโยชน์ + สัญญาเพิ่มเติม (Riders) — ย้ายมาจาก Section
           รายละเอียด. render จาก risk schema เหมือนเดิม แต่เฉพาะ 2 sections
           นี้ (มีเฉพาะสินค้าที่ schema ประกาศไว้ เช่น ประกันชีวิต). -->
      <div v-if="productDetail" class="mt-5 pt-4 border-t border-slate-100">
        <RiskFieldRenderer
          :schema="(productDetail.productType?.riskSchema as unknown as RiskSchema | null)"
          v-model="form.risk"
          locale="th"
          :only="['riders', 'beneficiaries']"
          :product-search-context="productSearchContext"
        />
      </div>
    </section>

    <!-- ── Section 3: Risk (dynamic renderer) ───────────────────────────── -->
    <section class="card p-5">
      <h2 class="font-semibold text-slate-900 mb-3">{{ t('policyCreate.step.3') }}</h2>
      <div v-if="!productDetail" class="p-4 rounded-lg border border-dashed border-slate-200 text-center text-sm text-slate-500">
        เลือกสินค้าที่ Section 2 ก่อน
      </div>
      <template v-else>
        <div v-if="priorAssets.length > 0" class="p-3 rounded-lg bg-brand-50 border border-brand-200 space-y-2 mb-4">
          <div class="text-xs font-medium text-brand-800 flex items-center gap-1">
            <i class="pi pi-history text-[10px]" /> {{ t('policyCreate.reuseFromPrior.label') }}
          </div>
          <div class="flex flex-wrap gap-1.5">
            <button v-for="a in priorAssets" :key="a.dedupeKey" type="button"
              @click="applyPriorAsset(a)"
              class="px-2.5 py-1 rounded-md text-xs bg-white border border-brand-300 text-brand-700 hover:bg-brand-100">
              {{ a.fields.vehicle_brand ?? a.fields.name ?? a.dedupeKey.split('|')[0] }}
              <span v-if="a.fields.vehicle_model" class="text-brand-500"> · {{ a.fields.vehicle_model }}</span>
              <span v-if="a.lastUsedApplicationNo" class="text-[10px] text-brand-400 ml-1">({{ a.lastUsedApplicationNo }})</span>
            </button>
          </div>
        </div>

        <RiskFieldRenderer
          :schema="(productDetail.productType?.riskSchema as unknown as RiskSchema | null)"
          v-model="form.risk"
          locale="th"
          :exclude="['beneficiaries', 'riders']"
        />
      </template>
    </section>

    <!-- ── Section 4: Premium ───────────────────────────────────────────── -->
    <section class="card p-5">
      <h2 class="font-semibold text-slate-900 mb-3">{{ t('policyCreate.step.4') }}</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <FormField :label="t('policyCreate.netPremium')" required>
          <input v-model.number="form.netPremium" type="number" min="0" step="0.01"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField :label="t('policyCreate.dutyStamp')">
          <input v-model.number="form.dutyStamp" type="number" min="0" step="0.01"
            @change="touched.dutyStamp = true"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField :label="t('policyCreate.vat')">
          <input v-model.number="form.vat" type="number" min="0" step="0.01"
            @change="touched.vat = true"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField :label="t('policyCreate.totalPremiumPaid')">
          <input v-model.number="form.totalPremiumPaid" type="number" min="0" step="0.01"
            @change="touched.totalPremiumPaid = true"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField :label="t('policyCreate.whtAmt')">
          <input v-model.number="form.whtAmt" type="number" min="0" step="0.01"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField :label="t('policyCreate.netCustomerPaid')">
          <input v-model.number="form.netCustomerPaid" type="number" min="0" step="0.01"
            @change="touched.netCustomerPaid = true"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
      </div>

      <!-- สูตร 1–4: back-solve เบี้ยสุทธิ/อากร/VAT from เบี้ยรวม (gross).
           Ported from the Access form. Each button computes differently by
           product type; the operator can still override any field after. -->
      <div class="mt-3">
        <span class="text-[10px] uppercase tracking-wider text-slate-400 mr-2">{{ t('policyCreate.taxFormula') }}</span>
        <div class="flex flex-wrap gap-2 mt-1">
          <button v-for="n in ([1, 2, 3, 4] as const)" :key="n" type="button" @click="runFormula(n)"
            class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 bg-white hover:bg-brand-50 hover:border-brand-300 focus:outline-none focus:border-brand-400">
            {{ t(`policyCreate.formula${n}`) }}
          </button>
        </div>
        <p class="text-[10px] text-slate-500 mt-1">
          <i class="pi pi-info-circle mr-1" />{{ t('policyCreate.taxFormulaHint') }}
        </p>
      </div>

      <p class="text-[10px] text-slate-500 mt-2">
        <i class="pi pi-info-circle mr-1" /> {{ t('policyCreate.autoRecalc') }}
      </p>

      <h3 class="text-xs uppercase tracking-wider text-slate-400 mt-4 mb-2">{{ t('policyCreate.installment') }}</h3>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <FormField :label="t('policyCreate.premiumMode')">
          <select v-model="form.premiumMode"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option v-for="m in premiumModeOptions" :key="m" :value="m">
              {{ t(`policyCreate.premiumModes.${m}`) }}
            </option>
          </select>
        </FormField>
        <FormField :label="t('policyCreate.installmentTerm')">
          <input v-model.trim="form.installmentTerm" type="text"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
      </div>

      <!-- C-21: editable commission, both directions. Defaults from the
           product (year-1 rate of the matching sum-assured band); operator
           can override. Amount auto-computes from rate x net premium. -->
      <div class="flex items-center justify-between mt-4 mb-2">
        <h3 class="text-xs uppercase tracking-wider text-slate-400">{{ t('policyCreate.commissionSection') }}</h3>
        <span
          class="inline-flex items-center gap-1 text-[11px] rounded-full px-2 py-0.5"
          :class="commissionDisplay.frozen
            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
            : 'bg-amber-50 text-amber-700 border border-amber-200'"
        >
          <i class="pi" :class="commissionDisplay.frozen ? 'pi-lock' : 'pi-info-circle'" />
          {{ commissionDisplay.frozen ? t('policyCreate.commissionFrozen') : t('policyCreate.commissionWillFreeze') }}
          </span>
      </div>

      <!-- C-23: non-blocking warning — insured age/SA outside every rated
           commission band → policy would accrue no agent commission. -->
      <div v-if="commissionBandWarning" class="mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        <i class="pi pi-exclamation-triangle mt-0.5" />
        <div>
          <div class="font-medium">{{ t('policyCreate.commissionBandTitle') }}</div>
          <div class="opacity-90">{{ commissionBandWarning }}</div>
        </div>
      </div>

      <!-- C-22: full per-year vector grid (life products). Rows = years,
           columns = carrier→hub % and hub→agent %. Defaults from the matching
           sum-assured band; every cell editable per policy. -->
      <div v-if="isVectorScheme" class="overflow-x-auto">
        <table class="text-sm border-collapse">
          <thead>
            <tr class="text-left text-xs text-slate-500">
              <th rowspan="2" class="py-1.5 pr-3 font-medium align-bottom">{{ t('policyCreate.commissionYear') }}</th>
              <th colspan="2" class="py-1.5 px-3 font-medium text-center border-b border-slate-100">{{ t('policyCreate.commissionCarrierToHub') }}</th>
              <th colspan="2" class="py-1.5 px-3 font-medium text-center border-b border-slate-100">{{ t('policyCreate.commissionHubToAgent') }}</th>
            </tr>
            <tr class="text-left text-[10px] text-slate-400">
              <th class="py-1 px-3 font-normal">{{ t('policyCreate.commissionRatePct') }}</th>
              <th class="py-1 px-3 font-normal">{{ t('policyCreate.commissionAmount') }}</th>
              <th class="py-1 px-3 font-normal">{{ t('policyCreate.commissionRatePct') }}</th>
              <th class="py-1 px-3 font-normal">{{ t('policyCreate.commissionAmount') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="yr in VECTOR_YEARS" :key="yr" class="border-t border-slate-100">
              <td class="py-1.5 pr-3 text-slate-600 whitespace-nowrap">{{ VECTOR_YEAR_LABELS[yr] }}</td>
              <!-- carrier → hub: rate % (editable) + amount (computed) -->
              <td class="py-1.5 px-3">
                <div class="relative w-24">
                  <input
                    :value="vectorPct('carrierToHub', yr)"
                    @input="onVectorInput('carrierToHub', yr, ($event.target as HTMLInputElement).value)"
                    type="number" min="0" max="100" step="0.01"
                    class="w-full border border-slate-200 rounded-md pl-2 pr-6 py-1 text-sm focus:outline-none focus:border-brand-400" />
                  <span class="absolute right-2 top-1 text-xs text-slate-400">%</span>
                </div>
              </td>
              <td class="py-1.5 px-3 text-right tabular-nums text-slate-500 w-28">
                {{ vectorAmount('carrierToHub', yr) !== null ? vectorAmount('carrierToHub', yr)!.toLocaleString() : '—' }}
              </td>
              <!-- hub → agent: rate % (editable) + amount (computed) -->
              <td class="py-1.5 px-3">
                <div class="relative w-24">
                  <input
                    :value="vectorPct('hubToAgent', yr)"
                    @input="onVectorInput('hubToAgent', yr, ($event.target as HTMLInputElement).value)"
                    type="number" min="0" max="100" step="0.01"
                    class="w-full border border-slate-200 rounded-md pl-2 pr-6 py-1 text-sm focus:outline-none focus:border-brand-400" />
                  <span class="absolute right-2 top-1 text-xs text-slate-400">%</span>
                </div>
              </td>
              <td class="py-1.5 px-3 text-right tabular-nums text-slate-500 w-28">
                {{ vectorAmount('hubToAgent', yr) !== null ? vectorAmount('hubToAgent', yr)!.toLocaleString() : '—' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Flat products: single rate + amount per direction. -->
      <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- carrier → hub (insurer pays InsureHub) -->
        <div class="rounded-lg border border-slate-200 p-3">
          <p class="text-xs font-medium text-slate-600 mb-2">{{ t('policyCreate.commissionCarrierToHub') }}</p>
          <div class="grid grid-cols-2 gap-2">
            <FormField :label="t('policyCreate.commissionRatePct')">
              <div class="relative">
                <input
                  :value="form.commCarrierToHubRate !== null ? +(form.commCarrierToHubRate * 100).toFixed(3) : null"
                  @input="onCommRatePct('commCarrierToHubRate', ($event.target as HTMLInputElement).value)"
                  type="number" min="0" max="100" step="0.01"
                  class="w-full border border-slate-200 rounded-lg pl-3 pr-7 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
                <span class="absolute right-2.5 top-1.5 text-xs text-slate-400">%</span>
              </div>
            </FormField>
            <FormField :label="t('policyCreate.commissionAmount')">
              <input v-model.number="form.commCarrierToHubAmount" @change="touched.commCarrierToHubAmount = true"
                type="number" min="0" step="0.01"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </div>

        <!-- hub → agent (InsureHub pays agent) -->
        <div class="rounded-lg border border-slate-200 p-3">
          <p class="text-xs font-medium text-slate-600 mb-2">{{ t('policyCreate.commissionHubToAgent') }}</p>
          <div class="grid grid-cols-2 gap-2">
            <FormField :label="t('policyCreate.commissionRatePct')">
              <div class="relative">
                <input
                  :value="form.commHubToAgentRate !== null ? +(form.commHubToAgentRate * 100).toFixed(3) : null"
                  @input="onCommRatePct('commHubToAgentRate', ($event.target as HTMLInputElement).value)"
                  type="number" min="0" max="100" step="0.01"
                  class="w-full border border-slate-200 rounded-lg pl-3 pr-7 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
                <span class="absolute right-2.5 top-1.5 text-xs text-slate-400">%</span>
              </div>
            </FormField>
            <FormField :label="t('policyCreate.commissionAmount')">
              <input v-model.number="form.commHubToAgentAmount" @change="touched.commHubToAgentAmount = true"
                type="number" min="0" step="0.01"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </div>
      </div>
      <p class="text-[10px] text-slate-500 mt-2">
        <i class="pi pi-info-circle mr-1" /> {{ t('policyCreate.commissionHint') }}
      </p>
    </section>

    <!-- ── Section 5: Notes ─────────────────────────────────────────────── -->
    <section class="card p-5">
      <h2 class="font-semibold text-slate-900 mb-3">{{ t('policyCreate.step.5') }}</h2>
      <FormField :label="t('policyCreate.notes')">
        <textarea v-model="form.notes" rows="3"
          class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
      </FormField>
    </section>
  </div>
</template>
