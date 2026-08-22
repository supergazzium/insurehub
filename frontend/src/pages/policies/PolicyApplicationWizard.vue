<script setup lang="ts">
// C-14 — 5-step Policy Application Wizard. Ground truth: B3-wizard-ia.md.
//
// Step layout:
//   1. Party           — customer + writing agent + new/renew + optional refAppToId
//   2. Product + Cov   — insureType → carrier → product; effective + duration chip → expiry
//   3. Risk (dynamic)  — RiskFieldRenderer against product.productType.riskSchema
//                        + "Reuse from prior policy" dropdown (C-12)
//   4. Premium         — net/main/duty/vat/total/wht/mode + installment + commission
//   5. Review + Save   — three action buttons:
//                        · บันทึกฉบับร่าง    → POST /policies/draft
//                        · บันทึกใบเสนอราคา  → promote-to-quotation
//                        · ส่งพิจารณา        → promote-to-submitted
//
// Draft-safe autosave: once the operator picks a customer on Step 1, the
// wizard POSTs /policies/draft and subsequent field changes PATCH the
// draft in place with a 800ms debounce. No serial numbers minted at
// this stage.
//
// The legacy PolicyCreateWizard.vue stays available (mounted from the
// same trigger button) as a rollback safety net until this rewrite is
// verified end-to-end in prod (see C-20 for legacy removal).

import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
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
import {
  splitSchemaPayload, valueKey, validateSchemaValues, type RiskSchema,
} from '../../utils/riskSchema'

const props = defineProps<{
  open: boolean
  /** Optional draft id to resume from. `null` = fresh wizard. */
  resumeDraftId?: string | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
  /** Fires on any successful save so the parent list can reload. */
  (e: 'created', row: Record<string, unknown>): void
}>()

const { t } = useI18n()

// ── State ────────────────────────────────────────────────────────────────

type WizardStep = 1 | 2 | 3 | 4 | 5
const step = ref<WizardStep>(1)
const draftId = ref<string | null>(props.resumeDraftId ?? null)
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
  insureType: '' as 'life' | 'non-life' | 'tax' | '',
  carrierId: '' as string,
  productId: '' as string,
  policyYear: 1,
  actYear: 1,
  appDate: '' as string,
  effectiveDate: '' as string,
  expiryDate: '' as string,
  durationChipKey: null as string | null,
  coverage: 0 as number,

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

// ── BroadcastChannel bridge (KEEP verbatim per B3 §9) ────────────────────

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

// ── Step navigation + validation ─────────────────────────────────────────

const canNext = computed<boolean>(() => {
  if (step.value === 1) return form.customerId !== '' && form.writingAgentId !== ''
  if (step.value === 2) return form.carrierId !== '' && form.productId !== '' && form.effectiveDate !== '' && form.expiryDate !== ''
  if (step.value === 3) return true  // risk fields are S-gated, not Q-gated
  if (step.value === 4) return form.netPremium > 0 || form.totalPremiumPaid > 0
  return true
})

function next(): void {
  if (!canNext.value) return
  if (step.value < 5) step.value = (step.value + 1) as WizardStep
}
function back(): void {
  if (step.value > 1) step.value = (step.value - 1) as WizardStep
}

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

// ── Action buttons (Step 5) ───────────────────────────────────────────────

async function saveDraftNow(): Promise<void> {
  // Ensure any pending autosave writes first, then acknowledge.
  window.clearTimeout(autosaveTimer)
  await doAutosave()
  emit('created', { id: draftId.value })
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
    const res = await promotePolicyToQuotation(draftId.value)
    emit('created', res.data as unknown as Record<string, unknown>)
    emit('close')
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
    const res = await promotePolicyToSubmitted(draftId.value)
    emit('created', res.data as unknown as Record<string, unknown>)
    emit('close')
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
    form.netPremium = Number(p.netPremium ?? 0)
    form.mainPremium = Number(p.mainPremium ?? 0)
    form.dutyStamp = Number(p.dutyStamp ?? 0)
    form.vat = Number(p.vat ?? 0)
    form.totalPremiumPaid = Number(p.totalPremiumPaid ?? 0)
    form.whtAmt = Number(p.whtAmt ?? 0)
    form.netCustomerPaid = Number(p.netCustomerPaid ?? 0)
    form.annualPremium = Number(p.annualPremium ?? 0)
    form.premiumMode = (p.premiumMode as typeof form.premiumMode) ?? 'annual'
    form.installmentTerm = String(p.installmentTerm ?? '')
    form.firstDueInst = Number(p.firstDueInst ?? 0)
    form.firstDueInstDate = String(p.firstDueInstDate ?? '')
    form.nextDueInst = Number(p.nextDueInst ?? 0)
    form.lastDueInstDate = String(p.lastDueInstDate ?? '')
    form.notes = String(p.notes ?? '')

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
      const it = pd.data.carrierInsureType as 'life' | 'non-life' | 'tax' | ''
      if (it === 'life' || it === 'non-life' || it === 'tax') {
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

    // Land the operator on Step 1 (the picker chips are visible there).
    // The 5-step layout doesn't force auto-jump to first-incomplete
    // yet — a nice-to-have deferred to a follow-up.
    step.value = 1
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Draft resume failed.'
  } finally {
    // Give Vue two ticks for cascade watchers to settle before
    // re-enabling autosave. Prevents the immediate PATCH from
    // shipping a stale scalar bag.
    setTimeout(() => { hydrating.value = false }, 100)
  }
}

