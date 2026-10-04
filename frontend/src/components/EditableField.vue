<script setup lang="ts">
// Generic click-to-edit field. Renders like a plain label when idle; click →
// input/select/textarea appears. Enter saves, Esc cancels, blur saves.
// Handles the PATCH against the parent entity, updates the parent's value
// via v-model:value emit so consumers stay in sync.
//
// Types supported: text, number, date, select, textarea, currency.
// The `currency` type formats as THB when idle and stores a float on save.
//
// Endpoint format: /api/v1/{entity}/{id}. `field` is the JSON key sent to
// the API (backend request-validation classes accept camelCase).

import { computed, nextTick, ref, watch } from 'vue'
import { api, ApiError } from '../api/client'
import DateInput from './DateInput.vue'
import { fmtDate } from '../util/dateFormat'

export type EditableType = 'text' | 'number' | 'currency' | 'date' | 'select' | 'textarea'

export interface SelectOption {
  value: string
  label: string
}

const props = withDefaults(
  defineProps<{
    /** The API path segment for the entity resource, e.g. "policies", "customers". */
    entity: string
    /** The row id used in the PATCH URL. */
    id: string
    /** camelCase key sent in the PATCH body. */
    field: string
    /** The current value shown when idle. */
    value: string | number | null
    type?: EditableType
    /** For select: [{ value, label }]. */
    options?: SelectOption[]
    /** Placeholder shown when value is empty/null. Defaults to "—". */
    placeholder?: string
    align?: 'left' | 'right'
    /** Disallow editing (still displays as a plain label). */
    readonly?: boolean
    controlled?: boolean
    errorText?: string | null
  }>(),
  {
    type: 'text',
    options: () => [],
    placeholder: '—',
    align: 'left',
    readonly: false,
    controlled: false,
    errorText: null,
  },
)

const emit = defineEmits<{
  (e: 'update', v: string | number | null): void
}>()

const editing = ref(false)
const saving = ref(false)
const savedFlash = ref(false)
const error = ref<string | null>(null)
const draft = ref<string>('')
const inputEl = ref<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null>(null)

const displayValue = computed<string>(() => {
  if (props.value === null || props.value === undefined || props.value === '') return props.placeholder
  if (props.type === 'currency' && typeof props.value === 'number') {
    return new Intl.NumberFormat('th-TH', {
      style: 'currency',
      currency: 'THB',
      maximumFractionDigits: 2,
    }).format(props.value)
  }
  if (props.type === 'select') {
    const opt = props.options.find((o) => o.value === String(props.value))
    return opt ? opt.label : String(props.value)
  }
  if (props.type === 'date') {
    // Display DD/MM/YYYY regardless of what the API returns.
    return fmtDate(String(props.value))
  }
  return String(props.value)
})

const isControlled = computed(() => props.controlled && !props.readonly)

function toText(v: string | number | null | undefined): string {
  return v === null || v === undefined ? '' : String(v)
}

watch(
  () => [isControlled.value, props.value] as const,
  () => {
    if (!isControlled.value) return
    let current: string | number | null | undefined
    try {
      current = parseDraft()
    } catch {
      current = undefined
    }
    if (current !== (props.value ?? null)) draft.value = toText(props.value)
  },
  { immediate: true },
)

function onControlledInput(): void {
  try {
    const next = parseDraft()
    error.value = null
    emit('update', next)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Invalid'
  }
}

function onBlur(): void {
  if (!isControlled.value) void save()
}

function onCommit(): void {
  if (isControlled.value) onControlledInput()
  else void save()
}

async function start(): Promise<void> {
  if (saving.value || props.readonly || isControlled.value) return
  editing.value = true
  draft.value = props.value === null || props.value === undefined ? '' : String(props.value)
  error.value = null
  await nextTick()
  if (inputEl.value && 'select' in inputEl.value) {
    (inputEl.value as HTMLInputElement).select?.()
  } else {
    inputEl.value?.focus()
  }
}

function cancel(): void {
  editing.value = false
  draft.value = ''
  error.value = null
}

/** Build the PATCH body, expanding a dotted `field` into a nested object. */
function buildBody(field: string, value: string | number | null): Record<string, unknown> {
  const parts = field.split('.')
  const root: Record<string, unknown> = {}
  let cursor = root
  for (let i = 0; i < parts.length - 1; i++) {
    const child: Record<string, unknown> = {}
    cursor[parts[i]] = child
    cursor = child
  }
  cursor[parts[parts.length - 1]] = value
  return root
}

function parseDraft(): string | number | null {
  const raw = draft.value.trim()
  if (raw === '') return null
  if (props.type === 'number' || props.type === 'currency') {
    const n = Number(raw)
    if (!Number.isFinite(n)) {
      throw new Error('Not a number')
    }
    return n
  }
  return raw
}

