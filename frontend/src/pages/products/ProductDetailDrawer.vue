<script setup lang="ts">
// Product detail drawer — full spec + two-panel commission-rate editor.
import { ref, watch, computed, onMounted, reactive } from 'vue'
import { fetchProduct, fetchProductTaxonomy, type ProductDetail, type ProductTaxonomyRow, type ProductCommissionRatePanel, type ProductCommissionBandRow } from '../../api/products'
import EditableField from '../../components/EditableField.vue'
import DeleteConfirmDialog from '../../components/DeleteConfirmDialog.vue'
import { api, ApiError } from '../../api/client'
import { useProductStore } from '../../stores/products'

const taxonomy = ref<ProductTaxonomyRow[]>([])

onMounted(async () => {
  try {
    const res = await fetchProductTaxonomy()
    taxonomy.value = res.data
  } catch { /* silent */ }
})

/**
 * Insure-type helpers. DB stores `Life` / `Non-Life` / `Tax` (capitalized,
 * hyphenated) while the create modal's radio uses `life` / `non-life` /
 * `tax`. Normalizing both sides keeps the drawer's conditionals in sync
 * with the modal's regardless of drift.
 */
function normalizeInsureType(v: string): 'life' | 'non-life' | 'tax' | '' {
  const s = (v || '').toLowerCase().replace(/\s+/g, '-')
  return s === 'life' || s === 'non-life' || s === 'tax' ? s : ''
}
function isLifeCarrier(insureType: string): boolean {
  return normalizeInsureType(insureType) === 'life'
}
function needsMainRiderChoice(insureType: string): boolean {
  // Same rule as the create modal — only Life carriers ask the operator
  // to pick Main vs Rider. Non-Life is forced Main; Tax is forced TAX.
  return normalizeInsureType(insureType) === 'life'
}

/**
 * Product-group options mirror ProductCreateModal's `productGroupOptions`
 * computed exactly. Life+Main → Life / PA / Group-Life; Non-Life+Main →
 * Group-NL / Motor / Non-Motor; Life+Rider → Rider auto; Tax → Tax auto.
 * When the auto value applies we return an empty list so the drawer
 * renders a static label instead of a select.
 */
function productGroupOptionsFor(insureType: string, mainRider: string | null): { value: string; label: string }[] {
  const t = normalizeInsureType(insureType)
  if (t === 'life' && mainRider === 'Main') {
    return [
      { value: 'Life', label: 'Life' },
      { value: 'PA', label: 'PA' },
      { value: 'Group-Life', label: 'Group' },
    ]
  }
  if (t === 'non-life' && mainRider === 'Main') {
    return [
      { value: 'Group-NL', label: 'Group' },
      { value: 'Motor', label: 'Motor' },
      { value: 'Non-Motor', label: 'Non-Motor' },
    ]
  }
  return []
}

function categoriesForGroup(group: string): { value: string; label: string }[] {
  const seen = new Set<string>()
  for (const r of taxonomy.value) if (r.group === group) seen.add(r.category)
  return [...seen].map((c) => ({ value: c, label: c }))
}
function subcategoriesFor(group: string, category: string): { value: string; label: string }[] {
  return taxonomy.value
    .filter((r) => r.group === group && r.category === category && r.subcategory !== null)
    .map((r) => ({ value: r.subcategory as string, label: r.subcategory as string }))
}

const productStore = useProductStore()
const props = defineProps<{ productId: string | null }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const product = ref<ProductDetail | null>(null)

/**
 * Editable commission-rate panels. Held as PERCENT (0..100) for the operator;
 * converted to fractions (0..1) at save time. Repopulated whenever a new
 * product loads. `null` = field is blank.
 */
type EditablePanel = { flatRate: number | null; yr1: number | null; yr2: number | null; yr3: number | null; yr4: number | null; yr5: number | null; yr6_10: number | null; yr11Up: number | null }
function blankPanel(): EditablePanel {
  return { flatRate: null, yr1: null, yr2: null, yr3: null, yr4: null, yr5: null, yr6_10: null, yr11Up: null }
}
function panelFromApi(p: ProductCommissionRatePanel | null): EditablePanel {
  if (p === null) return blankPanel()
  const toPct = (v: number | null) => (v === null ? null : Math.round(v * 100 * 100) / 100)
  return {
    flatRate: toPct(p.flatRate),
    yr1: toPct(p.yr1), yr2: toPct(p.yr2), yr3: toPct(p.yr3),
    yr4: toPct(p.yr4), yr5: toPct(p.yr5),
    yr6_10: toPct(p.yr6_10), yr11Up: toPct(p.yr11Up),
  }
}
const carrierToHub = reactive<EditablePanel>(blankPanel())
const hubToAgent = reactive<EditablePanel>(blankPanel())
const commissionScheme = computed<'flat' | 'life_years'>(() => product.value?.commissionRates?.scheme ?? 'flat')

