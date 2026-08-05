<script setup lang="ts">
// Server-paginated carrier list.
import { onMounted, reactive, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useCarrierStore } from '../../stores/carriers'
import { updateCarrier } from '../../api/carriers'
import { ApiError } from '../../api/client'
import CarrierDetailDrawer from './CarrierDetailDrawer.vue'
import CarrierCreateModal from './CarrierCreateModal.vue'

const { t } = useI18n()
const route = useRoute()
const carrierStore = useCarrierStore()

const detailId = ref<string | null>(null)
const showCreate = ref(false)

const filters = reactive({
  q: '',
  insureType: '' as '' | 'life' | 'non-life' | 'tax',
  activeOnly: false,
  perPage: 25,
})

const page = ref(1)

async function load(): Promise<void> {
  await carrierStore.loadPage({
    q: filters.q || undefined,
    insureType: filters.insureType || undefined,
    activeOnly: filters.activeOnly || undefined,
    page: page.value,
    perPage: filters.perPage,
  })
}

onMounted(async () => {
  await load()
  // Cross-page deep-link — /carriers?open=<id> opens that carrier's drawer.
  const openId = route.query.open
  if (typeof openId === 'string' && openId.trim() !== '') {
    detailId.value = openId.trim()
  }
})

let debounceTimer: number | undefined
watch(
  () => filters.q,
  () => {
    window.clearTimeout(debounceTimer)
    debounceTimer = window.setTimeout(() => { page.value = 1; void load() }, 300)
  },
)
watch(
  () => [filters.insureType, filters.activeOnly, filters.perPage],
  () => { page.value = 1; void load() },
)

function goPage(next: number): void {
  const meta = carrierStore.listMeta
  if (!meta) return
  const target = Math.max(1, Math.min(meta.lastPage, next))
  if (target === page.value) return
  page.value = target
  void load()
}

const rangeText = computed(() => {
  const meta = carrierStore.listMeta
  if (!meta) return ''
  const from = (meta.currentPage - 1) * meta.perPage + 1
  const to = Math.min(meta.total, meta.currentPage * meta.perPage)
  return `${from.toLocaleString()}–${to.toLocaleString()} จาก ${meta.total.toLocaleString()}`
})

function typeBadge(insureType: string): string {
  return {
    life: 'bg-emerald-50 text-emerald-700',
    'non-life': 'bg-sky-50 text-sky-700',
    tax: 'bg-violet-50 text-violet-700',
  }[insureType] ?? 'bg-slate-100 text-slate-600'
}

