<script setup lang="ts">
// MGM commission tiers admin — 3 tiers (BLUE/YELLOW/RED). Admin can rename
// tier metadata (name_th, name_en, color, notes) and edit any of the 30
// rate cells per tenant (3 tiers × 10 ranks × mgmt_fee + referral_fee).
//
// Tier count is fixed at 3 — no add/remove UI. Rate cells save inline on
// blur/change (per-cell PATCH, matches AdminMotorTariffs convention).

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '../../api/client'
import {
  fetchCommissionTiers, updateCommissionTier, updateTierRankRate,
  type CommissionTier, type TierRankRate,
} from '../../api/mgm'

const { t } = useI18n()

const tiers = ref<CommissionTier[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
// Per-cell saving indicator: "{tierId}:{rateId}:mgmt" or ":ref" for referral.
const savingCellKey = ref<string | null>(null)
const savingTierId = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchCommissionTiers()
    tiers.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load tiers.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ── Tier metadata edit (rename + notes + color) ──────────────────────────
async function saveTierMeta(tier: CommissionTier, field: keyof CommissionTier): Promise<void> {
  savingTierId.value = tier.id
  try {
    const payload: Record<string, unknown> = {}
    // Only send the field that changed.
    if (field === 'nameTh') payload.nameTh = tier.nameTh
    if (field === 'nameEn') payload.nameEn = tier.nameEn
    if (field === 'colorHex') payload.colorHex = tier.colorHex
    if (field === 'notes') payload.notes = tier.notes
    const res = await updateCommissionTier(tier.id, payload)
    Object.assign(tier, res.data)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Save failed.'
    // Reload to roll back the failed edit.
    void load()
  } finally {
    savingTierId.value = null
  }
}

// ── Rate cell edit (mgmt_fee_rate or referral_fee_rate) ──────────────────
async function saveRateCell(
  tier: CommissionTier,
  rate: TierRankRate,
  field: 'mgmtFeeRate' | 'referralFeeRate',
): Promise<void> {
  const key = `${tier.id}:${rate.id}:${field}`
  savingCellKey.value = key
  try {
    const payload = { [field]: rate[field] }
    const res = await updateTierRankRate(tier.id, rate.id, payload)
    // API returns the fresh rate; overwrite our local copy.
    rate.mgmtFeeRate = res.mgmtFeeRate
    rate.referralFeeRate = res.referralFeeRate
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Save failed.'
    void load()
  } finally {
    savingCellKey.value = null
  }
}

// Sort tier.rankRates by rank_level DESC so Lv10 is on top (matches Excel).
function sortedRates(tier: CommissionTier): TierRankRate[] {
  return [...(tier.rankRates ?? [])].sort((a, b) => b.rankLevel - a.rankLevel)
}

const hasTiers = computed(() => tiers.value.length > 0)
</script>

<template>
  <div class="space-y-6 max-w-6xl">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('adminCommissionTiers.title') }}</h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('adminCommissionTiers.subtitle') }}</p>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <div v-if="loading" class="text-sm text-slate-500">{{ t('adminCommissionTiers.loading') }}</div>

    <div v-else-if="!hasTiers" class="card p-6 text-center text-slate-500">
      {{ t('adminCommissionTiers.empty') }}
    </div>

    <section
      v-for="tier in tiers"
      :key="tier.id"
      class="card p-6 space-y-4"
      :class="{ 'opacity-60': savingTierId === tier.id }"
    >
      <!-- Tier header: color swatch + editable names + code -->
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div class="flex items-start gap-3">
          <div
            class="w-6 h-6 rounded border border-slate-200 shrink-0 mt-1"
            :style="{ backgroundColor: tier.colorHex ?? '#e2e8f0' }"
          />
          <div class="space-y-1">
            <input
              v-model.trim="tier.nameTh"
              type="text"
              class="text-xl font-semibold text-slate-900 border-b border-transparent hover:border-slate-200 focus:border-brand-400 focus:outline-none bg-transparent w-72"
              @change="saveTierMeta(tier, 'nameTh')"
            />
            <input
              v-model.trim="tier.nameEn"
              type="text"
              class="text-sm text-slate-500 border-b border-transparent hover:border-slate-200 focus:border-brand-400 focus:outline-none bg-transparent w-72 block"
              @change="saveTierMeta(tier, 'nameEn')"
            />
            <div class="text-xs font-mono text-slate-400">{{ tier.code }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-xs text-slate-500">{{ t('adminCommissionTiers.color') }}</label>
          <input
            v-model="tier.colorHex"
            type="color"
            class="w-10 h-8 border border-slate-200 rounded"
            @change="saveTierMeta(tier, 'colorHex')"
          />
        </div>
      </div>

      <!-- Notes -->
      <div>
        <label class="text-xs text-slate-500 block mb-1">{{ t('adminCommissionTiers.notes') }}</label>
        <textarea
          v-model="tier.notes"
          rows="2"
          class="w-full border border-slate-200 rounded px-3 py-2 text-sm focus:outline-none focus:border-brand-400"
          @change="saveTierMeta(tier, 'notes')"
        />
      </div>

      <!-- Rate grid: rows = ranks (Lv10 → Lv1), columns = mgmt + referral -->
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
            <tr>
              <th class="px-4 py-2 text-left">{{ t('adminCommissionTiers.rank') }}</th>
              <th class="px-4 py-2 text-right">{{ t('adminCommissionTiers.mgmtFee') }}</th>
              <th class="px-4 py-2 text-right">{{ t('adminCommissionTiers.referralFee') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="rate in sortedRates(tier)" :key="rate.id">
              <td class="px-4 py-2 font-medium text-slate-800">Lv{{ rate.rankLevel }}</td>
              <td class="px-4 py-2 text-right">
                <div class="inline-flex items-center gap-1">
                  <input
                    v-model.number="rate.mgmtFeeRate"
                    type="number"
                    step="0.0001"
                    min="0"
                    max="1"
                    :class="[
                      'w-24 border border-slate-200 rounded px-2 py-1 text-right text-sm font-mono focus:outline-none focus:border-brand-400',
                      savingCellKey === `${tier.id}:${rate.id}:mgmtFeeRate` ? 'opacity-60' : '',
                    ]"
                    @change="saveRateCell(tier, rate, 'mgmtFeeRate')"
                  />
                  <span class="text-xs text-slate-400">({{ (rate.mgmtFeeRate * 100).toFixed(2) }}%)</span>
                </div>
              </td>
              <td class="px-4 py-2 text-right">
                <div class="inline-flex items-center gap-1">
                  <input
                    v-model.number="rate.referralFeeRate"
                    type="number"
                    step="0.0001"
                    min="0"
                    max="1"
                    :class="[
                      'w-24 border border-slate-200 rounded px-2 py-1 text-right text-sm font-mono focus:outline-none focus:border-brand-400',
                      savingCellKey === `${tier.id}:${rate.id}:referralFeeRate` ? 'opacity-60' : '',
                    ]"
                    @change="saveRateCell(tier, rate, 'referralFeeRate')"
                  />
                  <span class="text-xs text-slate-400">({{ (rate.referralFeeRate * 100).toFixed(2) }}%)</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="text-xs text-slate-400">
        {{ t('adminCommissionTiers.hint') }}
      </p>
    </section>
  </div>
</template>
