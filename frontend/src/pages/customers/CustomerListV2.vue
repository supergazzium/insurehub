<script setup lang="ts">
// Server-side paginated customer list.
import { onMounted, reactive, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useCustomerStore } from '../../stores/customers'
import CustomerDetailDrawer from './CustomerDetailDrawer.vue'
import CustomerCreateModal from './CustomerCreateModal.vue'

const { t } = useI18n()
const route = useRoute()
const customerStore = useCustomerStore()

const detailId = ref<string | null>(null)
// Auto-open the create modal when the URL has ?new=1 — used by the policy
// create wizard's "New customer" button, which opens this page in a new tab
// pre-set to open the customer form.
const openedForNew = route.query.new === '1'
const showCreate = ref(openedForNew)

/** Broadcast a newly-created customer to any parent tab (the policy wizard)
 *  and close ourselves. Only fires when we were opened via ?new=1 — regular
 *  list-page create should not close the tab. */
function handleCreated(row: Record<string, unknown>): void {
  if (openedForNew) {
    try {
      const bc = new BroadcastChannel('insurehub')
      bc.postMessage({ type: 'customer:created', row })
      bc.close()
    } catch {
      // BroadcastChannel unavailable (very old browsers) — fall through and
      // just close; the parent tab will still work via manual re-search.
    }
    window.close()
    return
  }
  // Normal list-page usage: reload the list.
  page.value = 1
  load()
}

const filters = reactive({
  q: '',
  customerType: '' as '' | 'individual' | 'corporate' | 'other',
  unassigned: false,
  withPolicies: false,
  active: '' as '' | 'true' | 'false',
  perPage: 25,
})

const page = ref(1)

async function load(): Promise<void> {
  await customerStore.loadPage({
    q: filters.q || undefined,
    customerType: filters.customerType || undefined,
    unassigned: filters.unassigned || undefined,
    withPolicies: filters.withPolicies || undefined,
    active: filters.active === '' ? undefined : filters.active === 'true',
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

function initials(first: string, last: string): string {
  const a = (first || '').trim().charAt(0)
  const b = (last || '').trim().charAt(0)
  return (a + b) || '?'
}
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
          @click="showCreate = true">
          <i class="pi pi-plus text-xs" /> New Customer
        </button>
      </div>
    </header>

    <section class="card p-4 grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
      <div class="md:col-span-2">
        <label class="text-xs font-medium text-slate-500 mb-1 block">ค้นหา (ชื่อ / บัตรประชาชน / โทรศัพท์ / อีเมล)</label>
        <div class="relative">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input v-model.trim="filters.q" placeholder="ชื่อลูกค้า, C0001234, ..."
            class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white" />
        </div>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภท</label>
        <select v-model="filters.customerType" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="individual">Individual</option>
          <option value="corporate">Corporate</option>
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
              <th class="px-4 py-2 text-left">Code</th>
              <th class="px-4 py-2 text-left">ชื่อลูกค้า</th>
              <th class="px-4 py-2 text-left">ประเภท</th>
              <th class="px-4 py-2 text-left">ID card</th>
              <th class="px-4 py-2 text-left">โทรศัพท์</th>
              <th class="px-4 py-2 text-left">จังหวัด</th>
              <th class="px-4 py-2 text-left">ตัวแทน</th>
              <th class="px-4 py-2 text-right">Policies</th>
              <th class="px-4 py-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in customerStore.list" :key="c.id" class="hover:bg-slate-50 cursor-pointer" @click="detailId = c.id">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ c.customerCode }}</td>
              <td class="px-4 py-2">
                <div class="flex items-center gap-2">
                  <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 flex items-center justify-center text-xs font-medium shrink-0">
                    {{ initials(c.firstName, c.lastName) }}
                  </div>
                  <div class="text-slate-900">
                    {{ c.firstName }} {{ c.lastName }}
                    <span v-if="c.nickname" class="text-xs text-slate-500 ml-1">({{ c.nickname }})</span>
                  </div>
                </div>
              </td>
              <td class="px-4 py-2 text-slate-600">{{ c.customerType }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ c.idCard || '—' }}</td>
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

    <CustomerDetailDrawer :customer-id="detailId" @close="detailId = null" />
    <CustomerCreateModal :open="showCreate" @close="showCreate = false" @created="handleCreated" />
  </div>
</template>
