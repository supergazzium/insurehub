<script setup lang="ts">
// Phase 5 — Quote list. Filters + link to new/edit/detail.
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { fetchQuoteList, type Quote } from '../../api/quotes'
import { ApiError } from '../../api/client'

const { t } = useI18n()

const quotes = ref<Quote[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const q = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchQuoteList({ q: q.value || undefined, perPage: 50 })
    quotes.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load quotes.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('quotes.title') }}</h1>
        <p class="text-sm text-slate-500 mt-1">{{ t('quotes.subtitle') }}</p>
      </div>
      <RouterLink :to="{ name: 'quote-new' }"
        class="px-3 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm flex items-center gap-1.5">
        <i class="pi pi-plus text-xs" /> {{ t('quotes.new') }}
      </RouterLink>
    </header>

    <section class="card p-4 flex items-center gap-3">
      <input v-model.trim="q" @keydown.enter="load"
        :placeholder="t('quotes.search')"
        class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm" />
      <button type="button" class="px-3 py-2 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50"
        @click="load">
        <i :class="loading ? 'pi pi-spin pi-spinner' : 'pi pi-refresh'" class="text-xs mr-1" />
        {{ t('quotes.refresh') }}
      </button>
    </section>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <section class="card overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th class="px-4 py-2 text-left">{{ t('quotes.col.quoteNo') }}</th>
            <th class="px-4 py-2 text-left">{{ t('quotes.col.date') }}</th>
            <th class="px-4 py-2 text-left">{{ t('quotes.col.status') }}</th>
            <th class="px-4 py-2 text-right">{{ t('quotes.col.premium') }}</th>
            <th class="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!loading && !quotes.length">
            <td colspan="5" class="px-4 py-8 text-center text-slate-400 text-xs">
              {{ t('quotes.empty') }}
            </td>
          </tr>
          <tr v-for="q in quotes" :key="q.id">
            <td class="px-4 py-2 font-mono text-xs">{{ q.quoteNo }}</td>
            <td class="px-4 py-2">{{ q.quoteDate || '—' }}</td>
            <td class="px-4 py-2">
              <span :class="{
                'bg-slate-100 text-slate-700': q.status === 'quote',
                'bg-brand-50 text-brand-700': q.status === 'application',
              }" class="inline-flex px-2 py-0.5 rounded text-xs">{{ q.status }}</span>
            </td>
            <td class="px-4 py-2 text-right font-mono text-xs">
              {{ Number(q.totalPremiumPaid || q.annualPremium || 0).toLocaleString('th-TH', { minimumFractionDigits: 2 }) }}
            </td>
            <td class="px-4 py-2 text-right">
              <RouterLink :to="{ name: 'quote-detail', params: { id: q.id } }"
                class="text-brand-600 hover:text-brand-700 text-xs">
                {{ t('quotes.open') }}
              </RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
