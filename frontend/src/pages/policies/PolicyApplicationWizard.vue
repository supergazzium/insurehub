<script setup lang="ts">
// C-14 (v2 layout) — Policy Application Wizard as a full-page route.
//
// Sections (rendered as vertically-stacked cards):
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
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import FormField from '../../components/FormField.vue'
import MoneyInput from '../../components/MoneyInput.vue'
import DateInput from '../../components/DateInput.vue'
import DurationChip from '../../components/DurationChip.vue'
import RiskFieldRenderer from '../../components/RiskFieldRenderer.vue'
import EntityPicker from '../../components/EntityPicker.vue'
import ProductSearchSelect from '../../components/ProductSearchSelect.vue'
import PolicyPeriodBadge from '../../components/PolicyPeriodBadge.vue'
import RenewalSourceSearchModal from '../../components/RenewalSourceSearchModal.vue'
import CustomerCreateModal from '../customers/CustomerCreateModal.vue'
import { splitCustomerQuery } from '../../utils/genderFromTitle'
import { INSTALLMENT_MODES } from '../../utils/installmentCalc'
import PolicyPaymentPanel from './PolicyPaymentPanel.vue'
import type { ExpectedPremium } from './PolicyPaymentModal.vue'
import PolicyEndorsementModal, { type EndorsementInitial, type EndorsementSubmit } from './PolicyEndorsementModal.vue'
import {
  fetchEndorsements,
  createEndorsement,
  updateEndorsement,
  voidEndorsement,
  createPremiumEndorsement,
  updatePremiumEndorsement,
  type Endorsement,
  type PremiumEndorsementPayload,
} from '../../api/endorsements'
import { fmtDate } from '../../util/dateFormat'
import { ApiError } from '../../api/client'
import { fetchCustomerList, fetchPriorAssets, type CustomerListRow, type PriorAsset } from '../../api/customers'
import { fetchProduct, fetchProductList, type ProductDetail, type ProductListRow } from '../../api/products'
import { fetchAgent } from '../../api/agents'
import { fetchCustomer } from '../../api/customers'
import { fetchPolicy } from '../../api/policies'
import { hydrateSchemaValues } from '../../utils/riskSchema'
import { fetchAgentList, type AgentListRow } from '../../api/agents'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { fetchPolicyStatuses, type PolicyStatusRow } from '../../api/portal'
import {
  createDraftPolicy, updateDraftPolicy, updatePolicy,
  promotePolicyToQuotation, promotePolicyToSubmitted, requestPolicyDelete,
  fetchRenewalDraft,
  type PolicyListRow, type RenewalBlockedError, type RenewalDraftMeta,
} from '../../api/policies'
import type { Policy, PolicyChainLink } from '../../stores/policies'
import { fetchTenant } from '../../api/tenant'
import { updateFreelookDocs, type VerifyRow } from '../../api/followups'
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
const route = useRoute()

const { t } = useI18n()

// ── State ────────────────────────────────────────────────────────────────

// Full-page layout — no step gating. All 5 sections visible at once,
// `draftId` tracks the persisted row.
const draftId = ref<string | null>(props.id ?? null)
// Status of the loaded policy (edit mode). Null in create mode. When the
// policy is past 'draft', autosave must use the general PATCH /policies/{id}
// instead of /draft (which rejects non-draft rows).
const loadedStatus = ref<string | null>(null)
const isDraftMode = computed(() => loadedStatus.value === null || loadedStatus.value === 'draft')

const verifyOn = ref(false)
const freelookDays = reactive({ standard: 15, remote: 30 })
const pendingChange = ref<VerifyRow | null>(null)
const lastRejected = ref<VerifyRow | null>(null)
const verifyLocked = computed(() => !!pendingChange.value && pendingChange.value.action !== 'update')
const holdForVerify = computed(() => verifyOn.value && !isDraftMode.value)
const freelookDocs = reactive({ sentAt: '', signedAt: '', returnedAt: '' })
const showDeleteRequest = ref(false)
const deleteReason = ref('')
const SALES_CHANNELS = [
  { value: 'face_to_face', label: 'ขายตรง (พบลูกค้า)' },
  { value: 'telephone', label: 'ทางโทรศัพท์' },
  { value: 'online', label: 'ทางออนไลน์' },
]

async function loadTenantRules(): Promise<void> {
  try {
    const row = (await fetchTenant()).data
    verifyOn.value = !!row.policyVerifyRequired
    freelookDays.standard = Number(row.freelookDaysStandard ?? 15)
    freelookDays.remote = Number(row.freelookDaysRemote ?? 30)
  } catch {
    verifyOn.value = false
  }
}

function takePending(res: unknown): boolean {
  const pc = (res as { meta?: { pendingChange?: VerifyRow } } | null)?.meta?.pendingChange
  if (!pc) return false
  pendingChange.value = pc
  lastRejected.value = null
  return true
}

// ── Policy status lookup (manually-editable status dropdown) ──────────────
const policyStatuses = ref<PolicyStatusRow[]>([])
/** Settable statuses = lookup rows with a machine code (the ~10 the backend
 *  accepts). Follow-up sub-statuses (code=null) aren't a policy status. */
const statusOptions = computed(() =>
  policyStatuses.value.filter((s): s is PolicyStatusRow & { code: string } => !!s.code),
)
async function loadPolicyStatuses(): Promise<void> {
  if (policyStatuses.value.length) return
  try { policyStatuses.value = (await fetchPolicyStatuses()).data } catch { /* silent */ }
}
// Earliest effective_date across the customer's policies (from the resource).
// Anchors the "years with InsureHub" tenure metric.
const customerFirstPolicyDate = ref<string | null>(null)

/** Completed-year cycles from `startIso` to today, as an ordinal (ปีที่ N).
 *  A policy in its first 12 months is year 1; each full year bumps it. */
function yearsSince(startIso: string | null): number | null {
  if (!startIso) return null
  const start = new Date(startIso)
  if (Number.isNaN(start.getTime())) return null
  const now = new Date()
  let years = now.getFullYear() - start.getFullYear()
  // Subtract a year if today hasn't yet reached the anniversary month/day.
  const beforeAnniv = now.getMonth() < start.getMonth()
    || (now.getMonth() === start.getMonth() && now.getDate() < start.getDate())
  if (beforeAnniv) years--
  return years + 1 // ordinal: within the first year → ปีที่ 1
}

// ── Renewal (ต่ออายุ) prefill context ────────────────────────────────────
// Set when the wizard is opened via ?renewFrom=<sourceId>. Holds the source
// policy's STORED year figures (task 1.4) so the renewal's years follow:
//   customer year = source customer year + 1 (always)
//   policy year   = source policy year + 1, UNLESS the product was changed,
//                   in which case it resets to 1 (a new product = fresh policy).
const renewSource = ref<{
  policyYear: number
  customerYear: number | null
  productId: string
} | null>(null)
const isRenewingFromSource = computed(() => renewSource.value !== null)
/** True once the renewed policy is on a different product than the source. */
const renewProductChanged = computed(() =>
  isRenewingFromSource.value && String(form.productId) !== String(renewSource.value?.productId ?? ''),
)

/** ปีของกรมธรรม์ to suggest for a renewal: source + 1, or 1 when the product
 *  was changed. Null outside a renewal (the stored value stands). */
const suggestedPolicyYear = computed<number | null>(() => {
  if (!isRenewingFromSource.value) return null
  return renewProductChanged.value ? 1 : (renewSource.value?.policyYear ?? 0) + 1
})
// ปีของกรมธรรม์ / ปีของลูกค้า are STORED on the policy (task 1.4) — the badges
// show the saved figures, not a date calculation. The operator may type them
// while the policy is a draft/quotation; past that only an admin can correct
// them (the backend enforces this and audits the change).
const auth = useAuthStore()
const yearsTouched = ref(false)
const canEditYears = computed(() => auth.user?.role === 'super_admin' || auth.user?.role === 'admin')
// #13 — gate the save/submit actions: creating a NEW policy needs
// policies.create; editing an existing one needs policies.update. Users
// without the right permission can open and view the wizard but see no
// save buttons (the server also enforces this).
const canSavePolicy = computed(() =>
  auth.hasPermission(draftId.value ? 'policies.update' : 'policies.create'),
)

const renewalMeta = ref<RenewalDraftMeta | null>(null)
const renewalBlocked = ref<RenewalBlockedError | null>(null)
const renewalConfirmed = ref(false)
const showRenewalSearch = ref(false)
const renewalLoading = ref(false)
const renewedFrom = ref<PolicyChainLink | null>(null)
const renewedTo = ref<(PolicyChainLink & { live: boolean; count: number }) | null>(null)
const periodLabel = ref<string | null>(null)
const isRenewalInProgress = computed(() =>
  !!form.refAppToId && (isDraftMode.value || loadedStatus.value === 'quotation'),
)
function needsConfirm(key: string): boolean {
  return isRenewalInProgress.value && !renewalConfirmed.value && !!renewalMeta.value?.mustConfirm.includes(key)
}
const confirmRing = 'ring-2 ring-amber-300 ring-offset-2 rounded-lg'
/** ปีของลูกค้า for the badge: the stored value, else (new policy, not yet
 *  saved) the customer's tenure — the backend fills it on create. */
const customerYearDisplay = computed<number | null>(() =>
  // `||` not `??`: a cleared number input yields '' rather than null.
  form.customerYear || yearsSince(customerFirstPolicyDate.value),
)
watch(suggestedPolicyYear, (n) => {
  if (n !== null && !yearsTouched.value && !hydrating.value) form.policyYear = n
})
const DELIVERY_METHODS = ['post', 'kerry', 'flash', 'pickup', 'e_policy', 'other'] as const
const needsTracking = computed(() => !['pickup', 'e_policy'].includes(form.deliveryMethod))
const needsCourier = computed(() => ['post', 'other'].includes(form.deliveryMethod))
const saving = ref(false)
const error = ref<string | null>(null)
const flash = ref<string | null>(null)

// The single source of truth for every editable field. Kept as a flat
// reactive so v-model bindings are one-liners; the payload builder
// projects into the backend shape.
const form = reactive({
  // Step 1 — Party
  newOrRenew: 'new' as 'new' | 'renew',
  // Manually-editable policy status (edit mode). Options from the
  // policy_statuses lookup — only settable statuses (those with a code).
  status: '' as string,
  refAppToId: '' as string,
  customerId: '' as string,
  writingAgentId: '' as string,
  // application_no (เลขที่ใบสมัคร) + job_no (เลขงาน) are auto-run by the
  // backend at draft creation — read-only in the wizard.
  applicationNo: '' as string,
  jobNo: '' as string,
  // policy_no (เลขที่กรมธรรม์) — issued by the carrier later once payment is
  // made and the policy is approved. Editable; usually filled in on edit.
  policyNo: '' as string,
  notionNo: '' as string,

  // Step 2 — Product + Coverage
  // Backend carriers.insure_type stores exact strings "Life", "Non-Life",
  // "Tax". The dropdown / hydrate must send those exact values or the
  // case-sensitive WHERE clause in CarrierController returns 0 rows.
  insureType: '' as 'Life' | 'Non-Life' | 'Tax' | '',
  carrierId: '' as string,
  productId: '' as string,
  policyYear: 1,
  customerYear: null as number | null,  // ปีของลูกค้า — null until stored
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
  // เบี้ยรวม (Section 2) = the MAIN premium the operator types. It is the
  // gross the tax formulas back-solve from; for life the formula also adds
  // the riders' premiums on top. Distinct from totalPremiumPaid, which is
  // the computed รวมเบี้ยที่ต้องชำระ (main + riders + duty + vat).
  grossPremiumInput: 0 as number,
  netPremium: 0 as number,
  mainPremium: 0 as number,
  dutyStamp: 0 as number,
  vat: 0 as number,
  totalPremiumPaid: 0 as number,
  whtAmt: 0 as number,
  netCustomerPaid: 0 as number,
  annualPremium: 0 as number,
  premiumMode: 'annual' as 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single',
  // จำนวนงวด — auto-set from premiumMode (monthly 12 / quarterly 4 /
  // semiannual 2 / annual|single 1) but editable. Drives the prefilled
  // installment rows in the payment modal.
  installmentCount: 1 as number,
  installmentTerm: '' as string,
  installmentMode: '' as string,   // A/B/C — who bears fee + interest
  compulsoryPremium: 0 as number,  // พ.ร.บ. — billed on งวด 1 only
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

  // การรับกรมธรรม์และจัดส่ง — received-from-carrier + delivered-to-customer
  // tracking. `received`/`delivered` are UI-only "done" checkboxes derived
  // from / driving the presence of the paired date.
  approved: false as boolean,
  approvedDate: '' as string,
  approvalRef: '' as string,
  approvalNote: '' as string,
  received: false as boolean,
  receivedDate: '' as string,
  receivedNote: '' as string,
  delivered: false as boolean,
  shippedDate: '' as string,
  mailingNote: '' as string,
  deliveryMethod: '' as string,
  courier: '' as string,
  trackingNo: '' as string,
  deliveredDate: '' as string,
  deliveredTo: '' as string,
  // Free Look date — life products only.
  freelookEndDate: '' as string,
  salesChannel: 'face_to_face' as string,
})

const freelookComputed = computed(() => {
  if (!form.delivered || !form.deliveredDate) return null
  const days = ['telephone', 'online'].includes(form.salesChannel) ? freelookDays.remote : freelookDays.standard
  const d = new Date(`${form.deliveredDate}T00:00:00`)
  if (Number.isNaN(d.getTime())) return null
  d.setDate(d.getDate() + days)
  return { date: d.toLocaleDateString('sv-SE'), days }
})

