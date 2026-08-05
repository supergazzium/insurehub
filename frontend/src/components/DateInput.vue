<script setup lang="ts">
// Single-date picker used across the app in place of <input type="date">.
//
// - Model in / out: ISO `YYYY-MM-DD` string (matches backend + everything
//   already stored). Empty string means "no date".
// - Display format: DD/MM/YYYY regardless of browser locale.
// - `min` / `max` props (ISO strings) constrain the calendar and the
//   underlying VueDatepicker validation.
//
// Design note: v-model is a STRING (ISO), not a Date. When you feed
// VueDatePicker a Date, it ignores the `format` prop and falls back to
// its locale-aware formatter (which renders MM/DD/YYYY on en-US locales
// AND appends the time). Using `model-type="yyyy-MM-dd"` + a string
// v-model keeps display + parsing in our chosen format.
//
// Format is *also* forced via a function-form :format so nothing in the
// picker's option chain (locale, textInput.format inheritance) can
// override it. If you see MM/DD/YYYY after editing this file, it's a
// browser cache — hard-refresh the tab.
import { computed } from 'vue'
import { VueDatePicker } from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'

const props = defineProps<{
  modelValue: string | null | undefined
  min?: string | null
  max?: string | null
  placeholder?: string
  disabled?: boolean
  clearable?: boolean
  /** Optional CSS class list forwarded to the underlying input wrapper. */
  inputClass?: string
  /**
   * Optional ISO date that tells the calendar which month to *open* on
   * when the picker has no value yet. Useful for the "expiry" field of a
   * date range — points the calendar at the effective date's month.
   */
  startDate?: string | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: string): void
}>()

const value = computed<string>({
  get: () => props.modelValue ?? '',
  set: (v) => emit('update:modelValue', v ?? ''),
})

// Function-form formatter — bypasses any locale/textInput format inheritance
// and guarantees DD/MM/YYYY in the input field regardless of picker version.
function formatDate(d: Date): string {
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const yyyy = d.getFullYear()
  return `${dd}/${mm}/${yyyy}`
}
</script>

<template>
  <VueDatePicker
    v-model="value"
    model-type="yyyy-MM-dd"
    :format="formatDate"
    :enable-time-picker="false"
    :min-date="min ?? undefined"
    :max-date="max ?? undefined"
    :start-date="startDate ?? undefined"
    :placeholder="placeholder ?? 'dd/mm/yyyy'"
    :disabled="disabled"
    :clearable="clearable !== false"
    auto-apply
    :teleport="true"
    :input-class-name="inputClass ?? ''"
    text-input
    :text-input-options="{ format: 'dd/MM/yyyy' }"
  />
</template>

<style>
/* Match the app's design tokens so the picker feels native alongside our
   other inputs. The library exposes CSS variables — override the palette
   to line up with slate + brand-500. */
.dp__theme_light {
  --dp-primary-color: #0e74e8;
  --dp-primary-text-color: #fff;
  --dp-border-color: #e2e8f0;
  --dp-border-color-hover: #94a3b8;
  --dp-border-radius: 8px;
  --dp-cell-padding: 6px;
  --dp-font-family: inherit;
  --dp-menu-border-color: #e2e8f0;
}
.dp__input {
  padding: 6px 32px 6px 12px;
  font-size: 14px;
  line-height: 20px;
  border-color: #e2e8f0;
  border-radius: 8px;
  background: #fff;
}
.dp__input:focus {
  outline: none;
  border-color: #0e74e8;
}
</style>
