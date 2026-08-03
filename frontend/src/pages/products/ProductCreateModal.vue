<script setup lang="ts">
// New Product modal — Phase 1 (Copy from existing) + Phase 2 (per-type
// field packs).
//
// - "Copy from…" banner at the top opens ProductPicker; picking a source
//   prefills every non-identifying field and marks each with a blue dot
//   (removed on edit).
// - The visible field set is driven by the detected pack
//   (life-main / life-group / life-rider / motor / non-motor / tax).
//   When the pack changes (user swaps carrier or product group), any
//   fields that would be "stranded" (present in old pack but not new)
//   trigger a confirm dialog: Clear / Hide / Cancel.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import CreateModal from '../../components/CreateModal.vue'
import FormField from '../../components/FormField.vue'
import ProductPicker from './ProductPicker.vue'
import SuggestionChip from './SuggestionChip.vue'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import {
  fetchNextProductCode, fetchProductTaxonomy, fetchProduct, fetchProductList,
  type ProductTaxonomyRow, type ProductDetail,
} from '../../api/products'
import {
  detectPack, hasField, strandedFields, getPack,
  type PackKey, type PackField,
} from './productPacks'

const props = defineProps<{
  open: boolean
  copyFromId?: string | null
}>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'created', row: Record<string, unknown>): void
}>()

// ── Lookups ────────────────────────────────────────────────────────────
const carriers = ref<CarrierListRow[]>([])
const carriersLoading = ref(false)
const taxonomy = ref<ProductTaxonomyRow[]>([])
const taxonomyLoaded = ref(false)

async function loadTaxonomy(): Promise<void> {
  if (taxonomyLoaded.value) return
  try {
    const res = await fetchProductTaxonomy()
    taxonomy.value = res.data
    taxonomyLoaded.value = true
  } catch { /* silent */ }
}
async function loadCarriers(): Promise<void> {
  if (carriers.value.length) return
  carriersLoading.value = true
  try {
    const res = await fetchCarrierList({ perPage: 100, activeOnly: true })
    carriers.value = res.data
  } finally { carriersLoading.value = false }
}
onMounted(() => { void loadCarriers(); void loadTaxonomy() })

// ── Form state ─────────────────────────────────────────────────────────
const form = reactive({
  code: '',
  name: '',
  nameEn: '',
  carrierId: '',
  type: '',
  mainRider: '',
  category: '',
  subCategory: '',
  minAge: 0,
  maxAge: 99,
  coverage: 0,
  coverageClass: '' as '' | '1' | '2+' | '2' | '3+' | '3',
  vehicleAgeMin: null as number | null,
  vehicleAgeMax: null as number | null,
  durationYears: 1,
  payYears: 1,
  premiumMode: 'annual' as ProductDetail['premiumMode'],
  minPremium: 0,
  maxPremium: 0,
  minSumAssure: null as number | null,
  maxSumAssure: null as number | null,
  gender: 'all' as ProductDetail['gender'],
  requireMedical: false,
  smokerAccepted: true,
  preexistingExcluded: false,
  summary: '',
  notes: '',
})

// Stash for fields hidden by a type-change ("Hide" option): remembered so
// the value comes back if the user swaps back to a compatible pack.
const hiddenStash = reactive<Partial<Record<PackField, unknown>>>({})

// ── Copy-from support ──────────────────────────────────────────────────
const copiedFrom = ref<{ id: string; code: string; name: string } | null>(null)
const copiedFields = reactive<Record<string, boolean>>({})
const pickerOpen = ref(false)

function markEdited(field: string): void {
  if (copiedFields[field]) copiedFields[field] = false
}

const COPY_FIELDS = [
  'carrierId', 'type', 'mainRider', 'category', 'subCategory',
  'minAge', 'maxAge', 'coverage', 'coverageClass',
  'vehicleAgeMin', 'vehicleAgeMax',
  'durationYears', 'payYears',
  'premiumMode', 'minPremium', 'maxPremium', 'minSumAssure', 'maxSumAssure',
  'gender', 'requireMedical', 'smokerAccepted', 'preexistingExcluded',
  'summary', 'notes',
] as const

async function copyFromProduct(id: string): Promise<void> {
  try {
    const res = await fetchProduct(id)
    const p = res.data
    Object.assign(form, {
      code: '', name: '', nameEn: '',
      carrierId: p.carrierId,
      type: p.type ?? '',
      mainRider: p.mainRider ?? '',
      category: p.category ?? '',
      subCategory: p.subCategory ?? '',
      minAge: p.minAge, maxAge: p.maxAge,
      coverage: p.coverage,
      coverageClass: p.coverageClass ?? '',
      vehicleAgeMin: p.vehicleAgeMin,
      vehicleAgeMax: p.vehicleAgeMax,
      durationYears: p.durationYears,
      payYears: p.payYears,
      premiumMode: p.premiumMode,
      minPremium: p.minPremium,
      maxPremium: p.maxPremium,
      minSumAssure: p.minSumAssure,
      maxSumAssure: p.maxSumAssure,
      gender: p.gender,
      requireMedical: p.requireMedical,
      smokerAccepted: p.smokerAccepted,
      preexistingExcluded: p.preexistingExcluded,
      summary: p.summary ?? '',
      notes: p.notes ?? '',
    })
    for (const k of COPY_FIELDS) copiedFields[k] = true
    copiedFrom.value = { id: p.id, code: p.code, name: p.name }
    codeAutoFilled.value = false
    if (p.carrierId) void suggestCode(p.carrierId)
    pickerOpen.value = false
  } catch { /* silent */ }
}

