<script setup lang="ts">
// Server-paginated product list.
import { onMounted, reactive, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useProductStore } from '../../stores/products'
import ProductDetailDrawer from './ProductDetailDrawer.vue'
import ProductCreateModal from './ProductCreateModal.vue'

const { t } = useI18n()
const route = useRoute()
const productStore = useProductStore()

const detailId = ref<string | null>(null)
// Auto-open the create modal when the URL has ?new=1 — used by the policy
// create wizard's "New product" button, which opens this page in a new tab
// pre-set to open the product form.
const openedForNew = route.query.new === '1'
const showCreate = ref(openedForNew)

/** Broadcast a newly-created product to the parent tab (the policy wizard) and
 *  close ourselves. Only fires when we were opened via ?new=1 — regular
 *  list-page create should not close the tab. */
function handleCreated(row: Record<string, unknown>): void {
  if (openedForNew) {
    try {
      const bc = new BroadcastChannel('insurehub')
      bc.postMessage({ type: 'product:created', row })
      bc.close()
    } catch {
      // BroadcastChannel unavailable — fall through and just close.
    }
    window.close()
    return
  }
  page.value = 1
  load()
}

const filters = reactive({
  q: '',
  type: '',
  mainRider: '',
  activeOnly: false,
  perPage: 25,
})

const page = ref(1)

async function load(): Promise<void> {
  await productStore.loadPage({
    q: filters.q || undefined,
    type: filters.type || undefined,
    mainRider: filters.mainRider || undefined,
    activeOnly: filters.activeOnly || undefined,
    page: page.value,
    perPage: filters.perPage,
  })
}

onMounted(load)

let debounceTimer: number | undefined
watch(
  () => filters.q,
  () => {
    window.clearTimeout(debounceTimer)
    debounceTimer = window.setTimeout(() => { page.value = 1; void load() }, 300)
  },
)
watch(
  () => [filters.type, filters.mainRider, filters.activeOnly, filters.perPage],
  () => { page.value = 1; void load() },
)

function goPage(next: number): void {
  const meta = productStore.listMeta
  if (!meta) return
  const target = Math.max(1, Math.min(meta.lastPage, next))
  if (target === page.value) return
  page.value = target
  void load()
}

const rangeText = computed(() => {
  const meta = productStore.listMeta
  if (!meta) return ''
  const from = (meta.currentPage - 1) * meta.perPage + 1
  const to = Math.min(meta.total, meta.currentPage * meta.perPage)
  return `${from.toLocaleString()}–${to.toLocaleString()} จาก ${meta.total.toLocaleString()}`
})

function typeBadge(type: string): string {
  return {
    life: 'bg-emerald-50 text-emerald-700',
    health: 'bg-sky-50 text-sky-700',
    motor: 'bg-amber-50 text-amber-700',
    home: 'bg-violet-50 text-violet-700',
    travel: 'bg-rose-50 text-rose-700',
    group: 'bg-indigo-50 text-indigo-700',
    other: 'bg-slate-100 text-slate-600',
  }[type] ?? 'bg-slate-100 text-slate-600'
}

function riderBadge(mainRider: string): string {
  return mainRider === 'Rider'
    ? 'bg-violet-50 text-violet-700'
    : 'bg-slate-100 text-slate-600'
}

