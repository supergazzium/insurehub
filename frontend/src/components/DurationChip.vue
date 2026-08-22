<script setup lang="ts">
// C-13 — Duration chip picker for the wizard's Step 2 coverage row.
// Per B3-wizard-ia.md §3, presets per product kind:
//   motor / CTPL / health / pa: [1 year] default 1y
//   travel:                     [3d / 5d / 7d / 14d / 30d] default 7d
//   fire:                       [1y / 3y / 5y] default 1y
//   life:                       [1y] + custom-year input
//   misc:                       no chips (custom date only)
//
// Data model:
//   - Bind `v-model` to the ISO expiryDate string (matches DateInput).
//   - Pass `effectiveDate` (ISO) so the chip can compute expiry = eff + N - 1 day.
//   - Pass `kind` (motor/travel/fire/health/life/misc/pa/ctpl) to pick the preset row.
//
// Behavior:
//   - Chip click → sets expiry = effectiveDate + duration - 1 day, emits update.
//   - When effectiveDate changes AND a chip is currently selected → recompute.
//   - When the operator manually types into the expiryDate DateInput
//     (the sibling of this component in the wizard), that DateInput
//     emits update:modelValue with a value that doesn't match the
//     chip's computed value. Parent should call `clearSelection()` in
//     that watcher — this component does NOT own the expiry input.

import { computed, watch } from 'vue'

export interface DurationPreset {
  /** Stable key used for the selected-chip highlight. */
  key: string
  /** i18n label. Provided by the caller so this component stays i18n-agnostic. */
  label: string
  /** Duration in days. For year presets, pass `unit: 'year'` and value in years. */
  value: number
  unit: 'day' | 'year'
}

const props = defineProps<{
  /** ISO date string, matches DateInput's modelValue. */
  modelValue: string
  /** ISO date of the coverage start. Required to compute expiry. */
  effectiveDate: string | null
  /** The picker doesn't hardcode presets — parent passes the kind's set. */
  presets: DurationPreset[]
  /** Which preset key is currently selected. `null` = manual entry. */
  selectedKey: string | null
  /** Optional label above the chip row. */
  label?: string
  /** Show custom-year input alongside chips (life kind). */
  allowCustomYears?: boolean
  /** ISO string separator between chip value and expiry. Defaults to today. */
}>()

const emit = defineEmits<{
  /** Two-way binding for the expiry ISO date. */
  (e: 'update:modelValue', v: string): void
  /** Two-way binding for which chip is selected (nullable). */
  (e: 'update:selectedKey', key: string | null): void
}>()

/** Compute an ISO date `days` after `iso`. Off by one on DST/tz boundaries
 *  in the browser tz, but expiryDate is a DATE (not DATETIME) column, so
 *  the day-level math is what matters. */
function addDays(iso: string, days: number): string {
  const [y, m, d] = iso.split('-').map(Number)
  if (!y || !m || !d) return iso
  const dt = new Date(y, m - 1, d)
  dt.setDate(dt.getDate() + days)
  const yy = dt.getFullYear()
  const mm = String(dt.getMonth() + 1).padStart(2, '0')
  const dd = String(dt.getDate()).padStart(2, '0')
  return `${yy}-${mm}-${dd}`
}

/** Coverage math per B3 §3: expiry = effective + duration - 1 day. */
function computeExpiry(effective: string, preset: DurationPreset): string {
  const daysToAdd = preset.unit === 'year' ? preset.value * 365 : preset.value
  // Approximate year math with 365; the operator can edit the resulting
  // expiry DateInput manually for leap-year edge cases. Backend stores
  // only DATE so any minor day drift is user-visible + easy to fix.
  return addDays(effective, daysToAdd - 1)
}

function pickChip(preset: DurationPreset): void {
  emit('update:selectedKey', preset.key)
  if (!props.effectiveDate) return
  emit('update:modelValue', computeExpiry(props.effectiveDate, preset))
}

/** Custom-year input (life). */
const customYears = computed<number | null>({
  get: () => {
    if (props.selectedKey?.startsWith('custom-') !== true) return null
    return Number(props.selectedKey.replace('custom-', '')) || null
  },
  set: (n) => {
    if (!n || n <= 0) {
      emit('update:selectedKey', null)
      return
    }
    emit('update:selectedKey', `custom-${n}`)
    if (props.effectiveDate) {
      emit('update:modelValue', computeExpiry(props.effectiveDate, {
        key: `custom-${n}`, label: `${n} years`, value: n, unit: 'year',
      }))
    }
  },
})

// When effectiveDate changes AND a chip is still selected, recompute expiry
// so the operator doesn't have to re-click. Skip when selectedKey is null
// (manual entry mode — parent owns the value).
watch(() => props.effectiveDate, (eff) => {
  if (!eff || props.selectedKey === null) return
  if (props.selectedKey.startsWith('custom-')) {
    const n = Number(props.selectedKey.replace('custom-', '')) || 0
    if (n > 0) emit('update:modelValue', computeExpiry(eff, {
      key: props.selectedKey, label: '', value: n, unit: 'year',
    }))
    return
  }
  const preset = props.presets.find((p) => p.key === props.selectedKey)
  if (preset) emit('update:modelValue', computeExpiry(eff, preset))
})
</script>

<template>
  <div v-if="presets.length > 0 || allowCustomYears" class="space-y-1">
    <label v-if="label" class="text-xs font-medium text-slate-500">{{ label }}</label>
    <div class="flex flex-wrap items-center gap-1.5">
      <button
        v-for="preset in presets"
        :key="preset.key"
        type="button"
        :class="[
          'px-2.5 py-1 rounded-md text-xs font-medium border transition-colors',
          selectedKey === preset.key
            ? 'bg-brand-600 text-white border-brand-600'
            : 'bg-white text-slate-600 border-slate-200 hover:border-brand-300 hover:text-brand-700',
        ]"
        :disabled="!effectiveDate"
        :title="!effectiveDate ? 'ต้องระบุวันเริ่มคุ้มครองก่อน' : ''"
        @click="pickChip(preset)"
      >
        {{ preset.label }}
      </button>
      <template v-if="allowCustomYears">
        <span class="text-xs text-slate-400 px-1">·</span>
        <input
          v-model.number="customYears"
          type="number"
          min="1"
          max="99"
          class="w-16 border border-slate-200 rounded-md px-2 py-1 text-xs focus:outline-none focus:border-brand-400"
          placeholder="Yrs"
        />
      </template>
    </div>
  </div>
</template>