// In-place status change via dropdown — PATCHes carriers/{id} then updates
// the row in the local store list so the badge flips without a full reload.
const statusSavingId = ref<string | null>(null)
async function changeStatus(carrierId: string, next: 'active' | 'inactive'): Promise<void> {
  const active = next === 'active'
  const row = carrierStore.list.find((c) => c.id === carrierId)
  if (!row || row.active === active) return
  statusSavingId.value = carrierId
  const prev = row.active
  row.active = active // optimistic
  try {
    await updateCarrier(carrierId, { active })
  } catch (e: unknown) {
    row.active = prev // rollback
    carrierStore.listError = e instanceof ApiError ? e.message : 'Status change failed.'
  } finally {
    statusSavingId.value = null
  }
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.carriers.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.carriers.description') }}</p>
      </div>
      <div class="flex items-center gap-3">
        <div v-if="carrierStore.listMeta" class="text-sm text-slate-500">{{ rangeText }}</div>
        <button type="button"
          class="px-3 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm flex items-center gap-1.5"
          @click="showCreate = true">
          <i class="pi pi-plus text-xs" /> {{ t('carriers.list.addNew') }}
        </button>
      </div>
    </header>

    <section class="card p-4 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
      <div class="md:col-span-2">
        <label class="text-xs font-medium text-slate-500 mb-1 block">ค้นหา (code / ชื่อ / เลขประจำตัวผู้เสียภาษี)</label>
        <div class="relative">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input v-model.trim="filters.q" placeholder="AIA, เอไอเอ, ..."
            class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white" />
        </div>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภท</label>
        <select v-model="filters.insureType" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="life">Life</option>
          <option value="non-life">Non-life</option>
          <option value="tax">Tax</option>
        </select>
      </div>
      <div>
        <label class="flex items-center gap-2 text-xs font-medium text-slate-500 mb-1">
          <input v-model="filters.activeOnly" type="checkbox" /> Active only
        </label>
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

    <section v-if="carrierStore.listError" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ carrierStore.listError }}
    </section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">Code</th>
              <th class="px-4 py-2 text-left">ชื่อบริษัท</th>
              <th class="px-4 py-2 text-left">ประเภท</th>
              <th class="px-4 py-2 text-left">Tax ID</th>
              <th class="px-4 py-2 text-right">Products</th>
              <th class="px-4 py-2 text-right">Contracts</th>
              <th class="px-4 py-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in carrierStore.list" :key="c.id" class="hover:bg-slate-50 cursor-pointer" @click="detailId = c.id">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ c.code }}</td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ c.nicknameTh || c.name }}</div>
                <div v-if="c.name && c.name !== c.nicknameTh" class="text-xs text-slate-500 truncate max-w-[420px]">
                  {{ c.name }}
                </div>
              </td>
              <td class="px-4 py-2">
                <span v-if="c.insureType" :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', typeBadge(c.insureType)]">
                  {{ c.insureType }}
                </span>
                <span v-else class="text-slate-400 text-xs">—</span>
              </td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ c.taxId || '—' }}</td>
              <td class="px-4 py-2 text-right text-slate-900">{{ c.productCount.toLocaleString() }}</td>
              <td class="px-4 py-2 text-right text-slate-900">{{ c.contractCount.toLocaleString() }}</td>
              <td class="px-4 py-2" @click.stop>
                <select
                  :value="c.active ? 'active' : 'inactive'"
                  :disabled="statusSavingId === c.id"
                  :class="[
                    'px-2 py-1 rounded-md text-xs font-medium border focus:outline-none focus:ring-2 focus:ring-brand-100',
                    c.active
                      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                      : 'bg-slate-100 text-slate-600 border-slate-200',
                  ]"
                  @change="e => changeStatus(c.id, (e.target as HTMLSelectElement).value as 'active' | 'inactive')">
                  <option value="active">{{ t('carriers.list.statusActive') }}</option>
                  <option value="inactive">{{ t('carriers.list.statusInactive') }}</option>
                </select>
                <i v-if="statusSavingId === c.id" class="pi pi-spin pi-spinner text-brand-500 ml-1 text-xs" />
              </td>
            </tr>
            <tr v-if="!carrierStore.listLoading && carrierStore.list.length === 0">
              <td colspan="7" class="px-4 py-6 text-center text-slate-500">ไม่พบบริษัทประกัน</td>
            </tr>
            <tr v-if="carrierStore.listLoading && carrierStore.list.length === 0">
              <td colspan="7" class="px-4 py-6 text-center text-slate-500">Loading…</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="carrierStore.listMeta" class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
        <span class="text-slate-500">
          Page {{ carrierStore.listMeta.currentPage }} / {{ carrierStore.listMeta.lastPage }} · {{ carrierStore.listMeta.total.toLocaleString() }} total
        </span>
        <div class="flex items-center gap-2">
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="carrierStore.listLoading || page <= 1" @click="goPage(1)">« First</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="carrierStore.listLoading || page <= 1" @click="goPage(page - 1)">Prev</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="carrierStore.listLoading || page >= (carrierStore.listMeta?.lastPage ?? 1)" @click="goPage(page + 1)">Next</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="carrierStore.listLoading || page >= (carrierStore.listMeta?.lastPage ?? 1)" @click="goPage(carrierStore.listMeta?.lastPage ?? 1)">Last »</button>
        </div>
      </div>
    </section>

    <CarrierDetailDrawer :carrier-id="detailId" @close="detailId = null" />
    <CarrierCreateModal :open="showCreate" @close="showCreate = false" @created="() => { page = 1; load() }" />
  </div>
</template>
