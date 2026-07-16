<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import CreateModal from '../../components/CreateModal.vue'
import FormField from '../../components/FormField.vue'
import { fetchCarrierList, type CarrierListRow } from '../../api/carriers'
import { fetchNextProductCode, fetchProductTaxonomy, type ProductTaxonomyRow } from '../../api/products'

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
})

/** Selected carrier's insureType — drives whether the user picks main/rider. */
const selectedCarrier = computed(() => carriers.value.find((c) => c.id === form.carrierId) ?? null)
const carrierInsureType = computed(() => selectedCarrier.value?.insureType ?? '')
const needsMainRiderChoice = computed(() => carrierInsureType.value === 'life')

/**
 * Product-group ("type" column) options depend on carrier insureType + mainRider:
 *   - Life + Main    → user picks Life / PA / Group
 *   - Life + Rider   → auto Rider
 *   - Non-life + Main → user picks Group / Motor / Non-Motor
 *   - Tax             → auto Tax
 */
/** Options as {storage, label} pairs — storage is what we save, label is what the user sees. */
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
  if (!id) {
    form.mainRider = ''
    form.type = ''
    return
  }
  if (form.code === '' || codeAutoFilled.value) {
    void suggestCode(id)
  }
  // Derive main/rider from carrier's insureType: non-life → Main, tax → TAX,
  // life → user must pick (leave blank).
  const t = carrierInsureType.value
  if (t === 'non-life') form.mainRider = 'Main'
  else if (t === 'tax') form.mainRider = 'TAX'
  else if (t === 'life') form.mainRider = ''
})

/**
 * Product group follows carrier + main/rider. When a rule auto-picks it
 * (Life+Rider → Rider, Tax → Tax), set it. When the user must choose from
 * a list, blank it so a prior stale value doesn't leak across carriers.
 */
watch(
  () => [carrierInsureType.value, form.mainRider] as const,
  () => {
    if (productGroupAutoValue.value !== '') {
      form.type = productGroupAutoValue.value
    } else if (needsProductGroupChoice.value) {
      // User must pick — clear only if the current value is not one of the new options.
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

const canSubmit = computed(() =>
  form.code.trim() !== '' && form.name.trim() !== '' && form.carrierId !== '' &&
  (!needsMainRiderChoice.value || form.mainRider !== '') &&
  (!needsProductGroupChoice.value || form.type !== '') &&
  (form.category !== '') &&
  (!subcategoryRequired.value || form.subCategory !== ''),
)

const payload = computed(() => ({
  code: form.code.trim(),
  name: form.name.trim(),
  nameEn: form.nameEn.trim() || null,
  carrierId: Number(form.carrierId),
  type: form.type || null,
  mainRider: form.mainRider || null,
  category: form.category.trim() || null,
  subCategory: form.subCategory.trim() || null,
  minAge: form.minAge,
  maxAge: form.maxAge,
  active: true,
}))

watch(
  () => props.open,
  (v) => {
    if (v) {
      Object.assign(form, {
        code: '', name: '', nameEn: '', carrierId: '',
        type: '', mainRider: '', category: '', subCategory: '',
        minAge: 0, maxAge: 99,
      })
      codeAutoFilled.value = false
      void loadCarriers()
    }
  },
)
</script>

<template>
  <CreateModal
    :open="open" entity="products" title="New Product"
    :payload="payload" :can-submit="canSubmit"
    @close="emit('close')" @created="(row) => emit('created', row)"
  >
    <template #default="{ fieldErrors }">
      <div class="grid grid-cols-2 gap-4">
        <FormField label="Code" required error-key="code" :errors="fieldErrors" :hint="form.carrierId ? (codeAutoFilled ? 'Auto-filled from carrier — edit to override.' : 'Manual code — clear to auto-fill from carrier.') : 'Pick a carrier to auto-generate.'">
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
          error-key="mainRider" :errors="fieldErrors"
          :hint="needsMainRiderChoice
            ? 'Life carrier — pick whether this product is a main policy or a rider.'
            : (carrierInsureType === 'non-life' ? 'Non-life carrier — auto-set to Main.' : 'Tax carrier — auto-set to TAX.')">
          <select v-if="needsMainRiderChoice" v-model="form.mainRider"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400">
            <option value="">— select —</option>
            <option value="Main">Main</option>
            <option value="Rider">Rider</option>
          </select>
          <input v-else :value="form.mainRider" disabled
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-slate-50 text-slate-500 focus:outline-none" />
        </FormField>
        <FormField label="Name (Thai)" required class="col-span-2" error-key="name" :errors="fieldErrors">
          <input v-model.trim="form.name" placeholder="ประกันสุขภาพ ..."
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Name (English)" class="col-span-2" error-key="nameEn" :errors="fieldErrors">
          <input v-model.trim="form.nameEn"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField v-if="form.carrierId"
          :label="needsProductGroupChoice ? 'Product Group' : 'Product Group (auto)'"
          :required="needsProductGroupChoice"
          error-key="type" :errors="fieldErrors"
          :hint="needsProductGroupChoice
            ? (carrierInsureType === 'life'
                ? 'Life + Main — pick Life / PA / Group.'
                : 'Non-life + Main — pick Group / Motor / Non-Motor.')
            : (form.type === 'Rider' ? 'Life + Rider — auto-set to Rider.' : 'Tax carrier — auto-set to Tax.')">
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
        <FormField label="Min age" error-key="minAge" :errors="fieldErrors">
          <input v-model.number="form.minAge" type="number" min="0" max="99"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
        <FormField label="Max age" error-key="maxAge" :errors="fieldErrors">
          <input v-model.number="form.maxAge" type="number" min="0" max="99"
            class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
        </FormField>
      </div>
    </template>
  </CreateModal>
</template>
