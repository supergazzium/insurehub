<script setup lang="ts">
// New Product — full-page form (mirrors CustomerCreatePage / the policy
// create-edit page style instead of a cramped modal).
//
// Field visibility follows the carrier's insureType: Life carriers force a
// Main/Rider choice; that choice + carrier type drives the product-group
// options; product group drives the category list; category drives the
// subcategory list. All of the reactive/cascade logic below is ported
// verbatim from the former ProductCreateModal.vue — only the CreateModal
// wrapper (submit / saving / error handling) is replaced with page-local
// state, and the layout is a full page instead of a modal.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import FormField from '../../components/FormField.vue'
import { api, ApiError } from '../../api/client'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { fetchNextProductCode, fetchProductTaxonomy, type ProductTaxonomyRow } from '../../api/products'
import { fetchCommissionTiers, type CommissionTier } from '../../api/mgm'
import { lookupTemplate, fillCarrier } from './productNamePresets'

const router = useRouter()

// Submit state (replaces CreateModal's internal saving/error handling).
const saving = ref(false)
const submitError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

const carriers = ref<CarrierListRow[]>([])
const carriersLoading = ref(false)
const taxonomy = ref<ProductTaxonomyRow[]>([])
const taxonomyLoaded = ref(false)
// ระดับค่าคอม — commission tier chosen directly on the product. The
// engine reads products.commission_tier_id for referral / mgmt-fee
// resolution, so the operator must pick one for MGM accrual to fire.
const commissionTiers = ref<CommissionTier[]>([])
const commissionTiersLoaded = ref(false)

async function loadTaxonomy(): Promise<void> {
  if (taxonomyLoaded.value) return
  try {
    const res = await fetchProductTaxonomy()
    taxonomy.value = res.data
    taxonomyLoaded.value = true
  } catch {
    // Silent — category/subcategory dropdowns will just be empty.
  }
}
async function loadCommissionTiers(): Promise<void> {
  if (commissionTiersLoaded.value) return
  try {
    const res = await fetchCommissionTiers()
    commissionTiers.value = res.data
    commissionTiersLoaded.value = true
  } catch {
    // Silent — dropdown will be empty and canSubmit will stay false,
    // blocking submit until the operator can retry.
  }
}

async function loadCarriers(): Promise<void> {
  if (carriers.value.length) return
  carriersLoading.value = true
  try {
    const res = await fetchCarrierList({ perPage: 100, activeOnly: true })
    carriers.value = res.data
  } finally {
    carriersLoading.value = false
  }
}

onMounted(() => { void loadCarriers(); void loadTaxonomy(); void loadCommissionTiers() })

/**
 * One direction of commission rates on the form. Fields are entered by the
 * operator as PERCENT (0..100); we convert to fractions (0..1) at submit
 * time to match the backend contract. Which fields are used depends on the
 * product group's scheme (flat vs life_years).
 */
type CommissionRatePanel = {
  flatRate: number | null
  yr1: number | null
  yr2: number | null
  yr3: number | null
  yr4: number | null
  yr5: number | null
  yr6_10: number | null
  yr11Up: number | null
}
function blankPanel(): CommissionRatePanel {
  return { flatRate: null, yr1: null, yr2: null, yr3: null, yr4: null, yr5: null, yr6_10: null, yr11Up: null }
}

/**
 * One row in a Life product's banded commission table. Rate fields entered
 * as PERCENT (0..100); SA fields as ฿ integer; ages as year integers.
 * Blank = unbounded (null → -∞ for min, +∞ for max).
 */
type CommissionBandRow = {
  sumAssuredMin: number | null
  sumAssuredMax: number | null
  entryAgeMin: number | null
  entryAgeMax: number | null
  yr1: number | null
  yr2: number | null
  yr3: number | null
  yr4: number | null
  yr5: number | null
  yr6Up: number | null
}
function blankBand(): CommissionBandRow {
  return { sumAssuredMin: null, sumAssuredMax: null, entryAgeMin: null, entryAgeMax: null, yr1: null, yr2: null, yr3: null, yr4: null, yr5: null, yr6Up: null }
}

