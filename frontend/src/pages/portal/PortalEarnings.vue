<script setup lang="ts">
// Phase 7a — real-time earnings ledger for the current agent.
// Summary cards + monthly breakdown + latest 20 transactions with reversals.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchEarnings, type EarningsResponse } from '../../api/portal'
import { ApiError } from '../../api/client'

const { t } = useI18n()
const data = ref<EarningsResponse | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    data.value = await fetchEarnings()
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load earnings.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const byMonthSorted = computed(() =>
  [...(data.value?.byMonth ?? [])].sort((a, b) => b.month.localeCompare(a.month)),
)

function fmt(n: number): string {
  return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function typeColor(type: string): string {
  return {
    agent: 'bg-brand-50 text-brand-700',
    override: 'bg-violet-50 text-violet-700',
    inh: 'bg-slate-100 text-slate-700',
  }[type] ?? 'bg-slate-100 text-slate-700'
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('portal.earnings.title') }}</h1>
        <p class="text-sm text-slate-500 mt-1">{{ t('portal.earnings.subtitle') }}</p>
      </div>
      <button type="button" class="text-sm text-slate-500 hover:text-brand-600 flex items-center gap-1"
        @click="load">
        <i :class="loading ? 'pi pi-spin pi-spinner' : 'pi pi-refresh'" class="text-xs" />
        {{ t('common.refresh') }}
      </button>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <!-- Summary cards -->
    <section v-if="data" class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="card p-5">
        <div class="text-xs uppercase text-slate-400 mb-1">{{ t('portal.earnings.accrued') }}</div>
        <div class="text-2xl font-semibold text-brand-700 font-mono">฿ {{ fmt(data.summary.accrued) }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ t('portal.earnings.accruedHint') }}</div>
      </div>
      <div class="card p-5">
        <div class="text-xs uppercase text-slate-400 mb-1">{{ t('portal.earnings.paid') }}</div>
        <div class="text-2xl font-semibold text-emerald-700 font-mono">฿ {{ fmt(data.summary.paid) }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ t('portal.earnings.paidHint') }}</div>
      </div>
      <div class="card p-5">
        <div class="text-xs uppercase text-slate-400 mb-1">{{ t('portal.earnings.total') }}</div>
        <div class="text-2xl font-semibold text-slate-900 font-mono">฿ {{ fmt(data.summary.total) }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ data.summary.txnCount.toLocaleString() }} {{ t('portal.earnings.txnCount') }}</div>
      </div>
    </section>

    <!-- Monthly breakdown -->
    <section v-if="data" class="card overflow-hidden">
      <header class="px-5 py-4 border-b border-slate-100">
        <h2 class="text-sm font-semibold text-slate-600">{{ t('portal.earnings.byMonth') }}</h2>
      </header>
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th class="px-4 py-2 text-left">{{ t('portal.earnings.month') }}</th>
            <th class="px-4 py-2 text-right">{{ t('portal.earnings.accrued') }}</th>
            <th class="px-4 py-2 text-right">{{ t('portal.earnings.paid') }}</th>
            <th class="px-4 py-2 text-right">{{ t('portal.earnings.txnCount') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!byMonthSorted.length">
            <td colspan="4" class="px-4 py-8 text-center text-slate-400 text-xs">
              {{ t('portal.earnings.empty') }}
            </td>
          </tr>
          <tr v-for="m in byMonthSorted" :key="m.month">
            <td class="px-4 py-2 font-mono text-xs">{{ m.month }}</td>
            <td class="px-4 py-2 text-right font-mono">฿ {{ fmt(m.unsettled) }}</td>
            <td class="px-4 py-2 text-right font-mono text-emerald-700">฿ {{ fmt(m.settled) }}</td>
            <td class="px-4 py-2 text-right text-xs text-slate-500">{{ m.count }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Recent transactions -->
    <section v-if="data" class="card overflow-hidden">
      <header class="px-5 py-4 border-b border-slate-100">
        <h2 class="text-sm font-semibold text-slate-600">
          {{ t('portal.earnings.recent') }}
          <span class="text-slate-400 font-normal">({{ t('portal.earnings.latest20') }})</span>
        </h2>
      </header>
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th class="px-4 py-2 text-left">{{ t('portal.earnings.date') }}</th>
            <th class="px-4 py-2 text-left">{{ t('portal.earnings.type') }}</th>
            <th class="px-4 py-2 text-left">{{ t('portal.earnings.policyId') }}</th>
            <th class="px-4 py-2 text-right">{{ t('portal.earnings.basePremium') }}</th>
            <th class="px-4 py-2 text-right">%</th>
            <th class="px-4 py-2 text-right">{{ t('portal.earnings.amount') }}</th>
            <th class="px-4 py-2 text-left">{{ t('portal.earnings.status') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!data.recent.length">
            <td colspan="7" class="px-4 py-8 text-center text-slate-400 text-xs">
              {{ t('portal.earnings.empty') }}
            </td>
          </tr>
          <tr v-for="tx in data.recent" :key="tx.id" :class="{ 'bg-rose-50/40': tx.isReversal }">
            <td class="px-4 py-2 font-mono text-xs">{{ tx.createdAt?.slice(0, 10) }}</td>
            <td class="px-4 py-2">
              <span :class="typeColor(tx.type)" class="inline-flex px-2 py-0.5 rounded text-xs">{{ tx.type }}</span>
              <span v-if="tx.isReversal" class="ml-1 text-xs text-rose-600" :title="t('portal.earnings.reversal')">
                <i class="pi pi-refresh text-[10px]" />
              </span>
            </td>
            <td class="px-4 py-2 font-mono text-xs">#{{ tx.policyId }}</td>
            <td class="px-4 py-2 text-right font-mono text-xs">฿ {{ fmt(tx.basePremium) }}</td>
            <td class="px-4 py-2 text-right font-mono text-xs text-slate-500">{{ (tx.diffPct * 100).toFixed(2) }}%</td>
            <td class="px-4 py-2 text-right font-mono font-semibold"
              :class="tx.amount < 0 ? 'text-rose-700' : 'text-slate-900'">
              ฿ {{ fmt(tx.amount) }}
            </td>
            <td class="px-4 py-2">
              <span :class="tx.status === 'settled' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"
                class="inline-flex px-2 py-0.5 rounded text-xs">{{ tx.status }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
