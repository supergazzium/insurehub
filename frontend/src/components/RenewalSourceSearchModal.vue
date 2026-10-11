<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchPolicyList, type PolicyListRow } from '../api/policies'
import PolicyPeriodBadge from './PolicyPeriodBadge.vue'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'pick', row: PolicyListRow): void }>()

const { t } = useI18n()
const q = ref('')
const rows = ref<PolicyListRow[]>([])
const loading = ref(false)
const failed = ref<string | null>(null)
const inputEl = ref<HTMLInputElement | null>(null)
let timer: number | undefined
let seq = 0

async function search(): Promise<void> {
  const mine = ++seq
  loading.value = true
  failed.value = null
  try {
    const res = await fetchPolicyList({ q: q.value.trim() || undefined, renewable: 1, perPage: 10 })
    if (mine === seq) rows.value = res.data
  } catch (e) {
    if (mine === seq) failed.value = e instanceof Error ? e.message : String(e)
  } finally {
    if (mine === seq) loading.value = false
  }
}

watch(q, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => { void search() }, 300)
})

watch(() => props.open, async (open) => {
  if (!open) return
  q.value = ''
  rows.value = []
  void search()
  await nextTick()
  inputEl.value?.focus()
}, { immediate: true })

onBeforeUnmount(() => window.clearTimeout(timer))

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: 'numeric' })
}

function daysLeft(iso: string | null): number | null {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return Math.round((d.getTime() - Date.now()) / 86_400_000)
}
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.esc="emit('close')">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col" data-testid="renewal-source-modal">
      <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
        <div>
          <h3 class="font-semibold text-slate-900">{{ t('policyCreate.renewal.searchTitle') }}</h3>
          <p class="text-xs text-slate-500 mt-0.5">{{ t('policyCreate.renewal.searchSubtitle') }}</p>
        </div>
        <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('close')">
          <i class="pi pi-times" />
        </button>
      </div>

      <div class="px-5 pt-4">
        <div class="relative">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input
            ref="inputEl"
            v-model="q"
            type="search"
            :placeholder="t('policyCreate.renewal.searchPlaceholder')"
            class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-2 text-sm focus:outline-none focus:border-brand-400"
            data-testid="renewal-source-search"
          />
        </div>
      </div>

      <div class="p-5 overflow-y-auto">
        <div v-if="failed" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ failed }}</div>
        <div v-else-if="loading && rows.length === 0" class="py-10 text-center text-sm text-slate-400">
          <i class="pi pi-spin pi-spinner mr-1" /> {{ t('policyCreate.renewal.searching') }}
        </div>
        <div v-else-if="rows.length === 0" class="py-10 text-center text-sm text-slate-400">{{ t('policyCreate.renewal.noResults') }}</div>
        <table v-else class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-slate-500 border-b border-slate-100">
              <th class="py-2 pr-3 font-medium">{{ t('policyCreate.renewal.colPolicy') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('policyCreate.renewal.colCustomer') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('policyCreate.renewal.colProduct') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('policyCreate.renewal.colExpiry') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('policyCreate.renewal.colStatus') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('policyCreate.renewal.colYear') }}</th>
              <th class="py-2" />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="r in rows"
              :key="r.id"
              class="border-b border-slate-50 hover:bg-teal-50/40 cursor-pointer"
              data-testid="renewal-source-row"
              @click="emit('pick', r)"
            >
              <td class="py-2 pr-3 align-top">
                <div class="font-medium text-slate-800">{{ r.policyNo || r.applicationNo || '—' }}</div>
                <div class="text-[11px] text-slate-400 font-mono">{{ r.applicationNo }}</div>
              </td>
              <td class="py-2 pr-3 align-top">
                <div class="text-slate-800">{{ r.customerName || '—' }}</div>
                <div class="text-[11px] text-slate-400">
                  {{ r.customerCode }}<template v-if="r.motorLicenseNo"> · {{ r.motorLicenseNo }}</template>
                </div>
              </td>
              <td class="py-2 pr-3 align-top">
                <div class="text-slate-800 line-clamp-1">{{ r.productName || '—' }}</div>
                <div class="text-[11px] text-slate-400 line-clamp-1">{{ r.carrierName }}</div>
              </td>
              <td class="py-2 pr-3 align-top whitespace-nowrap">
                <div class="text-slate-800">{{ fmtDate(r.expiryDate) }}</div>
                <div
                  v-if="daysLeft(r.expiryDate) !== null"
                  class="text-[11px]"
                  :class="(daysLeft(r.expiryDate) ?? 0) < 0 ? 'text-rose-500' : 'text-amber-600'"
                >
                  {{ (daysLeft(r.expiryDate) ?? 0) < 0
                    ? t('policyCreate.renewal.expiredDaysAgo', { n: Math.abs(daysLeft(r.expiryDate) ?? 0) })
                    : t('policyCreate.renewal.expiresInDays', { n: daysLeft(r.expiryDate) }) }}
                </div>
              </td>
              <td class="py-2 pr-3 align-top">
                <span
                  class="inline-flex rounded px-1.5 py-px text-[11px]"
                  :class="r.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'"
                >{{ r.statusLabel }}</span>
              </td>
              <td class="py-2 pr-3 align-top"><PolicyPeriodBadge :label="r.periodLabel" /></td>
              <td class="py-2 align-top text-right">
                <button type="button" class="text-xs font-medium text-teal-700 hover:underline whitespace-nowrap">
                  {{ t('policyCreate.renewal.pick') }} <i class="pi pi-arrow-right text-[10px]" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