const form = reactive({
  // Chosen up-front via radio, drives everything below (carrier list,
  // main/rider, product group options). Empty until the user picks.
  insureType: '' as '' | 'life' | 'non-life' | 'tax',
  code: '',
  name: '',
  carrierId: '',
  type: '',
  mainRider: '',
  category: '',
  subCategory: '',
  // ระดับค่าคอม — required. Links product to a commission_tiers row so
  // the MGM engine can resolve referral_fee_rate + mgmt_fee_rate.
  commissionTierId: '',
  minAge: 0,
  maxAge: 99,
  // Non-life uses the flat panels (one rate per direction).
  carrierToHub: blankPanel(),
  hubToAgent: blankPanel(),
  // OV (Override) — a single flat rate the carrier pays InsureHub on top of
  // MAIN, carrier → hub only. Entered as PERCENT; applies to any scheme.
  carrierToHubOv: null as number | null,
  // Life uses banded tables — multiple bands per direction, each with
  // SA/age ranges + yr1..yr5 + yr6+ columns. Empty until operator adds
  // a row.
  carrierToHubBands: [] as CommissionBandRow[],
  hubToAgentBands: [] as CommissionBandRow[],
})

/**
 * Carriers matching the chosen insureType — empties when nothing is picked.
 * DB stores `Life` / `Non-Life` / `Tax`; UI radio uses `life` / `non-life` /
 * `tax`. Normalize both sides before compare so casing / hyphen drift can't
 * silently hide the carrier list.
 */
function normalizeInsureType(v: string): string {
  return v.toLowerCase().replace(/\s+/g, '-')
}
const availableCarriers = computed<CarrierListRow[]>(() =>
  form.insureType === ''
    ? []
    : carriers.value.filter((c) => normalizeInsureType(c.insureType) === form.insureType),
)

const needsMainRiderChoice = computed(() => form.insureType === 'life')

/**
 * Product-group values shown in the UI vs. stored in the DB.
 * The visible label is always "Group" for a group product, but the stored
 * value is 'Group-Life' or 'Group-NL' depending on insureType so the
 * taxonomy for ประกันกลุ่มชีวิต only surfaces for life products.
 */
const productGroupOptions = computed<Array<{ storage: string; label: string }>>(() => {
  if (form.insureType === 'life' && form.mainRider === 'Main') {
    return [
      { storage: 'Life', label: 'Life' },
      { storage: 'PA', label: 'PA' },
      { storage: 'Group-Life', label: 'Group' },
    ]
  }
  if (form.insureType === 'non-life' && form.mainRider === 'Main') {
    return [
      { storage: 'Group-NL', label: 'Group' },
      { storage: 'Motor', label: 'Motor' },
      { storage: 'Non-Motor', label: 'Non-Motor' },
    ]
  }
  return []
})
const productGroupAutoValue = computed<string>(() => {
  if (form.insureType === 'life' && form.mainRider === 'Rider') return 'Rider'
  if (form.insureType === 'tax') return 'Tax'
  return ''
})
const needsProductGroupChoice = computed(() => productGroupOptions.value.length > 0)

/**
 * Taxonomy filtered by current product group. Category list = unique
 * categories under this group; subcategory list = subs under the chosen
 * category. Case-sensitive so DB values (e.g. `Tax` vs `tax`) are preserved.
 */
const availableCategories = computed<string[]>(() => {
  if (!form.type) return []
  const seen = new Set<string>()
  for (const r of taxonomy.value) {
    if (r.group === form.type && !seen.has(r.category)) seen.add(r.category)
  }
  return [...seen]
})
const availableSubcategories = computed<string[]>(() => {
  if (!form.type || !form.category) return []
  return taxonomy.value
    .filter((r) => r.group === form.type && r.category === form.category && r.subcategory !== null)
    .map((r) => r.subcategory as string)
})
const subcategoryRequired = computed(() => availableSubcategories.value.length > 0)

// Tracks whether Code was auto-filled — an auto-filled value gets overwritten
// on carrier change, a manually-typed value is preserved.
const codeAutoFilled = ref(false)
const codeLoading = ref(false)

async function suggestCode(carrierId: string): Promise<void> {
  if (!carrierId) return
  codeLoading.value = true
  try {
    const res = await fetchNextProductCode(carrierId)
    form.code = res.code
    codeAutoFilled.value = true
  } catch {
    // Silent — user can still type manually.
  } finally {
    codeLoading.value = false
  }
}