/**
 * Editable bands for Life products. Rate values held as PERCENT for the
 * operator; converted to fractions at save time. Replace-all semantics on
 * save — reloading rebuilds from the server response.
 */
type EditableBand = {
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
function bandFromApi(b: ProductCommissionBandRow): EditableBand {
  const toPct = (v: number | null) => (v === null ? null : Math.round(v * 100 * 100) / 100)
  return {
    sumAssuredMin: b.sumAssuredMin,
    sumAssuredMax: b.sumAssuredMax,
    entryAgeMin: b.entryAgeMin,
    entryAgeMax: b.entryAgeMax,
    yr1: toPct(b.yr1), yr2: toPct(b.yr2), yr3: toPct(b.yr3),
    yr4: toPct(b.yr4), yr5: toPct(b.yr5), yr6Up: toPct(b.yr6Up),
  }
}
function bandToPayload(b: EditableBand): Record<string, number | null> {
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
const carrierToHubBands = ref<EditableBand[]>([])
const hubToAgentBands = ref<EditableBand[]>([])
function addBand(kind: 'carrierToHub' | 'hubToAgent'): void {
  const blank: EditableBand = { sumAssuredMin: null, sumAssuredMax: null, entryAgeMin: null, entryAgeMax: null, yr1: null, yr2: null, yr3: null, yr4: null, yr5: null, yr6Up: null }
  if (kind === 'carrierToHub') carrierToHubBands.value = [...carrierToHubBands.value, blank]
  else hubToAgentBands.value = [...hubToAgentBands.value, blank]
}
function removeBand(kind: 'carrierToHub' | 'hubToAgent', i: number): void {
  if (kind === 'carrierToHub') carrierToHubBands.value = carrierToHubBands.value.filter((_, j) => j !== i)
  else hubToAgentBands.value = hubToAgentBands.value.filter((_, j) => j !== i)
}
const savingRates = ref(false)
const rateSaveError = ref<string | null>(null)
const rateSaveFlash = ref<string | null>(null)

function percentToFraction(v: number | null): number | null {
  if (v === null || Number.isNaN(v)) return null
  return Math.round((v / 100) * 100000) / 100000
}
function panelToPayload(p: EditablePanel, scheme: 'flat' | 'life_years'): Record<string, number | null> {
  if (scheme === 'flat') return { flatRate: percentToFraction(p.flatRate) }
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

async function saveCommissionRates(): Promise<void> {
  if (!product.value) return
  savingRates.value = true
  rateSaveError.value = null
  rateSaveFlash.value = null
  try {
    const payload: Record<string, unknown> = {}
    if (commissionScheme.value === 'flat') {
      payload.commissionRates = {
        carrierToHub: panelToPayload(carrierToHub, 'flat'),
        hubToAgent: panelToPayload(hubToAgent, 'flat'),
      }
    } else {
      payload.commissionBands = {
        carrierToHub: carrierToHubBands.value.map(bandToPayload),
        hubToAgent: hubToAgentBands.value.map(bandToPayload),
      }
    }
    const res = await api.patch<{ data: ProductDetail }>(`products/${product.value.id}`, payload)
    product.value = res.data
    Object.assign(carrierToHub, panelFromApi(res.data.commissionRates?.carrierToHub ?? null))
    Object.assign(hubToAgent, panelFromApi(res.data.commissionRates?.hubToAgent ?? null))
    carrierToHubBands.value = (res.data.commissionBands?.carrierToHub ?? []).map(bandFromApi)
    hubToAgentBands.value = (res.data.commissionBands?.hubToAgent ?? []).map(bandFromApi)
    rateSaveFlash.value = 'บันทึกค่าคอมมิชชั่นแล้ว'
    setTimeout(() => { rateSaveFlash.value = null }, 2000)
  } catch (e: unknown) {
    rateSaveError.value = e instanceof ApiError ? (e.body?.message ?? `HTTP ${e.status}`) : (e instanceof Error ? e.message : 'Save failed')
  } finally {
    savingRates.value = false
  }
}

// ── Delete ────────────────────────────────────────────────────────────────
const showDelete = ref(false)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

async function doDelete(): Promise<void> {
  if (!props.productId) return
  deleting.value = true
  deleteError.value = null
  try {
    await api.delete(`products/${props.productId}`)
    await productStore.loadPage({})
    showDelete.value = false
    emit('close')
  } catch (e: unknown) {
    deleteError.value = e instanceof ApiError ? e.message : 'Delete failed'
  } finally {
    deleting.value = false
  }
}

// Optimistic apply — patch the in-memory product object.
function apply(pathKey: string, v: unknown): void {
  if (!product.value) return
  const parts = pathKey.split('.')
  let obj: Record<string, unknown> = product.value as unknown as Record<string, unknown>
  for (let i = 0; i < parts.length - 1; i++) {
    const next = obj[parts[i]]
    if (next && typeof next === 'object') {
      obj = next as Record<string, unknown>
    } else {
      return
    }
  }
  obj[parts[parts.length - 1]] = v
}
const loading = ref(false)
const errorMsg = ref<string | null>(null)

watch(
  () => props.productId,
  async (id) => {
    if (!id) {
      product.value = null
      Object.assign(carrierToHub, blankPanel())
      Object.assign(hubToAgent, blankPanel())
      carrierToHubBands.value = []
      hubToAgentBands.value = []
      return
    }
    loading.value = true
    errorMsg.value = null
    try {
      const prod = await fetchProduct(id)
      product.value = prod.data
      Object.assign(carrierToHub, panelFromApi(prod.data.commissionRates?.carrierToHub ?? null))
      Object.assign(hubToAgent, panelFromApi(prod.data.commissionRates?.hubToAgent ?? null))
      carrierToHubBands.value = (prod.data.commissionBands?.carrierToHub ?? []).map(bandFromApi)
      hubToAgentBands.value = (prod.data.commissionBands?.hubToAgent ?? []).map(bandFromApi)
    } catch (e: unknown) {
      errorMsg.value = e instanceof Error ? e.message : 'Failed to load product detail.'
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

</script>

<template>
  <div v-if="props.productId" class="fixed inset-0 bg-slate-900/40 flex justify-end z-50" @click.self="emit('close')">
    <div class="bg-white w-full max-w-3xl h-full overflow-y-auto shadow-xl flex flex-col">
      <header class="px-6 py-4 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white z-10">
        <div v-if="product">
          <div class="flex items-center gap-2 text-xs uppercase text-slate-400">
            <span class="font-mono">{{ product.code }}</span>
            <span v-if="product.commissionCode">· {{ product.commissionCode }}</span>
          </div>
          <div class="text-lg font-semibold text-slate-900 mt-1">{{ product.name }}</div>
          <div v-if="product.nameEn" class="text-xs text-slate-500 mt-0.5">{{ product.nameEn }}</div>
        </div>
        <div v-else class="text-slate-500">Loading…</div>
        <button class="text-slate-400 hover:text-slate-700 p-2" @click="emit('close')">
          <i class="pi pi-times" />
        </button>
      </header>

      <div v-if="errorMsg" class="m-6 p-4 bg-rose-50 border border-rose-200 rounded-lg text-rose-700 text-sm">
        {{ errorMsg }}
      </div>

      <div v-if="product" class="flex-1 p-6 space-y-6">
        <!--
          Details section — mirrors the "+ New Product" modal 1:1 so the
          drawer is the exact edit view of the create form. Every field
          the create modal writes is here and editable; every field it
          doesn't touch is intentionally hidden. Carrier itself is
          read-only — moving a product between carriers is a different
          operation, not a field edit.
        -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Details</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div>
              <div class="text-xs text-slate-400">ประเภทประกัน</div>
              <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs',
                product.carrierInsureType === 'Life' || product.carrierInsureType === 'life'
                  ? 'bg-brand-50 text-brand-700'
                  : product.carrierInsureType === 'Tax' || product.carrierInsureType === 'tax'
                    ? 'bg-amber-50 text-amber-700'
                    : 'bg-slate-100 text-slate-700']">
                {{ product.carrierInsureType || '—' }}
              </span>
            </div>
            <div>
              <div class="text-xs text-slate-400">Status</div>
              <span v-if="product.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">active</span>
              <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">inactive</span>
            </div>
            <div class="md:col-span-2">
              <div class="text-xs text-slate-400">Carrier</div>
              <div class="text-slate-700">
                <span class="font-mono">{{ product.carrierCode || '—' }}</span>
                <span v-if="product.carrierName" class="text-slate-500"> · {{ product.carrierName }}</span>
              </div>
            </div>

            <div>
              <div class="text-xs text-slate-400">Code</div>
              <EditableField entity="products" :id="product.id" field="code" :value="product.code" @update="v => apply('code', v)" />
            </div>
            <div>
              <div class="text-xs text-slate-400">Main / Rider</div>
              <EditableField
                v-if="needsMainRiderChoice(product.carrierInsureType)"
                entity="products" :id="product.id" field="mainRider" type="select"
                :options="[{ value: 'Main', label: 'Main' }, { value: 'Rider', label: 'Rider' }]"
                :value="product.mainRider" @update="v => apply('mainRider', v)"
              />
              <div v-else class="text-slate-500">{{ product.mainRider || '—' }}</div>
            </div>

            <div>
              <div class="text-xs text-slate-400">Product Group</div>
              <EditableField
                v-if="productGroupOptionsFor(product.carrierInsureType, product.mainRider).length > 0"
                entity="products" :id="product.id" field="type" type="select"
                :options="productGroupOptionsFor(product.carrierInsureType, product.mainRider)"
                :value="product.type" @update="v => apply('type', v)"
              />
              <div v-else class="text-slate-500">{{ product.type || '—' }}</div>
            </div>
            <div class="md:col-span-3">
              <div class="text-xs text-slate-400">Category</div>
              <EditableField
                v-if="categoriesForGroup(product.type).length > 0"
                entity="products" :id="product.id" field="category" type="select"
                :options="categoriesForGroup(product.type)"
                :value="product.category" @update="v => apply('category', v)"
              />
              <div v-else class="text-slate-500">{{ product.category || '—' }}</div>
            </div>

            <div class="md:col-span-4">
              <div class="text-xs text-slate-400">Sub-category</div>
              <EditableField
                v-if="subcategoriesFor(product.type, product.category).length > 0"
                entity="products" :id="product.id" field="subCategory" type="select"
                :options="subcategoriesFor(product.type, product.category)"
                :value="product.subCategory" @update="v => apply('subCategory', v)"
              />
              <div v-else class="text-slate-500">—</div>
            </div>

            <div class="md:col-span-4">
              <div class="text-xs text-slate-400">Name (Thai)</div>
              <EditableField entity="products" :id="product.id" field="name" :value="product.name" @update="v => apply('name', v)" />
            </div>
          </div>
        </section>

        <!--
          Age band — Life only, mirroring the modal's conditional. The
          modal skips the age fields entirely for non-Life products
          because Motor / Fire / etc. don't gate on insured age at the
          product level; we do the same here.
        -->
        <section v-if="isLifeCarrier(product.carrierInsureType)">
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Age band</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div>
              <div class="text-xs text-slate-400">Min age (0–99)</div>
              <EditableField entity="products" :id="product.id" field="minAge" type="number" :value="product.minAge" @update="v => apply('minAge', v)" />
            </div>
            <div>
              <div class="text-xs text-slate-400">Max age (0–99)</div>
              <EditableField entity="products" :id="product.id" field="maxAge" type="number" :value="product.maxAge" @update="v => apply('maxAge', v)" />
            </div>
          </div>
        </section>

        <!-- Commission rates — two-panel editor. Right panel (hub→agent)
             feeds the MGM engine. -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Commission rates</h3>
          <div class="text-xs text-slate-500 mb-3">
            ค่าที่บันทึกที่นี่จะถูกใช้ในระบบ MGM แทนตารางบริษัท × ประเภทสินค้า
          </div>
          <!-- Flat scheme (Non-Life, PA, Group, Tax): one % per direction. -->
          <div v-if="commissionScheme === 'flat'" class="grid grid-cols-2 gap-4">
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="text-sm font-medium text-slate-800 mb-2">บริษัท → InsureHub</div>
              <div class="relative w-32">
                <input v-model.number="carrierToHub.flatRate" type="number" min="0" max="100" step="0.01"
                  placeholder="เช่น 15"
                  class="w-full border border-slate-200 rounded-lg pl-3 pr-8 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">%</span>
              </div>
            </div>
            <div class="border border-brand-200 bg-brand-50/40 rounded-lg p-3">
              <div class="text-sm font-medium text-brand-800 mb-2">InsureHub → Agent (MGM)</div>
              <div class="relative w-32">
                <input v-model.number="hubToAgent.flatRate" type="number" min="0" max="100" step="0.01"
                  placeholder="เช่น 10"
                  class="w-full border border-brand-200 rounded-lg pl-3 pr-8 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">%</span>
              </div>
            </div>
          </div>
          <!-- Life scheme: banded tables. Rendered twice (once per direction)
               with a shared v-for so the row layout stays in sync. -->
          <div v-else class="space-y-4">
            <div class="rounded-lg p-3 border border-slate-200">
              <div class="text-sm font-medium mb-2 text-slate-800">บริษัท → InsureHub</div>
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
                    <tr v-if="carrierToHubBands.length === 0">
                      <td colspan="11" class="px-1 py-3 text-center text-slate-400 italic">
                        ยังไม่มีช่วงราคา / อายุ — กด "+ เพิ่มช่วง"
                      </td>
                    </tr>
                    <tr v-for="(band, i) in carrierToHubBands" :key="i" class="border-t border-slate-100">
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
                        <button type="button" @click="removeBand('carrierToHub', i)" class="text-rose-500 hover:text-rose-700 text-xs">
                          <i class="pi pi-trash" />
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <button type="button" @click="addBand('carrierToHub')" class="mt-2 px-2 py-1 rounded border border-slate-300 text-slate-600 hover:bg-slate-50 text-xs">+ เพิ่มช่วง</button>
            </div>
            <div class="rounded-lg p-3 border border-brand-200 bg-brand-50/40">
              <div class="text-sm font-medium mb-2 text-brand-800">InsureHub → Agent (MGM)</div>
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
                    <tr v-if="hubToAgentBands.length === 0">
                      <td colspan="11" class="px-1 py-3 text-center text-slate-400 italic">
                        ยังไม่มีช่วงราคา / อายุ — กด "+ เพิ่มช่วง"
                      </td>
                    </tr>
                    <tr v-for="(band, i) in hubToAgentBands" :key="i" class="border-t border-slate-100">
                      <td class="px-1 py-1"><input v-model.number="band.sumAssuredMin" type="number" min="0" step="1000" placeholder="ไม่จำกัด" class="w-24 border border-brand-200 rounded px-1 py-0.5 text-xs" /></td>
                      <td class="px-1 py-1"><input v-model.number="band.sumAssuredMax" type="number" min="0" step="1000" placeholder="ไม่จำกัด" class="w-24 border border-brand-200 rounded px-1 py-0.5 text-xs" /></td>
                      <td class="px-1 py-1"><input v-model.number="band.entryAgeMin" type="number" min="0" max="120" step="1" placeholder="0" class="w-14 border border-brand-200 rounded px-1 py-0.5 text-xs" /></td>
                      <td class="px-1 py-1"><input v-model.number="band.entryAgeMax" type="number" min="0" max="120" step="1" placeholder="120" class="w-14 border border-brand-200 rounded px-1 py-0.5 text-xs" /></td>
                      <td v-for="y in ['yr1','yr2','yr3','yr4','yr5','yr6Up']" :key="y" class="px-1 py-1">
                        <input :value="(band as any)[y]" type="number" min="0" max="100" step="0.01"
                          @input="(band as any)[y] = ($event.target as HTMLInputElement).valueAsNumber || null"
                          class="w-14 border border-brand-200 rounded px-1 py-0.5 text-xs text-right" />
                      </td>
                      <td class="px-1 py-1">
                        <button type="button" @click="removeBand('hubToAgent', i)" class="text-rose-500 hover:text-rose-700 text-xs">
                          <i class="pi pi-trash" />
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <button type="button" @click="addBand('hubToAgent')" class="mt-2 px-2 py-1 rounded border border-brand-200 text-brand-700 hover:bg-brand-50 text-xs">+ เพิ่มช่วง</button>
            </div>
          </div>
          <div class="mt-3 flex items-center gap-3">
            <button type="button"
              class="px-3 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5"
              :disabled="savingRates" @click="saveCommissionRates">
              <i class="pi pi-check text-xs" v-if="!savingRates" />
              <i class="pi pi-spin pi-spinner text-xs" v-else />
              {{ savingRates ? 'Saving…' : 'บันทึกค่าคอมมิชชั่น' }}
            </button>
            <div v-if="rateSaveFlash" class="text-xs text-emerald-600">{{ rateSaveFlash }}</div>
            <div v-if="rateSaveError" class="text-xs text-rose-600">{{ rateSaveError }}</div>
          </div>
        </section>

        <div v-if="loading" class="text-center text-slate-500 py-4">Loading…</div>
      </div>

      <!-- Footer -->
      <footer v-if="product" class="border-t border-slate-200 px-6 py-3 flex items-center justify-between sticky bottom-0 bg-white">
        <div class="text-xs text-slate-400">
          Click any field to edit · Enter saves, Esc cancels
        </div>
        <button type="button"
          class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 text-sm flex items-center gap-1.5"
          @click="showDelete = true">
          <i class="pi pi-trash text-xs" /> Delete
        </button>
      </footer>
    </div>

    <DeleteConfirmDialog
      v-if="product"
      :open="showDelete"
      :label="`product ${product.code}`"
      :confirm-token="product.code"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
      @cancel="showDelete = false"
    />
  </div>
</template>
