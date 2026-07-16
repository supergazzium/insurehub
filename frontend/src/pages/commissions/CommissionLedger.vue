<script setup lang="ts">
import { onMounted, reactive, ref, computed } from 'vue'
import {
  fetchCommissionLedger,
  type CommissionLedgerRow,
  type LedgerParty,
} from '../../api/reports'
import { ApiError } from '../../api/client'

const filters = reactive<{
  agentCode: string
  party: LedgerParty | ''
  fromDate: string
  toDate: string
}>({
  agentCode: '',
  party: '',
  fromDate: '',
  toDate: '',
})

const rows = ref<CommissionLedgerRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchCommissionLedger({
      ...filters,
      perPage: 500,
    })
    rows.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'ไม่สามารถโหลด commission ledger'
    rows.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)

const totals = computed(() => {
  const inh = { amount: 0, count: 0 }
  const ag = { amount: 0, count: 0 }
  for (const r of rows.value) {
    const bucket = r.party === 'inh' ? inh : ag
    bucket.count += 1
    if (r.commissionAmount) bucket.amount += r.commissionAmount
  }
  return { inh, ag }
})

function fmtBaht(n: number | null): string {
  if (n === null) return '—'
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 2 }).format(n)
}
function fmtPct(n: number | null): string {
  if (n === null) return '—'
  return (n * 100).toFixed(2) + '%'
}

function sourceBadge(s: 'main' | 'rider'): string {
  return s === 'main'
    ? 'bg-sky-50 text-sky-700 border-sky-200'
    : 'bg-violet-50 text-violet-700 border-violet-200'
}
function partyBadge(p: LedgerParty): string {
  return p === 'inh'
    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
    : 'bg-amber-50 text-amber-700 border-amber-200'
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">Commission Ledger</h1>
      <p class="text-slate-500 text-sm mt-1">
        Main + rider commissions รวมกันในตารางเดียว กรองตามตัวแทน / ฝ่าย / ช่วงเวลา
      </p>
    </header>

    <section class="card p-4 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Agent code</label>
        <input v-model.trim="filters.agentCode" placeholder="AG200014"
          class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white" />
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Party</label>
        <select v-model="filters.party" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="inh">In-house</option>
          <option value="ag">Agent</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">From</label>
        <input type="date" v-model="filters.fromDate"
          class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white" />
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">To</label>
        <input type="date" v-model="filters.toDate"
          class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white" />
      </div>
      <button
        class="btn btn-brand w-full md:w-auto px-4 py-1.5 bg-brand-600 text-white rounded-lg text-sm hover:bg-brand-700"
        :disabled="loading"
        @click="load"
      >
        {{ loading ? 'Loading…' : 'Apply' }}
      </button>
    </section>

    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Rows returned</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ rows.length.toLocaleString() }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">InH total</div>
        <div class="text-2xl font-semibold text-emerald-700 mt-1">{{ fmtBaht(totals.inh.amount) }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ totals.inh.count.toLocaleString() }} entries</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Agent total</div>
        <div class="text-2xl font-semibold text-amber-700 mt-1">{{ fmtBaht(totals.ag.amount) }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ totals.ag.count.toLocaleString() }} entries</div>
      </div>
    </section>

    <section v-if="error" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">App date</th>
              <th class="px-4 py-2 text-left">Application</th>
              <th class="px-4 py-2 text-left">Agent</th>
              <th class="px-4 py-2 text-left">Source</th>
              <th class="px-4 py-2 text-left">Party</th>
              <th class="px-4 py-2 text-right">Base premium</th>
              <th class="px-4 py-2 text-right">Rate</th>
              <th class="px-4 py-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="(r, i) in rows" :key="`${r.policyId}-${r.source}-${r.party}-${i}`" class="hover:bg-slate-50">
              <td class="px-4 py-2 whitespace-nowrap text-slate-700">{{ r.appDate ?? '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.applicationNo ?? '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.agentCode ?? '—' }}</td>
              <td class="px-4 py-2">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs border', sourceBadge(r.source)]">
                  {{ r.source }}
                </span>
              </td>
              <td class="px-4 py-2">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs border', partyBadge(r.party)]">
                  {{ r.party }}
                </span>
              </td>
              <td class="px-4 py-2 text-right">{{ fmtBaht(r.basePremium) }}</td>
              <td class="px-4 py-2 text-right text-slate-600">{{ fmtPct(r.commissionRate) }}</td>
              <td class="px-4 py-2 text-right font-medium text-slate-900">{{ fmtBaht(r.commissionAmount) }}</td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td colspan="8" class="px-4 py-6 text-center text-slate-500">ไม่พบข้อมูลตามเงื่อนไข</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