// Touched flags for auto-fills (mirrors legacy wizard L556-681).
const touched = reactive({
  expiryDate: false,
  mainPremium: false,
  dutyStamp: false,
  vat: false,
  totalPremiumPaid: false,
  whtAmt: false,
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
// True while hydrateFromDraft is restoring a saved policy — see there.
const hydrating = ref(false)
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
    ? (rates?.hubToAgent ? panelYearRate(rates.hubToAgent, form.policyYear) : null)
    : (rates?.hubToAgent?.flatRate ?? null)
  return { rate: live, frozen: false, isLife, capturedAt: null }
})

function bandYearKey(year: number): 'yr1' | 'yr2' | 'yr3' | 'yr4' | 'yr5' | 'yr6Up' {
  const y = Math.max(1, Number(year) || 1)
  return y >= 6 ? 'yr6Up' : (`yr${y}` as 'yr1' | 'yr2' | 'yr3' | 'yr4' | 'yr5')
}

function panelYearRate(panel: Record<string, number | null | undefined>, year: number): number | null {
  const y = Math.max(1, Number(year) || 1)
  const key = y >= 11 ? 'yr11Up' : y >= 6 ? 'yr6_10' : `yr${y}`
  return panel[key] ?? null
}

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
    const bandRate = match?.[bandYearKey(form.policyYear)]
    if (bandRate != null) return Number(bandRate)
  }

  // 2. single rate row
  const rates = pd.commissionRates
  const panel = direction === 'carrierToHub' ? rates?.carrierToHub : rates?.hubToAgent
  if (!panel) return null
  return rates?.scheme === 'life_years'
    ? panelYearRate(panel as unknown as Record<string, number | null>, form.policyYear)
    : (panel.flatRate ?? null)
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
  () => [form.productId, form.coverage, productDetail.value, form.policyYear] as const,
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

// #45 — "ดึงแค่ปีปัจจุบันขึ้นมาแสดง ไม่ต้องให้กรอก 6 ปี": a life policy only
// uses its CURRENT policy-year commission, so by default the vector grid
// shows just that one row. The operator can expand to all six.
const showAllVectorYears = ref(false)
/** Map the policy-year ordinal (1,2,3,…) to its vector key; year ≥6 → yr_6_up. */
const currentVectorYear = computed<typeof VECTOR_YEARS[number]>(() => {
  const n = (Number(form.policyYear) || 1)
  if (n <= 1) return 'yr_1'
  if (n === 2) return 'yr_2'
  if (n === 3) return 'yr_3'
  if (n === 4) return 'yr_4'
  if (n === 5) return 'yr_5'
  return 'yr_6_up'
})
/** The rows the vector grid renders: just the current year unless expanded. */
const visibleVectorYears = computed<readonly string[]>(() =>
  showAllVectorYears.value ? VECTOR_YEARS : [currentVectorYear.value],
)

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

// ── Payment frequency (งวดการชำระ) ───────────────────────────────────────
// The product decides (ProductResource.effectivePremiumModes, which already
// falls back to the insure-type default). Before a product is picked:
// Life → every mode, Non-Life / Tax → annual only. The backend
// (PolicyRequest) rejects a mode the product doesn't offer.
type PremiumMode = typeof form.premiumMode
const premiumModeOptions = computed<PremiumMode[]>(() => {
  const fromProduct = productDetail.value?.effectivePremiumModes
  if (fromProduct && fromProduct.length > 0) return fromProduct
  return form.insureType === 'Life'
    ? ['monthly', 'quarterly', 'semiannual', 'annual', 'single']
    : ['annual']
})
// What the <select> lists: the allowed modes, plus a legacy stored mode the
// product no longer offers so the saved value still displays (the backend
// only re-checks the mode when it changes).
const premiumModeChoices = computed<PremiumMode[]>(() =>
  premiumModeOptions.value.includes(form.premiumMode)
    ? premiumModeOptions.value
    : [...premiumModeOptions.value, form.premiumMode],
)
// When the product / insure type changes, snap premiumMode back to a valid
// option (prefer annual). Skipped while hydrating so a stored mode isn't
// clobbered before the product detail arrives.
watch(premiumModeOptions, (opts) => {
  if (hydrating.value || opts.includes(form.premiumMode)) return
  form.premiumMode = opts.includes('annual') ? 'annual' : opts[0]
})

// จำนวนงวด per payment frequency. รายเดือน 12 / รายสามเดือน 4 /
// รายหกเดือน 2 / รายปี | จ่ายครั้งเดียว 1.
const INSTALLMENTS_BY_MODE: Record<PremiumMode, number> = {
  monthly: 12, quarterly: 4, semiannual: 2, annual: 1, single: 1,
}
// Auto-set the installment count from the chosen frequency. The operator can
// still edit installmentCount afterwards (this only fires when the mode
// changes, so a manual override survives until the next mode switch).
watch(() => form.premiumMode, (mode) => {
  // Hydration restores both from the saved policy instead.
  if (hydrating.value) return
  const n = INSTALLMENTS_BY_MODE[mode] ?? 1
  form.installmentCount = n
  // งวดการผ่อน (installment_term) is what gets saved — keep it in step
  // for multi-installment modes; annual/single = one payment (blank).
  form.installmentTerm = n > 1 ? String(n) : ''
}, { immediate: true })

// #43 — งวดการผ่อน (รายปี) ทำให้สับสน: the installment detail fields (count /
// term / mode) only make sense when the policy is actually paid in
// instalments. For "รายปี / จ่ายครั้งเดียว" (count = 1) they're noise, so we
// hide them unless a sub-annual frequency is chosen OR the operator has set
// more than one instalment.
const hasInstallmentPlan = computed<boolean>(() =>
  (form.premiumMode !== 'annual' && form.premiumMode !== 'single') ||
  (Number(form.installmentCount) || 1) > 1,
)

// ── Premium recalc watchers (KEEP verbatim per B3 §9) ────────────────────
// The Access-parity math: duty = 0.4% net, vat = 7% (net + duty), total =
// net + duty + vat. Rounded to 2dp. Operator overrides win via touched flags.
watch(() => form.netPremium, (net) => {
  if (!net || net <= 0) return
  // #52 — when the OPERATOR edits เบี้ยสุทธิ (i.e. not during draft
  // hydration), net becomes the source of truth again: clear the derived
  // fields' `touched` flags so อากร / VAT / เบี้ยรวม recompute. Without this,
  // a hydrated draft (which marks everything touched to protect saved values)
  // would never recalc on edit — the "auto-cal ใช้ไม่ได้ ในหน้า Edit" bug.
  if (!hydrating.value) {
    touched.dutyStamp = false
    touched.vat = false
    touched.totalPremiumPaid = false
  }
  if (!touched.dutyStamp) form.dutyStamp = Math.round(net * 0.004 * 100) / 100
  if (!touched.vat) form.vat = Math.round((net + (form.dutyStamp ?? 0)) * 0.07 * 100) / 100
  if (!touched.totalPremiumPaid) form.totalPremiumPaid = Math.round((net + form.dutyStamp + form.vat) * 100) / 100
  if (!touched.mainPremium) form.mainPremium = net
  if (!form.annualPremium) form.annualPremium = net
})

// ── ยอดหัก ณ ที่จ่าย (WHT) = (เบี้ยสุทธิ + อากรแสตมป์) × 1%. Auto unless the
//    operator edits it. Recomputes when net or duty changes.
// #37 — checkbox toggle: when OFF, WHT is forced to 0 and the field is locked;
//       when ON, it auto-calcs (unless the operator typed a custom value).
const WHT_RATE = 0.01
const whtEnabled = ref(true)
function recomputeWht(): void {
  if (!whtEnabled.value) { form.whtAmt = 0; return }
  if (touched.whtAmt) return
  form.whtAmt = Math.round(((Number(form.netPremium) || 0) + (Number(form.dutyStamp) || 0)) * WHT_RATE * 100) / 100
}
watch(() => [form.netPremium, form.dutyStamp], recomputeWht)
// Toggling the checkbox: OFF → zero + lock; ON → clear the manual flag and
// recompute from the current premium so the auto value comes back.
watch(whtEnabled, (on) => {
  if (!on) { form.whtAmt = 0 }
  else { touched.whtAmt = false; recomputeWht() }
})

// ── ยอดสุทธิที่ต้องชำระ = รวมเบี้ยที่ต้องชำระ − ส่วนลด − ยอดหัก ณ ที่จ่าย.
//    Auto unless edited. Recomputes when total / discount / wht changes.
function recomputeNetCustomerPaid(): void {
  // #52 — any operator-driven change to รวมเบี้ย / ส่วนลด / หัก ณ ที่จ่าย makes
  // ยอดสุทธิ a derived value again, so drop its hydration `touched` flag and
  // recompute. Skip during hydration so the saved value loads intact.
  if (!hydrating.value) touched.netCustomerPaid = false
  if (touched.netCustomerPaid) return
  form.netCustomerPaid = Math.round(
    ((Number(form.totalPremiumPaid) || 0) - (Number(form.discountAmount) || 0) - (Number(form.whtAmt) || 0)) * 100,
  ) / 100
}
watch(() => [form.totalPremiumPaid, form.discountAmount, form.whtAmt], recomputeNetCustomerPaid)

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

/** Sum of the rider premiums (form.risk['riders.rows'][].premium). For life
 *  products the total premium must include the riders' premiums. */
const riderPremiumTotal = computed<number>(() => {
  const rows = form.risk['riders.rows']
  if (!Array.isArray(rows)) return 0
  return rows.reduce((sum, r) => {
    const p = Number((r as Record<string, unknown>)?.premium)
    return sum + (Number.isFinite(p) ? p : 0)
  }, 0)
})

/** Which formula buttons show. ประกันชีวิต (Life) uses only สูตร 2; every
 *  other product type gets all four. */
const visibleFormulas = computed<(1 | 2 | 3 | 4)[]>(() =>
  form.insureType === 'Life' ? [2] : [1, 2, 3, 4],
)

// ── Payment tracking ─────────────────────────────────────────────────────
/** Human label for the picked carrier (for the payment modal's payee radio). */
const carrierLabel = computed<string>(() => {
  const c = carriers.value.find((x) => String(x.id) === String(form.carrierId))
  return c ? `${c.code} · ${c.name}` : ''
})
/** Additional (pro-rata) premium recorded across premium-change endorsements
 *  on this policy — folded into the payment modal's expected total so the
 *  operator collects it alongside the base premium for the period. */
const endorsementPremiumTotal = ref(0)

/** Expected premium numbers (from the 4 สูตร) passed to the payment modal.
 *  Any outstanding สลักหลังเบี้ยเพิ่ม is added onto the total owed. */
const expectedPremium = computed<ExpectedPremium>(() => ({
  netPremium: Number(form.netPremium) || 0,
  dutyStamp: Number(form.dutyStamp) || 0,
  vat: Number(form.vat) || 0,
  totalPremiumPaid: (Number(form.totalPremiumPaid) || 0) + (Number(endorsementPremiumTotal.value) || 0),
  discountAmount: Number(form.discountAmount) || 0,
  commissionAmount: Number(form.commHubToAgentAmount) || 0,
}))

// ── สลักหลัง (endorsement) — history + premium-increase modal ─────────────
const showEndorsementModal = ref(false)
const endorsements = ref<Endorsement[]>([])
const endorsementNextSeq = ref<number | null>(null)
const endorsementSaving = ref(false)
const endorsementErrors = ref<Record<string, string[]>>({})
/** The endorsement being edited (null = create mode) + its prefill. */
const editingEndorsementId = ref<string | null>(null)
const endorsementInitial = ref<EndorsementInitial | null>(null)
const canManageEndorsements = computed(() => auth.hasPermission('policies.update'))

function openNewEndorsement(): void {
  editingEndorsementId.value = null
  endorsementInitial.value = null
  endorsementErrors.value = {}
  showEndorsementModal.value = true
}

function openEditEndorsement(ev: Endorsement): void {
  const isPremium = ev.type === 'endorsement.premium_change'
  const p = isPremium ? endorsementSummary(ev) : null
  const raw = (ev.payload ?? {}) as Record<string, unknown>
  editingEndorsementId.value = ev.id
  endorsementInitial.value = {
    type: ev.type,
    seq: ev.seq,
    reason: String(raw.reason ?? ''),
    effectiveDate: String(raw.effectiveDate ?? ''),
    detail: String(raw.detail ?? ''),
    newAnnualPremium: p?.after?.annualPremium ?? 0,
    newCoverage: p?.after?.coverage ?? 0,
    additionalPremium: p?.additionalPremium ?? 0,
    additionalDutyStamp: p?.additionalDutyStamp ?? 0,
    additionalVat: p?.additionalVat ?? 0,
    beforeAnnualPremium: p?.before?.annualPremium ?? 0,
    beforeCoverage: p?.before?.coverage ?? 0,
  }
  endorsementErrors.value = {}
  showEndorsementModal.value = true
}

const voidTarget = ref<Endorsement | null>(null)
const voidReason = ref('')
const voidSaving = ref(false)
const voidError = ref<string | null>(null)

function openVoidEndorsement(ev: Endorsement): void {
  voidTarget.value = ev
  voidReason.value = ''
  voidError.value = null
}

async function confirmVoidEndorsement(): Promise<void> {
  if (!draftId.value || !voidTarget.value || !voidReason.value.trim()) return
  voidSaving.value = true
  voidError.value = null
  try {
    await voidEndorsement(draftId.value, voidTarget.value.id, voidReason.value.trim())
    voidTarget.value = null
    await hydrateFromDraft(draftId.value)
    await loadEndorsements()
    flash.value = t('endorsement.voided')
  } catch (e: unknown) {
    voidError.value = e instanceof ApiError
      ? (e.status === 403 ? t('endorsement.noPermission') : (e.body?.message ?? e.message))
      : (e instanceof Error ? e.message : 'Error')
  } finally {
    voidSaving.value = false
  }
}