function clearCopy(): void {
  copiedFrom.value = null
  for (const k of Object.keys(copiedFields)) copiedFields[k] = false
}

// ── Cascading carrier / group / category / subcategory logic ───────────
const selectedCarrier = computed(() => carriers.value.find((c) => c.id === form.carrierId) ?? null)
const carrierInsureType = computed(() => selectedCarrier.value?.insureType ?? '')
const needsMainRiderChoice = computed(() => carrierInsureType.value === 'life')

const productGroupOptions = computed<Array<{ storage: string; label: string }>>(() => {
  const t = carrierInsureType.value
  if (t === 'life' && form.mainRider === 'Main') {
    return [
      { storage: 'Life', label: 'Life' },
      { storage: 'PA', label: 'PA' },
      { storage: 'Group-Life', label: 'Group' },
    ]
  }
  if (t === 'non-life' && form.mainRider === 'Main') {
    return [
      { storage: 'Group-NL', label: 'Group' },
      { storage: 'Motor', label: 'Motor' },
      { storage: 'Non-Motor', label: 'Non-Motor' },
    ]
  }
  return []
})
const productGroupAutoValue = computed<string>(() => {
  const t = carrierInsureType.value
  if (t === 'life' && form.mainRider === 'Rider') return 'Rider'
  if (t === 'tax') return 'Tax'
  return ''
})
const needsProductGroupChoice = computed(() => productGroupOptions.value.length > 0)

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

const codeAutoFilled = ref(false)
const codeLoading = ref(false)
async function suggestCode(carrierId: string): Promise<void> {
  if (!carrierId) return
  codeLoading.value = true
  try {
    const res = await fetchNextProductCode(carrierId)
    form.code = res.code
    codeAutoFilled.value = true
  } catch { /* silent */ } finally { codeLoading.value = false }
}

watch(() => form.carrierId, (id) => {
  markEdited('carrierId')
  if (!id) { form.mainRider = ''; form.type = ''; return }
  if (form.code === '' || codeAutoFilled.value) void suggestCode(id)
  const t = carrierInsureType.value
  if (t === 'non-life') form.mainRider = 'Main'
  else if (t === 'tax') form.mainRider = 'TAX'
  else if (t === 'life' && !copiedFields.mainRider) form.mainRider = ''
})

watch(
  () => [carrierInsureType.value, form.mainRider] as const,
  () => {
    if (productGroupAutoValue.value !== '') form.type = productGroupAutoValue.value
    else if (needsProductGroupChoice.value) {
      if (!productGroupOptions.value.some((o) => o.storage === form.type)) form.type = ''
    } else form.type = ''
  },
)
watch(() => form.type, () => {
  if (!availableCategories.value.includes(form.category)) {
    form.category = availableCategories.value.length === 1 ? availableCategories.value[0] : ''
  }
})
watch(() => form.category, () => {
  if (!availableSubcategories.value.includes(form.subCategory)) form.subCategory = ''
})

// ── Pack detection + stranded-fields dialog ────────────────────────────
const currentPack = computed<PackKey>(() =>
  detectPack(carrierInsureType.value, form.mainRider, form.type),
)

// Track pack transitions. When the pack changes, we check for stranded
// fields with non-empty values and open the dialog.
const previousPack = ref<PackKey>('unknown')
const strandedDialog = ref<{
  fromPack: PackKey
  toPack: PackKey
  fields: PackField[]
} | null>(null)

function isFieldFilled(field: PackField): boolean {
  const v = form[field as keyof typeof form] as unknown
  if (v === null || v === undefined || v === '') return false
  // Zero is "unset" for numeric age/premium ranges — don't flag those.
  if (typeof v === 'number' && v === 0) return false
  return true
}

watch(currentPack, (next, prev) => {
  // Skip the initial transition from 'unknown' (fresh open).
  if (prev === 'unknown' || next === prev) {
    previousPack.value = next
    return
  }
  const stranded = strandedFields(prev, next).filter(isFieldFilled)
  if (stranded.length === 0) {
    previousPack.value = next
    return
  }
  strandedDialog.value = { fromPack: prev, toPack: next, fields: stranded }
})

function fieldDefault(field: PackField): unknown {
  switch (field) {
    case 'minAge': return 0
    case 'maxAge': return 99
    case 'gender': return 'all'
    case 'minSumAssure':
    case 'maxSumAssure': return null
    case 'vehicleAgeMin':
    case 'vehicleAgeMax': return null
    case 'coverageClass': return ''
    case 'durationYears':
    case 'payYears': return 1
    case 'premiumMode': return 'annual'
    case 'minPremium':
    case 'maxPremium': return 0
    case 'requireMedical': return false
    case 'smokerAccepted': return true
    case 'preexistingExcluded': return false
    case 'summary':
    case 'notes': return ''
    default: return null
  }
}