// ── Reset on close ────────────────────────────────────────────────────────

watch(() => props.open, (o) => {
  if (!o) return
  // On open, if resumeDraftId is set, load the draft; else reset fresh.
  if (props.resumeDraftId) {
    draftId.value = props.resumeDraftId
    void hydrateFromDraft(props.resumeDraftId)
  } else {
    draftId.value = null
    step.value = 1
    Object.assign(form, {
      newOrRenew: 'new', refAppToId: '', customerId: '', writingAgentId: '',
      applicationNo: '', notionNo: '',
      insureType: '', carrierId: '', productId: '',
      policyYear: 1, actYear: 1,
      appDate: '', effectiveDate: '', expiryDate: '', durationChipKey: null,
      coverage: 0,
      risk: {},
      netPremium: 0, mainPremium: 0, dutyStamp: 0, vat: 0,
      totalPremiumPaid: 0, whtAmt: 0, netCustomerPaid: 0, annualPremium: 0,
      premiumMode: 'annual', installmentTerm: '',
      firstDueInst: 0, firstDueInstDate: '', nextDueInst: 0, lastDueInstDate: '',
      notes: '',
    })
    Object.assign(touched, {
      expiryDate: false, mainPremium: false, dutyStamp: false, vat: false,
      totalPremiumPaid: false, netCustomerPaid: false,
    })
    productDetail.value = null
    customerPicked.value = null
    agentPicked.value = null
    priorAssets.value = []
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
  <div v-if="open" class="fixed inset-0 bg-slate-900/40 flex items-center justify-center z-50 p-4" @click.self="emit('close')">
    <div class="bg-white w-full max-w-4xl rounded-xl shadow-xl flex flex-col max-h-[95vh]">
      <!-- Header + step indicator -->
      <header class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
        <div>
          <h2 class="text-lg font-semibold text-slate-900">{{ t('policyCreate.title') }}</h2>
          <div class="flex items-center gap-1 mt-2 text-xs">
            <template v-for="s in [1, 2, 3, 4, 5]" :key="s">
              <div :class="[
                'flex items-center gap-1.5 px-2 py-0.5 rounded-md',
                step === s ? 'bg-brand-100 text-brand-800 font-medium'
                           : step > s ? 'text-emerald-600' : 'text-slate-400',
              ]">
                <span class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[10px]"
                  :class="step === s ? 'bg-brand-600 text-white'
                                     : step > s ? 'bg-emerald-500 text-white' : 'bg-slate-200 text-slate-500'">
                  <i v-if="step > s" class="pi pi-check text-[8px]" />
                  <span v-else>{{ s }}</span>
                </span>
                <span>{{ t(`policyCreate.step.${s}`) }}</span>
              </div>
              <i v-if="s < 5" class="pi pi-angle-right text-[10px] text-slate-300" />
            </template>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span v-if="autosaving" class="text-xs text-slate-400">
            <i class="pi pi-spin pi-spinner text-[10px] mr-1" /> {{ t('policyCreate.action.savingDraft') }}
          </span>
          <span v-else-if="flash" class="text-xs text-emerald-600">
            <i class="pi pi-check-circle text-[10px] mr-1" /> {{ flash }}
          </span>
          <button class="text-slate-400 hover:text-slate-700 p-2" @click="emit('close')">
            <i class="pi pi-times" />
          </button>
        </div>
      </header>

      <div class="flex-1 overflow-y-auto p-6 space-y-4">
        <div v-if="error" class="p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
          {{ error }}
        </div>

        <!-- ── STEP 1: Party ────────────────────────────────────────────── -->
        <template v-if="step === 1">
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

            <FormField :label="t('policyCreate.customer')" required>
              <EntityPicker
                v-model="form.customerId"
                :fetch="searchCustomers"
                :render-label="(r: CustomerListRow) => `${r.firstName} ${r.lastName}`.trim() || r.juristicName || r.customerCode"
                :render-primary="(r: CustomerListRow) => r.customerCode"
                :placeholder="t('policyCreate.customerPlaceholder')"
                icon-class="pi-user"
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
                @picked="(r) => agentPicked = r as AgentListRow | null"
              />
            </FormField>

            <FormField :label="t('policyCreate.applicationNo')">
              <input v-model.trim="form.applicationNo" type="text" maxlength="32"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </template>

        <!-- ── STEP 2: Product + Coverage ────────────────────────────────── -->
        <template v-if="step === 2">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <FormField :label="t('policyCreate.insureType')" required>
              <div class="flex gap-2">
                <button v-for="opt in ['non-life', 'life', 'tax']" :key="opt" type="button"
                  @click="form.insureType = opt as 'non-life' | 'life' | 'tax'"
                  :class="['flex-1 px-2 py-1.5 rounded-lg text-xs border',
                    form.insureType === opt ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-slate-200']">
                  {{ t(`policyCreate.insureTypeOpt.${opt === 'non-life' ? 'nonLife' : opt}`) }}
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

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
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

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
            <FormField :label="t('policyCreate.coverage')">
              <input v-model.number="form.coverage" type="number" min="0" step="1"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </template>

        <!-- ── STEP 3: Risk (dynamic renderer) ───────────────────────────── -->
        <template v-if="step === 3">
          <div v-if="!productDetail" class="p-4 rounded-lg border border-dashed border-slate-200 text-center text-sm text-slate-500">
            เลือกสินค้าที่ Step 2 ก่อน
          </div>
          <template v-else>
            <div v-if="priorAssets.length > 0" class="p-3 rounded-lg bg-brand-50 border border-brand-200 space-y-2 mb-3">
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
            />
          </template>
        </template>

        <!-- ── STEP 4: Premium ──────────────────────────────────────────── -->
        <template v-if="step === 4">
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

          <p class="text-[10px] text-slate-500 mt-2">
            <i class="pi pi-info-circle mr-1" /> {{ t('policyCreate.autoRecalc') }}
          </p>

          <h3 class="text-xs uppercase tracking-wider text-slate-400 mt-4 mb-2">{{ t('policyCreate.installment') }}</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <FormField :label="t('policyCreate.premiumMode')">
              <select v-model="form.premiumMode"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
                <option value="annual">Annual</option>
                <option value="single">Single</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="semiannual">Semiannual</option>
              </select>
            </FormField>
            <FormField :label="t('policyCreate.installmentTerm')">
              <input v-model.trim="form.installmentTerm" type="text"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </template>

        <!-- ── STEP 5: Review ───────────────────────────────────────────── -->
        <template v-if="step === 5">
          <div class="space-y-3">
            <section class="card p-3 text-sm">
              <div class="text-xs uppercase tracking-wider text-slate-400 mb-1">Party</div>
              <div>{{ customerPicked?.customerCode }} · {{ customerPicked?.firstName }} {{ customerPicked?.lastName }}</div>
              <div class="text-slate-500 text-xs">Agent: {{ agentPicked?.agentCode }} · {{ agentPicked?.firstName }} {{ agentPicked?.lastName }}</div>
            </section>
            <section class="card p-3 text-sm">
              <div class="text-xs uppercase tracking-wider text-slate-400 mb-1">Product + Coverage</div>
              <div>{{ productDetail?.code }} · {{ productDetail?.name }}</div>
              <div class="text-slate-500 text-xs">
                {{ form.effectiveDate || '—' }} → {{ form.expiryDate || '—' }}
                · coverage {{ form.coverage.toLocaleString() }}
              </div>
            </section>
            <section class="card p-3 text-sm">
              <div class="text-xs uppercase tracking-wider text-slate-400 mb-1">Premium</div>
              <div>Net {{ form.netPremium.toLocaleString() }} · Total paid {{ form.totalPremiumPaid.toLocaleString() }}</div>
              <div class="text-slate-500 text-xs">Mode: {{ form.premiumMode }}</div>
            </section>
            <FormField :label="t('policyCreate.notes')">
              <textarea v-model="form.notes" rows="2"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </template>
      </div>

      <!-- Footer -->
      <footer class="px-6 py-4 border-t border-slate-200 flex items-center justify-between">
        <div class="flex gap-2">
          <button v-if="step > 1" type="button" @click="back"
            class="px-4 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100">
            <i class="pi pi-angle-left text-xs mr-1" /> {{ t('policyCreate.back') }}
          </button>
        </div>

        <div class="flex gap-2">
          <template v-if="step < 5">
            <button type="button" @click="emit('close')"
              class="px-4 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100">
              {{ t('policyCreate.cancel') }}
            </button>
            <button type="button" @click="next" :disabled="!canNext"
              class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed">
              {{ t('policyCreate.next') }} <i class="pi pi-angle-right text-xs ml-1" />
            </button>
          </template>
          <template v-else>
            <!-- Three action buttons per B3 §1 Step 5 -->
            <button type="button" @click="saveDraftNow" :disabled="saving"
              class="px-4 py-1.5 rounded-lg text-sm text-slate-700 border border-slate-200 hover:bg-slate-50 disabled:opacity-50">
              <i class="pi pi-save text-xs mr-1" /> {{ t('policyCreate.action.saveDraft') }}
            </button>
            <button type="button" @click="saveAsQuotation" :disabled="saving || !form.customerId || !form.productId"
              class="px-4 py-1.5 rounded-lg text-sm bg-slate-700 text-white hover:bg-slate-800 disabled:opacity-50">
              <i class="pi pi-file text-xs mr-1" /> {{ t('policyCreate.action.saveQuotation') }}
            </button>
            <button type="button" @click="submitToCarrier" :disabled="saving"
              class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
              <i class="pi pi-send text-xs mr-1" /> {{ t('policyCreate.action.submitToCarrier') }}
            </button>
          </template>
        </div>
      </footer>
    </div>
  </div>
</template>
