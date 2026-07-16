<script setup lang="ts">
// Phase 8a — mailing pipeline (policies to be sent to customers).
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchMailingPipeline, type MailingRow } from '../../api/opsReports'
import { toCsv, downloadCsv } from '../../util/csvExport'
import { ApiError } from '../../api/client'

const { t } = useI18n()

const now = new Date()
const defaults = {
  from: new Date(now.getTime() - 30 * 86_400_000).toISOString().slice(0, 10),
  to: new Date(now.getTime() + 30 * 86_400_000).toISOString().slice(0, 10),
}
const range = reactive({ from: defaults.from, to: defaults.to })
const rows = ref<MailingRow[]>([])
const total = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true; error.value = null
  try {
    const res = await fetchMailingPipeline(range.from, range.to)
    rows.value = res.data; total.value = res.meta.total
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed.'
  } finally { loading.value = false }
}
onMounted(load)

function exportCsv(): void {
  const csv = toCsv(rows.value, [
    { header: 'Mailing date', value: (r) => r.mailingDate },
    { header: 'Application', value: (r) => r.applicationNo },
    { header: 'Policy', value: (r) => r.policyNo },
    { header: 'Customer', value: (r) => `${r.customerCode ?? ''} ${r.customerName ?? ''}`.trim() },
    { header: 'Address', value: (r) => r.mailingAddress },
    { header: 'Note', value: (r) => r.mailingNote },
    { header: 'Agent', value: (r) => r.agentCode },
    { header: 'Carrier', value: (r) => r.carrierCode },
  ])
  downloadCsv(csv, `mailing-${range.from}-${range.to}.csv`)
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('reports.mailing.title') }}</h1>
        <p class="text-sm text-slate-500 mt-1">{{ t('reports.mailing.subtitle') }}</p>
      </div>
      <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
        :disabled="!rows.length" @click="exportCsv">
        <i class="pi pi-download text-xs mr-1" /> {{ t('reports.exportCsv') }}
      </button>
    </header>

    <section class="card p-4 flex items-end gap-3">
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('reports.from') }}</label>
        <input v-model="range.from" type="date" class="border border-slate-200 rounded-lg px-3 py-2 text-sm" />
      </div>
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('reports.to') }}</label>
        <input v-model="range.to" type="date" class="border border-slate-200 rounded-lg px-3 py-2 text-sm" />
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
            <th class="px-3 py-2 text-left">{{ t('reports.mailing.mailingDate') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.application') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.customer') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.mailing.address') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.agent') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.carrier') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!rows.length && !loading"><td colspan="6" class="text-center py-8 text-slate-400 text-xs">{{ t('reports.empty') }}</td></tr>
          <tr v-for="r in rows" :key="r.policyId">
            <td class="px-3 py-2 text-xs font-mono">{{ r.mailingDate || '—' }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ r.applicationNo }}</td>
            <td class="px-3 py-2 truncate max-w-xs">{{ r.customerName }}</td>
            <td class="px-3 py-2 text-xs text-slate-600 truncate max-w-md">{{ r.mailingAddress || '—' }}</td>
            <td class="px-3 py-2 text-xs">{{ r.agentCode }}</td>
            <td class="px-3 py-2 text-xs">{{ r.carrierCode }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
