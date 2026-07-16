<script setup lang="ts">
// Server-side paginated agent list.
import { onMounted, reactive, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgentStore } from '../../stores/agents'

const { t } = useI18n()
const agentStore = useAgentStore()

const filters = reactive({
  q: '',
  agentType: '' as '' | 'AG' | 'IN',
  level: '' as '' | 'l1' | 'l2' | 'l3' | 'l4' | 'l5',
  licenseStatus: '' as '' | 'valid' | 'expired' | 'expiring60d',
  activeOnly: false,
  perPage: 25,
})

const page = ref(1)

async function load(): Promise<void> {
  await agentStore.loadPage({
    q: filters.q || undefined,
    agentType: filters.agentType || undefined,
    level: filters.level || undefined,
    licenseStatus: filters.licenseStatus || undefined,
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
  () => [filters.agentType, filters.level, filters.licenseStatus, filters.activeOnly, filters.perPage],
  () => { page.value = 1; void load() },
)

function goPage(next: number): void {
  const meta = agentStore.listMeta
  if (!meta) return
  const target = Math.max(1, Math.min(meta.lastPage, next))
  if (target === page.value) return
  page.value = target
  void load()
}

const rangeText = computed(() => {
  const meta = agentStore.listMeta
  if (!meta) return ''
  const from = (meta.currentPage - 1) * meta.perPage + 1
  const to = Math.min(meta.total, meta.currentPage * meta.perPage)
  return `${from.toLocaleString()}–${to.toLocaleString()} จาก ${meta.total.toLocaleString()}`
})

function levelBadge(l: string): string {
  return {
    l1: 'bg-slate-100 text-slate-600',
    l2: 'bg-sky-50 text-sky-700',
    l3: 'bg-emerald-50 text-emerald-700',
    l4: 'bg-amber-50 text-amber-700',
    l5: 'bg-violet-50 text-violet-700',
  }[l] ?? 'bg-slate-100 text-slate-600'
}

function licenseStatus(expiry: string | null): { cls: string; label: string } {
  if (!expiry) return { cls: 'bg-slate-100 text-slate-500', label: 'ไม่มี' }
  const now = new Date().toISOString().slice(0, 10)
  const in60 = new Date(); in60.setDate(in60.getDate() + 60)
  if (expiry < now) return { cls: 'bg-rose-50 text-rose-700', label: 'หมดอายุ' }
  if (expiry < in60.toISOString().slice(0, 10)) return { cls: 'bg-amber-50 text-amber-700', label: '<60 วัน' }
  return { cls: 'bg-emerald-50 text-emerald-700', label: 'valid' }
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.agents.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.agents.description') }}</p>
      </div>
      <div v-if="agentStore.listMeta" class="text-sm text-slate-500">{{ rangeText }}</div>
    </header>

    <section class="card p-4 grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
      <div class="md:col-span-2">
        <label class="text-xs font-medium text-slate-500 mb-1 block">ค้นหา (ชื่อ / รหัสตัวแทน / อีเมล / โทรศัพท์)</label>
        <div class="relative">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input v-model.trim="filters.q" placeholder="AG200014, ชื่อตัวแทน, ..."
            class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white" />
        </div>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภท</label>
        <select v-model="filters.agentType" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="AG">AG</option>
          <option value="IN">IN</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Level</label>
        <select v-model="filters.level" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="l1">L1</option>
          <option value="l2">L2</option>
          <option value="l3">L3</option>
          <option value="l4">L4</option>
          <option value="l5">L5</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">License</label>
        <select v-model="filters.licenseStatus" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="valid">valid</option>
          <option value="expired">expired</option>
          <option value="expiring60d">expiring &lt; 60d</option>
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

    <section v-if="agentStore.listError" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ agentStore.listError }}
    </section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">Code</th>
              <th class="px-4 py-2 text-left">ชื่อ</th>
              <th class="px-4 py-2 text-left">ประเภท / Level</th>
              <th class="px-4 py-2 text-left">Upline</th>
              <th class="px-4 py-2 text-left">Team</th>
              <th class="px-4 py-2 text-left">License Life</th>
              <th class="px-4 py-2 text-left">License Non-Life</th>
              <th class="px-4 py-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="a in agentStore.list" :key="a.id" class="hover:bg-slate-50">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ a.agentCode }}</td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ a.firstName }} {{ a.lastName }}</div>
                <div class="text-xs text-slate-500">{{ a.email || a.phone || '—' }}</div>
              </td>
              <td class="px-4 py-2">
                <div class="flex items-center gap-1.5">
                  <span class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">{{ a.agentType }}</span>
                  <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', levelBadge(a.level)]">{{ a.level.toUpperCase() }}</span>
                </div>
              </td>
              <td class="px-4 py-2">
                <div v-if="a.parentAgentCode" class="text-slate-900">
                  {{ a.parentAgentName || a.parentAgentCode }}
                  <div class="text-xs text-slate-500">{{ a.parentAgentCode }}</div>
                </div>
                <span v-else class="text-xs text-slate-400">—</span>
              </td>
              <td class="px-4 py-2 text-slate-700">{{ a.team || a.teamNo || '—' }}</td>
              <td class="px-4 py-2">
                <div class="font-mono text-xs text-slate-700">{{ a.licenseLifeNo || '—' }}</div>
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-[10px] mt-0.5', licenseStatus(a.licenseLifeExpiry).cls]">
                  {{ licenseStatus(a.licenseLifeExpiry).label }}
                  <span v-if="a.licenseLifeExpiry" class="ml-1">— {{ a.licenseLifeExpiry }}</span>
                </span>
              </td>
              <td class="px-4 py-2">
                <div class="font-mono text-xs text-slate-700">{{ a.licenseNonLifeNo || '—' }}</div>
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-[10px] mt-0.5', licenseStatus(a.licenseNonLifeExpiry).cls]">
                  {{ licenseStatus(a.licenseNonLifeExpiry).label }}
                  <span v-if="a.licenseNonLifeExpiry" class="ml-1">— {{ a.licenseNonLifeExpiry }}</span>
                </span>
              </td>
              <td class="px-4 py-2">
                <span v-if="a.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">active</span>
                <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">inactive</span>
              </td>
            </tr>
            <tr v-if="!agentStore.listLoading && agentStore.list.length === 0">
              <td colspan="8" class="px-4 py-6 text-center text-slate-500">ไม่พบตัวแทน</td>
            </tr>
            <tr v-if="agentStore.listLoading && agentStore.list.length === 0">
              <td colspan="8" class="px-4 py-6 text-center text-slate-500">Loading…</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="agentStore.listMeta" class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
        <span class="text-slate-500">
          Page {{ agentStore.listMeta.currentPage }} / {{ agentStore.listMeta.lastPage }} · {{ agentStore.listMeta.total.toLocaleString() }} total
        </span>
        <div class="flex items-center gap-2">
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="agentStore.listLoading || page <= 1" @click="goPage(1)">« First</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="agentStore.listLoading || page <= 1" @click="goPage(page - 1)">Prev</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="agentStore.listLoading || page >= (agentStore.listMeta?.lastPage ?? 1)" @click="goPage(page + 1)">Next</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="agentStore.listLoading || page >= (agentStore.listMeta?.lastPage ?? 1)" @click="goPage(agentStore.listMeta?.lastPage ?? 1)">Last »</button>
        </div>
      </div>
    </section>
  </div>
</template>