function fmtBaht(n: number | null): string {
  if (n === null) return '—'
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 0 }).format(n)
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.products.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.products.description') }}</p>
      </div>
      <div class="flex items-center gap-3">
        <div v-if="productStore.listMeta" class="text-sm text-slate-500">{{ rangeText }}</div>
        <button type="button"
          class="px-3 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm flex items-center gap-1.5"
          @click="showCreate = true">
          <i class="pi pi-plus text-xs" /> New Product
        </button>
      </div>
    </header>

    <section class="card p-4 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
      <div class="md:col-span-2">
        <label class="text-xs font-medium text-slate-500 mb-1 block">ค้นหา (code / ชื่อ / commission code / บริษัท)</label>
        <div class="relative">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input v-model.trim="filters.q" placeholder="PDAIA0001, AIA, สุขภาพ, ..."
            class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white" />
        </div>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภท</label>
        <select v-model="filters.type" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="life">Life</option>
          <option value="health">Health</option>
          <option value="motor">Motor</option>
          <option value="home">Home</option>
          <option value="travel">Travel</option>
          <option value="group">Group</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Main / Rider</label>
        <select v-model="filters.mainRider" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="Main">Main</option>
          <option value="Rider">Rider</option>
          <option value="TAX">Tax</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Per page</label>
        <select v-model.number="filters.perPage" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option :value="25">25</option>
          <option :value="50">50</option>
          <option :value="100">100</option>
        </select>
      </div>
    </section>

    <section v-if="productStore.listError" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ productStore.listError }}
    </section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">Code</th>
              <th class="px-4 py-2 text-left">ชื่อสินค้า</th>
              <th class="px-4 py-2 text-left">บริษัท</th>
              <th class="px-4 py-2 text-left">ประเภท</th>
              <th class="px-4 py-2 text-left">Category</th>
              <th class="px-4 py-2 text-left">Main/Rider</th>
              <th class="px-4 py-2 text-right">อายุ</th>
              <th class="px-4 py-2 text-right">Sum assured</th>
              <th class="px-4 py-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="p in productStore.list" :key="p.id" class="hover:bg-slate-50 cursor-pointer" @click="detailId = p.id">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ p.code }}</td>
              <td class="px-4 py-2">
                <div class="text-slate-900 truncate max-w-[320px]" :title="p.name">{{ p.name }}</div>
                <div v-if="p.commissionCode" class="text-[10px] text-slate-400 font-mono">{{ p.commissionCode }}</div>
              </td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ p.carrierCode ?? '—' }}</div>
                <div class="text-xs text-slate-500 truncate max-w-[180px]">{{ p.carrierName ?? '' }}</div>
              </td>
              <td class="px-4 py-2">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', typeBadge(p.type)]">
                  {{ p.type || '—' }}
                </span>
              </td>
              <td class="px-4 py-2 text-slate-700 text-xs truncate max-w-[160px]">
                {{ p.category || '—' }}
                <div v-if="p.subCategory" class="text-slate-400">{{ p.subCategory }}</div>
              </td>
              <td class="px-4 py-2">
                <span v-if="p.mainRider" :class="['inline-flex px-2 py-0.5 rounded-md text-xs', riderBadge(p.mainRider)]">
                  {{ p.mainRider }}
                </span>
                <span v-else class="text-slate-400 text-xs">—</span>
              </td>
              <td class="px-4 py-2 text-right text-slate-700 text-xs">
                <span v-if="p.minAge || p.maxAge">{{ p.minAge }}–{{ p.maxAge }}</span>
                <span v-else class="text-slate-400">—</span>
              </td>
              <td class="px-4 py-2 text-right text-slate-700 text-xs">
                <div>{{ fmtBaht(p.minSumAssure) }}</div>
                <div class="text-slate-400">— {{ fmtBaht(p.maxSumAssure) }}</div>
              </td>
              <td class="px-4 py-2">
                <span v-if="p.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">active</span>
                <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">inactive</span>
              </td>
            </tr>
            <tr v-if="!productStore.listLoading && productStore.list.length === 0">
              <td colspan="9" class="px-4 py-6 text-center text-slate-500">ไม่พบผลิตภัณฑ์</td>
            </tr>
            <tr v-if="productStore.listLoading && productStore.list.length === 0">
              <td colspan="9" class="px-4 py-6 text-center text-slate-500">Loading…</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="productStore.listMeta" class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
        <span class="text-slate-500">
          Page {{ productStore.listMeta.currentPage }} / {{ productStore.listMeta.lastPage }} · {{ productStore.listMeta.total.toLocaleString() }} total
        </span>
        <div class="flex items-center gap-2">
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="productStore.listLoading || page <= 1" @click="goPage(1)">« First</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="productStore.listLoading || page <= 1" @click="goPage(page - 1)">Prev</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="productStore.listLoading || page >= (productStore.listMeta?.lastPage ?? 1)" @click="goPage(page + 1)">Next</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="productStore.listLoading || page >= (productStore.listMeta?.lastPage ?? 1)" @click="goPage(productStore.listMeta?.lastPage ?? 1)">Last »</button>
        </div>
      </div>
    </section>

    <ProductDetailDrawer :product-id="detailId" @close="detailId = null" />
    <ProductCreateModal :open="showCreate" @close="showCreate = false" @created="handleCreated" />
  </div>
</template>
