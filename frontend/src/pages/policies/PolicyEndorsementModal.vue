<script setup lang="ts">
// สลักหลังเบี้ยเพิ่ม (v1) — premium-increase endorsement modal.
//
// The operator enters the new annual premium and the additional (pro-rata)
// premium to collect for the remaining period. We DO NOT auto-compute the
// charge (carriers use their own proration), but we DO show a reference
// suggestion (annual delta × remaining-days / total-days) as a sanity check
// the operator can copy or ignore. On confirm the parent calls the API,
// which updates the policy premium and records a before→after audit event.

import { ref, computed, watch } from 'vue'
import FormField from '../../components/FormField.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

function fmt(x: number): string {
  return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(x || 0)
}

/** Prefill shape when editing an existing endorsement. */
export interface EndorsementInitial {
  reason: string
  effectiveDate: string
  newAnnualPremium: number
  newCoverage: number
  additionalPremium: number
  additionalDutyStamp: number
  additionalVat: number
  /** The premium BEFORE this endorsement — the read-only "current" figures
   *  shown while editing (so the diff still reads old → new correctly). */
  beforeAnnualPremium: number
  beforeCoverage: number
}

const props = defineProps<{
  open: boolean
  /** Current policy figures — the "before" side of a NEW endorsement. */
  currentAnnualPremium: number
  currentCoverage: number
  /** Coverage window, for the remaining-days reference calc. */
  effectiveDate: string | null
  expiryDate: string | null
  /** When set, the modal opens in edit mode prefilled from this endorsement. */
  initial?: EndorsementInitial | null
  /** Disable the submit while the parent's request is in flight. */
  saving?: boolean
  /** Backend validation errors keyed by field. */
  errors?: Record<string, string[]>
}>()

const isEditing = computed<boolean>(() => !!props.initial)
/** The "before" premium/coverage to show + diff against (edit vs new). */
const baseAnnualPremium = computed<number>(() =>
  props.initial ? props.initial.beforeAnnualPremium : props.currentAnnualPremium)
const baseCoverage = computed<number>(() =>
  props.initial ? props.initial.beforeCoverage : props.currentCoverage)

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'submit', payload: {
    reason: string
    effectiveDate: string
    newAnnualPremium: number
    newCoverage: number | null
    additionalPremium: number
    additionalDutyStamp: number | null
    additionalVat: number | null
  }): void
}>()

// ── Form state ───────────────────────────────────────────────────────────
const reason = ref('')
const endorsementDate = ref('')
const newAnnualPremium = ref<number | null>(null)
const newCoverage = ref<number | null>(null)
const additionalPremium = ref<number | null>(null)
const additionalDutyStamp = ref<number | null>(null)
const additionalVat = ref<number | null>(null)

// Reset (or prefill, when editing) the form each time the modal opens.
watch(() => props.open, (open) => {
  if (!open) return
  if (props.initial) {
    reason.value = props.initial.reason
    endorsementDate.value = props.initial.effectiveDate
    newAnnualPremium.value = props.initial.newAnnualPremium
    newCoverage.value = props.initial.newCoverage || null
    additionalPremium.value = props.initial.additionalPremium
    additionalDutyStamp.value = props.initial.additionalDutyStamp || null
    additionalVat.value = props.initial.additionalVat || null
  } else {
    reason.value = ''
    endorsementDate.value = ''
    newAnnualPremium.value = null
    newCoverage.value = props.currentCoverage || null
    additionalPremium.value = null
    additionalDutyStamp.value = null
    additionalVat.value = null
  }
})

// ── Reference numbers (display-only) ─────────────────────────────────────
function parseDate(s: string | null): Date | null {
  if (!s) return null
  const d = new Date(s)
  return Number.isNaN(d.getTime()) ? null : d
}
const DAY = 1000 * 60 * 60 * 24

/** Days remaining from the endorsement date to policy expiry. */
const remainingDays = computed<number | null>(() => {
  const end = parseDate(props.expiryDate)
  const from = parseDate(endorsementDate.value)
  if (!end || !from) return null
  return Math.max(0, Math.round((end.getTime() - from.getTime()) / DAY))
})
/** Total days in the coverage window. */
const totalDays = computed<number | null>(() => {
  const start = parseDate(props.effectiveDate)
  const end = parseDate(props.expiryDate)
  if (!start || !end) return null
  return Math.max(1, Math.round((end.getTime() - start.getTime()) / DAY))
})
/** ส่วนต่างเบี้ยรายปี = ใหม่ − เดิม (annual). */
const annualDelta = computed<number>(() =>
  Math.max(0, (Number(newAnnualPremium.value) || 0) - (Number(baseAnnualPremium.value) || 0)),
)
/** Suggested pro-rata additional premium (reference only). */
const suggestedProrata = computed<number | null>(() => {
  if (remainingDays.value === null || totalDays.value === null) return null
  if (annualDelta.value <= 0) return 0
  return Math.round(annualDelta.value * (remainingDays.value / totalDays.value) * 100) / 100
})

function applySuggestion(): void {
  if (suggestedProrata.value !== null) additionalPremium.value = suggestedProrata.value
}

// ── Validation ───────────────────────────────────────────────────────────
const canSubmit = computed<boolean>(() =>
  !props.saving &&
  reason.value.trim().length > 0 &&
  !!endorsementDate.value &&
  Number(newAnnualPremium.value) > 0 &&
  Number(newAnnualPremium.value) > Number(baseAnnualPremium.value) &&
  additionalPremium.value !== null &&
  Number(additionalPremium.value) >= 0,
)