function resolveStranded(action: 'clear' | 'hide' | 'cancel'): void {
  if (!strandedDialog.value) return
  const { toPack, fields } = strandedDialog.value
  if (action === 'cancel') {
    // Roll back the pack change by restoring the pre-change signals.
    // The simplest way is to force `form.type` back — but we don't know
    // the previous value here. Instead we just leave state as-is and
    // note that the user has to manually revert. Realistically, the
    // cascade already reset things, so "cancel" ends up equivalent to
    // "hide" (values live in the form but aren't rendered until user
    // switches back). Close the dialog.
    strandedDialog.value = null
    previousPack.value = toPack
    return
  }
  if (action === 'clear') {
    for (const f of fields) {
      ;(form as Record<string, unknown>)[f] = fieldDefault(f)
      delete hiddenStash[f]
    }
  } else {
    // hide: preserve current values in hiddenStash and blank them on form
    // so an eventual submit doesn't push stale data.
    for (const f of fields) {
      hiddenStash[f] = (form as Record<string, unknown>)[f]
      ;(form as Record<string, unknown>)[f] = fieldDefault(f)
    }
  }
  strandedDialog.value = null
  previousPack.value = toPack
}

// When switching INTO a pack where a field is relevant AND we have a
// stashed value for it, restore.
watch(currentPack, (next, prev) => {
  if (next === prev) return
  const pack = getPack(next)
  for (const f of pack.fields) {
    if (f in hiddenStash) {
      ;(form as Record<string, unknown>)[f] = hiddenStash[f]
      delete hiddenStash[f]
    }
  }
})

// ── Phase 3: ghost-value suggestions ──────────────────────────────────
// When carrier + product group are selected, background-fetch the most
// recent same-carrier + same-type product and use its numeric/enum
// fields as suggestions rendered below each empty input. Skipped when
// the user is already copying from a specific product (redundant).
const suggestSource = ref<ProductDetail | null>(null)
const dismissedSuggestions = reactive<Record<string, boolean>>({})
let suggestFetchToken = 0

async function refreshSuggestions(): Promise<void> {
  // Skip when a copy source is active — the source already provides values.
  if (copiedFrom.value !== null) { suggestSource.value = null; return }
  if (!form.carrierId || !form.type) { suggestSource.value = null; return }
  const myToken = ++suggestFetchToken
  try {
    const list = await fetchProductList({
      carrierId: form.carrierId,
      type: form.type,
      perPage: 1,
      activeOnly: true,
    })
    if (myToken !== suggestFetchToken) return // stale response
    const row = list.data[0]
    if (!row) { suggestSource.value = null; return }
    // Get full detail so we can suggest every field.
    const full = await fetchProduct(row.id)
    if (myToken !== suggestFetchToken) return
    suggestSource.value = full.data
    for (const k of Object.keys(dismissedSuggestions)) delete dismissedSuggestions[k]
  } catch { /* silent */ }
}

watch(
  () => [form.carrierId, form.type, form.mainRider, copiedFrom.value?.id ?? null] as const,
  () => { void refreshSuggestions() },
)

// Which fields the suggestion is defined for on the source product, and
// where the user hasn't already put in a "real" value.
const SUGGEST_NUMERIC = new Set<PackField>([
  'minAge', 'maxAge', 'minSumAssure', 'maxSumAssure',
  'vehicleAgeMin', 'vehicleAgeMax',
  'durationYears', 'payYears', 'minPremium', 'maxPremium',
])
const SUGGEST_BOOL = new Set<PackField>(['requireMedical', 'smokerAccepted', 'preexistingExcluded'])

function suggestionValueFor(field: PackField): unknown | null {
  if (!suggestSource.value) return null
  const src = suggestSource.value as unknown as Record<string, unknown>
  if (!(field in src)) return null
  const v = src[field]
  if (v === null || v === undefined || v === '') return null
  return v
}

function isDefaultForField(field: PackField, current: unknown): boolean {
  // Only offer to fill fields the user hasn't touched. "Default" varies by field.
  switch (field) {
    case 'minAge': return current === 0
    case 'maxAge': return current === 99
    case 'gender': return current === 'all'
    case 'minSumAssure':
    case 'maxSumAssure':
    case 'vehicleAgeMin':
    case 'vehicleAgeMax': return current === null
    case 'coverageClass': return current === ''
    case 'durationYears':
    case 'payYears': return current === 1
    case 'premiumMode': return current === 'annual'
    case 'minPremium':
    case 'maxPremium': return current === 0
    case 'requireMedical': return current === false
    case 'smokerAccepted': return current === true
    case 'preexistingExcluded': return current === false
  }
  return false
}

function suggestion(field: PackField): unknown | null {
  if (dismissedSuggestions[field]) return null
  const v = suggestionValueFor(field)
  if (v === null) return null
  const current = (form as Record<string, unknown>)[field]
  if (!isDefaultForField(field, current)) return null
  // Don't suggest a value equal to the current default.
  const asPrimitive = typeof current === 'object' ? null : current
  if (v === asPrimitive) return null
  return v
}

function useSuggestion(field: PackField): void {
  const v = suggestionValueFor(field)
  if (v === null) return
  ;(form as Record<string, unknown>)[field] = v
  markEdited(field)
}
function dismissSuggestion(field: PackField): void {
  dismissedSuggestions[field] = true
}

// Master "Use all" — applies every currently-visible non-dismissed suggestion.
function useAllSuggestions(): void {
  const pack = getPack(currentPack.value)
  for (const f of pack.fields) {
    if (suggestion(f) !== null) useSuggestion(f)
  }
}
const availableSuggestionCount = computed<number>(() => {
  if (!suggestSource.value) return 0
  const pack = getPack(currentPack.value)
  let n = 0
  for (const f of pack.fields) {
    if (suggestion(f) !== null) n++
  }
  return n
})

