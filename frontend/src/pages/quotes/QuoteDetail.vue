<script setup lang="ts">
// Phase 5 — Quote detail with convert-to-application button.
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { fetchQuote, convertQuoteToApplication, type Quote } from '../../api/quotes'
import { ApiError } from '../../api/client'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const quote = ref<Quote | null>(null)
const converting = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    const res = await fetchQuote(String(route.params.id))
    quote.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load quote.'
  }
}
onMounted(load)

async function convert(): Promise<void> {
  if (!quote.value) return
  if (!window.confirm(t('quotes.convertConfirm'))) return
  converting.value = true
  error.value = null
  try {
    const res = await convertQuoteToApplication(quote.value.id)
    quote.value = res.data
    void router.push({ name: 'policy-edit', params: { id: res.data.id } })
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Convert failed.'
  } finally {
    converting.value = false
  }
}
</script>

<template>
  <div class="space-y-6 max-w-3xl">
    <header class="flex items-center justify-between">
      <div>
        <div class="text-xs text-slate-400">{{ t('quotes.detail.title') }}</div>
        <h1 class="text-2xl font-semibold text-slate-900 font-mono">{{ quote?.quoteNo || '—' }}</h1>
      </div>
      <div class="flex items-center gap-2">
        <button v-if="quote?.status === 'quote'" type="button"
          class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50"
          @click="router.push({ name: 'quote-edit', params: { id: quote!.id } })">
          <i class="pi pi-pencil text-xs mr-1" /> {{ t('quotes.edit') }}
        </button>
        <button v-if="quote?.status === 'quote'" type="button"
          class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
          :disabled="converting" @click="convert">
          <i v-if="converting" class="pi pi-spin pi-spinner mr-1" />
          <i v-else class="pi pi-arrow-right text-xs mr-1" />
          {{ t('quotes.convert') }}
        </button>
      </div>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">{{ error }}</div>

    <section v-if="quote" class="card p-5 space-y-3">
      <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div><dt class="text-xs text-slate-400">{{ t('quotes.field.status') }}</dt>
          <dd><span :class="{
            'bg-slate-100 text-slate-700': quote.status === 'quote',
            'bg-brand-50 text-brand-700': quote.status === 'application',
            'bg-emerald-50 text-emerald-700': ['active','issued'].includes(quote.status),
          }" class="inline-flex px-2 py-0.5 rounded text-xs">{{ quote.status }}</span></dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('quotes.field.applicationNo') }}</dt>
          <dd class="font-mono text-sm">{{ quote.applicationNo || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('quotes.field.effectiveDate') }}</dt>
          <dd>{{ quote.effectiveDate || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('quotes.field.expiryDate') }}</dt>
          <dd>{{ quote.expiryDate || '—' }}</dd></div>
      </dl>
    </section>

    <section v-if="quote" class="card p-5">
      <h2 class="text-sm font-semibold text-slate-600 mb-3">{{ t('quotes.premium.title') }}</h2>
      <dl class="grid grid-cols-4 gap-3 text-sm">
        <div><dt class="text-xs text-slate-400">{{ t('quotes.premium.net') }}</dt>
          <dd class="font-mono">฿ {{ Number(quote.netPremium || 0).toFixed(2) }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('quotes.premium.duty') }}</dt>
          <dd class="font-mono">฿ {{ Number(quote.dutyStamp || 0).toFixed(2) }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('quotes.premium.vat') }}</dt>
          <dd class="font-mono">฿ {{ Number(quote.vat || 0).toFixed(2) }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('quotes.premium.total') }}</dt>
          <dd class="font-mono text-brand-700 font-semibold">฿ {{ Number(quote.totalPremiumPaid || 0).toFixed(2) }}</dd></div>
      </dl>
    </section>

    <section v-if="quote?.internalNote" class="card p-5">
      <div class="text-xs text-slate-400 mb-1">{{ t('quotes.field.internalNote') }}</div>
      <div class="text-sm whitespace-pre-wrap">{{ quote.internalNote }}</div>
    </section>
  </div>
</template>