watch(() => form.carrierId, (id) => {
  if (!id) return
  if (form.code === '' || codeAutoFilled.value) {
    void suggestCode(id)
  }
})

/**
 * Insure-type radio drives main/rider default, clears any carrier that no
 * longer matches, and resets the derived fields so no stale state leaks
 * between types.
 */
watch(() => form.insureType, (t) => {
  if (form.carrierId && !availableCarriers.value.some((c) => c.id === form.carrierId)) {
    form.carrierId = ''
    form.code = ''
    codeAutoFilled.value = false
  }
  if (t === 'non-life') form.mainRider = 'Main'
  else if (t === 'tax') form.mainRider = 'TAX'
  else form.mainRider = ''
  form.type = ''
  form.category = ''
  form.subCategory = ''
  form.minAge = 0
  form.maxAge = 99
})

/**
 * Product group follows insureType + main/rider. When a rule auto-picks it
 * (Life+Rider → Rider, Tax → Tax), set it. When the user must choose from
 * a list, blank it so a prior stale value doesn't leak across types.
 */
watch(
  () => [form.insureType, form.mainRider] as const,
  () => {
    if (productGroupAutoValue.value !== '') {
      form.type = productGroupAutoValue.value
    } else if (needsProductGroupChoice.value) {
      if (!productGroupOptions.value.some((o) => o.storage === form.type)) {
        form.type = ''
      }
    } else {
      form.type = ''
    }
  },
)

/**
 * Group changed → clear category+subcategory unless the current values are
 * still valid under the new group. Auto-pick when there's only one option.
 */
watch(() => form.type, () => {
  if (!availableCategories.value.includes(form.category)) {
    form.category = availableCategories.value.length === 1 ? availableCategories.value[0] : ''
  }
})

watch(() => form.category, () => {
  if (!availableSubcategories.value.includes(form.subCategory)) {
    form.subCategory = ''
  }
})

// ── Name preset autofill ────────────────────────────────────────────────
// Each taxonomy leaf (group + category + subCategory) has a canned name
// template like "ประกันรถยนต์ชั้น 1 [ซ่อม???] (TOK)". When the operator
// finishes picking the leaf, we fill the Name field with the template —
// `[???]` marks portions the operator needs to type over; `(TOK)` gets
// swapped for the carrier's short name (nickname preferred, code fallback).
//
// Only fills while `nameTouched` is false — once the operator types in
// the Name field, we stop overwriting. Cleared on page mount (initial state).
const nameTouched = ref(false)
const selectedCarrier = computed(() => carriers.value.find((c) => c.id === form.carrierId) ?? null)
watch(
  () => [form.type, form.category, form.subCategory, form.carrierId] as const,
  () => {
    if (nameTouched.value) return
    if (!form.type) { form.name = ''; return }
    const template = lookupTemplate({
      group: form.type,
      category: form.category,
      subCategory: form.subCategory,
    })
    if (template === undefined || template === null) {
      // Unknown leaf, or explicit "operator must name" — leave blank.
      form.name = ''
      return
    }
    // Use the carrier CODE (e.g. TOK, AIA) — not the nickname or full name.
    // The code matches the product-code prefix (PDTOK0113) and the first
    // token in the carrier dropdown label, so the auto-filled product name
    // reads consistently with the rest of the UI.
    const shortName = selectedCarrier.value?.code || null
    form.name = fillCarrier(template, shortName)
  },
)

function clampAge(v: number): number {
  if (!Number.isFinite(v)) return 0
  return Math.max(0, Math.min(99, Math.round(v)))
}

/**
 * Which commission-rate shape applies to the current product group.
 *   'life_years' — 7 per-year inputs per direction (Life + Rider groups)
 *   'flat'       — 1 flat % per direction (everything else)
 *   ''           — no group picked yet, so the whole commission section is
 *                  hidden until the user picks a product group
 */
const commissionScheme = computed<'life_years' | 'flat' | ''>(() => {
  if (form.type === 'Life' || form.type === 'Rider') return 'life_years'
  if (form.type === '') return ''
  return 'flat'
})

/**
 * Group-change cleanup: if the operator switched between life and flat
 * schemes, blank the panels so a stale per-year value doesn't leak into a
 * flat product's payload (and vice versa).
 */
watch(commissionScheme, (next, prev) => {
  if (prev !== next) {
    form.carrierToHub = blankPanel()
    form.hubToAgent = blankPanel()
    form.carrierToHubBands = []
    form.hubToAgentBands = []
  }
})

