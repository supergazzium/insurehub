<script setup lang="ts" generic="T extends { id: string }">
// C-13 — Generic entity typeahead. Extracted from the wizard's inline
// customer / agent / renewal / product pickers (PolicyCreateWizard L306-368)
// and AgentPicker.vue so the same debounced-search + highlight + keyboard-nav
// pattern lives in one place.
//
// Contract:
//   - Parent provides a `fetch(query) → Promise<T[]>` closure. Keeps this
//     component decoupled from the API surface — anything with an `id`
//     field works.
//   - `renderLabel(row)` returns the display string per row (both in
//     the dropdown and in the input after pick).
//   - `renderPrimary(row)` (optional) returns a code/badge prefix
//     shown in monospace to the left of the label.
//   - Two-way binds the picked id via `v-model`; also emits `picked`
//     with the full row so callers can hydrate a "chip" or trigger
//     cascade fetches (carrier → products, customer → prior-assets).
//   - Optional "+ New" affordance: pass `newLabel` + listen to `newClick`
//     to open the caller's create modal (or `window.open('...?new=1')`
//     + BroadcastChannel pickup like the wizard does).

import { computed, onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps<{
  /** Selected id (parent-controlled). Empty string = no selection. */
  modelValue: string
  /** Debounced query executor. Return rows to render in the dropdown. */
  fetch: (query: string) => Promise<T[]>
  /** Display string for a row. Called for both dropdown + input value. */
  renderLabel: (row: T) => string
  /** Optional prefix shown monospace to the left of the label. */
  renderPrimary?: (row: T) => string
  /** Optional secondary line rendered below the primary. */
  renderSecondary?: (row: T) => string
  placeholder?: string
  /** Debounce ms. Default 250 to match the wizard's inline patterns. */
  debounceMs?: number
  /** Minimum chars before firing search. `0` = fetch on empty focus.
   *  Default 2 (matches wizard). */
  minChars?: number
  /** Rows returned per page. Default 10. */
  perPage?: number
  /** Optional "+ New" button label. Omit to hide. */
  newLabel?: string
  /** Optional icon class (PrimeIcons). Default `pi-search`. */
  iconClass?: string
  /** Optional pre-picked row for external hydration (e.g. resume flow). */
  initialLabel?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: string): void
  /** Fires with the full row when a pick happens (or null on clear). */
  (e: 'picked', row: T | null): void
  /** Fires when the "+ New" button is clicked. Parent opens their modal. */
  (e: 'newClick'): void
}>()

const q = ref(props.initialLabel ?? '')
const open = ref(false)
const results = ref<T[]>([])
const loading = ref(false)
const highlighted = ref(0)
let debounceTimer: number | undefined
let fetchToken = 0

const minChars = computed(() => props.minChars ?? 2)
const debounceMs = computed(() => props.debounceMs ?? 250)

async function search(query: string): Promise<void> {
  const trimmed = query.trim()
  if (trimmed.length < minChars.value && trimmed.length > 0) {
    // partial query: don't hit the server. Empty query IS fetched so
    // the operator sees the initial batch on focus.
    results.value = []
    return
  }
  loading.value = true
  const myToken = ++fetchToken
  try {
    const rows = await props.fetch(trimmed)
    if (myToken !== fetchToken) return
    results.value = rows
    highlighted.value = 0
  } catch {
    results.value = []
  } finally {
    loading.value = false
  }
}

watch(q, (v) => {
  window.clearTimeout(debounceTimer)
  debounceTimer = window.setTimeout(() => { void search(v) }, debounceMs.value)
})

function onFocus(): void {
  open.value = true
  if (results.value.length === 0) void search(q.value)
}
function onBlur(): void {
  // 150ms so a mousedown on a suggestion has time to fire pick().
  window.setTimeout(() => { open.value = false }, 150)
}

