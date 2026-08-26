<script setup lang="ts">
// Generic type-to-search dropdown over an IN-MEMORY option list. Unlike
// ProductSearchSelect (which fetches from an endpoint), this filters a
// client-side array — the right fit for lookup-table dropdowns whose rows
// are already loaded (nationalities, name-prefixes, province/amphoe/tambon).
//
// Options are { value, label, sublabel? }. v-model binds to the option's
// `value`. Typing filters label + sublabel (case-insensitive substring).
// Keyboard: ↑/↓ move the highlight, Enter selects, Esc closes.
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'

export interface SearchOption {
  value: string
  label: string
  sublabel?: string
}

const props = withDefaults(defineProps<{
  modelValue: string
  options: SearchOption[]
  placeholder?: string
  disabled?: boolean
  disabledHint?: string
  /** Extra classes applied to the input (border colour states etc.). */
  inputClass?: string
  emptyText?: string
}>(), {
  placeholder: '— เลือก —',
  disabled: false,
  disabledHint: '',
  inputClass: '',
  emptyText: 'ไม่พบรายการ',
})

const emit = defineEmits<{
  (e: 'update:modelValue', v: string): void
  (e: 'blur'): void
}>()

const open = ref(false)
const query = ref('')
const highlight = ref(0)
const root = ref<HTMLElement | null>(null)

/** Label shown in the input when the dropdown is closed. */
const selectedLabel = computed(() => {
  const hit = props.options.find((o) => o.value === props.modelValue)
  return hit ? hit.label : ''
})

const filtered = computed<SearchOption[]>(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter((o) =>
    o.label.toLowerCase().includes(q) || (o.sublabel ?? '').toLowerCase().includes(q),
  )
})

function focusOpen(): void {
  if (props.disabled) return
  open.value = true
  query.value = ''
  highlight.value = 0
}

function choose(opt: SearchOption): void {
  emit('update:modelValue', opt.value)
  open.value = false
  query.value = ''
  emit('blur')
}

function clear(): void {
  emit('update:modelValue', '')
  query.value = ''
  highlight.value = 0
}

function onKeydown(e: KeyboardEvent): void {
  if (props.disabled) return
  if (!open.value && (e.key === 'ArrowDown' || e.key === 'Enter')) { focusOpen(); return }
  if (e.key === 'ArrowDown') { e.preventDefault(); highlight.value = Math.min(highlight.value + 1, filtered.value.length - 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); highlight.value = Math.max(highlight.value - 1, 0) }
  else if (e.key === 'Enter') { e.preventDefault(); const opt = filtered.value[highlight.value]; if (opt) choose(opt) }
  else if (e.key === 'Escape') { open.value = false }
}

// Reset the highlight to the top whenever the visible list changes.
watch(filtered, () => { highlight.value = 0 })

function onDocClick(e: MouseEvent): void {
  if (root.value && !root.value.contains(e.target as Node)) {
    if (open.value) { open.value = false; emit('blur') }
  }
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

// Scroll the highlighted row into view as the user arrows through.
const listEl = ref<HTMLUListElement | null>(null)
watch(highlight, async () => {
  await nextTick()
  const li = listEl.value?.children[highlight.value] as HTMLElement | undefined
  li?.scrollIntoView({ block: 'nearest' })
})
</script>

<template>
  <div ref="root" class="relative">
    <input
      type="text"
      :disabled="disabled"
      :placeholder="disabled ? (disabledHint || placeholder) : placeholder"
      :value="open ? query : selectedLabel"
      :class="inputClass || 'w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400 disabled:bg-slate-50 disabled:text-slate-400'"
      @focus="focusOpen"
      @input="query = ($event.target as HTMLInputElement).value; open = true"
      @keydown="onKeydown"
      autocomplete="off"
    />
    <!-- Clear (×) button when a value is set and the control is enabled. -->
    <button
      v-if="modelValue && !disabled && !open" type="button" tabindex="-1"
      @mousedown.prevent="clear"
      class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-300 hover:text-slate-500 text-xs"
    ><i class="pi pi-times" /></button>
    <ul
      v-if="open && !disabled"
      ref="listEl"
      class="absolute z-30 mt-1 w-full max-h-56 overflow-auto rounded-md border border-slate-200 bg-white shadow-lg text-sm"
    >
      <li v-if="filtered.length === 0" class="px-3 py-2 text-slate-400">{{ emptyText }}</li>
      <li
        v-for="(opt, i) in filtered" :key="opt.value"
        @mousedown.prevent="choose(opt)"
        :class="['px-3 py-2 cursor-pointer', i === highlight ? 'bg-brand-50' : 'hover:bg-slate-50']"
      >
        {{ opt.label }}<span v-if="opt.sublabel" class="text-slate-400 text-xs"> · {{ opt.sublabel }}</span>
      </li>
    </ul>
  </div>
</template>
