<script setup lang="ts">
// Phase 8a — freelook policies (still inside cooling-off window).
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchFreelook, type FreelookRow } from '../../api/opsReports'
import { toCsv, downloadCsv } from '../../util/csvExport'
import { ApiError } from '../../api/client'

const { t } = useI18n()
const days = ref(30)
const rows = ref<FreelookRow[]>([])
const total = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true; error.value = null
  try {
    const res = await fetchFreelook(days.value)
    rows.value = res.data; total.value = res.meta.total
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load.'
  } finally { loading.value = false }
}
onMounted(load)

function exportCsv(): void {
  const csv = toCsv(rows.value, [
    { header: 'Application', value: (r) => r.applicationNo },
    { header: 'Policy', value: (r) => r.policyNo },
    { header: 'Effective', value: (r) => r.effectiveDate },
    { header: 'Expiry', value: (r) => r.expiryDate },
    { header: 'Days since eff.', value: (r) => r.daysSinceEffective },
    { header: 'Annual premium', value: (r) => r.annualPremium.toFixed(2) },
    { header: 'Customer', value: (r) => `${r.customerCode ?? ''} ${r.customerName ?? ''}`.trim() },
    { header: 'Agent', value: (r) => `${r.agentCode ?? ''} ${r.agentName ?? ''}`.trim() },
    { header: 'Carrier', value: (r) => r.carrierCode },
    { header: 'Product', value: (r) => r.productCode },
  ])
  downloadCsv(csv, `freelook-${new Date().toISOString().slice(0, 10)}.csv`)
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('reports.freelook.title') }}</h1>
        <p class="text-sm text-slate-500 mt-1">{{ t('reports.freelook.subtitle') }}</p>
      </div>
      <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
        :disabled="!rows.length" @click="exportCsv">
        <i class="pi pi-download text-xs mr-1" /> {{ t('reports.exportCsv') }}
      </button>
    </header>

    <section class="card p-4 flex items-end gap-3">
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('reports.freelook.daysWindow') }}</label>
        <input v-model.number="days" type="number" min="1" max="365" class="w-24 border border-slate-200 rounded-lg px-3 py-2 text-sm" />
      </div>
      <button type="button" class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
        :disabled="loading" @click="load">
        <i :class="loading ? 'pi pi-spin pi-spinner' : 'pi pi-search'" class="text-xs mr-1" />
        {{ t('reports.load') }}
      </button>
      <div class="ml-auto text-xs text-slate-500">{{ t('reports.totalRows', { n: total }) }}</div>
    </section>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">{{ error }}</div>

    <section class="card overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th class="px-3 py-2 text-left">{{ t('reports.col.application') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.policy') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.freelook.effective') }}</th>
            <th class="px-3 py-2 text-right">{{ t('reports.freelook.daysSince') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.customer') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.agent') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.carrier') }}</th>
            <th class="px-3 py-2 text-right">{{ t('reports.col.premium') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!rows.length && !loading"><td colspan="8" class="text-center py-8 text-slate-400 text-xs">{{ t('reports.empty') }}</td></tr>
          <tr v-for="r in rows" :key="r.policyId">
            <td class="px-3 py-2 font-mono text-xs">{{ r.applicationNo }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ r.policyNo || '—' }}</td>
            <td class="px-3 py-2 text-xs">{{ r.effectiveDate }}</td>
            <td class="px-3 py-2 text-right font-mono text-xs">{{ r.daysSinceEffective }}</td>
            <td class="px-3 py-2 truncate max-w-xs">{{ r.customerName }}</td>
            <td class="px-3 py-2 text-xs">{{ r.agentCode }}</td>
            <td class="px-3 py-2 text-xs">{{ r.carrierCode }}</td>
            <td class="px-3 py-2 text-right font-mono text-xs">฿ {{ r.annualPremium.toLocaleString('th-TH', { minimumFractionDigits: 2 }) }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
