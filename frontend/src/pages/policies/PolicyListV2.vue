<script setup lang="ts">
// Server-side paginated policy list. Reads from usePolicyStore().list which is
// backed by GET /api/v1/policies (returns the lean PolicyListRow shape with
// joined customer/agent/carrier/product display columns).

import { onMounted, reactive, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePolicyStore, type PolicyStatus } from '../../stores/policies'
import PolicyDetailDrawer from './PolicyDetailDrawer.vue'
import PolicyCreateWizard from './PolicyCreateWizard.vue'

const { t } = useI18n()
const policyStore = usePolicyStore()

const detailId = ref<string | null>(null)
const showCreate = ref(false)

// Filter state — bound to loadPage() on change.
const filters = reactive({
  q: '',
  status: '' as '' | PolicyStatus,
  newOrRenew: '' as '' | 'new' | 'renew',
  perPage: 25,
})

const page = ref(1)

async function load(): Promise<void> {
  await policyStore.loadPage({
    q: filters.q || undefined,
    status: filters.status || undefined,
    newOrRenew: filters.newOrRenew || undefined,
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
    debounceTimer = window.setTimeout(() => {
      page.value = 1
      void load()
    }, 300)
  },
)

watch(
  () => [filters.status, filters.newOrRenew, filters.perPage],
  () => {
    page.value = 1
    void load()
  },
)

function goPage(next: number): void {
  const meta = policyStore.listMeta
  if (!meta) return
  const target = Math.max(1, Math.min(meta.lastPage, next))
  if (target === page.value) return
  page.value = target
  void load()
}

const allStatuses: { value: PolicyStatus | ''; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'active', label: 'อนุมัติแล้ว' },
  { value: 'submitted', label: 'รอพิจารณา' },
  { value: 'application', label: 'รอตรวจรถ' },
  { value: 'cancelled', label: 'Cancel / Reject' },
  { value: 'quote', label: 'ใบเสนอราคา' },
  { value: 'issued', label: 'ออกกรมธรรม์แล้ว' },
  { value: 'lapsed', label: 'ขาดต่ออายุ' },
  { value: 'reinstated', label: 'กลับมาคุ้มครองใหม่' },
  { value: 'expired', label: 'หมดอายุ' },
]

function statusBadge(s: PolicyStatus): string {
  return {
    quote: 'bg-slate-100 text-slate-600',
    application: 'bg-amber-50 text-amber-700',
    submitted: 'bg-amber-50 text-amber-700',
    issued: 'bg-sky-50 text-sky-700',
    active: 'bg-emerald-50 text-emerald-700',
    lapsed: 'bg-rose-50 text-rose-700',
    cancelled: 'bg-slate-100 text-slate-500',
    reinstated: 'bg-violet-50 text-violet-700',
    expired: 'bg-slate-100 text-slate-500',
  }[s]
}

function fmtBaht(n: number): string {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 0 }).format(n)
}

const rangeText = computed(() => {
  const meta = policyStore.listMeta
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
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.policies.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">
          {{ t('modules.policies.description') }}
        </p>
      </div>
      <div class="flex items-center gap-3">
        <div v-if="policyStore.listMeta" class="text-sm text-slate-500">
          {{ rangeText }}
        </div>
        <button type="button"
          class="px-3 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm flex items-center gap-1.5"
          @click="showCreate = true">
          <i class="pi pi-plus text-xs" /> New Policy
        </button>
      </div>
    </header>

    <!-- Filter bar -->
    <section class="card p-4 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
      <div class="md:col-span-2">
        <label class="text-xs font-medium text-slate-500 mb-1 block">ค้นหา (policy no / application no / ชื่อลูกค้า / รหัสตัวแทน)</label>
        <div class="relative">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input v-model.trim="filters.q" placeholder="A2001030001, John, C0001234, ..."
            class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white" />
        </div>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">สถานะ</label>
        <select v-model="filters.status" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option v-for="s in allStatuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภท</label>
        <select v-model="filters.newOrRenew" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="new">New</option>
          <option value="renew">Renew</option>
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

    <section v-if="policyStore.listError" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ policyStore.listError }}
    </section>

    <!-- Results table -->
    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">Application</th>
              <th class="px-4 py-2 text-left">Policy no</th>
              <th class="px-4 py-2 text-left">ลูกค้า</th>
              <th class="px-4 py-2 text-left">ตัวแทน</th>
              <th class="px-4 py-2 text-left">บริษัท / สินค้า</th>
              <th class="px-4 py-2 text-right">Premium / ปี</th>
              <th class="px-4 py-2 text-left">คุ้มครอง</th>
              <th class="px-4 py-2 text-left">สถานะ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="p in policyStore.list" :key="p.id" class="hover:bg-slate-50 cursor-pointer" @click="detailId = p.id">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ p.applicationNo ?? '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ p.policyNo ?? '—' }}</td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ p.customerName || p.customerCode }}</div>
                <div class="text-xs text-slate-500">{{ p.customerCode }}</div>
              </td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ p.agentName || p.agentCode }}</div>
                <div class="text-xs text-slate-500">{{ p.agentCode }}</div>
              </td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ p.carrierCode ?? '—' }}</div>
                <div class="text-xs text-slate-500 truncate max-w-[220px]">{{ p.productName ?? p.productCode }}</div>
              </td>
              <td class="px-4 py-2 text-right font-medium text-slate-900">{{ fmtBaht(p.annualPremium) }}</td>
              <td class="px-4 py-2 text-slate-700">
                <div>{{ p.effectiveDate ?? '—' }}</div>
                <div class="text-xs text-slate-500">ถึง {{ p.expiryDate ?? '—' }}</div>
              </td>
              <td class="px-4 py-2">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', statusBadge(p.status)]"
                  :title="p.status">
                  {{ p.statusLabel || p.status }}
                </span>
                <div v-if="p.premiumCheck === 'mismatch'" class="text-[10px] text-amber-600 mt-0.5">Δ premium</div>
              </td>
            </tr>
            <tr v-if="!policyStore.listLoading && policyStore.list.length === 0">
              <td colspan="8" class="px-4 py-6 text-center text-slate-500">ไม่พบกรมธรรม์</td>
            </tr>
            <tr v-if="policyStore.listLoading && policyStore.list.length === 0">
              <td colspan="8" class="px-4 py-6 text-center text-slate-500">Loading…</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="policyStore.listMeta" class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
        <span class="text-slate-500">
          Page {{ policyStore.listMeta.currentPage }} / {{ policyStore.listMeta.lastPage }} · {{ policyStore.listMeta.total.toLocaleString() }} total
        </span>
        <div class="flex items-center gap-2">
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="policyStore.listLoading || page <= 1" @click="goPage(1)">« First</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="policyStore.listLoading || page <= 1" @click="goPage(page - 1)">Prev</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="policyStore.listLoading || page >= (policyStore.listMeta?.lastPage ?? 1)" @click="goPage(page + 1)">Next</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="policyStore.listLoading || page >= (policyStore.listMeta?.lastPage ?? 1)" @click="goPage(policyStore.listMeta?.lastPage ?? 1)">Last »</button>
        </div>
      </div>
    </section>

    <PolicyDetailDrawer :policy-id="detailId" @close="detailId = null" />
    <PolicyCreateWizard :open="showCreate" @close="showCreate = false" @created="() => { page = 1; load() }" />
  </div>
</template>
