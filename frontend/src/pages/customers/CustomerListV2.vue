<script setup lang="ts">
// Server-side paginated customer list.
import { onMounted, reactive, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useCustomerStore } from '../../stores/customers'
import {
  customerDisplayName,
  customerInitials,
  customerIdentityNumber,
  customerTypeLabel,
} from '../../utils/customerDisplay'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const customerStore = useCustomerStore()

// Create + view/edit are now full-page routes (customer-new / customer-edit).
function goCreate(): void { router.push({ name: 'customer-new' }) }
function openCustomer(id: string): void { router.push({ name: 'customer-edit', params: { id } }) }

const filters = reactive({
  q: '',
  customerType: '' as '' | 'individual' | 'foreign_individual' | 'corporate' | 'other',
  unassigned: false,
  withPolicies: false,
  active: '' as '' | 'true' | 'false',
  perPage: 25,
})

const page = ref(1)
type SortCol = 'customerCode' | 'firstName' | 'lastName' | 'province' | 'registeredAt' | 'newest'
const sortBy = ref<SortCol>('newest')
const sortDir = ref<'asc' | 'desc'>('desc')

// Column header click → toggle direction, or switch column (asc default,
// desc for "newest" so freshly-registered rows land on page 1).
function toggleSort(col: SortCol): void {
  if (sortBy.value === col) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value = col
    sortDir.value = col === 'newest' || col === 'registeredAt' ? 'desc' : 'asc'
  }
  page.value = 1
  void load()
}

async function load(): Promise<void> {
  await customerStore.loadPage({
    q: filters.q || undefined,
    customerType: filters.customerType || undefined,
    unassigned: filters.unassigned || undefined,
    withPolicies: filters.withPolicies || undefined,
    active: filters.active === '' ? undefined : filters.active === 'true',
    page: page.value,
    perPage: filters.perPage,
    sortBy: sortBy.value,
    sortDir: sortDir.value,
  })
}

