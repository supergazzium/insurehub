<script setup lang="ts">
// Product detail drawer — full spec + commission rate table.
import { ref, watch, computed, onMounted } from 'vue'
import { fetchProduct, fetchProductCommissionRates, fetchProductTaxonomy, type ProductListRow, type CommissionRateRow, type ProductTaxonomyRow } from '../../api/products'
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
 * Group-Life and Group-NL both surface as "Group" in the UI. The storage
 * value depends on the current product's carrier: life carriers save
 * Group-Life, non-life save Group-NL. When building the group dropdown for
 * a given product we collapse the two into a single "Group" option and
 * pick the storage value that matches this product's carrier.
 */
function groupStorageForCarrier(insureType: string): 'Group-Life' | 'Group-NL' {
  return insureType === 'life' ? 'Group-Life' : 'Group-NL'
}
function groupOptionsForProduct(insureType: string): { value: string; label: string }[] {
  const rows = new Set<string>()
  for (const r of taxonomy.value) rows.add(r.group)
  const collapsed: { value: string; label: string }[] = []
  const seenLabels = new Set<string>()
  for (const g of rows) {
    // Group-Life / Group-NL → collapse to single "Group" using the carrier's storage side.
    if (g === 'Group-Life' || g === 'Group-NL') {
      if (seenLabels.has('Group')) continue
      collapsed.push({ value: groupStorageForCarrier(insureType), label: 'Group' })
      seenLabels.add('Group')
    } else {
      collapsed.push({ value: g, label: g })
      seenLabels.add(g)
    }
  }
  return collapsed
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

const product = ref<ProductListRow | null>(null)
const rates = ref<CommissionRateRow[]>([])

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
      rates.value = []
      return
    }
    loading.value = true
    errorMsg.value = null
    try {
      const [prod, cr] = await Promise.all([
        fetchProduct(id),
        fetchProductCommissionRates(id),
      ])
      product.value = prod.data
      rates.value = cr.data
    } catch (e: unknown) {
      errorMsg.value = e instanceof Error ? e.message : 'Failed to load product detail.'
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

// Group rates by party for a cleaner table
const groupedRates = computed(() => {
  const groups: Record<string, CommissionRateRow[]> = {}
  for (const r of rates.value) {
    if (!groups[r.party]) groups[r.party] = []
    groups[r.party].push(r)
  }
  return groups
})

function fmtBaht(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 0 }).format(n)
}

function fmtRate(n: number): string {
  const pct = n <= 1 ? n * 100 : n
  return pct.toFixed(2) + '%'
}

function partyBadge(party: string): string {
  const key = party.toLowerCase()
  if (key.includes('inh') || key === 'com') return 'bg-emerald-50 text-emerald-700 border-emerald-200'
  if (key === 'ag' || key === 'agent') return 'bg-amber-50 text-amber-700 border-amber-200'
  if (key.includes('override') || key === 'ov') return 'bg-violet-50 text-violet-700 border-violet-200'
  if (key === 'in' || key === 'influencer') return 'bg-sky-50 text-sky-700 border-sky-200'
  return 'bg-slate-100 text-slate-600 border-slate-200'
}
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
        <!-- Overview (editable) -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Overview</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div><div class="text-xs text-slate-400">Code</div>
              <EditableField entity="products" :id="product.id" field="code" :value="product.code" @update="v => apply('code', v)" /></div>
            <div><div class="text-xs text-slate-400">Carrier</div>
              <div class="text-slate-500">{{ product.carrierCode || '—' }}</div></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Carrier name</div>
              <div class="text-slate-500">{{ product.carrierName || '—' }}</div></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Name</div>
              <EditableField entity="products" :id="product.id" field="name" :value="product.name" @update="v => apply('name', v)" /></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Name (English)</div>
              <EditableField entity="products" :id="product.id" field="nameEn" :value="product.nameEn" @update="v => apply('nameEn', v)" /></div>
            <div><div class="text-xs text-slate-400">Product Group</div>
              <EditableField entity="products" :id="product.id" field="type" type="select" :options="groupOptionsForProduct(product.carrierInsureType)" :value="product.type" @update="v => apply('type', v)" /></div>
            <div><div class="text-xs text-slate-400">Main / Rider</div>
              <div class="text-slate-500">{{ product.mainRider || '—' }}</div></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Category</div>
              <EditableField entity="products" :id="product.id" field="category" type="select" :options="categoriesForGroup(product.type)" :value="product.category" @update="v => apply('category', v)" /></div>
            <div class="md:col-span-2"><div class="text-xs text-slate-400">Sub-category</div>
              <EditableField v-if="subcategoriesFor(product.type, product.category).length" entity="products" :id="product.id" field="subCategory" type="select" :options="subcategoriesFor(product.type, product.category)" :value="product.subCategory" @update="v => apply('subCategory', v)" />
              <div v-else class="text-slate-500">—</div></div>
            <div><div class="text-xs text-slate-400">Valid from</div>
              <div class="text-slate-500">{{ product.validStart || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">Valid to</div>
              <div class="text-slate-500">{{ product.validEnd || '—' }}</div></div>
            <div class="col-span-2"><div class="text-xs text-slate-400">Status</div>
              <span v-if="product.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">active</span>
              <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">inactive</span>
            </div>
          </div>
        </section>

        <!-- Age + sum assured (editable) -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">Eligibility &amp; coverage</h3>
          <div class="card p-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 text-sm">
            <div><div class="text-xs text-slate-400">Min age</div>
              <EditableField entity="products" :id="product.id" field="minAge" type="number" :value="product.minAge" @update="v => apply('minAge', v)" /></div>
            <div><div class="text-xs text-slate-400">Max age</div>
              <EditableField entity="products" :id="product.id" field="maxAge" type="number" :value="product.maxAge" @update="v => apply('maxAge', v)" /></div>
            <div><div class="text-xs text-slate-400">Min sum assured</div>
              <div class="text-slate-500">{{ fmtBaht(product.minSumAssure) }}</div></div>
            <div><div class="text-xs text-slate-400">Max sum assured</div>
              <div class="text-slate-500">{{ fmtBaht(product.maxSumAssure) }}</div></div>
          </div>
        </section>

        <!-- Commission rates -->
        <section>
          <h3 class="text-xs uppercase tracking-wider text-slate-400 mb-2">
            Commission rates
            <span class="text-slate-500 normal-case">({{ rates.length }})</span>
          </h3>
          <div v-if="!rates.length" class="card p-4 text-sm text-slate-500">No commission rates recorded for this product.</div>
          <div v-else class="card overflow-hidden">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
                <tr>
                  <th class="px-4 py-2 text-left">Party</th>
                  <th class="px-4 py-2 text-left">Installment term</th>
                  <th class="px-4 py-2 text-right">Rate</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <template v-for="(rows, party) in groupedRates" :key="party">
                  <tr v-for="(r, i) in rows" :key="r.id">
                    <td class="px-4 py-2">
                      <span v-if="i === 0" :class="['inline-flex px-2 py-0.5 rounded-md text-xs border', partyBadge(String(party))]">
                        {{ party }}
                      </span>
                    </td>
                    <td class="px-4 py-2 text-slate-700">{{ r.installmentTerm || 'main' }}</td>
                    <td class="px-4 py-2 text-right font-medium text-slate-900">{{ fmtRate(r.rate) }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
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
