<script setup lang="ts">
// Phase 8a — payment history: one row per policy_payment in the window.
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchPaymentHistory, type PaymentRow } from '../../api/opsReports'
import { toCsv, downloadCsv } from '../../util/csvExport'
import { ApiError } from '../../api/client'
import DateInput from '../../components/DateInput.vue'
import { fmtDate } from '../../util/dateFormat'

const { t } = useI18n()

const now = new Date()
const first = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
const today = now.toISOString().slice(0, 10)
const range = reactive({ from: first, to: today })

const rows = ref<PaymentRow[]>([])
const total = ref(0)
const totalAmount = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true; error.value = null
  try {
    const res = await fetchPaymentHistory(range.from, range.to)
    rows.value = res.data
    total.value = res.meta.total
    totalAmount.value = res.meta.totalAmount
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed.'
  } finally { loading.value = false }
}
onMounted(load)

function exportCsv(): void {
  const csv = toCsv(rows.value, [
    { header: 'Payment date', value: (r) => r.paymentDate },
    { header: 'Amount', value: (r) => r.amount.toFixed(2) },
    { header: 'Method', value: (r) => r.method },
    { header: 'Reference', value: (r) => r.reference },
    { header: 'Application', value: (r) => r.applicationNo },
    { header: 'Policy', value: (r) => r.policyNo },
    { header: 'Customer', value: (r) => `${r.customerCode ?? ''} ${r.customerName ?? ''}`.trim() },
    { header: 'Agent', value: (r) => r.agentCode },
    { header: 'Carrier', value: (r) => r.carrierCode },
  ])
  downloadCsv(csv, `payments-${range.from}-${range.to}.csv`)
}

function fmt(n: number): string {
  return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('reports.payments.title') }}</h1>
        <p class="text-sm text-slate-500 mt-1">{{ t('reports.payments.subtitle') }}</p>
      </div>
      <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
        :disabled="!rows.length" @click="exportCsv">
        <i class="pi pi-download text-xs mr-1" /> {{ t('reports.exportCsv') }}
      </button>
    </header>

    <section class="card p-4 flex items-end gap-3">
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('reports.from') }}</label>
        <DateInput v-model="range.from" :max="range.to || undefined" />
      </div>
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('reports.to') }}</label>
        <DateInput v-model="range.to" :min="range.from || undefined" />
      </div>
      <button type="button" class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
        :disabled="loading" @click="load">
        <i :class="loading ? 'pi pi-spin pi-spinner' : 'pi pi-search'" class="text-xs mr-1" />
        {{ t('reports.load') }}
      </button>
      <div class="ml-auto text-sm">
        <div class="text-xs text-slate-500">{{ t('reports.payments.totalAmount') }}</div>
        <div class="font-mono font-semibold text-brand-700">฿ {{ fmt(totalAmount) }}</div>
      </div>
    </section>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">{{ error }}</div>

    <section class="card overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th class="px-3 py-2 text-left">{{ t('reports.payments.date') }}</th>
            <th class="px-3 py-2 text-right">{{ t('reports.payments.amount') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.payments.method') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.application') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.customer') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.agent') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.col.carrier') }}</th>
            <th class="px-3 py-2 text-left">{{ t('reports.payments.reference') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!rows.length && !loading"><td colspan="8" class="text-center py-8 text-slate-400 text-xs">{{ t('reports.empty') }}</td></tr>
          <tr v-for="r in rows" :key="r.paymentId">
            <td class="px-3 py-2 text-xs font-mono">{{ fmtDate(r.paymentDate) }}</td>
            <td class="px-3 py-2 text-right font-mono">฿ {{ fmt(r.amount) }}</td>
            <td class="px-3 py-2 text-xs">
              <span class="inline-flex px-2 py-0.5 rounded bg-slate-100 text-slate-700">{{ r.method }}</span>
            </td>
            <td class="px-3 py-2 font-mono text-xs">{{ r.applicationNo }}</td>
            <td class="px-3 py-2 truncate max-w-xs">{{ r.customerName }}</td>
            <td class="px-3 py-2 text-xs">{{ r.agentCode }}</td>
            <td class="px-3 py-2 text-xs">{{ r.carrierCode }}</td>
            <td class="px-3 py-2 text-xs text-slate-500 truncate max-w-xs">{{ r.reference || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