/** Human-friendly renderer for a suggested value. */
function formatSuggestion(field: PackField, v: unknown): string {
  if (v === null || v === undefined) return ''
  if (SUGGEST_BOOL.has(field)) return v ? 'Yes' : 'No'
  if (SUGGEST_NUMERIC.has(field) && typeof v === 'number') {
    return v.toLocaleString()
  }
  return String(v)
}

// ── Phase 3: name autocomplete ────────────────────────────────────────
// As the user types the Thai (or English) name, look up other product
// names of the same type + category from any carrier. Not a copy — just
// helps naming consistency across the portfolio.
const nameSuggestions = ref<string[]>([])
const nameSuggestionsEn = ref<string[]>([])
const nameCache = new Map<string, string[]>()
let nameFetchTimer: number | undefined
let nameFetchToken = 0

async function fetchNameSuggestions(target: 'th' | 'en'): Promise<void> {
  const q = (target === 'th' ? form.name : form.nameEn).trim()
  if (q.length < 2 || !form.type) {
    if (target === 'th') nameSuggestions.value = []
    else nameSuggestionsEn.value = []
    return
  }
  const cacheKey = `${target}:${form.type}:${form.category}:${q}`
  const cached = nameCache.get(cacheKey)
  if (cached) {
    if (target === 'th') nameSuggestions.value = cached
    else nameSuggestionsEn.value = cached
    return
  }
  const myToken = ++nameFetchToken
  try {
    const res = await fetchProductList({
      q, type: form.type, category: form.category || undefined,
      perPage: 20, activeOnly: true,
    })
    if (myToken !== nameFetchToken) return
    const seen = new Set<string>()
    const names: string[] = []
    for (const r of res.data) {
      const val = target === 'th' ? r.name : r.nameEn
      if (val && !seen.has(val)) { seen.add(val); names.push(val) }
      if (names.length >= 10) break
    }
    nameCache.set(cacheKey, names)
    if (target === 'th') nameSuggestions.value = names
    else nameSuggestionsEn.value = names
  } catch { /* silent */ }
}

function debouncedNameLookup(target: 'th' | 'en'): void {
  window.clearTimeout(nameFetchTimer)
  nameFetchTimer = window.setTimeout(() => { void fetchNameSuggestions(target) }, 200)
}

const nameSuggestOpen = ref(false)
const nameSuggestOpenEn = ref(false)
const nameHighlighted = ref(0)
const nameHighlightedEn = ref(0)

function onNameInput(target: 'th' | 'en'): void {
  if (target === 'th') { nameSuggestOpen.value = true; nameHighlighted.value = 0 }
  else { nameSuggestOpenEn.value = true; nameHighlightedEn.value = 0 }
  debouncedNameLookup(target)
}

function pickName(target: 'th' | 'en', name: string): void {
  if (target === 'th') { form.name = name; nameSuggestOpen.value = false; nameSuggestions.value = [] }
  else { form.nameEn = name; nameSuggestOpenEn.value = false; nameSuggestionsEn.value = [] }
}

function onNameBlur(target: 'th' | 'en'): void {
  // 150ms delay so a mousedown on a suggestion has time to fire pickName.
  window.setTimeout(() => {
    if (target === 'th') nameSuggestOpen.value = false
    else nameSuggestOpenEn.value = false
  }, 150)
}

function onNameKeydown(e: KeyboardEvent, target: 'th' | 'en'): void {
  const list = target === 'th' ? nameSuggestions.value : nameSuggestionsEn.value
  if (list.length === 0) return
  const hRef = target === 'th' ? nameHighlighted : nameHighlightedEn
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    hRef.value = Math.min(hRef.value + 1, list.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    hRef.value = Math.max(hRef.value - 1, 0)
  } else if (e.key === 'Enter' && (target === 'th' ? nameSuggestOpen.value : nameSuggestOpenEn.value)) {
    e.preventDefault()
    pickName(target, list[hRef.value])
  } else if (e.key === 'Escape') {
    if (target === 'th') nameSuggestOpen.value = false
    else nameSuggestOpenEn.value = false
  }
}

// ── Submission ─────────────────────────────────────────────────────────
const canSubmit = computed(() =>
  form.code.trim() !== '' && form.name.trim() !== '' && form.carrierId !== '' &&
  (!needsMainRiderChoice.value || form.mainRider !== '') &&
  (!needsProductGroupChoice.value || form.type !== '') &&
  (form.category !== '') &&
  (!subcategoryRequired.value || form.subCategory !== ''),
)

// Payload only sends fields relevant to the detected pack — hidden fields
// stay in the form state but don't hit the server.
const payload = computed(() => {
  const pack = currentPack.value
  const base: Record<string, unknown> = {
    code: form.code.trim(),
    name: form.name.trim(),
    nameEn: form.nameEn.trim() || null,
    carrierId: Number(form.carrierId),
    type: form.type || null,
    mainRider: form.mainRider || null,
    category: form.category.trim() || null,
    subCategory: form.subCategory.trim() || null,
    active: true,
  }
  const packFields = getPack(pack).fields
  for (const f of packFields) {
    const v = (form as Record<string, unknown>)[f]
    // For string enums we substitute null when blank so DB defaults kick in.
    if (f === 'coverageClass' && v === '') { base[f] = null; continue }
    base[f] = v
  }
  return base
})