/** Endorsements are available on any saved (non-draft) policy — most useful
 *  when in force, but also allowed on expired/other statuses so a late
 *  correction can be recorded. Hidden only on an unsaved / draft form. */
const canEndorse = computed<boolean>(() =>
  !!draftId.value && !isDraftMode.value,
)

async function loadEndorsements(): Promise<void> {
  if (!draftId.value) return
  try {
    const res = await fetchEndorsements(draftId.value)
    endorsements.value = res.data ?? []
    endorsementNextSeq.value = res.meta?.nextSeq ?? null
  } catch { /* non-fatal — history just stays empty */ }
}

async function submitEndorsement(payload: EndorsementSubmit): Promise<void> {
  if (!draftId.value) return
  endorsementSaving.value = true
  endorsementErrors.value = {}
  const isPremium = payload.type === 'endorsement.premium_change'
  const premiumBody = {
    reason: payload.reason,
    effectiveDate: payload.effectiveDate,
    newAnnualPremium: payload.newAnnualPremium,
    newCoverage: payload.newCoverage,
    additionalPremium: payload.additionalPremium,
    additionalDutyStamp: payload.additionalDutyStamp,
    additionalVat: payload.additionalVat,
  }
  const generalBody = { reason: payload.reason, effectiveDate: payload.effectiveDate, detail: payload.detail }
  try {
    if (editingEndorsementId.value) {
      if (isPremium) await updatePremiumEndorsement(draftId.value, editingEndorsementId.value, premiumBody)
      else await updateEndorsement(draftId.value, editingEndorsementId.value, generalBody)
    } else if (isPremium) {
      await createPremiumEndorsement(draftId.value, premiumBody)
    } else {
      await createEndorsement(draftId.value, { type: payload.type, ...generalBody })
    }
    showEndorsementModal.value = false
    // The policy premium changed server-side — re-hydrate so the form and the
    // payment total reflect the new figures, then refresh the history list.
    await hydrateFromDraft(draftId.value)
    await loadEndorsements()
    flash.value = editingEndorsementId.value ? t('endorsement.updated') : t('endorsement.saved')
    editingEndorsementId.value = null
    endorsementInitial.value = null
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      endorsementErrors.value = e.body?.errors ?? (e.body?.message ? { reason: [e.status === 403 ? t('endorsement.noPermission') : e.body.message] } : {})
    }
  } finally {
    endorsementSaving.value = false
  }
}

/** Human summary for a premium-endorsement history row. */
function endorsementSummary(ev: Endorsement): PremiumEndorsementPayload | null {
  return (ev.payload as unknown as PremiumEndorsementPayload) ?? null
}

function endorsementField(ev: Endorsement, key: string): string {
  const v = (ev.payload ?? {})[key]
  return v === null || v === undefined ? '' : String(v)
}

function endorsementTypeLabel(type: string): string {
  return t(`endorsement.types.${type.replace('endorsement.', '')}`)
}

function fmtMoney(x: number): string {
  return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(x || 0)
}

// ── Per-rider commission (ค่าคอมมิชชั่น Inh % / Agent %) ──────────────────
// These used to be columns in the rider table (สินค้า section). They now
// live in the commission section as a per-rider table, editing the same
// form.risk['riders.rows'][i].rate_inh / rate_ag the schema no longer renders.
const riderRows = computed<Record<string, unknown>[]>(() => {
  const rows = form.risk['riders.rows']
  return Array.isArray(rows) ? (rows as Record<string, unknown>[]) : []
})

/** Read a rider's rate_inh / rate_ag as a display number (or ''). */
function riderRate(idx: number, key: 'rate_inh' | 'rate_ag'): number | '' {
  const v = riderRows.value[idx]?.[key]
  return v === null || v === undefined || v === '' ? '' : Number(v)
}

/** Write a rider's rate_inh / rate_ag back into the risk bag immutably. */
function setRiderRate(idx: number, key: 'rate_inh' | 'rate_ag', raw: string): void {
  const rows = riderRows.value.slice()
  if (!rows[idx]) return
  const n = raw === '' ? null : Number(raw)
  rows[idx] = { ...rows[idx], [key]: Number.isFinite(n as number) ? n : null }
  form.risk = { ...form.risk, 'riders.rows': rows }
}

/** Label for a rider row in the commission table (its picked name or index). */
function riderLabel(idx: number): string {
  const name = riderRows.value[idx]?.name
  return (typeof name === 'string' && name.trim()) ? name : `${t('policyCreate.riders.title')} #${idx + 1}`
}

// ── Rider table (independent of riskSchema) ────────────────────────────────
// Not every product carries a rider field in its risk schema, so we edit
// form.risk['riders.rows'] directly here. All downstream logic (premium total,
// per-rider commission, save) already reads from this same array.
function addRiderRow(): void {
  const rows = riderRows.value.slice()
  rows.push({ name: '', premium: 0, notes: '' })
  form.risk = { ...form.risk, 'riders.rows': rows }
}
function removeRiderRow(idx: number): void {
  const rows = riderRows.value.slice()
  rows.splice(idx, 1)
  form.risk = { ...form.risk, 'riders.rows': rows }
}
/** Update a plain field (name / premium / notes) on a rider row immutably. */
function setRiderField(idx: number, key: 'name' | 'premium' | 'notes', raw: string): void {
  const rows = riderRows.value.slice()
  if (!rows[idx]) return
  const val = key === 'premium' ? (raw === '' ? 0 : Number(raw)) : raw
  rows[idx] = { ...rows[idx], [key]: val }
  form.risk = { ...form.risk, 'riders.rows': rows }
}
function riderField(idx: number, key: 'name' | 'premium' | 'notes'): string | number {
  const v = riderRows.value[idx]?.[key]
  if (key === 'premium') return v === null || v === undefined || v === '' ? 0 : Number(v)
  return typeof v === 'string' ? v : ''
}
/** #42 — read a rider row's picked product code (powers the searchable
 *  dropdown's v-model). Stored alongside name so the selection sticks. */
function riderCode(idx: number): string {
  const v = riderRows.value[idx]?.productCode
  return typeof v === 'string' ? v : ''
}
/** #42 — a Life rider is picked from the product catalog (carrier-matched,
 *  insure_type=life, mainRider=Rider). Record its code + name onto the row;
 *  premium stays operator-entered (rider premium depends on SA/age). */
function pickRider(idx: number, row: ProductListRow | null): void {
  const rows = riderRows.value.slice()
  if (!rows[idx]) return
  rows[idx] = row
    ? { ...rows[idx], productCode: row.code, name: row.name }
    : { ...rows[idx], productCode: '', name: '' }
  form.risk = { ...form.risk, 'riders.rows': rows }
}

/** @param gross the VAT/duty-inclusive amount the formula back-solved from;
 *  it becomes รวมเบี้ยที่ต้องชำระ (may include rider premiums for life). */
function applyFormula(net: number, duty: number, vat: number, gross: number) {
  form.netPremium = round2(net)
  form.dutyStamp = round2(duty)
  form.vat = round2(vat)
  form.totalPremiumPaid = round2(gross)
  touched.dutyStamp = true
  touched.vat = true
  touched.totalPremiumPaid = true
  // Re-derive WHT + net-customer-paid from the freshly-computed net/duty
  // (unless the operator has pinned them).
  recomputeWht()
  recomputeNetCustomerPaid()
}

function runFormula(n: 1 | 2 | 3 | 4) {
  // Base = เบี้ยรวม (grossPremiumInput = main premium the operator entered).
  // For life the total premium also includes the riders' premiums, so fold
  // them in. Idempotent: derived from the entered main premium + riders each
  // click, never by re-reading a value we already inflated.
  const riderAdd = form.insureType === 'Life' ? riderPremiumTotal.value : 0
  const gross = round2((Number(form.grossPremiumInput) || 0) + riderAdd)
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
    applyFormula(premium, duty, vat, gross)
  } else if (n === 2) {
    applyFormula(gross / 1.07, 0, 0, gross)
  } else if (n === 3) {
    applyFormula(gross - 20, 20, 0, gross)
  } else {
    applyFormula(gross - 150, 150, 0, gross)
  }
}

function customerRowFromResource(r: Record<string, unknown>): CustomerListRow {
  return {
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
    assignedAgentId: (r.assignedAgentId as string | null) ?? null,
    assignedAgentCode: (r.assignedAgentCode as string | null) ?? null,
    assignedAgentName: String(r.assignedAgentName ?? ''),
    activePolicyCount: Number(r.activePolicyCount ?? 0),
    totalPolicyCount: Number(r.totalPolicyCount ?? 0),
    active: r.active !== false,
    registeredAt: (r.registeredAt as string | null) ?? null,
  } as CustomerListRow
}

function defaultAgentFromCustomer(row: CustomerListRow | null): void {
  if (!row?.assignedAgentId || form.writingAgentId) return
  const [firstName = '', ...rest] = (row.assignedAgentName ?? '').split(' ')
  form.writingAgentId = String(row.assignedAgentId)
  agentPicked.value = {
    id: String(row.assignedAgentId),
    agentCode: row.assignedAgentCode ?? '',
    firstName,
    lastName: rest.join(' '),
  } as unknown as AgentListRow
}

function applyPickedCustomer(row: CustomerListRow): void {
  form.customerId = row.id
  customerPicked.value = row
  defaultAgentFromCustomer(row)
}

const showCustomerCreate = ref(false)
const customerCreateInitial = ref<ReturnType<typeof splitCustomerQuery> | null>(null)

function openCustomerCreate(query: string): void {
  customerCreateInitial.value = splitCustomerQuery(query)
  showCustomerCreate.value = true
}

function onCustomerCreated(row: Record<string, unknown>): void {
  showCustomerCreate.value = false
  applyPickedCustomer(customerRowFromResource(row))
}

async function onCustomerOpenExisting(id: string): Promise<void> {
  showCustomerCreate.value = false
  try {
    const res = await fetchCustomer(id)
    applyPickedCustomer(customerRowFromResource(res.data as unknown as Record<string, unknown>))
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
}

const fullCustomerCreateHref = computed(() => router.resolve({ name: 'customer-new' }).href)

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
      applyPickedCustomer(customerRowFromResource(r))
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
  if (verifyLocked.value || holdForVerify.value) return
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
      const created = res.data as unknown as { id: string; applicationNo?: string; jobNo?: string; status?: string; customerYear?: number | null }
      draftId.value = created.id
      // Reflect the auto-run numbers the backend minted at draft creation.
      if (created.applicationNo) form.applicationNo = created.applicationNo
      if (created.jobNo) form.jobNo = created.jobNo
      // Seed the status dropdown from the freshly-created draft's status.
      if (!form.status && created.status) form.status = created.status
      applyServerState(res.data)
      flash.value = t('policyCreate.action.draftSaved')
      void router.replace({ name: 'policy-edit-draft', params: { id: created.id } })
    } else if (isDraftMode.value) {
      const res = await updateDraftPolicy(draftId.value, payload)
      applyServerState(res.data)
      if (takePending(res)) flash.value = 'ส่งคำขอให้ผู้ตรวจ Verify แล้ว'
    } else {
      // Editing a non-draft policy — use the general update endpoint.
      const res = await updatePolicy(draftId.value, payload)
      applyServerState(res.data)
      takePending(res)
    }
    if (error.value && renewalBlocked.value === null) error.value = null
  } catch (e) {
    flash.value = null
    reportSaveError(e)
  } finally {
    autosaving.value = false
    window.setTimeout(() => { flash.value = null }, 2000)
  }
}

function applyServerState(p: Policy): void {
  if (!yearsTouched.value) {
    if (p.policyYear && p.policyYear !== form.policyYear) form.policyYear = p.policyYear
    if (p.customerYear != null && p.customerYear !== form.customerYear) form.customerYear = p.customerYear
  }
  renewedFrom.value = p.renewedFrom ?? null
  renewedTo.value = p.renewedTo ?? null
  periodLabel.value = p.periodLabel ?? null
  if (p.verification) {
    pendingChange.value = p.verification.pending
    lastRejected.value = p.verification.lastRejected
  }
  if (form.delivered && form.deliveredDate && p.freelookEndDate && p.freelookEndDate !== form.freelookEndDate) {
    form.freelookEndDate = p.freelookEndDate
  }
}

async function saveFreelookDoc(field: 'sentAt' | 'signedAt' | 'returnedAt', e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  if (!draftId.value) return
  const value = input.value || null
  try {
    await updateFreelookDocs(draftId.value, { [field]: value })
    freelookDocs[field] = value ?? ''
    flash.value = 'บันทึกวันที่เอกสาร Freelook แล้ว'
    window.setTimeout(() => { flash.value = null }, 2000)
  } catch (err) {
    input.value = freelookDocs[field]
    reportSaveError(err)
  }
}

async function submitDeleteRequest(): Promise<void> {
  if (!draftId.value || !deleteReason.value.trim()) return
  saving.value = true
  error.value = null
  try {
    const res = await requestPolicyDelete(draftId.value, deleteReason.value.trim())
    takePending(res)
    showDeleteRequest.value = false
    deleteReason.value = ''
    flash.value = 'ส่งคำขอลบให้ผู้ตรวจ Verify แล้ว'
    window.setTimeout(() => { flash.value = null }, 2500)
  } catch (e) {
    reportSaveError(e)
  } finally {
    saving.value = false
  }
}