function pick(row: T | null): void {
  if (row === null) {
    q.value = ''
    emit('update:modelValue', '')
    emit('picked', null)
  } else {
    q.value = props.renderLabel(row)
    emit('update:modelValue', row.id)
    emit('picked', row)
  }
  open.value = false
}

function clear(): void { pick(null) }

function onKeydown(e: KeyboardEvent): void {
  if (!results.value.length) return
  if (e.key === 'ArrowDown') { e.preventDefault(); highlighted.value = Math.min(highlighted.value + 1, results.value.length - 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted.value = Math.max(highlighted.value - 1, 0) }
  else if (e.key === 'Enter') { e.preventDefault(); const r = results.value[highlighted.value] as T | undefined; if (r) pick(r) }
  else if (e.key === 'Escape') { open.value = false }
}

// External clear: parent sets modelValue = '' → wipe the input too.
watch(() => props.modelValue, (id) => {
  if (id === '') { q.value = '' }
})

// External hydrate: initialLabel arriving late (e.g. after ensureDetail)
// updates the input if the operator hasn't typed anything.
watch(() => props.initialLabel, (label) => {
  if (label && props.modelValue !== '' && q.value === '') q.value = label
})

const showClear = computed(() => props.modelValue !== '' || q.value !== '')
onBeforeUnmount(() => window.clearTimeout(debounceTimer))
</script>

<template>
  <div class="relative">
    <div class="relative">
      <input v-model="q"
        :placeholder="placeholder ?? 'พิมพ์เพื่อค้นหา…'"
        class="w-full border border-slate-200 rounded-lg pl-9 pr-8 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
        @focus="onFocus" @blur="onBlur" @keydown="onKeydown" />
      <i :class="['pi', iconClass ?? 'pi-search', 'absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm']" />
      <button v-if="showClear" type="button" @mousedown.prevent="clear"
        class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 p-1">
        <i class="pi pi-times text-xs" />
      </button>
    </div>
    <div v-if="open" class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg z-30 max-h-64 overflow-y-auto">
      <div v-if="loading" class="px-3 py-2 text-xs text-slate-500">
        <i class="pi pi-spin pi-spinner mr-1" /> กำลังค้นหา…
      </div>
      <template v-else>
        <div v-if="results.length === 0 && !newLabel" class="px-3 py-3 text-xs text-slate-500 text-center">
          ไม่พบข้อมูล
        </div>
        <!-- `r as T` casts through Vue's UnwrapRefSimple<T> which strips
             optional properties. The row shape is unchanged at runtime;
             only the compile-time signature needs the coercion. -->
        <button v-for="(r, i) in (results as T[])" :key="r.id" type="button"
          @mousedown.prevent="pick(r)" @mouseenter="highlighted = i"
          :class="[
            'w-full text-left px-3 py-1.5 border-b border-slate-100 last:border-b-0',
            highlighted === i ? 'bg-brand-50' : 'hover:bg-slate-50',
          ]">
          <div class="flex items-center gap-2">
            <span v-if="renderPrimary" class="font-mono text-xs text-slate-500 shrink-0">{{ renderPrimary(r) }}</span>
            <span class="text-sm text-slate-900 truncate flex-1">{{ renderLabel(r) }}</span>
          </div>
          <div v-if="renderSecondary" class="text-[10px] text-slate-500 mt-0.5 ml-1">{{ renderSecondary(r) }}</div>
        </button>
        <!-- "+ New" affordance rendered last so it stays out of the way of
             pick suggestions but is always available. Parent handles the
             actual create flow (modal, popup + BroadcastChannel, etc). -->
        <button v-if="newLabel" type="button"
          @mousedown.prevent="emit('newClick')"
          class="w-full text-left px-3 py-1.5 text-xs text-brand-600 hover:bg-brand-50 border-t border-slate-100">
          <i class="pi pi-plus text-[10px] mr-1" /> {{ newLabel }}
        </button>
      </template>
    </div>
  </div>
</template>