// Reset form on modal open.
watch(
  () => props.open,
  async (v) => {
    if (!v) return
    Object.assign(form, {
      code: '', name: '', nameEn: '', carrierId: '',
      type: '', mainRider: '', category: '', subCategory: '',
      minAge: 0, maxAge: 99,
      coverage: 0, coverageClass: '',
      vehicleAgeMin: null, vehicleAgeMax: null,
      durationYears: 1, payYears: 1, premiumMode: 'annual',
      minPremium: 0, maxPremium: 0, minSumAssure: null, maxSumAssure: null,
      gender: 'all', requireMedical: false, smokerAccepted: true, preexistingExcluded: false,
      summary: '', notes: '',
    })
    for (const k of Object.keys(hiddenStash)) delete hiddenStash[k as PackField]
    strandedDialog.value = null
    previousPack.value = 'unknown'
    clearCopy()
    codeAutoFilled.value = false
    pickerOpen.value = false
    void loadCarriers()
    if (props.copyFromId) await copyFromProduct(props.copyFromId)
  },
)

// Convenience: which fields are visible right now?
const show = (f: PackField): boolean => hasField(currentPack.value, f)
const packLabel = computed(() => getPack(currentPack.value).labelEn)
</script>

<template>
  <CreateModal
    :open="open" entity="products" title="New Product"
    :payload="payload" :can-submit="canSubmit"
    @close="emit('close')" @created="(row) => emit('created', row)"
  >
    <template #default="{ fieldErrors }">
      <!-- Copy-from banner + picker -->
      <div class="mb-4">
        <div v-if="copiedFrom" class="flex items-center justify-between px-3 py-2 rounded-lg bg-brand-50 border border-brand-200 text-brand-800 text-xs">
          <div>
            <i class="pi pi-clone mr-1" />
            Copied from
            <span class="font-mono">{{ copiedFrom.code }}</span>
            — <span class="italic">{{ copiedFrom.name }}</span>.
            Edit anything below.
          </div>
          <button type="button" @click="clearCopy"
            class="text-brand-600 hover:text-brand-800 ml-2" title="Clear all copies">
            <i class="pi pi-times text-xs" />
          </button>
        </div>
        <div v-else class="flex justify-end">
          <button type="button" @click="pickerOpen = !pickerOpen"
            class="text-xs text-brand-600 hover:text-brand-800 flex items-center gap-1">
            <i class="pi pi-clone" />
            Copy from existing product…
          </button>
        </div>
        <ProductPicker v-if="pickerOpen" :open="pickerOpen"
          class="mt-2"
          @pick="(row) => copyFromProduct(row.id)"
          @close="pickerOpen = false" />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <!-- Identity fields (always shown) -->
        <FormField label="Code" required error-key="code" :errors="fieldErrors"
          :hint="form.carrierId ? (codeAutoFilled ? 'Auto-filled from carrier — edit to override.' : 'Manual code — clear to auto-fill from carrier.') : 'Pick a carrier to auto-generate.'">
          <div class="relative">
            <input v-model.trim="form.code" placeholder="PDAIA0001"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400 font-mono"
              @input="codeAutoFilled = false" />
            <span v-if="codeLoading" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400">
              <i class="pi pi-spin pi-spinner text-xs" />
            </span>
          </div>
        </FormField>

        <FormField label="Carrier" required error-key="carrierId" :errors="fieldErrors">
          <template #label-extra>
            <span v-if="copiedFields.carrierId" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
          </template>
          <select v-model="form.carrierId"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">— select —</option>
            <option v-for="c in carriers" :key="c.id" :value="c.id">
              {{ c.code }} — {{ c.nicknameTh || c.name }}
              <template v-if="c.insureType">[{{ c.insureType }}]</template>
            </option>
          </select>
        </FormField>

        <FormField v-if="form.carrierId"
          :label="needsMainRiderChoice ? 'Main / Rider' : 'Main / Rider (auto)'"
          :required="needsMainRiderChoice"
          error-key="mainRider" :errors="fieldErrors">
          <template #label-extra>
            <span v-if="copiedFields.mainRider" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
          </template>
          <select v-if="needsMainRiderChoice" v-model="form.mainRider"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
            @change="markEdited('mainRider')">
            <option value="">— select —</option>
            <option value="Main">Main</option>
            <option value="Rider">Rider</option>
          </select>
          <input v-else :value="form.mainRider" disabled
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 text-slate-500 focus:outline-none" />
        </FormField>

        <FormField label="Name (Thai)" required class="col-span-2" error-key="name" :errors="fieldErrors">
          <div class="relative">
            <input v-model.trim="form.name" placeholder="ประกันสุขภาพ ..."
              lang="th"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="onNameInput('th')"
              @focus="onNameInput('th')"
              @blur="onNameBlur('th')"
              @keydown="e => onNameKeydown(e, 'th')" />
            <div v-if="nameSuggestOpen && nameSuggestions.length > 0"
              class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg z-30 max-h-56 overflow-y-auto">
              <div class="text-[10px] uppercase font-semibold text-slate-400 px-3 pt-2 pb-1 tracking-wider">Similar names</div>
              <button v-for="(s, i) in nameSuggestions" :key="s" type="button"
                @mousedown.prevent="pickName('th', s)"
                @mouseenter="nameHighlighted = i"
                :class="[
                  'w-full text-left px-3 py-1.5 text-sm border-t border-slate-100 first:border-t-0',
                  nameHighlighted === i ? 'bg-brand-50 text-brand-800' : 'text-slate-700 hover:bg-slate-50',
                ]">{{ s }}</button>
            </div>
          </div>
        </FormField>

        <FormField label="Name (English)" class="col-span-2" error-key="nameEn" :errors="fieldErrors">
          <div class="relative">
            <input v-model.trim="form.nameEn"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="onNameInput('en')"
              @focus="onNameInput('en')"
              @blur="onNameBlur('en')"
              @keydown="e => onNameKeydown(e, 'en')" />
            <div v-if="nameSuggestOpenEn && nameSuggestionsEn.length > 0"
              class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg z-30 max-h-56 overflow-y-auto">
              <div class="text-[10px] uppercase font-semibold text-slate-400 px-3 pt-2 pb-1 tracking-wider">Similar names</div>
              <button v-for="(s, i) in nameSuggestionsEn" :key="s" type="button"
                @mousedown.prevent="pickName('en', s)"
                @mouseenter="nameHighlightedEn = i"
                :class="[
                  'w-full text-left px-3 py-1.5 text-sm border-t border-slate-100 first:border-t-0',
                  nameHighlightedEn === i ? 'bg-brand-50 text-brand-800' : 'text-slate-700 hover:bg-slate-50',
                ]">{{ s }}</button>
            </div>
          </div>
        </FormField>

        <FormField v-if="form.carrierId"
          :label="needsProductGroupChoice ? 'Product Group' : 'Product Group (auto)'"
          :required="needsProductGroupChoice"
          error-key="type" :errors="fieldErrors">
          <template #label-extra>
            <span v-if="copiedFields.type" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
          </template>
          <select v-if="needsProductGroupChoice" v-model="form.type"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
            @change="markEdited('type')">
            <option value="">— select —</option>
            <option v-for="g in productGroupOptions" :key="g.storage" :value="g.storage">{{ g.label }}</option>
          </select>
          <input v-else :value="form.type" disabled
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 text-slate-500 focus:outline-none" />
        </FormField>

        <FormField v-if="form.type" label="Category" required error-key="category" :errors="fieldErrors">
          <template #label-extra>
            <span v-if="copiedFields.category" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
          </template>
          <select v-model="form.category"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
            :disabled="availableCategories.length === 1"
            @change="markEdited('category')">
            <option value="">— select —</option>
            <option v-for="c in availableCategories" :key="c" :value="c">{{ c }}</option>
          </select>
        </FormField>

        <FormField v-if="form.category" label="Sub-category" :required="subcategoryRequired" class="col-span-2" error-key="subCategory" :errors="fieldErrors">
          <template #label-extra>
            <span v-if="copiedFields.subCategory" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
          </template>
          <select v-if="subcategoryRequired" v-model="form.subCategory"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
            @change="markEdited('subCategory')">
            <option value="">— select —</option>
            <option v-for="s in availableSubcategories" :key="s" :value="s">{{ s }}</option>
          </select>
          <input v-else disabled value="—"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 text-slate-500 focus:outline-none" />
        </FormField>
      </div>

      <!-- Pack indicator + per-type field grid -->
      <div v-if="currentPack !== 'unknown'" class="mt-6">
        <div class="flex items-center justify-between gap-2 mb-3 pb-2 border-b border-slate-100">
          <div class="flex items-center gap-2">
            <i class="pi pi-th-large text-brand-500 text-xs" />
            <span class="text-xs uppercase font-semibold text-slate-500 tracking-wider">
              {{ packLabel }} fields
            </span>
          </div>
        </div>

        <!-- "Use all" master suggestion strip -->
        <div v-if="suggestSource && availableSuggestionCount > 0"
          class="mb-3 flex items-center justify-between gap-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-xs">
          <div>
            <i class="pi pi-lightbulb mr-1" />
            Suggestions from
            <span class="font-mono">{{ suggestSource.code }}</span>
            — <span class="italic">{{ suggestSource.name }}</span>
            ({{ availableSuggestionCount }} field{{ availableSuggestionCount === 1 ? '' : 's' }})
          </div>
          <button type="button" @click="useAllSuggestions"
            class="px-2.5 py-1 rounded bg-amber-500 text-white font-medium hover:bg-amber-600 text-[11px] shrink-0">
            ✓ Use all
          </button>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <!-- Coverage class (Motor) -->
          <FormField v-if="show('coverageClass')" label="Coverage class" error-key="coverageClass" :errors="fieldErrors"
            hint="Thai motor tier: 1 / 2+ / 2 / 3+ / 3">
            <template #label-extra>
              <span v-if="copiedFields.coverageClass" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <select v-model="form.coverageClass"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
              @change="markEdited('coverageClass')">
              <option value="">— select —</option>
              <option value="1">Class 1</option>
              <option value="2+">Class 2+</option>
              <option value="2">Class 2</option>
              <option value="3+">Class 3+</option>
              <option value="3">Class 3</option>
            </select>
            <SuggestionChip v-if="suggestion('coverageClass') !== null"
              label="Suggested" :value="formatSuggestion('coverageClass', suggestion('coverageClass'))"
              @use="useSuggestion('coverageClass')" @ignore="dismissSuggestion('coverageClass')" />
          </FormField>

          <!-- Insured age range -->
          <FormField v-if="show('minAge')" label="Min age" error-key="minAge" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.minAge" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.minAge" type="number" min="0" max="99"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('minAge')" />
            <SuggestionChip v-if="suggestion('minAge') !== null"
              label="Suggested" :value="formatSuggestion('minAge', suggestion('minAge'))"
              @use="useSuggestion('minAge')" @ignore="dismissSuggestion('minAge')" />
          </FormField>
          <FormField v-if="show('maxAge')" label="Max age" error-key="maxAge" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.maxAge" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.maxAge" type="number" min="0" max="99"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('maxAge')" />
            <SuggestionChip v-if="suggestion('maxAge') !== null"
              label="Suggested" :value="formatSuggestion('maxAge', suggestion('maxAge'))"
              @use="useSuggestion('maxAge')" @ignore="dismissSuggestion('maxAge')" />
          </FormField>

          <!-- Gender (Life-Main only) -->
          <FormField v-if="show('gender')" label="Gender" error-key="gender" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.gender" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <select v-model="form.gender"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
              @change="markEdited('gender')">
              <option value="all">All</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
            <SuggestionChip v-if="suggestion('gender') !== null"
              label="Suggested" :value="formatSuggestion('gender', suggestion('gender'))"
              @use="useSuggestion('gender')" @ignore="dismissSuggestion('gender')" />
          </FormField>

          <!-- Vehicle age (Motor) -->
          <FormField v-if="show('vehicleAgeMin')" label="Vehicle age min (years)" error-key="vehicleAgeMin" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.vehicleAgeMin" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.vehicleAgeMin" type="number" min="0" max="99"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('vehicleAgeMin')" />
            <SuggestionChip v-if="suggestion('vehicleAgeMin') !== null"
              label="Suggested" :value="formatSuggestion('vehicleAgeMin', suggestion('vehicleAgeMin'))"
              @use="useSuggestion('vehicleAgeMin')" @ignore="dismissSuggestion('vehicleAgeMin')" />
          </FormField>
          <FormField v-if="show('vehicleAgeMax')" label="Vehicle age max (years)" error-key="vehicleAgeMax" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.vehicleAgeMax" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.vehicleAgeMax" type="number" min="0" max="99"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('vehicleAgeMax')" />
            <SuggestionChip v-if="suggestion('vehicleAgeMax') !== null"
              label="Suggested" :value="formatSuggestion('vehicleAgeMax', suggestion('vehicleAgeMax'))"
              @use="useSuggestion('vehicleAgeMax')" @ignore="dismissSuggestion('vehicleAgeMax')" />
          </FormField>

          <!-- Sum assured / insured range -->
          <FormField v-if="show('minSumAssure')" label="Min sum assured / insured" error-key="minSumAssure" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.minSumAssure" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.minSumAssure" type="number" min="0"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('minSumAssure')" />
            <SuggestionChip v-if="suggestion('minSumAssure') !== null"
              label="Suggested" :value="formatSuggestion('minSumAssure', suggestion('minSumAssure'))"
              @use="useSuggestion('minSumAssure')" @ignore="dismissSuggestion('minSumAssure')" />
          </FormField>
          <FormField v-if="show('maxSumAssure')" label="Max sum assured / insured" error-key="maxSumAssure" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.maxSumAssure" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.maxSumAssure" type="number" min="0"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('maxSumAssure')" />
            <SuggestionChip v-if="suggestion('maxSumAssure') !== null"
              label="Suggested" :value="formatSuggestion('maxSumAssure', suggestion('maxSumAssure'))"
              @use="useSuggestion('maxSumAssure')" @ignore="dismissSuggestion('maxSumAssure')" />
          </FormField>

          <!-- Duration / Pay years / Premium mode -->
          <FormField v-if="show('durationYears')" label="Duration years" error-key="durationYears" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.durationYears" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.durationYears" type="number" min="1" max="99"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('durationYears')" />
            <SuggestionChip v-if="suggestion('durationYears') !== null"
              label="Suggested" :value="formatSuggestion('durationYears', suggestion('durationYears'))"
              @use="useSuggestion('durationYears')" @ignore="dismissSuggestion('durationYears')" />
          </FormField>
          <FormField v-if="show('payYears')" label="Pay years" error-key="payYears" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.payYears" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.payYears" type="number" min="1" max="99"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('payYears')" />
            <SuggestionChip v-if="suggestion('payYears') !== null"
              label="Suggested" :value="formatSuggestion('payYears', suggestion('payYears'))"
              @use="useSuggestion('payYears')" @ignore="dismissSuggestion('payYears')" />
          </FormField>
          <FormField v-if="show('premiumMode')" label="Premium mode" error-key="premiumMode" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.premiumMode" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <select v-model="form.premiumMode"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
              @change="markEdited('premiumMode')">
              <option value="annual">Annual</option>
              <option value="semiannual">Semi-annual</option>
              <option value="quarterly">Quarterly</option>
              <option value="monthly">Monthly</option>
              <option value="single">Single</option>
            </select>
            <SuggestionChip v-if="suggestion('premiumMode') !== null"
              label="Suggested" :value="formatSuggestion('premiumMode', suggestion('premiumMode'))"
              @use="useSuggestion('premiumMode')" @ignore="dismissSuggestion('premiumMode')" />
          </FormField>

          <!-- Premium range -->
          <FormField v-if="show('minPremium')" label="Min premium" error-key="minPremium" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.minPremium" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.minPremium" type="number" min="0"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('minPremium')" />
            <SuggestionChip v-if="suggestion('minPremium') !== null"
              label="Suggested" :value="formatSuggestion('minPremium', suggestion('minPremium'))"
              @use="useSuggestion('minPremium')" @ignore="dismissSuggestion('minPremium')" />
          </FormField>
          <FormField v-if="show('maxPremium')" label="Max premium" error-key="maxPremium" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.maxPremium" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
            </template>
            <input v-model.number="form.maxPremium" type="number" min="0"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('maxPremium')" />
            <SuggestionChip v-if="suggestion('maxPremium') !== null"
              label="Suggested" :value="formatSuggestion('maxPremium', suggestion('maxPremium'))"
              @use="useSuggestion('maxPremium')" @ignore="dismissSuggestion('maxPremium')" />
          </FormField>

          <!-- Underwriting (Life-Main mostly; Life-Group only shows requireMedical) -->
          <FormField v-if="show('requireMedical') || show('smokerAccepted') || show('preexistingExcluded')"
            label="Underwriting" class="col-span-2" error-key="requireMedical" :errors="fieldErrors">
            <div class="flex items-center gap-6 pt-1 text-sm flex-wrap">
              <label v-if="show('requireMedical')" class="inline-flex items-center gap-2">
                <input v-model="form.requireMedical" type="checkbox" @change="markEdited('requireMedical')" />
                Requires medical exam
                <span v-if="copiedFields.requireMedical" class="ml-1 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
              </label>
              <label v-if="show('smokerAccepted')" class="inline-flex items-center gap-2">
                <input v-model="form.smokerAccepted" type="checkbox" @change="markEdited('smokerAccepted')" />
                Smokers accepted
                <span v-if="copiedFields.smokerAccepted" class="ml-1 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
              </label>
              <label v-if="show('preexistingExcluded')" class="inline-flex items-center gap-2">
                <input v-model="form.preexistingExcluded" type="checkbox" @change="markEdited('preexistingExcluded')" />
                Pre-existing excluded
                <span v-if="copiedFields.preexistingExcluded" class="ml-1 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product" />
              </label>
            </div>
            <div v-if="suggestion('requireMedical') !== null || suggestion('smokerAccepted') !== null || suggestion('preexistingExcluded') !== null"
              class="flex flex-wrap gap-2 mt-2">
              <SuggestionChip v-if="suggestion('requireMedical') !== null"
                label="Medical" :value="formatSuggestion('requireMedical', suggestion('requireMedical'))"
                @use="useSuggestion('requireMedical')" @ignore="dismissSuggestion('requireMedical')" />
              <SuggestionChip v-if="suggestion('smokerAccepted') !== null"
                label="Smokers" :value="formatSuggestion('smokerAccepted', suggestion('smokerAccepted'))"
                @use="useSuggestion('smokerAccepted')" @ignore="dismissSuggestion('smokerAccepted')" />
              <SuggestionChip v-if="suggestion('preexistingExcluded') !== null"
                label="Pre-existing" :value="formatSuggestion('preexistingExcluded', suggestion('preexistingExcluded'))"
                @use="useSuggestion('preexistingExcluded')" @ignore="dismissSuggestion('preexistingExcluded')" />
            </div>
          </FormField>

          <!-- Summary / Notes (all packs) -->
          <FormField v-if="show('summary')" label="Summary" class="col-span-2" error-key="summary" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.summary" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product — often needs edits" />
            </template>
            <textarea v-model="form.summary" rows="2"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('summary')" />
          </FormField>
          <FormField v-if="show('notes')" label="Notes" class="col-span-2" error-key="notes" :errors="fieldErrors">
            <template #label-extra>
              <span v-if="copiedFields.notes" class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full bg-brand-500" title="Copied from source product — often needs edits" />
            </template>
            <textarea v-model="form.notes" rows="2"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="markEdited('notes')" />
          </FormField>
        </div>
      </div>

      <!-- Stranded-fields confirm dialog -->
      <div v-if="strandedDialog" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-5">
          <h3 class="text-base font-semibold text-slate-900 mb-2">Type changed</h3>
          <p class="text-sm text-slate-600 mb-3">
            Some fields don't apply to <strong>{{ getPack(strandedDialog.toPack).labelEn }}</strong>:
          </p>
          <ul class="text-xs text-slate-600 mb-4 space-y-0.5 max-h-32 overflow-y-auto pl-4 list-disc">
            <li v-for="f in strandedDialog.fields" :key="f">{{ f }}</li>
          </ul>
          <p class="text-xs text-slate-500 mb-4">
            <strong>Hide</strong> keeps the values so they return if you switch back. <strong>Clear</strong> resets them.
          </p>
          <div class="flex justify-end gap-2">
            <button @click="resolveStranded('cancel')"
              class="px-3 py-1.5 rounded-lg border border-slate-300 text-sm text-slate-700 hover:bg-slate-50">
              Keep as-is
            </button>
            <button @click="resolveStranded('hide')"
              class="px-3 py-1.5 rounded-lg border border-amber-300 text-sm text-amber-700 hover:bg-amber-50">
              Hide
            </button>
            <button @click="resolveStranded('clear')"
              class="px-3 py-1.5 rounded-lg bg-rose-600 text-white text-sm hover:bg-rose-700">
              Clear
            </button>
          </div>
        </div>
      </div>
    </template>
  </CreateModal>
</template>