async function save(): Promise<void> {
  if (!editing.value) return
  let next: string | number | null
  try {
    next = parseDraft()
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Invalid'
    return
  }
  const current = props.value ?? null
  const isSame = next === current || (next === '' && current === null)
  if (isSame) {
    editing.value = false
    return
  }

  saving.value = true
  error.value = null
  try {
    // A dotted `field` (e.g. "mailing.address") PATCHes a nested object —
    // Laravel validates "mailing.address" against a nested array, so the body
    // must be { mailing: { address: next } }, not a flat "mailing.address" key.
    const body = buildBody(props.field, next)
    await api.patch(`${props.entity}/${props.id}`, body)
    emit('update', next)
    editing.value = false
    savedFlash.value = true
    setTimeout(() => (savedFlash.value = false), 900)
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      error.value = e.body?.message ?? `HTTP ${e.status}`
    } else {
      error.value = e instanceof Error ? e.message : 'Save failed'
    }
  } finally {
    saving.value = false
  }
}

function onKey(e: KeyboardEvent): void {
  if (isControlled.value) {
    if (e.key === 'Enter' && props.type !== 'textarea') e.preventDefault()
    return
  }
  if (e.key === 'Enter' && props.type !== 'textarea') {
    e.preventDefault()
    void save()
  } else if (e.key === 'Escape') {
    e.preventDefault()
    cancel()
  } else if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && props.type === 'textarea') {
    e.preventDefault()
    void save()
  }
}

// If the parent replaces value while editing (e.g. reload), sync the draft.
watch(
  () => props.value,
  () => {
    if (!editing.value) return
    draft.value = props.value === null || props.value === undefined ? '' : String(props.value)
  },
)

const alignClass = computed(() => (props.align === 'right' ? 'text-right' : 'text-left'))
const isEmpty = computed(() => props.value === null || props.value === undefined || props.value === '')
const shownError = computed(() => props.errorText || error.value)
</script>

<template>
  <div class="group relative inline-block w-full">
    <template v-if="editing || isControlled">
      <textarea
        v-if="type === 'textarea'"
        ref="inputEl"
        v-model="draft"
        rows="3"
        :class="['w-full border rounded px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200', shownError ? 'border-rose-400' : isControlled ? 'border-slate-300' : 'border-brand-400']"
        :disabled="saving"
        @keydown="onKey"
        @input="isControlled && onControlledInput()"
        @blur="onBlur"
      />
      <select
        v-else-if="type === 'select'"
        ref="inputEl"
        v-model="draft"
        :class="['w-full border rounded px-2 py-0.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-200', shownError ? 'border-rose-400' : isControlled ? 'border-slate-300' : 'border-brand-400']"
        :disabled="saving"
        @keydown="onKey"
        @change="onCommit"
        @blur="onBlur"
      >
        <option value="">—</option>
        <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <DateInput
        v-else-if="type === 'date'"
        v-model="draft"
        :disabled="saving"
        @update:model-value="onCommit"
      />
      <input
        v-else
        ref="inputEl"
        v-model="draft"
        :type="type === 'number' || type === 'currency' ? 'text' : 'text'"
        :inputmode="type === 'number' || type === 'currency' ? 'decimal' : undefined"
        :placeholder="isControlled && placeholder !== '—' ? placeholder : undefined"
        :class="[
          'w-full border rounded px-2 py-0.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-200',
          shownError ? 'border-rose-400' : isControlled ? 'border-slate-300' : 'border-brand-400',
          alignClass,
        ]"
        :disabled="saving"
        @keydown="onKey"
        @input="isControlled && onControlledInput()"
        @blur="onBlur"
      />
    </template>
    <div
      v-else-if="readonly"
      :class="[
        'w-full py-0.5 px-1 text-sm min-h-[24px] select-text break-words cursor-text',
        alignClass,
        type === 'textarea' ? 'whitespace-pre-line' : '',
        isEmpty ? 'text-slate-400 italic' : 'text-slate-900',
      ]"
    >{{ displayValue }}</div>
    <template v-else>
      <button
        type="button"
        :class="[
          'w-full py-0.5 px-1 rounded text-sm min-h-[24px]',
          alignClass,
          readonly ? 'cursor-default text-slate-500' : 'hover:bg-slate-100 hover:ring-1 hover:ring-slate-200',
          savedFlash ? 'bg-emerald-50 ring-1 ring-emerald-200' : '',
          isEmpty ? 'text-slate-400 italic' : 'text-slate-900',
        ]"
        :disabled="saving || readonly"
        @click.stop="start"
      >
        {{ displayValue }}
      </button>
    </template>
    <span v-if="saving" class="absolute right-1 top-0.5 text-[10px] text-slate-400">…</span>
    <p v-if="isControlled && shownError" class="mt-0.5 text-[11px] text-rose-600 leading-snug">{{ shownError }}</p>
    <span
      v-else-if="shownError"
      class="absolute -bottom-4 right-0 text-[10px] text-rose-600 whitespace-nowrap max-w-[240px] truncate"
      :title="shownError"
    >{{ shownError }}</span>
  </div>
</template>
