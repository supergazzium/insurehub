<script setup lang="ts">
// Phase 8a — cancelled policies list (reads existing cancellation-ledger).
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchCancellations, type CancellationRow } from '../../api/opsReports'
import { toCsv, downloadCsv } from '../../util/csvExport'
import { ApiError } from '../../api/client'

const { t } = useI18n()
const days = ref(90)
const rows = ref<CancellationRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true; error.value = null
  try {
    const res = await fetchCancellations(days.value)
    rows.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed.'
  } finally { loading.value = false }
}
onMounted(load)

function exportCsv(): void {
  const csv = toCsv(rows.value, [
    { header: 'Cancel status', value: (r) => r.cancelStatus },
    { header: 'Cancel date', value: (r) => r.cancelDate },
    { header: 'Application', value: (r) => r.applicationNo },
    { header: 'Policy', value: (r) => r.policyNo },
    { header: 'Customer', value: (r) => r.customerName },
    { header: 'Agent', value: (r) => r.agentCode },
    { header: 'Annual premium', value: (r) => r.annualPremium?.toFixed(2) },
    { header: 'Refund premium', value: (r) => r.refundPremium?.toFixed(2) ?? '' },
    { header: 'Net refund', value: (r) => r.netRefundAmount?.toFixed(2) ?? '' },
  ])
  downloadCsv(csv, `cancellations-${new Date().toISOString().slice(0, 10)}.csv`)
}
function fmt(n: number | null): string {
  return n == null ? '—' : n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('reports.cancellations.title') }}</h1>
        <p class="text-sm text-slate-500 mt-1">{{ t('reports.cancellations.subtitle') }}</p>
      </div>
      <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
        :disabled="!rows.length" @click="exportCsv">
        <i class="pi pi-download text-xs mr-1" /> {{ t('reports.exportCsv') }}
      </button>
    </header>

    <section class="card p-4 flex items-end gap-3">
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('reports.cancellations.daysBack') }}</label>
        <input v-model.number="days" type="number" min="1" max="3650" class="w-24 border border-slate-200 rounded-lg px-3 py-2 text-sm" />
      </div>
      <button type="button" class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
        :disabled="loading" @click="load">
        <i :class="loading ? 'pi pi-spin pi-spinner' : 'pi pi-search'" class="text-xs mr-1" />
        {{ t('reports.load') }}
      </button>
      <div class="ml-auto text-xs text-slate-500">{{ t('reports.totalRows', { n: rows.length }) }}</div>
    </section>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">{{ error }}</div>

    <section class="card overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th class="px-3 py-2 text-left">{{ t('reports.cancellations.status') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.cancellations.cancelDate') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.application') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.policy') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.customer') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.agent') }}</th>
            <th class="px-3 py-2 text-right">{{ t('reports.col.premium') }}</th>
            <th class="px-3 py-2 text-right">{{ t('reports.cancellations.refund') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!rows.length && !loading"><td colspan="8" class="text-center py-8 text-slate-400 text-xs">{{ t('reports.empty') }}</td></tr>
          <tr v-for="r in rows" :key="r.policyId">
            <td class="px-3 py-2">
              <span class="inline-flex px-2 py-0.5 rounded bg-rose-50 text-rose-700 text-xs">{{ r.cancelStatus }}</span>
            </td>
            <td class="px-3 py-2 text-xs font-mono">{{ r.cancelDate || '—' }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ r.applicationNo }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ r.policyNo || '—' }}</td>
            <td class="px-3 py-2 truncate max-w-xs">{{ r.customerName }}</td>
            <td class="px-3 py-2 text-xs">{{ r.agentCode }}</td>
            <td class="px-3 py-2 text-right font-mono text-xs">฿ {{ fmt(r.annualPremium) }}</td>
            <td class="px-3 py-2 text-right font-mono text-xs text-emerald-700">฿ {{ fmt(r.netRefundAmount) }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
