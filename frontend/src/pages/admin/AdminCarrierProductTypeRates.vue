<script setup lang="ts">
// MGM (carrier × product-type) standard commission matrix admin.
//
// Rows = carriers (~24), columns = product-types (~21).  Each cell is a
// percentage (0..100%) or blank (null, meaning "carrier doesn't sell
// this type" — the "-" cells in the source Excel).
//
// Interaction: click a cell to edit; save on blur/change. Blank input
// clears the cell (null standard_rate). Non-existent cells are created
// on first save (POST); existing cells are patched (PATCH).
//
// Filters: pick a product-type group (Motor / Fire / Health / ...) to
// narrow the columns and reduce horizontal scroll on wide screens.

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '../../api/client'
import {
  createCarrierProductTypeRate, fetchCarrierProductTypeRates,
  updateCarrierProductTypeRate,
  type MatrixCarrier, type MatrixProductType, type MatrixRate,
} from '../../api/mgm'

const { t } = useI18n()

const carriers = ref<MatrixCarrier[]>([])
const productTypes = ref<MatrixProductType[]>([])
const rates = ref<MatrixRate[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
// Per-cell saving indicator: "{carrierId}:{productTypeId}"
const savingCellKey = ref<string | null>(null)

// Filter: null = show all groups; otherwise show only product-types in that group.
const groupFilter = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchCarrierProductTypeRates()
    carriers.value = res.carriers
    productTypes.value = res.productTypes
    rates.value = res.rates
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load matrix.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ── Rate lookup: (carrierId, productTypeId) → MatrixRate | null ──────────
// Build an index once when rates change, so per-cell lookup is O(1).
const rateIndex = computed<Map<string, MatrixRate>>(() => {
  const map = new Map<string, MatrixRate>()
  for (const rate of rates.value) {
    map.set(`${rate.carrierId}:${rate.productTypeId}`, rate)
  }
  return map
})

function findRate(carrierId: string, productTypeId: string): MatrixRate | undefined {
  return rateIndex.value.get(`${carrierId}:${productTypeId}`)
}

// Filtered columns based on the group picker.
const visibleTypes = computed<MatrixProductType[]>(() => {
  if (groupFilter.value === null) return productTypes.value
  return productTypes.value.filter((t) => t.subOf === groupFilter.value)
})

const groups = computed<string[]>(() => {
  const set = new Set<string>()
  for (const t of productTypes.value) {
    if (t.subOf) set.add(t.subOf)
  }
  return [...set].sort()
})

// ── Cell edit: upsert (POST if no row exists, PATCH if it does) ──────────
async function saveCell(carrier: MatrixCarrier, type: MatrixProductType, valuePct: string): Promise<void> {
  const key = `${carrier.id}:${type.id}`
  savingCellKey.value = key
  try {
    // Parse "8.5" → 0.085. Blank → null (clear the cell).
    let rate: number | null = null
    if (valuePct.trim() !== '') {
      const parsed = Number(valuePct)
      if (Number.isNaN(parsed) || parsed < 0 || parsed > 100) {
        throw new Error(t('adminMatrix.invalidRate'))
      }
      rate = parsed / 100
    }
    const existing = findRate(carrier.id, type.id)
    if (existing) {
      const res = await updateCarrierProductTypeRate(existing.id, { standardRate: rate })
      Object.assign(existing, res)
    } else {
      const res = await createCarrierProductTypeRate({
        carrierId: Number(carrier.id),
        productTypeId: Number(type.id),
        standardRate: rate,
      })
      rates.value.push(res)
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : e instanceof Error ? e.message : 'Save failed.'
    // Reload the matrix to roll back the failed edit.
    void load()
  } finally {
    savingCellKey.value = null
  }
}

// Format a rate for display: 0.085 → "8.50", null → ""
function fmt(rate: number | null | undefined): string {
  if (rate === null || rate === undefined) return ''
  return (rate * 100).toFixed(2)
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('adminMatrix.title') }}</h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('adminMatrix.subtitle') }}</p>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <!-- Group filter -->
    <div class="flex items-center gap-3 flex-wrap">
      <span class="text-xs text-slate-500">{{ t('adminMatrix.filter') }}</span>
      <button
        type="button"
        :class="[
          'px-3 py-1 rounded text-xs',
          groupFilter === null ? 'bg-brand-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200',
        ]"
        @click="groupFilter = null"
      >
        {{ t('adminMatrix.allGroups') }}
      </button>
      <button
        v-for="g in groups"
        :key="g"
        type="button"
        :class="[
          'px-3 py-1 rounded text-xs',
          groupFilter === g ? 'bg-brand-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200',
        ]"
        @click="groupFilter = g"
      >
        {{ g }}
      </button>
    </div>

    <div v-if="loading" class="text-sm text-slate-500">{{ t('adminMatrix.loading') }}</div>

    <div v-else class="card overflow-hidden">
      <div class="overflow-auto max-h-[70vh]">
        <table class="min-w-full text-xs">
          <!-- Sticky column headers -->
          <thead class="bg-slate-100 sticky top-0 z-20">
            <tr>
              <th class="sticky left-0 bg-slate-100 px-3 py-2 text-left font-semibold text-slate-700 z-30 min-w-[140px]">
                {{ t('adminMatrix.carrier') }}
              </th>
              <th
                v-for="type in visibleTypes"
                :key="type.id"
                class="px-2 py-2 text-center font-medium text-slate-600 whitespace-nowrap"
                :title="`${type.nameTh} / ${type.nameEn} · ${type.subOf ?? ''}`"
              >
                <div class="max-w-[120px] truncate">{{ type.nameTh }}</div>
                <div class="text-[10px] text-slate-400 font-mono">{{ type.code }}</div>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="carrier in carriers" :key="carrier.id" class="hover:bg-slate-50">
              <!-- Sticky first column: carrier -->
              <td class="sticky left-0 bg-white px-3 py-2 font-medium text-slate-800 z-10">
                <div class="text-sm">{{ carrier.code }}</div>
                <div class="text-[10px] text-slate-400 truncate max-w-[130px]">{{ carrier.name }}</div>
              </td>
              <td
                v-for="type in visibleTypes"
                :key="type.id"
                class="px-1 py-1 text-center"
                :class="{ 'opacity-60': savingCellKey === `${carrier.id}:${type.id}` }"
              >
                <input
                  :value="fmt(findRate(carrier.id, type.id)?.standardRate)"
                  type="text"
                  inputmode="decimal"
                  placeholder="-"
                  class="w-16 border border-transparent hover:border-slate-200 focus:border-brand-400 focus:outline-none bg-transparent text-right px-1 py-0.5 rounded text-xs font-mono"
                  @change="(e) => saveCell(carrier, type, (e.target as HTMLInputElement).value)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p class="text-xs text-slate-400">
      {{ t('adminMatrix.hint') }}
    </p>
  </div>
</template>