/** Add/remove bands per direction — used by the two Life tables. */
function addBand(direction: 'carrierToHubBands' | 'hubToAgentBands'): void {
  form[direction] = [...form[direction], blankBand()]
}
function removeBand(direction: 'carrierToHubBands' | 'hubToAgentBands', index: number): void {
  form[direction] = form[direction].filter((_, i) => i !== index)
}

const ageError = computed<string | null>(() => {
  const min = form.minAge
  const max = form.maxAge
  if (!Number.isInteger(min) || !Number.isInteger(max)) return 'อายุต้องเป็นจำนวนเต็ม'
  if (min < 0 || min > 99) return 'อายุต่ำสุดต้องอยู่ระหว่าง 0–99'
  if (max < 0 || max > 99) return 'อายุสูงสุดต้องอยู่ระหว่าง 0–99'
  if (min > max) return 'อายุต่ำสุดต้องไม่มากกว่าอายุสูงสุด'
  return null
})

const canSubmit = computed(() =>
  form.insureType !== '' && form.code.trim() !== '' && form.name.trim() !== '' && form.carrierId !== '' &&
  (!needsMainRiderChoice.value || form.mainRider !== '') &&
  (!needsProductGroupChoice.value || form.type !== '') &&
  (form.category !== '') &&
  (!subcategoryRequired.value || form.subCategory !== '') &&
  form.commissionTierId !== '' &&
  ageError.value === null,
)

/**
 * Convert operator-typed percent (0..100) to the fraction (0..1) the
 * backend expects. Blank input stays null; anything else divides by 100
 * and rounds to 5 dp so ".33333" doesn't drift into DECIMAL(8,5) overflow.
 */
function percentToFraction(v: number | null): number | null {
  if (v === null || Number.isNaN(v)) return null
  return Math.round((v / 100) * 100000) / 100000
}

function panelToPayload(p: CommissionRatePanel, scheme: 'life_years' | 'flat'): Record<string, number | null> {
  if (scheme === 'flat') {
    return { flatRate: percentToFraction(p.flatRate) }
  }
  return {
    yr1: percentToFraction(p.yr1),
    yr2: percentToFraction(p.yr2),
    yr3: percentToFraction(p.yr3),
    yr4: percentToFraction(p.yr4),
    yr5: percentToFraction(p.yr5),
    yr6_10: percentToFraction(p.yr6_10),
    yr11Up: percentToFraction(p.yr11Up),
  }
}
/** Serialize a band row for the backend — percents → fractions, nulls stay null. */
function bandToPayload(b: CommissionBandRow): Record<string, number | null> {
  return {
    sumAssuredMin: b.sumAssuredMin,
    sumAssuredMax: b.sumAssuredMax,
    entryAgeMin: b.entryAgeMin,
    entryAgeMax: b.entryAgeMax,
    yr1: percentToFraction(b.yr1),
    yr2: percentToFraction(b.yr2),
    yr3: percentToFraction(b.yr3),
    yr4: percentToFraction(b.yr4),
    yr5: percentToFraction(b.yr5),
    yr6Up: percentToFraction(b.yr6Up),
  }
}

const payload = computed(() => {
  const base: Record<string, unknown> = {
    code: form.code.trim(),
    name: form.name.trim(),
    carrierId: Number(form.carrierId),
    type: form.type || null,
    mainRider: form.mainRider || null,
    category: form.category.trim() || null,
    subCategory: form.subCategory.trim() || null,
    commissionTierId: form.commissionTierId ? Number(form.commissionTierId) : null,
    active: true,
  }
  // Age band is Life-only — omit for non-life so we don't persist stale
  // 0/99 defaults that would falsely narrow future queries.
  if (form.insureType === 'life') {
    base.minAge = form.minAge
    base.maxAge = form.maxAge
  }
  // Only include commissionRates once a scheme is known (product group
  // chosen). Panels are always sent as a pair so the backend can clear
  // either side to blank by upserting a null-filled row. For Life we ALSO
  // send commissionBands — bands are the real per-year source; the flat
  // rate rows stay in place as a fallback and stay untouched here.
  if (commissionScheme.value === 'flat') {
    base.commissionRates = {
      carrierToHub: panelToPayload(form.carrierToHub, 'flat'),
      hubToAgent: panelToPayload(form.hubToAgent, 'flat'),
    }
  } else if (commissionScheme.value === 'life_years') {
    base.commissionBands = {
      carrierToHub: form.carrierToHubBands.map(bandToPayload),
      hubToAgent: form.hubToAgentBands.map(bandToPayload),
    }
  }
  // OV is a single flat rate independent of the MAIN scheme (flat or life),
  // so send it whenever a scheme is known. Merge onto any existing
  // commissionRates (flat) or create the key (life uses bands for MAIN).
  if (commissionScheme.value) {
    base.commissionRates = {
      ...(base.commissionRates as Record<string, unknown> ?? {}),
      carrierToHubOv: { flatRate: percentToFraction(form.carrierToHubOv) },
    }
  }
  return base
})

