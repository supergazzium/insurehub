<script setup lang="ts">
// New Product modal — simple, single-screen form. Field visibility follows
// the carrier's insureType: Life carriers force a Main/Rider choice; that
// choice + carrier type drives the product-group options; product group
// drives the category list; category drives the subcategory list.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import CreateModal from '../../components/CreateModal.vue'
import FormField from '../../components/FormField.vue'
import CommissionRatesForm from './CommissionRatesForm.vue'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { fetchNextProductCode, fetchProductTaxonomy, type ProductTaxonomyRow, type CommissionRatesPayload } from '../../api/products'
import { lookupTemplate, fillCarrier } from './productNamePresets'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{
  (e: 'close'): void
  /** Forward the created product row so callers can act on which row was
   *  created (e.g. broadcast it to another tab for auto-populate). */
  (e: 'created', row: Record<string, unknown>): void
}>()

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
  } catch {
    // Silent — category/subcategory dropdowns will just be empty.
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

onMounted(() => { void loadCarriers(); void loadTaxonomy() })

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
  minAge: 0,
  maxAge: 99,
  // Structured commission-rate payload emitted by CommissionRatesForm. Default
  // is `{ shape: 'skip' }` so an operator who ignores the section persists
  // no rates — matching the pre-refactor behavior of a blank percent input.
  commissionRates: { shape: 'skip' } as CommissionRatesPayload,
})

/** Carriers matching the chosen insureType — empties when nothing is picked. */
const availableCarriers = computed<CarrierListRow[]>(() =>
  form.insureType === ''
    ? []
    : carriers.value.filter((c) => c.insureType === form.insureType),
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
// the Name field, we stop overwriting. Cleared on modal open.
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
  ageError.value === null,
)

const payload = computed(() => {
  const base: Record<string, unknown> = {
    code: form.code.trim(),
    name: form.name.trim(),
    carrierId: Number(form.carrierId),
    type: form.type || null,
    mainRider: form.mainRider || null,
    category: form.category.trim() || null,
    subCategory: form.subCategory.trim() || null,
    commissionRates: form.commissionRates,
    active: true,
  }
  // Age band is Life-only — omit for non-life so we don't persist stale
  // 0/99 defaults that would falsely narrow future queries.
  if (form.insureType === 'life') {
    base.minAge = form.minAge
    base.maxAge = form.maxAge
  }
  return base
})

watch(
  () => props.open,
  (v) => {
    if (v) {
      Object.assign(form, {
        insureType: '', code: '', name: '', carrierId: '',
        type: '', mainRider: '', category: '', subCategory: '',
        minAge: 0, maxAge: 99,
        commissionRates: { shape: 'skip' } as CommissionRatesPayload,
      })
      codeAutoFilled.value = false
      nameTouched.value = false
      void loadCarriers()
    }
  },
)

/**
 * After "Save & add another" fires, forward the created row to the parent
 * list and reset just the product-specific fields — keep the insureType,
 * carrier, and main/rider so the operator can batch products under one
 * carrier without re-picking anything. Fetch the next auto-code too.
 */
function onCreated(row: Record<string, unknown>): void {
  emit('created', row)
  form.name = ''
  form.type = ''
  form.category = ''
  form.subCategory = ''
  form.minAge = 0
  form.maxAge = 99
  form.commissionRates = { shape: 'skip' }
  form.code = ''
  codeAutoFilled.value = false
  nameTouched.value = false
  if (form.carrierId) void suggestCode(form.carrierId)
}
</script>

<template>
  <CreateModal
    :open="open" entity="products" title="New Product"
    :payload="payload" :can-submit="canSubmit"
    @close="emit('close')" @created="onCreated"
  >
    <template #default="{ fieldErrors }">
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
        <FormField label="ค่าคอมมิชชั่น" class="col-span-2" error-key="commissionRates" :errors="fieldErrors"
          hint="เลือกรูปแบบให้ตรงกับสินค้า — Rider/Motor/PA มักใช้ 'อัตราเดียว', Whole Life/Endowment ใช้ 'ตามปีกรมธรรม์'">
          <CommissionRatesForm :product-type="form.type" :product-category="form.category" v-model="form.commissionRates" />
        </FormField>
      </div>
    </template>
  </CreateModal>
</template>