// ── Mount + legacy deep-link redirects ───────────────────────────────────
onMounted(() => {
  // Back-compat redirects for old deep links now that create/view are pages:
  //   ?new=1     (policy wizard's "new customer") → the create page
  //   ?open=<id> (old drawer bookmark)            → the edit page
  if (route.query.new === '1') { router.replace({ name: 'customer-new' }); return }
  const openId = route.query.open
  if (typeof openId === 'string' && openId.trim() !== '') {
    router.replace({ name: 'customer-edit', params: { id: openId.trim() } })
    return
  }
  void load()
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
  () => [filters.customerType, filters.unassigned, filters.withPolicies, filters.active, filters.perPage],
  () => { page.value = 1; void load() },
)

function goPage(next: number): void {
  const meta = customerStore.listMeta
  if (!meta) return
  const target = Math.max(1, Math.min(meta.lastPage, next))
  if (target === page.value) return
  page.value = target
  void load()
}

const rangeText = computed(() => {
  const meta = customerStore.listMeta
  if (!meta) return ''
  const from = (meta.currentPage - 1) * meta.perPage + 1
  const to = Math.min(meta.total, meta.currentPage * meta.perPage)
  return `${from.toLocaleString()}–${to.toLocaleString()} จาก ${meta.total.toLocaleString()}`
})

</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.customers.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.customers.description') }}</p>
      </div>
      <div class="flex items-center gap-3">
        <div v-if="customerStore.listMeta" class="text-sm text-slate-500">{{ rangeText }}</div>
        <button type="button"
          class="px-3 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm flex items-center gap-1.5"
          @click="goCreate">
          <i class="pi pi-plus text-xs" /> New Customer
        </button>
      </div>
    </header>

    <section class="card p-4 grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
      <div class="md:col-span-2">
        <label class="text-xs font-medium text-slate-500 mb-1 block">ค้นหา (รหัส / ชื่อ / นิติบุคคล / บัตรประชาชน / เลขผู้เสียภาษี / passport / โทรศัพท์ / อีเมล)</label>
        <div class="relative">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input v-model.trim="filters.q" placeholder="ชื่อลูกค้า, C0001234, ..."
            class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white" />
        </div>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภท</label>
        <select v-model="filters.customerType" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">ทั้งหมด</option>
          <option value="individual">บุคคลธรรมดา</option>
          <option value="foreign_individual">ชาวต่างชาติ</option>
          <option value="corporate">นิติบุคคล</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Active</label>
        <select v-model="filters.active" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="true">Active</option>
          <option value="false">Inactive</option>
        </select>
      </div>
      <div class="flex items-center gap-3">
        <label class="flex items-center gap-1 text-xs text-slate-500">
          <input v-model="filters.unassigned" type="checkbox" /> Unassigned
        </label>
        <label class="flex items-center gap-1 text-xs text-slate-500">
          <input v-model="filters.withPolicies" type="checkbox" /> Has policies
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

    <section v-if="customerStore.listError" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ customerStore.listError }}
    </section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left cursor-pointer select-none hover:text-slate-800"
                @click="toggleSort('customerCode')">
                Code
                <i v-if="sortBy === 'customerCode'"
                  :class="sortDir === 'asc' ? 'pi-sort-amount-up-alt' : 'pi-sort-amount-down'"
                  class="pi text-[10px] ml-1" />
                <i v-else class="pi pi-sort text-[10px] ml-1 text-slate-300" />
              </th>
              <th class="px-4 py-2 text-left cursor-pointer select-none hover:text-slate-800"
                @click="toggleSort('firstName')">
                ชื่อลูกค้า
                <i v-if="sortBy === 'firstName'"
                  :class="sortDir === 'asc' ? 'pi-sort-amount-up-alt' : 'pi-sort-amount-down'"
                  class="pi text-[10px] ml-1" />
                <i v-else class="pi pi-sort text-[10px] ml-1 text-slate-300" />
              </th>
              <th class="px-4 py-2 text-left">ประเภท</th>
              <th class="px-4 py-2 text-left">ID card</th>
              <th class="px-4 py-2 text-left">โทรศัพท์</th>
              <th class="px-4 py-2 text-left cursor-pointer select-none hover:text-slate-800"
                @click="toggleSort('province')">
                จังหวัด
                <i v-if="sortBy === 'province'"
                  :class="sortDir === 'asc' ? 'pi-sort-amount-up-alt' : 'pi-sort-amount-down'"
                  class="pi text-[10px] ml-1" />
                <i v-else class="pi pi-sort text-[10px] ml-1 text-slate-300" />
              </th>
              <th class="px-4 py-2 text-left">ตัวแทน</th>
              <th class="px-4 py-2 text-right">Policies</th>
              <th class="px-4 py-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in customerStore.list" :key="c.id" class="hover:bg-slate-50 cursor-pointer" @click="openCustomer(c.id)">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ c.customerCode }}</td>
              <td class="px-4 py-2">
                <div class="flex items-center gap-2">
                  <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 flex items-center justify-center text-xs font-medium shrink-0">
                    {{ customerInitials(c) }}
                  </div>
                  <div class="text-slate-900">
                    {{ customerDisplayName(c) || '—' }}
                    <span v-if="c.nickname" class="text-xs text-slate-500 ml-1">({{ c.nickname }})</span>
                  </div>
                </div>
              </td>
              <td class="px-4 py-2 text-slate-600">{{ customerTypeLabel(c.customerType) }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ customerIdentityNumber(c) || '—' }}</td>
              <td class="px-4 py-2 text-slate-700">{{ c.phone || '—' }}</td>
              <td class="px-4 py-2 text-slate-700">{{ c.province || '—' }}</td>
              <td class="px-4 py-2">
                <div v-if="c.assignedAgentCode" class="text-slate-900">
                  {{ c.assignedAgentName || c.assignedAgentCode }}
                  <div class="text-xs text-slate-500">{{ c.assignedAgentCode }}</div>
                </div>
                <span v-else class="text-xs text-slate-400">unassigned</span>
              </td>
              <td class="px-4 py-2 text-right">
                <span class="text-slate-900 font-medium">{{ c.activePolicyCount }}</span>
                <span class="text-xs text-slate-500"> / {{ c.totalPolicyCount }}</span>
              </td>
              <td class="px-4 py-2">
                <span v-if="c.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">active</span>
                <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">inactive</span>
              </td>
            </tr>
            <tr v-if="!customerStore.listLoading && customerStore.list.length === 0">
              <td colspan="9" class="px-4 py-6 text-center text-slate-500">ไม่พบลูกค้า</td>
            </tr>
            <tr v-if="customerStore.listLoading && customerStore.list.length === 0">
              <td colspan="9" class="px-4 py-6 text-center text-slate-500">Loading…</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="customerStore.listMeta" class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
        <span class="text-slate-500">
          Page {{ customerStore.listMeta.currentPage }} / {{ customerStore.listMeta.lastPage }} · {{ customerStore.listMeta.total.toLocaleString() }} total
        </span>
        <div class="flex items-center gap-2">
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="customerStore.listLoading || page <= 1" @click="goPage(1)">« First</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="customerStore.listLoading || page <= 1" @click="goPage(page - 1)">Prev</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="customerStore.listLoading || page >= (customerStore.listMeta?.lastPage ?? 1)" @click="goPage(page + 1)">Next</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="customerStore.listLoading || page >= (customerStore.listMeta?.lastPage ?? 1)" @click="goPage(customerStore.listMeta?.lastPage ?? 1)">Last »</button>
        </div>
      </div>
    </section>
  </div>
</template>