// ─── Submit ────────────────────────────────────────────────────────────────
// Mirrors CreateModal's submit flow: POST the payload; on success navigate
// back to the product list. Laravel 422 field errors surface inline.
async function submit(): Promise<void> {
  if (saving.value || !canSubmit.value) return
  saving.value = true
  submitError.value = null
  fieldErrors.value = {}
  try {
    const res = await api.post<{ data: Record<string, unknown> }>('products', payload.value)
    const row = (res.data ?? res) as Record<string, unknown>
    // Notify any listening tab (e.g. the policy wizard) that a product was
    // created — same BroadcastChannel contract the list handler used.
    try {
      const bc = new BroadcastChannel('insurehub')
      bc.postMessage({ type: 'product:created', row })
      bc.close()
    } catch { /* BroadcastChannel unavailable — ignore */ }
    router.push({ name: 'products' })
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      if (e.body?.errors) fieldErrors.value = e.body.errors
      submitError.value = e.body?.message ?? 'บันทึกไม่สำเร็จ กรุณาลองใหม่'
    } else {
      submitError.value = 'บันทึกไม่สำเร็จ กรุณาลองใหม่'
    }
  } finally {
    saving.value = false
  }
}

function cancel(): void {
  router.push({ name: 'products' })
}
</script>

<template>
  <div class="space-y-6 max-w-5xl">
    <!-- Header — mirrors the customer create / policy create-edit page -->
    <header class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <div class="text-xs text-slate-400">
          <RouterLink :to="{ name: 'products' }" class="hover:text-brand-600">ผลิตภัณฑ์</RouterLink>
          <span class="mx-1">/</span>
          <span>เพิ่มผลิตภัณฑ์ใหม่</span>
        </div>
        <h1 class="text-2xl font-semibold text-slate-900">เพิ่มผลิตภัณฑ์ใหม่</h1>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" @click="cancel"
          class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100">
          ยกเลิก
        </button>
        <button type="button" @click="submit" :disabled="saving || !canSubmit"
          class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5">
          <i class="pi pi-check text-xs" v-if="!saving" />
          <i class="pi pi-spin pi-spinner text-xs" v-else />
          {{ saving ? 'กำลังบันทึก…' : 'บันทึกผลิตภัณฑ์' }}
        </button>
      </div>
    </header>

    <p v-if="submitError" class="rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-700">
      <i class="pi pi-exclamation-circle mr-1" />{{ submitError }}
    </p>

    <!-- Section 1: ประเภท + carrier + taxonomy + ชื่อ -->
    <section class="card p-5">
      <h2 class="font-semibold text-slate-900 mb-3">ข้อมูลผลิตภัณฑ์</h2>
      <div class="grid grid-cols-2 gap-4">
        <FormField label="ประเภทประกัน" required class="col-span-2" error-key="insureType" :errors="fieldErrors">
          <div class="flex gap-2">
            <label v-for="opt in [
              { value: 'life', label: 'Life' },
              { value: 'non-life', label: 'Non-life' },
              { value: 'tax', label: 'Tax' },
            ]" :key="opt.value"
              :class="[
                'flex-1 flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer text-sm transition-colors',
                form.insureType === opt.value
                  ? 'border-brand-500 bg-brand-50 text-brand-700'
                  : 'border-slate-200 hover:bg-slate-50 text-slate-700',
              ]">
              <input type="radio" :value="opt.value" v-model="form.insureType"
                class="accent-brand-500" />
              <span class="font-medium">{{ opt.label }}</span>
            </label>
          </div>
        </FormField>
        <FormField label="Carrier" required class="col-span-2" error-key="carrierId" :errors="fieldErrors"
          :hint="form.insureType === '' ? 'Pick an insurance type first.' : (form.carrierId && form.code ? `Product code will be ${form.code}` : undefined)">
          <select v-model="form.carrierId" :disabled="form.insureType === ''"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400 disabled:bg-slate-50 disabled:text-slate-400">
            <option value="">— select —</option>
            <option v-for="c in availableCarriers" :key="c.id" :value="c.id">
              {{ c.code }} — {{ c.nicknameTh || c.name }}
            </option>
          </select>
        </FormField>
        <FormField v-if="form.insureType"
          :label="needsMainRiderChoice ? 'Main / Rider' : 'Main / Rider (auto)'"
          :required="needsMainRiderChoice"
          error-key="mainRider" :errors="fieldErrors"
          :hint="needsMainRiderChoice
            ? 'Life — pick whether this product is a main policy or a rider.'
            : (form.insureType === 'non-life' ? 'Non-life — auto-set to Main.' : 'Tax — auto-set to TAX.')">
          <select v-if="needsMainRiderChoice" v-model="form.mainRider"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">— select —</option>
            <option value="Main">Main</option>
            <option value="Rider">Rider</option>
          </select>
          <input v-else :value="form.mainRider" disabled
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 text-slate-500 focus:outline-none" />
        </FormField>
        <FormField v-if="form.insureType"
          :label="needsProductGroupChoice ? 'Product Group' : 'Product Group (auto)'"
          :required="needsProductGroupChoice"
          error-key="type" :errors="fieldErrors"
          :hint="needsProductGroupChoice
            ? (form.insureType === 'life'
                ? 'Life + Main — pick Life / PA / Group.'
                : 'Non-life + Main — pick Group / Motor / Non-Motor.')
            : (form.type === 'Rider' ? 'Life + Rider — auto-set to Rider.' : 'Tax — auto-set to Tax.')">
          <select v-if="needsProductGroupChoice" v-model="form.type"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">— select —</option>
            <option v-for="g in productGroupOptions" :key="g.storage" :value="g.storage">{{ g.label }}</option>
          </select>
          <input v-else :value="form.type" disabled
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 text-slate-500 focus:outline-none" />
        </FormField>
        <FormField v-if="form.type" label="Category" required error-key="category" :errors="fieldErrors"
          :hint="availableCategories.length === 1 ? 'Auto-selected — only one category for this product group.' : 'Pick a category for this product group.'">
          <select v-model="form.category"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
            :disabled="availableCategories.length === 1">
            <option value="">— select —</option>
            <option v-for="c in availableCategories" :key="c" :value="c">{{ c }}</option>
          </select>
        </FormField>
        <FormField v-if="form.category" label="Sub-category" :required="subcategoryRequired" class="col-span-2" error-key="subCategory" :errors="fieldErrors"
          :hint="subcategoryRequired ? 'Pick a sub-category.' : 'No sub-categories under this category.'">
          <select v-if="subcategoryRequired" v-model="form.subCategory"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">— select —</option>
            <option v-for="s in availableSubcategories" :key="s" :value="s">{{ s }}</option>
          </select>
          <input v-else disabled value="—"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 text-slate-500 focus:outline-none" />
        </FormField>
        <!--
          ระดับค่าคอม — required. This is what the MGM engine reads to
          resolve referral_fee_rate + mgmt_fee_rate. Without a tier the
          entire upline chain accrues zero, so we block save until picked.
        -->
        <FormField label="ระดับค่าคอม (Commission tier)" required class="col-span-2"
          error-key="commissionTierId" :errors="fieldErrors"
          hint="กำหนดสูตรจ่าย REFERRAL / MANAGEMENT DIFFERENTIAL — เลือกให้ตรงกับ carrier tariff">
          <select v-model="form.commissionTierId"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">— select —</option>
            <option v-for="t in commissionTiers" :key="t.id" :value="t.id">
              {{ t.nameTh }} ({{ t.code }})
            </option>
          </select>
        </FormField>
        <FormField label="Name (Thai)" required class="col-span-2" error-key="name" :errors="fieldErrors"
          hint="ระบบเติมชื่อให้อัตโนมัติจากประเภทที่เลือก — แก้ [???] เป็นชื่อจริงได้เลย">
          <input v-model.trim="form.name" placeholder="ประกันสุขภาพ ..."
            @input="nameTouched = true"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <!-- Age band is a Life-only concept — motor / tax / non-motor
             products don't gate on the insured's age at the product level.
             Hidden entirely for other types; skipped from the payload too. -->
        <template v-if="form.insureType === 'life'">
          <FormField label="Min age (0–99)" error-key="minAge" :errors="fieldErrors">
            <input v-model.number="form.minAge" type="number" min="0" max="99" step="1"
              @change="form.minAge = clampAge(form.minAge)"
              :class="['w-full border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400',
                ageError ? 'border-rose-400' : 'border-slate-200']" />
          </FormField>
          <FormField label="Max age (0–99)" error-key="maxAge" :errors="fieldErrors">
            <input v-model.number="form.maxAge" type="number" min="0" max="99" step="1"
              @change="form.maxAge = clampAge(form.maxAge)"
              :class="['w-full border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400',
                ageError ? 'border-rose-400' : 'border-slate-200']" />
          </FormField>
          <p v-if="ageError" class="col-span-2 -mt-2 text-xs text-rose-600">{{ ageError }}</p>
        </template>
      </div>
    </section>

    <!--
      Section 2: Commission rates. Shown once the product group has been
      chosen so the scheme (flat vs per-year) is known. Two panels:
         Carrier → InsureHub   |   InsureHub → Agent
      The right panel (hub→agent) is the number the MGM engine uses for
      DIRECT accrual. Left panel is for reporting / reconciliation.
    -->
    <section v-if="commissionScheme !== ''" class="card p-5">
      <h2 class="font-semibold text-slate-900 mb-1">ค่าคอมมิชชั่นมาตรฐาน</h2>
      <div class="text-xs text-slate-500 mb-3">
        เว้นว่างได้ — ค่าที่บันทึกจะถูกใช้ในระบบ MGM แทนตารางบริษัท×ประเภทสินค้า
      </div>
      <!-- Flat scheme (Non-Life, PA, Group, Tax): one % per direction. -->
      <div v-if="commissionScheme === 'flat'" class="grid grid-cols-2 gap-4">
        <div class="border border-slate-200 rounded-lg p-3">
          <div class="text-sm font-medium text-slate-800 mb-2">บริษัท → InsureHub</div>
          <div class="relative w-32">
            <input v-model.number="form.carrierToHub.flatRate" type="number" min="0" max="100" step="0.01"
              placeholder="เช่น 15"
              class="w-full border border-slate-200 rounded-lg pl-3 pr-8 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">%</span>
          </div>
        </div>
        <div class="border border-brand-200 bg-brand-50/40 rounded-lg p-3">
          <div class="text-sm font-medium text-brand-800 mb-2">InsureHub → Agent (MGM)</div>
          <div class="relative w-32">
            <input v-model.number="form.hubToAgent.flatRate" type="number" min="0" max="100" step="0.01"
              placeholder="เช่น 10"
              class="w-full border border-brand-200 rounded-lg pl-3 pr-8 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">%</span>
          </div>
        </div>
      </div>
      <!-- Life scheme: two banded tables. Each band is (SA range, age
           range) + yr1..yr5 + yr6+ commission. Add as many bands as
           the product's tariff sheet lists. -->
      <div v-else class="space-y-4">
        <div v-for="direction in [
          { key: 'carrierToHubBands' as const, label: 'บริษัท → InsureHub', tone: 'slate' },
          { key: 'hubToAgentBands' as const, label: 'InsureHub → Agent (MGM)', tone: 'brand' },
        ]" :key="direction.key" :class="['rounded-lg p-3 border',
          direction.tone === 'brand' ? 'border-brand-200 bg-brand-50/40' : 'border-slate-200']">
          <div :class="['text-sm font-medium mb-2',
            direction.tone === 'brand' ? 'text-brand-800' : 'text-slate-800']">
            {{ direction.label }}
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
              <thead class="text-[10px] uppercase text-slate-500">
                <tr>
                  <th class="px-1 py-1 text-left">SA ต่ำสุด (฿)</th>
                  <th class="px-1 py-1 text-left">SA สูงสุด (฿)</th>
                  <th class="px-1 py-1 text-left">อายุ ต่ำสุด</th>
                  <th class="px-1 py-1 text-left">อายุ สูงสุด</th>
                  <th class="px-1 py-1 text-right">ปี 1</th>
                  <th class="px-1 py-1 text-right">ปี 2</th>
                  <th class="px-1 py-1 text-right">ปี 3</th>
                  <th class="px-1 py-1 text-right">ปี 4</th>
                  <th class="px-1 py-1 text-right">ปี 5</th>
                  <th class="px-1 py-1 text-right">ปี 6+</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="form[direction.key].length === 0">
                  <td colspan="11" class="px-1 py-3 text-center text-slate-400 italic">
                    ยังไม่มีช่วงราคา / อายุ — กด "+ เพิ่มช่วง"
                  </td>
                </tr>
                <tr v-for="(band, i) in form[direction.key]" :key="i" class="border-t border-slate-100">
                  <td class="px-1 py-1"><input v-model.number="band.sumAssuredMin" type="number" min="0" step="1000" placeholder="ไม่จำกัด" class="w-24 border border-slate-200 rounded px-1 py-0.5 text-xs" /></td>
                  <td class="px-1 py-1"><input v-model.number="band.sumAssuredMax" type="number" min="0" step="1000" placeholder="ไม่จำกัด" class="w-24 border border-slate-200 rounded px-1 py-0.5 text-xs" /></td>
                  <td class="px-1 py-1"><input v-model.number="band.entryAgeMin" type="number" min="0" max="120" step="1" placeholder="0" class="w-14 border border-slate-200 rounded px-1 py-0.5 text-xs" /></td>
                  <td class="px-1 py-1"><input v-model.number="band.entryAgeMax" type="number" min="0" max="120" step="1" placeholder="120" class="w-14 border border-slate-200 rounded px-1 py-0.5 text-xs" /></td>
                  <td v-for="y in ['yr1','yr2','yr3','yr4','yr5','yr6Up']" :key="y" class="px-1 py-1">
                    <input :value="(band as any)[y]" type="number" min="0" max="100" step="0.01"
                      @input="(band as any)[y] = ($event.target as HTMLInputElement).valueAsNumber || null"
                      class="w-14 border border-slate-200 rounded px-1 py-0.5 text-xs text-right" />
                  </td>
                  <td class="px-1 py-1">
                    <button type="button" @click="removeBand(direction.key, i)"
                      class="text-rose-500 hover:text-rose-700 text-xs">
                      <i class="pi pi-trash" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <button type="button" @click="addBand(direction.key)"
            class="mt-2 px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50 text-xs">
            + เพิ่มช่วง
          </button>
        </div>
      </div>

      <!-- OV (Override) — a single flat % the carrier pays InsureHub on
           top of MAIN. Carrier → hub only, applies to any scheme. -->
      <div class="mt-4 border-t border-slate-200 pt-4">
        <div class="text-sm font-semibold text-slate-700 mb-1">ค่าคอม OV (Override)</div>
        <div class="text-xs text-slate-500 mb-3">
          ค่าคอมส่วนเพิ่มที่บริษัทประกันจ่ายให้ InsureHub นอกเหนือจากค่าคอมมาตรฐาน — เว้นว่างได้
        </div>
        <div class="border border-slate-200 rounded-lg p-3 inline-block">
          <div class="text-sm font-medium text-slate-800 mb-2">บริษัท → InsureHub (OV)</div>
          <div class="relative w-32">
            <input v-model.number="form.carrierToHubOv" type="number" min="0" max="100" step="0.01"
              placeholder="เช่น 3"
              class="w-full border border-slate-200 rounded-lg pl-3 pr-8 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">%</span>
          </div>
        </div>
      </div>
    </section>

    <!-- Footer actions (duplicate of header for long forms) -->
    <div class="flex items-center justify-end gap-2">
      <button type="button" @click="cancel"
        class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100">
        ยกเลิก
      </button>
      <button type="button" @click="submit" :disabled="saving || !canSubmit"
        class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5">
        <i class="pi pi-check text-xs" v-if="!saving" />
        <i class="pi pi-spin pi-spinner text-xs" v-else />
        {{ saving ? 'กำลังบันทึก…' : 'บันทึกผลิตภัณฑ์' }}
      </button>
    </div>
  </div>
</template>
