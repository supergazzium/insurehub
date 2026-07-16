<script setup lang="ts">
// Click-to-edit cell for a single numeric rebate field.
// Idle → click → shows an <input>. Enter or blur saves via updateRebate();
// Esc cancels. Optimistic UI: value updates instantly, error rolls back.

import { computed, nextTick, ref, watch } from 'vue'
import { updateRebate, type RebateUpdatePayload } from '../../api/reports'
import { ApiError } from '../../api/client'

const props = defineProps<{
  rebateId: string
  /** camelCase field name, e.g. actualAmount, calculatedOv, actualAgentAmount */
  field: keyof RebateUpdatePayload
  value: number | null
  align?: 'left' | 'right'
}>()

const emit = defineEmits<{ (e: 'update', v: number | null): void }>()

const editing = ref(false)
const draft = ref<string>('')
const saving = ref(false)
const savedFlash = ref(false)
const error = ref<string | null>(null)
const inputEl = ref<HTMLInputElement | null>(null)

const displayValue = computed(() => {
  if (props.value === null || props.value === undefined) return '—'
  return new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    maximumFractionDigits: 2,
  }).format(props.value)
})

async function start(): Promise<void> {
  if (saving.value) return
  editing.value = true
  draft.value = props.value !== null && props.value !== undefined ? String(props.value) : ''
  error.value = null
  await nextTick()
  inputEl.value?.select()
}

function cancel(): void {
  editing.value = false
  draft.value = ''
  error.value = null
}

async function save(): Promise<void> {
  const raw = draft.value.trim()
  const next: number | null = raw === '' ? null : Number(raw)
  if (next !== null && !Number.isFinite(next)) {
    error.value = 'Not a number'
    return
  }
  // No-op if unchanged.
  if (next === props.value) {
    editing.value = false
    return
  }

  saving.value = true
  error.value = null
  try {
    const payload: RebateUpdatePayload = { [props.field]: next }
    await updateRebate(props.rebateId, payload)
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
  if (e.key === 'Enter') {
    e.preventDefault()
    void save()
  } else if (e.key === 'Escape') {
    e.preventDefault()
    cancel()
  }
}

// If value prop changes while editing (external reload), refresh draft.
watch(
  () => props.value,
  () => {
    if (!editing.value) return
    draft.value = props.value !== null && props.value !== undefined ? String(props.value) : ''
  },
)
</script>

<template>
  <div
    :class="[
      'group relative inline-flex items-center gap-1 w-full min-h-[24px]',
      align === 'right' ? 'justify-end text-right' : 'justify-start text-left',
    ]"
  >
    <template v-if="editing">
      <input
        ref="inputEl"
        v-model="draft"
        type="text"
        inputmode="decimal"
        class="w-28 border border-brand-400 rounded px-2 py-0.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-brand-200"
        :disabled="saving"
        @keydown="onKey"
        @blur="save"
      />
    </template>
    <template v-else>
      <button
        type="button"
        class="w-full py-0.5 px-1 rounded hover:bg-slate-100 hover:ring-1 hover:ring-slate-200 group-hover:cursor-text"
        :class="[
          align === 'right' ? 'text-right' : 'text-left',
          savedFlash ? 'bg-emerald-50 ring-1 ring-emerald-200' : '',
        ]"
        :disabled="saving"
        @click.stop="start"
      >
        {{ displayValue }}
      </button>
    </template>
    <span v-if="saving" class="text-[10px] text-slate-400 ml-1">…</span>
    <span
      v-if="error"
      class="absolute -bottom-4 right-0 text-[10px] text-rose-600 whitespace-nowrap"
      :title="error"
    >{{ error }}</span>
  </div>
</template>