function reportSaveError(e: unknown): void {
  if (e instanceof ApiError) {
    const body = (e.body ?? {}) as Partial<RenewalBlockedError> & { message?: string }
    if (e.status === 422 && body.code && ['already_renewed', 'source_not_renewable', 'source_not_found', 'self_renewal', 'renewal_cycle'].includes(body.code)) {
      renewalBlocked.value = body as RenewalBlockedError
      return
    }
    error.value = body.message ?? e.message
    return
  }
  error.value = e instanceof Error ? e.message : String(e)
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
    // Manual status — sent once the policy exists (draft or beyond) so the
    // status dropdown works everywhere. Not sent on the very first create.
    ...(draftId.value && form.status ? { status: form.status } : {}),
    refAppToId: form.refAppToId || null,
    applicationNo: form.applicationNo || null,
    jobNo: form.jobNo || null,
    policyNo: form.policyNo || null,
    notionNo: form.notionNo || null,
    appDate: form.appDate || null,
    effectiveDate: form.effectiveDate || null,
    expiryDate: form.expiryDate || null,
    ...(canEditYears.value && yearsTouched.value
      ? { yearsOverride: true, policyYear: form.policyYear || 1, ...(form.customerYear ? { customerYear: form.customerYear } : {}) }
      : {}),
    actYear: form.actYear || 1,
    coverage: form.coverage || 0,
    discountAmount: form.discountAmount || 0,
    netPremium: form.netPremium || 0,
    // เบี้ยรวม (main premium the operator entered) persists to main_premium.
    mainPremium: form.grossPremiumInput || form.mainPremium || 0,
    dutyStamp: form.dutyStamp || 0,
    vat: form.vat || 0,
    compulsoryPremium: kind.value === 'motor' ? (form.compulsoryPremium || 0) : 0,
    totalPremiumPaid: form.totalPremiumPaid || 0,
    whtAmt: form.whtAmt || 0,
    netCustomerPaid: form.netCustomerPaid || 0,
    annualPremium: form.annualPremium || 0,
    premiumMode: form.premiumMode,
    installmentTerm: form.installmentTerm || null,
    installmentMode: form.installmentMode || null,
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
    // การรับกรมธรรม์ (received from carrier) — when the "done" box is off we
    // clear the date so the status is unambiguous.
    approvedDate: form.approved ? (form.approvedDate || null) : null,
    approvalRef: form.approved ? (form.approvalRef || null) : null,
    approvalNote: form.approved ? (form.approvalNote || null) : null,
    receivedDate: form.received ? (form.receivedDate || null) : null,
    receivedNote: form.receivedNote || null,
    // การจัดส่ง (delivered to customer) — reuses the mailing_* columns.
    shippedDate: form.delivered ? (form.shippedDate || null) : null,
    mailingNote: form.mailingNote || null,
    deliveryMethod: form.delivered ? (form.deliveryMethod || null) : null,
    courier: form.delivered ? (form.courier || null) : null,
    trackingNo: form.delivered ? (form.trackingNo || null) : null,
    deliveredDate: form.delivered ? (form.deliveredDate || null) : null,
    deliveredTo: form.delivered ? (form.deliveredTo || null) : null,
    // Free Look date (life products). Empty until recorded.
    freelookEndDate: form.freelookEndDate || null,
    salesChannel: form.salesChannel || 'face_to_face',
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

async function ensureDraftForPayment(): Promise<void> {
  if (draftId.value) return
  if (!form.customerId) throw new Error('customer required')
  await saveDraftNow()
  if (!draftId.value) throw new Error('draft not created')
}

async function onPaymentsChanged(): Promise<void> {
  if (!draftId.value) return
  try {
    const res = await fetchPolicy(draftId.value)
    const status = String(res.data.status ?? '')
    hydrating.value = true
    if (status) {
      form.status = status
      loadedStatus.value = status
    }
    applyServerState(res.data)
    window.setTimeout(() => { hydrating.value = false }, 100)
  } catch {
    flash.value = 'บันทึกการชำระเงินแล้ว แต่รีเฟรชสถานะกรมธรรม์ไม่สำเร็จ กรุณากดโหลดหน้าใหม่'
    window.setTimeout(() => { flash.value = null }, 4000)
  }
}

async function saveDraftNow(): Promise<void> {
  // Ensure any pending autosave writes first, then acknowledge with a
  // toast; stay on the page so the operator can keep editing.
  window.clearTimeout(autosaveTimer)
  await doAutosave()
  flash.value = t('policyCreate.action.draftSaved')
  window.setTimeout(() => { flash.value = null }, 2000)
}

/** Save a NON-draft policy edit via the general update endpoint, then return
 *  to the list. Flushes any pending autosave first. */
async function savePolicyNow(): Promise<void> {
  if (saving.value || !draftId.value) return
  saving.value = true
  error.value = null
  try {
    window.clearTimeout(autosaveTimer)
    const res = await updatePolicy(draftId.value, buildDraftPayload())
    if (takePending(res)) {
      applyServerState(res.data)
      flash.value = 'ส่งข้อมูลสำคัญที่แก้ให้ผู้ตรวจ Verify แล้ว'
      window.setTimeout(() => { flash.value = null }, 3000)
      return
    }
    await router.push({ name: 'policies' })
  } catch (e) {
    error.value = e instanceof ApiError ? (e.body as { message?: string })?.message ?? e.message : (e instanceof Error ? e.message : 'Save failed.')
  } finally {
    saving.value = false
  }
}

/** ต่ออายุ — open a fresh renewal draft prefilled from THIS policy. */
function startRenewal(): void {
  if (!draftId.value) return
  if (renewedTo.value?.live) {
    openPolicy(renewedTo.value.id)
    return
  }
  void router.push({ name: 'policy-new', query: { renewFrom: draftId.value } })
}

function openPolicy(id: string): void {
  void router.push({ name: 'policy-edit-draft', params: { id } })
}

async function applyRenewalSource(sourceId: string): Promise<void> {
  renewalBlocked.value = null
  error.value = null
  renewalLoading.value = true
  try {
    const res = await fetchRenewalDraft(sourceId, draftId.value)
    renewalMeta.value = res.meta
    renewalConfirmed.value = false
    yearsTouched.value = false
    await hydrateFromDraft(sourceId, true, res.data as unknown as Record<string, unknown>)
  } catch (e) {
    reportSaveError(e)
  } finally {
    renewalLoading.value = false
  }
}

function onRenewalSourcePicked(row: PolicyListRow): void {
  showRenewalSearch.value = false
  void applyRenewalSource(row.id)
}

async function loadRenewalMetaForDraft(sourceId: string): Promise<void> {
  try {
    renewalMeta.value = (await fetchRenewalDraft(sourceId, draftId.value)).meta
  } catch {
    renewalMeta.value = null
  }
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
  if (isRenewalInProgress.value && !renewalConfirmed.value) {
    error.value = t('policyCreate.renewal.confirmRequired')
    document.getElementById('renewal-confirm')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    return
  }
  saving.value = true
  error.value = null
  try {
    if (!draftId.value) {
      await doAutosave()
    }
    if (!draftId.value) throw new Error('Draft save failed — cannot submit.')
    const res = await promotePolicyToSubmitted(draftId.value, isRenewalInProgress.value ? { renewalTermsConfirmed: renewalConfirmed.value } : {})
    if (takePending(res)) {
      applyServerState(res.data)
      flash.value = 'ส่งคำขอให้ผู้ตรวจ Verify แล้ว'
      window.setTimeout(() => { flash.value = null }, 3000)
      return
    }
    await router.push({ name: 'policies' })
  } catch (e) {
    reportSaveError(e)
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
 *  short-circuiting scheduleAutosave() when it's set. (`hydrating` itself
 *  is declared near the top — the premium-mode watchers read it at setup.)
 */

/** @param renewMode when true, prefill a NEW renewal draft from this policy:
 *  keep the party/product/premium/risk, but drop the identifiers + dates and
 *  set the renewal context (newOrRenew='renew', refAppToId=<source>). */
async function hydrateFromDraft(id: string, renewMode = false, preset?: Record<string, unknown>): Promise<void> {
  hydrating.value = true
  error.value = null
  try {
    const p = preset ?? (await fetchPolicy(id)).data as unknown as Record<string, unknown>
    const keepExistingDraft = renewMode && !!draftId.value

    if (renewMode) {
      const src = renewalMeta.value?.source
      renewSource.value = {
        policyYear: Number(src?.policyYear ?? 1) || 1,
        customerYear: src?.customerYear ?? null,
        productId: String(src?.productId ?? p.productId ?? ''),
      }
      if (!keepExistingDraft) loadedStatus.value = null
    } else {
      // Remember the loaded status so autosave picks the right save endpoint
      // (draft → /draft, otherwise → general PATCH /policies/{id}).
      loadedStatus.value = (p.status as string | null) ?? null
    }
    customerFirstPolicyDate.value = (p.customerFirstPolicyDate as string | null) ?? null
    // Manual status edit value (edit mode only; renewals start as a fresh draft).
    form.status = renewMode ? (keepExistingDraft ? form.status : '') : String(p.status ?? '')

    // Scalars — same field names as buildDraftPayload emits.
    form.customerId = String(p.customerId ?? '')
    form.writingAgentId = String(p.writingAgentId ?? '')
    form.productId = String(p.productId ?? '')
    form.carrierId = String(p.carrierId ?? '')
    form.newOrRenew = renewMode ? 'renew' : ((p.newOrRenew as 'new' | 'renew' | null) ?? 'new')
    form.refAppToId = String(p.refAppToId ?? (renewMode ? id : ''))
    form.applicationNo = keepExistingDraft ? form.applicationNo : String(p.applicationNo ?? '')
    form.jobNo = keepExistingDraft ? form.jobNo : String(p.jobNo ?? '')
    form.policyNo = String(p.policyNo ?? '')
    form.notionNo = String(p.notionNo ?? '')
    form.appDate = String(p.appDate ?? '')
    form.effectiveDate = String(p.effectiveDate ?? '')
    form.expiryDate = String(p.expiryDate ?? '')
    form.coverage = Number(p.coverage ?? 0)
    form.policyYear = Number(p.policyYear ?? 1)
    form.customerYear = (p.customerYear as number | null) ?? null
    renewedFrom.value = (p.renewedFrom as PolicyChainLink | null | undefined) ?? null
    renewedTo.value = (p.renewedTo as typeof renewedTo.value | undefined) ?? null
    periodLabel.value = (p.periodLabel as string | null | undefined) ?? null
    yearsTouched.value = false
    form.actYear = Number(p.actYear ?? 1)
    form.annualPremium = Number(p.annualPremium ?? 0)
    form.premiumMode = (p.premiumMode as typeof form.premiumMode) ?? 'annual'
    form.notes = String(p.notes ?? '')

    // การรับกรมธรรม์และจัดส่ง — cleared on renewal (fresh document lifecycle).
    // The "done" checkbox is derived from whether a date is present.
    const approvedDate = renewMode ? '' : String(p.approvedDate ?? '')
    form.approvedDate = approvedDate
    form.approved = !!approvedDate
    form.approvalRef = renewMode ? '' : String(p.approvalRef ?? '')
    form.approvalNote = renewMode ? '' : String(p.approvalNote ?? '')
    const receivedDate = renewMode ? '' : String(p.receivedDate ?? '')
    form.receivedDate = receivedDate
    form.received = !!receivedDate
    form.receivedNote = renewMode ? '' : String(p.receivedNote ?? '')
    const shippedDate = renewMode ? '' : String(p.shippedDate ?? p.mailingDate ?? '')
    form.shippedDate = shippedDate
    form.delivered = !!shippedDate
    form.deliveryMethod = renewMode ? '' : String(p.deliveryMethod ?? '')
    form.courier = renewMode ? '' : String(p.courier ?? '')
    form.trackingNo = renewMode ? '' : String(p.trackingNo ?? '')
    form.deliveredDate = renewMode ? '' : String(p.deliveredDate ?? '')
    form.deliveredTo = renewMode ? '' : String(p.deliveredTo ?? '')
    // Free Look date — cleared on renewal (fresh lifecycle).
    form.freelookEndDate = renewMode ? '' : String(p.freelookEndDate ?? '')
    form.salesChannel = String(p.salesChannel ?? 'face_to_face')
    freelookDocs.sentAt = renewMode ? '' : String(p.freelookDocSentAt ?? '')
    freelookDocs.signedAt = renewMode ? '' : String(p.freelookDocSignedAt ?? '')
    freelookDocs.returnedAt = renewMode ? '' : String(p.freelookDocReturnedAt ?? '')
    const ver = p.verification as { pending: VerifyRow | null; lastRejected: VerifyRow | null } | undefined
    pendingChange.value = renewMode || !ver ? null : ver.pending
    lastRejected.value = renewMode || !ver ? null : ver.lastRejected
    form.mailingNote = renewMode ? '' : String(p.mailingNote ?? '')

    // สลักหลังเบี้ยเพิ่ม — outstanding endorsement premium (cleared on renewal,
    // a fresh policy year starts with no carried-over endorsement charge).
    endorsementPremiumTotal.value = renewMode ? 0 : Number(p.endorsementPremiumTotal ?? 0)

    // PolicyResource nests premium/installment/wht fields; read them from the
    // nested blocks (with a flat fallback for any older draft-shaped response).
    // Previously these read flat top-level keys the resource doesn't emit, so
    // they never hydrated on edit-draft.
    const premium = (p.premium ?? {}) as Record<string, number | null>
    const installment = (p.installment ?? {}) as Record<string, number | string | null>
    const wht = (p.wht ?? {}) as Record<string, number | null>

    form.netPremium = Number(premium.net ?? p.netPremium ?? 0)
    form.mainPremium = Number(premium.main ?? p.mainPremium ?? 0)
    // Restore เบี้ยรวม (grossPremiumInput) from the persisted main premium.
    form.grossPremiumInput = form.mainPremium
    form.dutyStamp = Number(premium.dutyStamp ?? p.dutyStamp ?? 0)
    form.vat = Number(premium.vat ?? p.vat ?? 0)
    form.compulsoryPremium = Number(premium.compulsory ?? p.compulsoryPremium ?? 0)
    form.installmentMode = (installment.mode as string) ?? (p.installmentMode as string) ?? ''
    form.totalPremiumPaid = Number(premium.totalPaid ?? p.totalPremiumPaid ?? 0)
    form.netCustomerPaid = Number(premium.netCustomerPaid ?? p.netCustomerPaid ?? 0)
    form.whtAmt = Number(wht.amount ?? p.whtAmt ?? 0)
    form.discountAmount = Number(installment.discountAmount ?? p.discountAmount ?? 0)

    form.installmentTerm = String(installment.term ?? p.installmentTerm ?? '')
    form.installmentCount = Number(form.installmentTerm) || INSTALLMENTS_BY_MODE[form.premiumMode] || 1
    form.firstDueInst = Number(installment.firstDueAmount ?? p.firstDueInst ?? 0)
    form.firstDueInstDate = String(installment.firstDueDate ?? p.firstDueInstDate ?? '')
    form.nextDueInst = Number(installment.nextDueAmount ?? p.nextDueInst ?? 0)
    form.lastDueInstDate = String(installment.lastDueDate ?? p.lastDueInstDate ?? '')

    // C-20: frozen commission (read-only display in the premium section).
    const cs = p.commissionSnapshot as { hubToAgentRate?: number | null; scheme?: string | null; capturedAt?: string | null; frozen?: boolean } | undefined
    snapshotCommission.value = cs?.frozen && !renewMode
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
      touched.commCarrierToHubRate = !renewMode
      touched.commCarrierToHubAmount = !renewMode
      touched.commHubToAgentRate = !renewMode
      touched.commHubToAgentAmount = !renewMode
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
          gender: String(c.gender ?? ''),
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

    // Load สลักหลัง history (skip on a renewal draft — it starts clean).
    if (!renewMode) void loadEndorsements()
    if (!renewMode && isRenewalInProgress.value) void loadRenewalMetaForDraft(form.refAppToId)
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
function initFromRoute(): void {
  void loadPolicyStatuses()
  void loadTenantRules()
  if (props.id) {
    draftId.value = props.id
    void hydrateFromDraft(props.id)
    return
  }
  // Renewal: /policies/new?renewFrom=<sourceId> — prefill a new draft from the
  // source policy (party/product/premium/risk), fresh identifiers + dates.
  const renewFrom = route.query.renewFrom
  if (typeof renewFrom === 'string' && renewFrom.trim() !== '') {
    void applyRenewalSource(renewFrom.trim())
  }
}

onMounted(initFromRoute)

// The wizard component is reused across its routes (new / edit-draft), so a
// navigation like edit-draft → new?renewFrom=<id> does NOT remount it and
// onMounted won't refire. Re-run init when the route identity changes, after
// reloading so the previous form is fully cleared.
watch(
  () => [props.id, route.query.renewFrom] as const,
  ([id]) => {
    if (id && id === draftId.value) return
    window.location.reload()
  },
)

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
          <span>{{ isRenewingFromSource ? t('policyCreate.renewTitle') : (props.id ? (isDraftMode ? t('policyCreate.resumeDraft.title') : t('policyEdit.title')) : t('policyCreate.title')) }}</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900 font-mono">
          {{ form.applicationNo || (draftId ? `#${draftId}` : t('policyCreate.title')) }}
        </h1>
        <div class="mt-1 flex items-center gap-2 text-xs flex-wrap">
          <!-- Header shows the current status as a badge; edit it via the
               "สถานะกรมธรรม์" dropdown in the ผู้เอาประกัน section. -->
          <span :class="['inline-flex px-2 py-0.5 rounded', statusBadgeClass(((!isDraftMode && form.status) || loadedStatus || 'draft') as PolicyStatus)]">
            {{ t(`policies.status.${(!isDraftMode && form.status) || loadedStatus || 'draft'}`) }}
          </span>
          <!-- ปีของกรมธรรม์ / ปีของลูกค้า — stored values (task 1.4) -->
          <span v-if="form.policyYear" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-sky-50 text-sky-700 border border-sky-100">
            <i class="pi pi-calendar text-[10px]" /> {{ t('policyCreate.policyYearBadge', { n: form.policyYear }) }}
          </span>
          <span v-if="customerYearDisplay !== null" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-violet-50 text-violet-700 border border-violet-100">
            <i class="pi pi-user text-[10px]" /> {{ t('policyCreate.customerTenureBadge', { n: customerYearDisplay }) }}
          </span>
          <PolicyPeriodBadge v-if="periodLabel" :label="periodLabel" size="md" />
          <button v-if="renewedFrom" type="button" data-testid="renewed-from-link"
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded border border-slate-200 bg-white text-slate-600 hover:border-brand-300 hover:text-brand-700"
            @click="openPolicy(renewedFrom.id)">
            <i class="pi pi-arrow-left text-[10px]" />
            {{ t('policyCreate.renewal.renewedFrom') }}
            <span class="font-mono">{{ renewedFrom.policyNo || renewedFrom.applicationNo }}</span>
            <PolicyPeriodBadge :label="renewedFrom.periodLabel" />
          </button>
          <button v-if="renewedTo" type="button" data-testid="renewed-to-link"
            :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded border',
              renewedTo.live ? 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-400' : 'border-slate-200 bg-white text-slate-400 line-through']"
            @click="openPolicy(renewedTo.id)">
            {{ t('policyCreate.renewal.renewedTo') }}
            <span class="font-mono">{{ renewedTo.policyNo || renewedTo.applicationNo }}</span>
            <span class="no-underline">({{ t(`policies.status.${renewedTo.status}`) }})</span>
            <i class="pi pi-arrow-right text-[10px]" />
          </button>
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
        <!-- #13 — no save permission: view-only badge, no action buttons. -->
        <span v-if="!canSavePolicy" class="rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-700">
          <i class="pi pi-eye mr-1" />ดูข้อมูลเท่านั้น
        </span>
        <!-- Draft / create mode: the three draft → quotation → submit actions. -->
        <template v-if="isDraftMode">
          <button v-if="canSavePolicy" type="button" @click="saveDraftNow" :disabled="saving || !form.customerId"
            class="px-3 py-1.5 rounded-lg text-sm text-slate-700 border border-slate-200 hover:bg-slate-50 disabled:opacity-50">
            <i class="pi pi-save text-xs mr-1" /> {{ t('policyCreate.action.saveDraft') }}
          </button>
          <button v-if="canSavePolicy" type="button" @click="saveAsQuotation" :disabled="saving || !form.customerId || !form.productId"
            class="px-3 py-1.5 rounded-lg text-sm bg-slate-700 text-white hover:bg-slate-800 disabled:opacity-50">
            <i class="pi pi-file text-xs mr-1" /> {{ t('policyCreate.action.saveQuotation') }}
          </button>
          <button v-if="canSavePolicy" type="button" @click="submitToCarrier" :disabled="saving || verifyLocked"
            class="px-3 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
            <i class="pi pi-send text-xs mr-1" /> {{ t('policyCreate.action.submitToCarrier') }}
          </button>
        </template>
        <!-- Editing a non-draft policy: renew (pull data forward) + save. -->
        <template v-else>
          <button type="button" @click="startRenewal" :disabled="saving"
            class="px-3 py-1.5 rounded-lg text-sm text-brand-700 border border-brand-300 hover:bg-brand-50 disabled:opacity-50">
            <i class="pi pi-replay text-xs mr-1" />
            {{ renewedTo?.live ? t('policyCreate.renewal.openRenewal') : t('policyCreate.action.renew') }}
          </button>
          <button v-if="verifyOn && !pendingChange" type="button" data-testid="request-delete" @click="showDeleteRequest = true" :disabled="saving"
            class="px-3 py-1.5 rounded-lg text-sm text-rose-600 border border-rose-200 hover:bg-rose-50 disabled:opacity-50">
            <i class="pi pi-trash text-xs mr-1" /> ขอลบ
          </button>
          <button v-if="canSavePolicy" type="button" data-testid="save-policy" @click="savePolicyNow" :disabled="saving || verifyLocked"
            class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
            <i :class="['pi text-xs mr-1', holdForVerify ? 'pi-send' : 'pi-check']" /> {{ holdForVerify ? 'บันทึก / ส่งตรวจ' : t('policyCreate.action.save') }}
          </button>
        </template>
      </div>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <div v-if="renewalBlocked" data-testid="renewal-blocked"
      class="card p-4 bg-amber-50 border border-amber-300 text-amber-900 text-sm flex items-start gap-3">
      <i class="pi pi-exclamation-triangle mt-0.5 text-amber-600" />
      <div class="flex-1 space-y-2">
        <div class="font-medium">{{ t('policyCreate.renewal.blockedTitle') }}</div>
        <div>{{ renewalBlocked.message }}</div>
        <div class="flex flex-wrap gap-2">
          <button v-if="renewalBlocked.existingRenewal" type="button"
            class="px-3 py-1 rounded-lg bg-amber-600 text-white text-xs hover:bg-amber-700"
            @click="openPolicy(renewalBlocked.existingRenewal.id)">
            {{ t('policyCreate.renewal.openExisting', { no: renewalBlocked.existingRenewal.applicationNo || renewalBlocked.existingRenewal.policyNo || `#${renewalBlocked.existingRenewal.id}` }) }}
          </button>
          <button type="button" class="px-3 py-1 rounded-lg border border-amber-400 text-xs hover:bg-amber-100"
            @click="renewalBlocked = null; showRenewalSearch = true">
            {{ t('policyCreate.renewal.pickAnother') }}
          </button>
        </div>
      </div>
    </div>

    <RenewalSourceSearchModal :open="showRenewalSearch" @close="showRenewalSearch = false" @pick="onRenewalSourcePicked" />

    <div v-if="pendingChange" data-testid="verify-pending-banner"
      class="card p-4 bg-amber-50 border border-amber-300 text-amber-900 text-sm flex items-start gap-3">
      <i class="pi pi-hourglass mt-0.5 text-amber-600" />
      <div class="flex-1">
        <div class="font-medium">{{ pendingChange.actionLabel }} · รอตรวจสอบ (Verify)</div>
        <div class="text-xs mt-0.5">
          ส่งโดย {{ pendingChange.makerName || '—' }} · {{ fmtDate(pendingChange.createdAt) }}
          <template v-if="pendingChange.fieldCount"> · {{ pendingChange.fieldCount }} ช่อง</template>
        </div>
        <div class="text-xs mt-1">
          {{ verifyLocked ? 'แก้ไขกรมธรรม์นี้ไม่ได้จนกว่าผู้ตรวจจะอนุมัติหรือไม่อนุมัติ' : 'ข้อมูลสำคัญยังเป็นค่าเดิมจนกว่าผู้ตรวจอนุมัติ ช่องอื่นแก้และบันทึกได้ตามปกติ' }}
        </div>
      </div>
      <RouterLink :to="{ name: 'policy-verification', params: { id: pendingChange.id } }"
        class="px-3 py-1 rounded-lg bg-amber-600 text-white text-xs hover:bg-amber-700">ดูคำขอ</RouterLink>
    </div>
    <div v-else-if="lastRejected" data-testid="verify-rejected-banner"
      class="card p-4 bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-start gap-3">
      <i class="pi pi-times-circle mt-0.5" />
      <div class="flex-1">
        <div class="font-medium">คำขอ{{ lastRejected.actionLabel }}ล่าสุดไม่ผ่านการตรวจสอบ</div>
        <div class="text-xs mt-0.5">โดย {{ lastRejected.checkerName || '—' }} · {{ fmtDate(lastRejected.decidedAt) }}</div>
        <div class="text-xs mt-1">เหตุผล: <b>{{ lastRejected.reason }}</b> · แก้ไขแล้วส่งใหม่ได้</div>
      </div>
      <RouterLink :to="{ name: 'policy-verification', params: { id: lastRejected.id } }"
        class="px-3 py-1 rounded-lg border border-rose-300 text-xs hover:bg-rose-100">ดูรายละเอียด</RouterLink>
    </div>
    <div v-else-if="holdForVerify" data-testid="verify-hold-note" class="card p-3 bg-sky-50 border border-sky-200 text-sky-800 text-xs flex items-center gap-2">
      <i class="pi pi-verified" />
      หน่วยงานเปิดการตรวจสอบ 2 ชั้น: การแก้ข้อมูลสำคัญ (ลูกค้า ตัวแทน บริษัท ทุน เบี้ย ค่าคอม วันที่ สถานะ) จะส่งให้อีกคนตรวจก่อนมีผล หน้านี้ไม่บันทึกอัตโนมัติ กด "บันทึก / ส่งตรวจ"
    </div>

    <div v-if="showDeleteRequest" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" data-testid="delete-request-dialog" @click.self="showDeleteRequest = false">
      <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="text-base font-semibold text-slate-900">ขอลบกรมธรรม์</h3>
        <p class="mt-1 text-xs text-slate-500">คำขอลบจะส่งให้ผู้ตรวจ Verify ก่อน กรมธรรม์จะถูกลบเมื่ออนุมัติเท่านั้น</p>
        <label class="mt-3 block text-xs text-slate-600">เหตุผลที่ขอลบ
          <textarea v-model="deleteReason" rows="3" data-testid="delete-reason" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-sm" />
        </label>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100" @click="showDeleteRequest = false">ยกเลิก</button>
          <button type="button" data-testid="delete-request-submit" :disabled="saving || !deleteReason.trim()"
            class="px-3 py-1.5 rounded-lg text-sm bg-rose-600 text-white hover:bg-rose-700 disabled:opacity-50" @click="submitDeleteRequest">ส่งคำขอลบ</button>
        </div>
      </div>
    </div>

    <fieldset :disabled="verifyLocked" class="min-w-0 space-y-6" data-testid="policy-form">
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
          <div v-if="isDraftMode" class="mt-2">
            <div v-if="isRenewalInProgress && renewedFrom" data-testid="renewal-source-card"
              class="flex items-center justify-between gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs">
              <div class="min-w-0">
                <div class="text-teal-800 font-medium">{{ t('policyCreate.renewal.sourceLabel') }}</div>
                <div class="text-slate-600 truncate">
                  <span class="font-mono">{{ renewedFrom.policyNo || renewedFrom.applicationNo }}</span>
                  · {{ t('policyCreate.renewal.sourceExpiry', { date: renewedFrom.expiryDate || '—' }) }}
                  <PolicyPeriodBadge :label="renewedFrom.periodLabel" />
                </div>
              </div>
              <button type="button" class="shrink-0 text-teal-700 hover:underline" @click="showRenewalSearch = true">
                {{ t('policyCreate.renewal.change') }}
              </button>
            </div>
            <button v-else type="button" data-testid="renewal-search-button" :disabled="renewalLoading"
              class="w-full inline-flex items-center justify-center gap-2 px-3 py-1.5 rounded-lg text-sm border border-dashed border-teal-400 text-teal-700 bg-white hover:bg-teal-50 disabled:opacity-50"
              @click="showRenewalSearch = true">
              <i :class="renewalLoading ? 'pi pi-spin pi-spinner' : 'pi pi-search'" class="text-xs" />
              {{ t('policyCreate.renewal.searchButton') }}
            </button>
          </div>
        </FormField>

        <!-- ปีของกรมธรรม์ / ปีของลูกค้า (task 1.4) — suggested on renewal,
             editable while draft/quotation; afterwards admin-only + audited. -->
        <div class="grid grid-cols-2 gap-4">
          <FormField :label="t('policyCreate.policyYearField')"
            :hint="canEditYears ? (yearsTouched ? t('policyCreate.renewal.yearsOverriding') : t('policyCreate.renewal.yearsAdminCanOverride')) : t('policyCreate.renewal.yearsServerComputed')">
            <input v-model.number="form.policyYear" type="number" min="1" max="99" step="1"
              :disabled="!canEditYears" @input="yearsTouched = true"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 disabled:bg-slate-50 disabled:text-slate-500" />
          </FormField>
          <FormField :label="t('policyCreate.customerYearField')">
            <input v-model.number="form.customerYear" type="number" min="1" max="99" step="1"
              :placeholder="customerYearDisplay != null ? String(customerYearDisplay) : ''"
              :disabled="!canEditYears" @input="yearsTouched = true"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 disabled:bg-slate-50 disabled:text-slate-500" />
          </FormField>
        </div>

        <!-- เลขที่ใบสมัคร (application_no) — auto-run by the backend at draft
             creation. Read-only; shows a hint until the first save mints it. -->
        <FormField :label="t('policyCreate.applicationNo')">
          <input :value="form.applicationNo || t('policyCreate.autoRunPending')" type="text" readonly
            :class="['w-full border rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none',
              form.applicationNo ? 'border-slate-200 bg-slate-50 text-slate-700' : 'border-slate-200 bg-slate-50 text-slate-400']" />
        </FormField>

        <!-- เลขงาน (job_no) — auto-run running work number. Read-only. -->
        <FormField :label="t('policyCreate.jobNo')">
          <input :value="form.jobNo || t('policyCreate.autoRunPending')" type="text" readonly
            :class="['w-full border rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none',
              form.jobNo ? 'border-slate-200 bg-slate-50 text-slate-700' : 'border-slate-200 bg-slate-50 text-slate-400']" />
        </FormField>

        <!-- เลขที่กรมธรรม์ (policy_no) — issued by the carrier later once the
             payment is made and the policy is approved. Editable. -->
        <FormField :label="t('policyCreate.policyNo')" :hint="t('policyCreate.policyNoHint')">
          <input v-model.trim="form.policyNo" type="text" maxlength="64"
            :placeholder="t('policyCreate.policyNoPlaceholder')"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-brand-400" />
        </FormField>

        <FormField :label="t('policyCreate.customer')" required>
          <!-- Quick link to create a customer when search comes up empty.
               Opens the full-page form in a new tab; the created customer is
               broadcast back here (customer:created) and auto-selected. -->
          <template #label-extra>
            <a :href="router.resolve({ name: 'customer-new' }).href" target="_blank" rel="noopener"
              class="ml-2 inline-flex items-center gap-1 text-xs text-brand-600 hover:text-brand-700 hover:underline">
              <i class="pi pi-plus text-[10px]" /> สร้างลูกค้าใหม่
            </a>
          </template>
          <EntityPicker
            v-model="form.customerId"
            :fetch="searchCustomers"
            :render-label="(r: CustomerListRow) => `${r.firstName} ${r.lastName}`.trim() || r.juristicName || r.customerCode"
            :render-primary="(r: CustomerListRow) => r.customerCode"
            :placeholder="t('policyCreate.customerPlaceholder')"
            icon-class="pi-user"
            :initial-label="customerPicked ? (`${customerPicked.firstName} ${customerPicked.lastName}`.trim() || customerPicked.juristicName || customerPicked.customerCode) : ''"
            :new-label="t('customers.ui.newCustomer')"
            @picked="(r) => { customerPicked = r as CustomerListRow | null; defaultAgentFromCustomer(r as CustomerListRow | null) }"
            @new-click="openCustomerCreate"
          />
          <div class="mt-1 flex items-center gap-3 text-[11px]">
            <button type="button" class="text-brand-600 hover:underline" data-testid="wizard-new-customer" @click="openCustomerCreate('')">
              <i class="pi pi-user-plus text-[10px]" /> {{ t('customers.ui.newCustomer') }}
            </button>
            <a :href="fullCustomerCreateHref" target="_blank" rel="noopener" class="text-slate-400 hover:text-slate-600 hover:underline">
              {{ t('customers.ui.openFullCreate') }} <i class="pi pi-external-link text-[9px]" />
            </a>
          </div>
          <CustomerCreateModal
            :open="showCustomerCreate"
            :initial="customerCreateInitial"
            @close="showCustomerCreate = false"
            @created="onCustomerCreated"
            @open-existing="onCustomerOpenExisting"
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

        <!-- #39 — สถานะกรมธรรม์ moved to the bottom of the section so the
             data-entry flow runs identity → customer → agent → status without
             the status dropdown interrupting mid-grid. Editable once the
             policy exists (draft or beyond); options from policy_statuses. -->
        <FormField v-if="draftId" :label="t('policyCreate.statusLabel')" :hint="t('policyCreate.statusHint')">
          <select v-model="form.status"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option v-for="s in statusOptions" :key="s.code" :value="s.code">{{ s.nameTh }}</option>
          </select>
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
        <FormField :label="t('policyCreate.effectiveDate')" required :class="needsConfirm('effectiveDate') ? confirmRing : ''">
          <DateInput v-model="form.effectiveDate" />
        </FormField>
        <FormField :label="t('policyCreate.expiryDate')" required :class="needsConfirm('expiryDate') ? confirmRing : ''">
          <DateInput
            v-model="form.expiryDate"
            @update:model-value="() => { touched.expiryDate = true }"
            :min="form.effectiveDate || null"
          />
        </FormField>
        <FormField :label="t('policyCreate.coverage')" :class="needsConfirm('coverage') ? confirmRing : ''">
          <MoneyInput v-model="form.coverage"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
        </FormField>
      </div>

      <!-- เบี้ยรวม = เบี้ยหลัก (main premium) ที่ operator กรอก. เป็น gross ที่
           สูตรภาษีใช้ back-solve; สำหรับประกันชีวิตสูตรจะบวกเบี้ย Rider เพิ่ม.
           ส่วนลดย้ายไปอยู่ Section เบี้ย + การชำระ. -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
        <FormField :label="t('policyCreate.totalPremium')" :class="needsConfirm('grossPremiumInput') ? confirmRing : ''">
          <MoneyInput v-model="form.grossPremiumInput"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
          <p class="text-[10px] text-slate-500 mt-1">
            <i class="pi pi-info-circle mr-1" />{{ t('policyCreate.totalPremiumHint') }}
          </p>
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

        <!-- #38 — สัญญาเพิ่มเติม (Riders): เพิ่มได้เฉพาะประกันชีวิตเท่านั้น.
             Riders are a life-insurance concept; non-life / tax products
             don't carry them, so the whole block is gated on insureType. -->
        <div v-if="form.insureType === 'Life'" class="mt-4">
          <div class="flex items-center justify-between mb-2">
            <h4 class="text-xs font-medium text-slate-600">
              {{ t('policyCreate.riders.title') }}
              <span class="text-slate-400 font-normal ml-1">({{ riderRows.length }})</span>
            </h4>
            <button type="button" class="text-xs text-brand-600 hover:text-brand-700 flex items-center gap-1" @click="addRiderRow">
              <i class="pi pi-plus text-[10px]" /> {{ t('policyCreate.riders.add') }}
            </button>
          </div>
          <div v-if="riderRows.length" class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="text-[10px] text-slate-400 uppercase">
                <tr>
                  <th class="text-left px-2 py-1 w-8">#</th>
                  <th class="text-left px-2 py-1">{{ t('policyCreate.riders.name') }}</th>
                  <th class="text-right px-2 py-1 w-32">{{ t('policyCreate.riders.premium') }}</th>
                  <th class="text-left px-2 py-1">{{ t('policyCreate.riders.notes') }}</th>
                  <th class="w-10"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="(_r, idx) in riderRows" :key="idx">
                  <td class="px-2 py-1 text-slate-400 text-xs">{{ idx + 1 }}</td>
                  <td class="px-2 py-1">
                    <!-- #42 — searchable dropdown of this carrier's Life riders.
                         Falls back to the picked name so existing free-text
                         rows still render; emits the full product row. -->
                    <ProductSearchSelect
                      :model-value="riderCode(idx) || (riderField(idx, 'name') as string)"
                      :carrier-id="form.carrierId || null"
                      insure-type="life"
                      main-rider="Rider"
                      placeholder="ค้นหาสัญญาเพิ่มเติม…"
                      :disabled="!form.carrierId"
                      disabled-hint="เลือกบริษัท/สินค้าหลักก่อน"
                      @select="(row) => pickRider(idx, row)"
                      @update:model-value="(v) => { if (!v) pickRider(idx, null) }"
                    />
                  </td>
                  <td class="px-2 py-1">
                    <input :value="riderField(idx, 'premium')" @input="setRiderField(idx, 'premium', ($event.target as HTMLInputElement).value)"
                      type="number" step="0.01" class="w-full border border-slate-200 rounded px-2 py-1 text-right font-mono text-xs" />
                  </td>
                  <td class="px-2 py-1">
                    <input :value="riderField(idx, 'notes')" @input="setRiderField(idx, 'notes', ($event.target as HTMLInputElement).value)"
                      class="w-full border border-slate-200 rounded px-2 py-1" />
                  </td>
                  <td class="px-2 py-1 text-right">
                    <button type="button" class="text-rose-500 hover:text-rose-700 p-1" @click="removeRiderRow(idx)">
                      <i class="pi pi-trash text-xs" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="text-xs text-slate-400 border border-dashed border-slate-200 rounded-lg px-3 py-3 text-center">
            ยังไม่มีสัญญาเพิ่มเติม — กด “เพิ่ม”
          </div>
        </div>
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

        <div v-if="needsConfirm('risk.license_no') && kind === 'motor'"
          class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
          <i class="pi pi-car mr-1" />{{ t('policyCreate.renewal.checkVehicle') }}
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

      <div v-if="isRenewalInProgress" id="renewal-confirm" data-testid="renewal-confirm"
        :class="['mb-4 rounded-lg border p-3 text-sm', renewalConfirmed ? 'border-emerald-200 bg-emerald-50' : 'border-amber-300 bg-amber-50']">
        <div v-if="renewalMeta?.quoteUsed && renewalMeta.quote" class="mb-2 text-amber-900">
          <i class="pi pi-file-check mr-1" />
          {{ t('policyCreate.renewal.quoteUsed', { amount: renewalMeta.quote.annualPremium.toLocaleString() }) }}
          <span v-if="renewalMeta.needsPremiumFormula" class="block text-xs text-amber-700 mt-0.5">{{ t('policyCreate.renewal.runFormula') }}</span>
        </div>
        <div v-else class="mb-2 text-amber-900 text-xs">
          <i class="pi pi-info-circle mr-1" />{{ t('policyCreate.renewal.copiedFromSource') }}
        </div>
        <label class="flex items-start gap-2 cursor-pointer select-none">
          <input v-model="renewalConfirmed" type="checkbox" class="mt-0.5" data-testid="renewal-confirm-checkbox" />
          <span :class="renewalConfirmed ? 'text-emerald-800' : 'text-amber-900 font-medium'">{{ t('policyCreate.renewal.confirmLabel') }}</span>
        </label>
      </div>

      <!-- ── Group A: เบี้ยประกัน (formulas on top) ─────────────────────── -->
      <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">{{ t('policyCreate.premiumGroup') }}</h3>

      <!-- สูตร: back-solve เบี้ยสุทธิ/อากร/VAT from เบี้ยรวม (gross). For life
           only สูตร 2 is shown; the life gross also includes rider premiums. -->
      <div class="mb-3">
        <span class="text-[10px] uppercase tracking-wider text-slate-400 mr-2">{{ t('policyCreate.taxFormula') }}</span>
        <div class="flex flex-wrap gap-2 mt-1">
          <button v-for="n in visibleFormulas" :key="n" type="button" @click="runFormula(n)"
            class="px-3 py-1.5 text-sm rounded-lg border border-slate-200 bg-white hover:bg-brand-50 hover:border-brand-300 focus:outline-none focus:border-brand-400">
            {{ t(`policyCreate.formula${n}`) }}
          </button>
        </div>
        <p class="text-[10px] text-slate-500 mt-1">
          <i class="pi pi-info-circle mr-1" />{{ t('policyCreate.taxFormulaHint') }}
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <FormField :label="t('policyCreate.netPremium')" required :class="needsConfirm('netPremium') ? confirmRing : ''">
          <MoneyInput v-model="form.netPremium"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
        </FormField>
        <FormField :label="t('policyCreate.dutyStamp')" :class="needsConfirm('dutyStamp') ? confirmRing : ''">
          <MoneyInput v-model="form.dutyStamp" @update:model-value="touched.dutyStamp = true"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
        </FormField>
        <FormField :label="t('policyCreate.vat')" :class="needsConfirm('vat') ? confirmRing : ''">
          <MoneyInput v-model="form.vat" @update:model-value="touched.vat = true"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
        </FormField>
        <FormField :label="t('policyCreate.totalPremiumPaid')" :class="needsConfirm('totalPremiumPaid') ? confirmRing : ''">
          <MoneyInput v-model="form.totalPremiumPaid" @update:model-value="touched.totalPremiumPaid = true"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
        </FormField>
      </div>

      <p class="text-[10px] text-slate-500 mt-2">
        <i class="pi pi-info-circle mr-1" /> {{ t('policyCreate.autoRecalc') }}
      </p>

      <!-- ── Group B: ส่วนลดและหัก ณ ที่จ่าย ───────────────────────────── -->
      <h3 class="text-xs uppercase tracking-wider text-slate-400 mt-5 mb-2 pt-4 border-t border-slate-100">{{ t('policyCreate.discountWhtGroup') }}</h3>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <FormField :label="t('policyCreate.discountAmount')" :class="needsConfirm('discountAmount') ? confirmRing : ''">
          <MoneyInput v-model="form.discountAmount"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
        </FormField>
        <FormField :label="t('policyCreate.whtAmt')">
          <label class="flex items-center gap-1.5 text-xs text-slate-600 mb-1 cursor-pointer select-none">
            <input type="checkbox" v-model="whtEnabled" class="rounded border-slate-300 text-brand-600 focus:ring-brand-400" />
            หัก ณ ที่จ่าย (1%)
          </label>
          <MoneyInput v-model="form.whtAmt" :disabled="!whtEnabled" @update:model-value="touched.whtAmt = true"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 disabled:bg-slate-100 disabled:text-slate-400'" />
          <p class="text-[10px] text-slate-500 mt-1">
            <i class="pi pi-info-circle mr-1" />{{ t('policyCreate.whtHint') }}
          </p>
        </FormField>
        <FormField :label="t('policyCreate.netCustomerPaid')">
          <MoneyInput v-model="form.netCustomerPaid" @update:model-value="touched.netCustomerPaid = true"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
        </FormField>
      </div>

      <h3 class="text-xs uppercase tracking-wider text-slate-400 mt-4 mb-2">{{ t('policyCreate.installment') }}</h3>
      <!-- #43 — explain the งวด fields so "รายปี" doesn't read as an instalment. -->
      <p class="text-[10px] text-slate-500 mb-2">
        <i class="pi pi-info-circle mr-1" />เลือก "รายปี / จ่ายครั้งเดียว" = ชำระเต็มจำนวนครั้งเดียว (ไม่ใช่การผ่อน) ·
        เลือกความถี่รายเดือน/ราย 3 เดือน/ราย 6 เดือน หรือใส่จำนวนงวด &gt; 1 เพื่อผ่อนชำระ
      </p>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <FormField :label="t('policyCreate.premiumMode')">
          <select v-model="form.premiumMode"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option v-for="m in premiumModeChoices" :key="m" :value="m">
              {{ t(`policyCreate.premiumModes.${m}`) }}
            </option>
          </select>
        </FormField>
        <!-- จำนวนงวด — auto from frequency, editable. Prefills the payment modal. -->
        <FormField :label="t('policyCreate.installmentCount')" :hint="t('policyCreate.installmentCountHint')">
          <input v-model.number="form.installmentCount" type="number" min="1" max="60" step="1"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <!-- #43 — term + mode only matter for an actual instalment plan; hidden
             for รายปี / จ่ายครั้งเดียว to cut the confusion. -->
        <FormField v-if="hasInstallmentPlan" :label="t('policyCreate.installmentTerm')">
          <input v-model.trim="form.installmentTerm" type="text"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <!-- Installment mode (A/B/C) — who bears fee + interest. Drives the
             payment modal's schedule. -->
        <FormField v-if="hasInstallmentPlan" label="รูปแบบการผ่อน (Mode)">
          <select v-model="form.installmentMode"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">— ไม่ระบุ —</option>
            <option v-for="m in INSTALLMENT_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </FormField>
        <!-- พ.ร.บ. — motor products only (compulsory motor insurance); billed
             in full on งวด 1. Hidden for non-motor where it doesn't apply. -->
        <FormField v-if="kind === 'motor'" label="พ.ร.บ. (compulsory)">
          <MoneyInput v-model="form.compulsoryPremium"
            :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
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
            <tr v-for="yr in visibleVectorYears" :key="yr"
              :class="['border-t border-slate-100', yr === currentVectorYear ? 'bg-brand-50/40' : '']">
              <td class="py-1.5 pr-3 text-slate-600 whitespace-nowrap">
                {{ VECTOR_YEAR_LABELS[yr] }}
                <span v-if="yr === currentVectorYear" class="ml-1 text-[10px] text-brand-600">(ปีนี้)</span>
              </td>
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
        <!-- #45 — by default only the current policy year shows; expand for all 6. -->
        <button type="button" @click="showAllVectorYears = !showAllVectorYears"
          class="mt-1 text-xs text-brand-600 hover:text-brand-700 flex items-center gap-1">
          <i :class="['pi text-[10px]', showAllVectorYears ? 'pi-chevron-up' : 'pi-chevron-down']" />
          {{ showAllVectorYears ? 'ย่อ — แสดงเฉพาะปีปัจจุบัน' : 'แสดงค่าคอมทุกปี (6 ปี)' }}
        </button>
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
              <MoneyInput v-model="form.commCarrierToHubAmount" @update:model-value="touched.commCarrierToHubAmount = true"
                :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
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
              <MoneyInput v-model="form.commHubToAgentAmount" @update:model-value="touched.commHubToAgentAmount = true"
                :input-class="'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400'" />
            </FormField>
          </div>
        </div>
      </div>

      <!-- Per-rider commission (ค่าคอมมิชชั่น Inh % / Agent %) — moved here
           from the rider table in the สินค้า section. One row per rider. -->
      <div v-if="riderRows.length" class="mt-4 pt-4 border-t border-slate-100">
        <h4 class="text-xs font-medium text-slate-600 mb-2">{{ t('policyCreate.riderCommission') }}</h4>
        <div class="overflow-x-auto">
          <table class="text-sm border-collapse min-w-[420px]">
            <thead>
              <tr class="text-left text-[10px] text-slate-400">
                <th class="py-1 pr-3 font-normal">{{ t('policyCreate.riders.name') }}</th>
                <th class="py-1 px-3 font-normal">{{ t('policyCreate.riders.rateInh') }}</th>
                <th class="py-1 px-3 font-normal">{{ t('policyCreate.riders.rateAg') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(_row, idx) in riderRows" :key="idx" class="border-t border-slate-100">
                <td class="py-1.5 pr-3 text-slate-600 whitespace-nowrap max-w-[200px] truncate">{{ riderLabel(idx) }}</td>
                <td class="py-1.5 px-3">
                  <div class="relative w-24">
                    <input :value="riderRate(idx, 'rate_inh')"
                      @input="setRiderRate(idx, 'rate_inh', ($event.target as HTMLInputElement).value)"
                      type="number" min="0" max="100" step="0.01"
                      class="w-full border border-slate-200 rounded-md pl-2 pr-6 py-1 text-sm focus:outline-none focus:border-brand-400" />
                    <span class="absolute right-2 top-1 text-xs text-slate-400">%</span>
                  </div>
                </td>
                <td class="py-1.5 px-3">
                  <div class="relative w-24">
                    <input :value="riderRate(idx, 'rate_ag')"
                      @input="setRiderRate(idx, 'rate_ag', ($event.target as HTMLInputElement).value)"
                      type="number" min="0" max="100" step="0.01"
                      class="w-full border border-slate-200 rounded-md pl-2 pr-6 py-1 text-sm focus:outline-none focus:border-brand-400" />
                    <span class="absolute right-2 top-1 text-xs text-slate-400">%</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="text-[10px] text-slate-500 mt-2">
        <i class="pi pi-info-circle mr-1" /> {{ t('policyCreate.commissionHint') }}
      </p>
    </section>

    <PolicyPaymentPanel
      :policy-id="draftId"
      :disabled="!form.customerId"
      :expected="expectedPremium"
      :carrier-label="carrierLabel"
      :installment-count="Number(form.installmentCount) || 1"
      :frequency-label="t(`policyCreate.premiumModes.${form.premiumMode}`)"
      :main-premium="Number(form.mainPremium) || 0"
      :compulsory-premium="Number(form.compulsoryPremium) || 0"
      :installment-mode="form.installmentMode"
      :ensure-draft="ensureDraftForPayment"
      @changed="onPaymentsChanged"
    />

    <section v-if="canEndorse" class="card p-5" data-testid="endorsement-section">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="font-semibold text-slate-900">{{ t('endorsement.sectionTitle') }}</h2>
          <p class="text-xs text-slate-500 mt-0.5">{{ t('endorsement.sectionSubtitle') }}</p>
        </div>
        <button type="button" @click="openNewEndorsement" data-testid="endorsement-new"
          class="px-3 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 flex items-center gap-1.5">
          <i class="pi pi-file-edit text-xs" /> {{ t('endorsement.newButton') }}
          <span v-if="endorsementNextSeq" class="ml-1 px-1.5 rounded bg-white/20 text-[11px] font-semibold">${{ endorsementNextSeq }}</span>
        </button>
      </div>

      <div v-if="endorsements.length" class="mt-4 overflow-x-auto">
        <table class="w-full text-sm" data-testid="endorsement-table">
          <thead>
            <tr class="text-left text-xs text-slate-500 border-b border-slate-100">
              <th class="py-2 pr-3 w-16">{{ t('endorsement.col.seq') }}</th>
              <th class="py-2 pr-3">{{ t('endorsement.col.date') }}</th>
              <th class="py-2 pr-3">{{ t('endorsement.col.type') }}</th>
              <th class="py-2 pr-3">{{ t('endorsement.col.reason') }}</th>
              <th class="py-2 pr-3 text-right">{{ t('endorsement.col.premiumChange') }}</th>
              <th class="py-2 pr-3 text-right">{{ t('endorsement.col.coverageChange') }}</th>
              <th class="py-2 px-3 text-right">{{ t('endorsement.col.additional') }}</th>
              <th class="py-2 px-3">{{ t('endorsement.col.recordedBy') }}</th>
              <th class="py-2 pl-3 text-right w-20"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ev in endorsements" :key="ev.id" :data-testid="`endorsement-row-${ev.seq}`"
              :class="['border-b border-slate-50 align-top', ev.voidedAt ? 'bg-slate-50/70 text-slate-400' : '']">
              <td class="py-2 pr-3 whitespace-nowrap">
                <span :class="['inline-flex px-1.5 py-0.5 rounded font-semibold text-xs', ev.voidedAt ? 'bg-slate-100 text-slate-400 line-through' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-200']">
                  ${{ ev.seq ?? '?' }}
                </span>
              </td>
              <td :class="['py-2 pr-3 whitespace-nowrap', ev.voidedAt ? 'line-through' : 'text-slate-600']">
                {{ fmtDate(endorsementField(ev, 'effectiveDate')) || fmtDate(ev.occurredAt) || '—' }}
              </td>
              <td :class="['py-2 pr-3 whitespace-nowrap', ev.voidedAt ? 'line-through' : 'text-slate-700']">
                {{ endorsementTypeLabel(ev.type) }}
                <div v-if="ev.voidedAt" class="no-underline">
                  <span class="inline-flex mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-rose-50 text-rose-600 ring-1 ring-rose-200"
                    :title="t('endorsement.voidedBy', { name: ev.voidedByName ?? '—', reason: ev.voidReason ?? '' })">
                    {{ t('endorsement.voidedBadge') }}
                  </span>
                </div>
              </td>
              <td class="py-2 pr-3">
                <div :class="ev.voidedAt ? 'line-through' : 'text-slate-700'">{{ endorsementField(ev, 'reason') || endorsementField(ev, 'note') || '—' }}</div>
                <div v-if="endorsementField(ev, 'detail')" :class="['text-xs mt-0.5 whitespace-pre-line', ev.voidedAt ? 'line-through' : 'text-slate-500']">{{ endorsementField(ev, 'detail') }}</div>
                <div v-if="ev.voidedAt" class="text-[11px] text-rose-500 mt-0.5">{{ t('endorsement.voidedBy', { name: ev.voidedByName ?? '—', reason: ev.voidReason ?? '' }) }}</div>
              </td>
              <template v-if="ev.type === 'endorsement.premium_change' && endorsementSummary(ev)?.before">
                <td :class="['py-2 pr-3 text-right whitespace-nowrap', ev.voidedAt ? 'line-through' : '']">
                  <span class="text-slate-400">{{ fmtMoney(endorsementSummary(ev)!.before.annualPremium) }}</span>
                  <span class="text-slate-400 mx-1">→</span>
                  <span :class="ev.voidedAt ? '' : 'text-slate-800 font-medium'">{{ fmtMoney(endorsementSummary(ev)!.after.annualPremium) }}</span>
                </td>
                <td :class="['py-2 pr-3 text-right whitespace-nowrap', ev.voidedAt ? 'line-through' : 'text-slate-500']">
                  <template v-if="endorsementSummary(ev)!.after.coverage !== endorsementSummary(ev)!.before.coverage">
                    {{ fmtMoney(endorsementSummary(ev)!.before.coverage) }} → {{ fmtMoney(endorsementSummary(ev)!.after.coverage) }}
                  </template>
                  <template v-else>—</template>
                </td>
                <td :class="['py-2 px-3 text-right whitespace-nowrap font-medium', ev.voidedAt ? 'line-through' : 'text-brand-700']">
                  {{ fmtMoney(endorsementSummary(ev)!.additionalTotal) }}
                </td>
              </template>
              <template v-else>
                <td class="py-2 pr-3 text-right text-slate-300">—</td>
                <td class="py-2 pr-3 text-right text-slate-300">—</td>
                <td class="py-2 px-3 text-right text-slate-300">—</td>
              </template>
              <td class="py-2 px-3 whitespace-nowrap text-xs">
                <div :class="ev.voidedAt ? '' : 'text-slate-700'">{{ ev.byUserName || '—' }}</div>
                <div class="text-slate-400">{{ fmtDate(ev.occurredAt) }}</div>
              </td>
              <td class="py-2 pl-3 text-right whitespace-nowrap">
                <template v-if="!ev.voidedAt && canManageEndorsements">
                  <button type="button" @click="openEditEndorsement(ev)" :data-testid="`endorsement-edit-${ev.seq}`"
                    class="text-slate-400 hover:text-brand-600 px-1" :title="t('endorsement.edit')">
                    <i class="pi pi-pencil text-xs" />
                  </button>
                  <button type="button" @click="openVoidEndorsement(ev)" :data-testid="`endorsement-void-${ev.seq}`"
                    class="text-slate-400 hover:text-rose-600 px-1" :title="t('endorsement.void')">
                    <i class="pi pi-ban text-xs" />
                  </button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="mt-4 text-xs text-slate-400">{{ t('endorsement.empty') }}</p>
    </section>

    <!-- ── Section: การรับกรมธรรม์และจัดส่ง (received + delivery) ────────── -->
    <section class="card p-5">
      <h2 class="font-semibold text-slate-900">{{ t('policyCreate.fulfilment.title') }}</h2>
      <p class="text-xs text-slate-500 mt-0.5 mb-4">{{ t('policyCreate.fulfilment.subtitle') }}</p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="rounded-lg border border-slate-200 p-4">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" v-model="form.approved"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-400" />
            <span class="font-medium text-sm text-slate-800">{{ t('policyCreate.fulfilment.approvedDone') }}</span>
          </label>
          <div v-if="form.approved" class="mt-3 space-y-3">
            <div class="grid grid-cols-2 gap-3">
              <FormField :label="t('policyCreate.fulfilment.approvedDate')">
                <input type="date" v-model="form.approvedDate"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.fulfilment.approvalRef')">
                <input v-model.trim="form.approvalRef" type="text" maxlength="64"
                  :placeholder="t('policyCreate.fulfilment.approvalRefPlaceholder')"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-brand-400" />
              </FormField>
            </div>
            <FormField :label="t('policyCreate.fulfilment.note')">
              <textarea v-model="form.approvalNote" rows="2"
                :placeholder="t('policyCreate.fulfilment.approvalNotePlaceholder')"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </div>

        <!-- การได้รับกรมธรรม์ — received from carrier -->
        <div class="rounded-lg border border-slate-200 p-4">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" v-model="form.received"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-400" />
            <span class="font-medium text-sm text-slate-800">{{ t('policyCreate.fulfilment.receivedDone') }}</span>
          </label>
          <div v-if="form.received" class="mt-3 space-y-3">
            <FormField :label="t('policyCreate.fulfilment.receivedDate')">
              <input type="date" v-model="form.receivedDate"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField :label="t('policyCreate.fulfilment.note')">
              <textarea v-model="form.receivedNote" rows="2"
                :placeholder="t('policyCreate.fulfilment.receivedNotePlaceholder')"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </div>

        <!-- การจัดส่ง — delivered to customer (reuses mailing_* columns) -->
        <div class="rounded-lg border border-slate-200 p-4">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" v-model="form.delivered"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-400" />
            <span class="font-medium text-sm text-slate-800">{{ t('policyCreate.fulfilment.deliveredDone') }}</span>
          </label>
          <div v-if="form.delivered" class="mt-3 space-y-3">
            <div class="grid grid-cols-2 gap-3">
              <FormField :label="t('policyCreate.fulfilment.deliveryMethod')">
                <select v-model="form.deliveryMethod"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
                  <option value="">—</option>
                  <option v-for="m in DELIVERY_METHODS" :key="m" :value="m">{{ t(`policyCreate.fulfilment.methods.${m}`) }}</option>
                </select>
              </FormField>
              <FormField v-if="needsCourier" :label="t('policyCreate.fulfilment.courier')">
                <input v-model.trim="form.courier" type="text" maxlength="64"
                  :placeholder="t('policyCreate.fulfilment.courierPlaceholder')"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
            </div>
            <FormField v-if="needsTracking" :label="t('policyCreate.fulfilment.trackingNo')">
              <input v-model.trim="form.trackingNo" type="text" maxlength="64"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-brand-400" />
            </FormField>
            <div class="grid grid-cols-2 gap-3">
              <FormField :label="t('policyCreate.fulfilment.deliveredDate')">
                <input type="date" v-model="form.shippedDate"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
              <FormField :label="t('policyCreate.fulfilment.customerReceivedDate')">
                <input type="date" v-model="form.deliveredDate" :min="form.shippedDate || undefined"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
              </FormField>
            </div>
            <FormField :label="t('policyCreate.fulfilment.deliveredTo')">
              <input v-model.trim="form.deliveredTo" type="text" maxlength="255"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField :label="t('policyCreate.fulfilment.note')">
              <textarea v-model="form.mailingNote" rows="2"
                :placeholder="t('policyCreate.fulfilment.deliveredNotePlaceholder')"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </div>

        <!-- Free Look date — life products only -->
        <div v-if="kind === 'life'" class="rounded-lg border border-slate-200 p-4" data-testid="freelook-box">
          <span class="font-medium text-sm text-slate-800">{{ t('policyCreate.fulfilment.freelook') }}</span>
          <p class="text-xs text-slate-500 mt-0.5 mb-3">
            วันสิ้นสุด Freelook = วันที่ลูกค้าได้รับกรมธรรม์ + {{ freelookDays.standard }} วัน (ขายทางโทรศัพท์/ออนไลน์ + {{ freelookDays.remote }} วัน)
          </p>
          <div class="grid grid-cols-2 gap-3">
            <FormField label="ช่องทางการขาย">
              <select v-model="form.salesChannel" data-testid="sales-channel"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
                <option v-for="c in SALES_CHANNELS" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
            </FormField>
            <FormField :label="t('policyCreate.fulfilment.freelookDate')">
              <div v-if="freelookComputed" data-testid="freelook-computed"
                class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-1.5 text-sm text-violet-800">
                {{ fmtDate(freelookComputed.date) }}
                <span class="text-xs text-violet-500">(ได้รับ + {{ freelookComputed.days }} วัน · คำนวณอัตโนมัติ)</span>
              </div>
              <template v-else>
                <input type="date" v-model="form.freelookEndDate"
                  class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
                <span class="mt-0.5 block text-[11px] text-slate-400">กรอกวันที่ลูกค้าได้รับในส่วนการจัดส่ง ระบบจะคำนวณให้</span>
              </template>
            </FormField>
          </div>
          <div v-if="draftId && !isDraftMode" class="mt-3 grid grid-cols-3 gap-3" data-testid="freelook-docs">
            <FormField label="ส่งเอกสาร Freelook">
              <input type="date" :value="freelookDocs.sentAt" @change="saveFreelookDoc('sentAt', $event)"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField label="ลูกค้าเซ็นรับ">
              <input type="date" :value="freelookDocs.signedAt" :min="freelookDocs.sentAt || undefined" @change="saveFreelookDoc('signedAt', $event)"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField label="ส่งคืนบริษัทประกัน">
              <input type="date" :value="freelookDocs.returnedAt" :min="freelookDocs.signedAt || undefined" @change="saveFreelookDoc('returnedAt', $event)"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </div>
      </div>
    </section>

    <!-- ── Section 5: Notes ─────────────────────────────────────────────── -->
    <section class="card p-5">
      <h2 class="font-semibold text-slate-900 mb-3">{{ t('policyCreate.step.5') }}</h2>
      <FormField :label="t('policyCreate.notes')">
        <textarea v-model="form.notes" rows="3"
          class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
      </FormField>
    </section>
    </fieldset>

    <!-- #44 — Bottom action bar: duplicates the header actions so the operator
         can save right where they finish typing instead of scrolling back up. -->
    <div class="flex items-center justify-end gap-2 pb-4">
      <template v-if="isDraftMode">
        <button v-if="canSavePolicy" type="button" @click="saveDraftNow" :disabled="saving || !form.customerId"
          class="px-3 py-1.5 rounded-lg text-sm text-slate-700 border border-slate-200 hover:bg-slate-50 disabled:opacity-50">
          <i class="pi pi-save text-xs mr-1" /> {{ t('policyCreate.action.saveDraft') }}
        </button>
        <button v-if="canSavePolicy" type="button" @click="saveAsQuotation" :disabled="saving || !form.customerId || !form.productId"
          class="px-3 py-1.5 rounded-lg text-sm bg-slate-700 text-white hover:bg-slate-800 disabled:opacity-50">
          <i class="pi pi-file text-xs mr-1" /> {{ t('policyCreate.action.saveQuotation') }}
        </button>
        <button v-if="canSavePolicy" type="button" @click="submitToCarrier" :disabled="saving"
          class="px-3 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
          <i class="pi pi-send text-xs mr-1" /> {{ t('policyCreate.action.submitToCarrier') }}
        </button>
      </template>
      <template v-else>
        <button type="button" @click="startRenewal" :disabled="saving"
          class="px-3 py-1.5 rounded-lg text-sm text-brand-700 border border-brand-300 hover:bg-brand-50 disabled:opacity-50">
          <i class="pi pi-replay text-xs mr-1" /> {{ t('policyCreate.action.renew') }}
        </button>
        <button v-if="canSavePolicy" type="button" @click="savePolicyNow" :disabled="saving"
          class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
          <i class="pi pi-check text-xs mr-1" /> {{ t('policyCreate.action.save') }}
        </button>
      </template>
    </div>

    <!-- สลักหลังเบี้ยเพิ่ม (v1) modal. Submits to the endorsement API, which
         updates the policy premium and records the before→after delta. -->
    <PolicyEndorsementModal
      :open="showEndorsementModal"
      :current-annual-premium="Number(form.annualPremium) || 0"
      :current-coverage="Number(form.coverage) || 0"
      :effective-date="form.effectiveDate || null"
      :expiry-date="form.expiryDate || null"
      :initial="endorsementInitial"
      :next-seq="endorsementNextSeq"
      :saving="endorsementSaving"
      :errors="endorsementErrors"
      @close="showEndorsementModal = false"
      @submit="submitEndorsement"
    />

    <div v-if="voidTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" data-testid="endorsement-void-dialog"
      @click.self="!voidSaving && (voidTarget = null)">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-5 py-4 border-b border-slate-100">
          <h3 class="font-semibold text-slate-900">{{ t('endorsement.voidTitle', { n: voidTarget.seq ?? '?' }) }}</h3>
          <p class="text-xs text-slate-500 mt-1">{{ endorsementTypeLabel(voidTarget.type) }} · {{ endorsementField(voidTarget, 'reason') }}</p>
        </div>
        <div class="p-5 space-y-3 text-sm">
          <p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-2.5">
            {{ t('endorsement.voidHint', { n: voidTarget.seq ?? '?' }) }}<template v-if="voidTarget.type === 'endorsement.premium_change'"> · {{ t('endorsement.voidPremiumHint') }}</template>
          </p>
          <div>
            <label class="text-xs font-medium text-slate-600 mb-1 block">{{ t('endorsement.voidReason') }} <span class="text-rose-500">*</span></label>
            <input v-model="voidReason" maxlength="500" :placeholder="t('endorsement.voidReasonPlaceholder')" data-testid="endorsement-void-reason"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
          </div>
          <p v-if="voidError" class="text-xs text-rose-600">{{ voidError }}</p>
        </div>
        <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-slate-100">
          <button type="button" class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100"
            :disabled="voidSaving" @click="voidTarget = null">{{ t('endorsement.cancel') }}</button>
          <button type="button" data-testid="endorsement-void-confirm"
            class="px-4 py-1.5 rounded-lg text-sm bg-rose-600 text-white hover:bg-rose-700 disabled:opacity-50"
            :disabled="voidSaving || !voidReason.trim()" @click="confirmVoidEndorsement">
            {{ voidSaving ? t('endorsement.saving') : t('endorsement.voidConfirm') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