function submit(): void {
  if (!canSubmit.value) return
  emit('submit', {
    reason: reason.value.trim(),
    effectiveDate: endorsementDate.value,
    newAnnualPremium: Number(newAnnualPremium.value),
    newCoverage: newCoverage.value !== null ? Number(newCoverage.value) : null,
    additionalPremium: Number(additionalPremium.value),
    additionalDutyStamp: additionalDutyStamp.value !== null ? Number(additionalDutyStamp.value) : null,
    additionalVat: additionalVat.value !== null ? Number(additionalVat.value) : null,
  })
}

const additionalTotal = computed<number>(() =>
  Math.round(((Number(additionalPremium.value) || 0)
    + (Number(additionalDutyStamp.value) || 0)
    + (Number(additionalVat.value) || 0)) * 100) / 100,
)
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
        <div>
          <h3 class="font-semibold text-slate-900">{{ isEditing ? t('endorsement.editTitle') : t('endorsement.modalTitle') }}</h3>
          <p class="text-xs text-slate-500 mt-0.5">{{ t('endorsement.modalSubtitle') }}</p>
        </div>
        <button type="button" @click="emit('close')" class="text-slate-400 hover:text-slate-600">
          <i class="pi pi-times" />
        </button>
      </div>

      <div class="p-5 space-y-5">
        <!-- Reason + date -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField :label="t('endorsement.effectiveDate')" required errorKey="effectiveDate" :errors="errors">
            <input type="date" v-model="endorsementDate"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
          </FormField>
          <FormField :label="t('endorsement.reason')" required errorKey="reason" :errors="errors">
            <input type="text" v-model="reason" :placeholder="t('endorsement.reasonPlaceholder')"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
          </FormField>
        </div>

        <!-- Premium change -->
        <div class="rounded-lg border border-slate-200 p-4 space-y-3">
          <div class="text-sm font-medium text-slate-700">{{ t('endorsement.premiumSection') }}</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <FormField :label="t('endorsement.currentPremium')">
              <div class="w-full border border-slate-100 bg-slate-50 rounded-lg px-3 py-1.5 text-sm text-slate-600">
                {{ fmt(baseAnnualPremium) }}
              </div>
            </FormField>
            <FormField :label="t('endorsement.newPremium')" required errorKey="newAnnualPremium" :errors="errors">
              <input type="number" min="0" step="0.01" v-model.number="newAnnualPremium"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField :label="t('endorsement.currentCoverage')">
              <div class="w-full border border-slate-100 bg-slate-50 rounded-lg px-3 py-1.5 text-sm text-slate-600">
                {{ fmt(baseCoverage) }}
              </div>
            </FormField>
            <FormField :label="t('endorsement.newCoverage')" :hint="t('endorsement.newCoverageHint')">
              <input type="number" min="0" step="1" v-model.number="newCoverage"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
        </div>

        <!-- Reference pro-rata helper -->
        <div v-if="annualDelta > 0" class="rounded-lg bg-brand-50 border border-brand-100 p-4 text-xs text-slate-600 space-y-1">
          <div class="font-medium text-slate-700 mb-1">{{ t('endorsement.referenceTitle') }}</div>
          <div class="flex justify-between"><span>{{ t('endorsement.annualDelta') }}</span><span>{{ fmt(annualDelta) }}</span></div>
          <div v-if="remainingDays !== null && totalDays !== null" class="flex justify-between">
            <span>{{ t('endorsement.remainingRatio') }}</span><span>{{ remainingDays }} / {{ totalDays }} {{ t('endorsement.days') }}</span>
          </div>
          <div v-if="suggestedProrata !== null" class="flex justify-between font-medium text-brand-700">
            <span>{{ t('endorsement.suggestedProrata') }}</span>
            <span class="flex items-center gap-2">
              {{ fmt(suggestedProrata) }}
              <button type="button" @click="applySuggestion"
                class="px-2 py-0.5 rounded bg-brand-600 text-white text-[10px] hover:bg-brand-700">
                {{ t('endorsement.useSuggestion') }}
              </button>
            </span>
          </div>
        </div>

        <!-- Additional premium to collect -->
        <div class="rounded-lg border border-slate-200 p-4 space-y-3">
          <div class="text-sm font-medium text-slate-700">{{ t('endorsement.additionalSection') }}</div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <FormField :label="t('endorsement.additionalPremium')" required errorKey="additionalPremium" :errors="errors">
              <input type="number" min="0" step="0.01" v-model.number="additionalPremium"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField :label="t('endorsement.additionalDuty')">
              <input type="number" min="0" step="0.01" v-model.number="additionalDutyStamp"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
            <FormField :label="t('endorsement.additionalVat')">
              <input type="number" min="0" step="0.01" v-model.number="additionalVat"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>
          <div class="flex justify-between text-sm font-medium text-slate-800 pt-1 border-t border-slate-100">
            <span>{{ t('endorsement.additionalTotal') }}</span>
            <span>{{ fmt(additionalTotal) }}</span>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-slate-100">
        <button type="button" @click="emit('close')"
          class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100">
          {{ t('endorsement.cancel') }}
        </button>
        <button type="button" @click="submit" :disabled="!canSubmit"
          class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed">
          {{ saving ? t('endorsement.saving') : (isEditing ? t('endorsement.saveEdit') : t('endorsement.confirm')) }}
        </button>
      </div>
    </div>
  </div>
</template>
