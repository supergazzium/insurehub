<script setup lang="ts">
// Money input used across the app in place of <input type="number"> for
// currency fields (#41 — show thousands separators while typing).
//
// - Model in / out: a `number | null`. Drop-in for `v-model.number` — the
//   parent keeps getting a number, so no downstream math changes.
// - Display: the raw number formatted with thousands commas (e.g. 10,000.50)
//   while the field is NOT focused or being edited. On focus we keep the
//   user's in-progress text so the caret doesn't jump.
// - Accepts digits, one dot, and commas (commas are ignored on parse). Any
//   other character is stripped as the user types.
//
// Why text, not number: an <input type="number"> cannot render commas at all
// (the browser rejects them), so a currency field with separators must be a
// text input that parses to a number itself.
import { ref, watch } from 'vue'

const props = defineProps<{
  modelValue: number | null | undefined
  placeholder?: string
  disabled?: boolean
  /** Max fraction digits to show when formatting (default 2 for satang). */
  maxFractionDigits?: number
  inputClass?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: number | null): void
}>()

const focused = ref(false)
// What the <input> actually shows. Kept in sync with modelValue when not
// focused; left as the user's raw text while focused.
const display = ref('')

function format(n: number | null | undefined): string {
  if (n === null || n === undefined || Number.isNaN(n)) return ''
  return n.toLocaleString('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: props.maxFractionDigits ?? 2,
  })
}

// Strip to a clean numeric string: digits + at most one dot. Commas removed.
function sanitize(raw: string): string {
  let s = raw.replace(/,/g, '').replace(/[^\d.]/g, '')
  const firstDot = s.indexOf('.')
  if (firstDot !== -1) {
    // keep only the first dot
    s = s.slice(0, firstDot + 1) + s.slice(firstDot + 1).replace(/\./g, '')
  }
  return s
}

function parse(raw: string): number | null {
  const s = sanitize(raw)
  if (s === '' || s === '.') return null
  const n = Number(s)
  return Number.isNaN(n) ? null : n
}

// Keep the field text in step with the model when the user isn't editing.
watch(
  () => props.modelValue,
  (v) => { if (!focused.value) display.value = format(v) },
  { immediate: true },
)

function onInput(e: Event): void {
  const el = e.target as HTMLInputElement
  const clean = sanitize(el.value)
  // Reflect the cleaned text back so stray characters can't be typed.
  if (clean !== el.value) { el.value = clean; display.value = clean }
  else display.value = clean
  emit('update:modelValue', parse(clean))
}

function onFocus(e: Event): void {
  focused.value = true
  // Show the unformatted number so editing/caret behaves naturally.
  display.value = sanitize((e.target as HTMLInputElement).value)
}

function onBlur(): void {
  focused.value = false
  display.value = format(props.modelValue)
}
</script>

<template>
  <input
    type="text"
    inputmode="decimal"
    :value="display"
    :placeholder="placeholder"
    :disabled="disabled"
    :class="inputClass"
    @input="onInput"
    @focus="onFocus"
    @blur="onBlur"
  />
</template>
