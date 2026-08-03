<script setup lang="ts">
// Typeahead picker for agents. Shows `AG12345 — สมชาย ใจดี` in the
// dropdown; passes back the picked agent's id + label to the parent so
// the parent can render a compact "chip" once a selection is made.
//
// Debounced 200ms server-side search. Keyboard nav: ↑↓ Enter Esc.
// Falls back to full list (up to 20 rows) when the query is empty.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { fetchAgentList, type AgentListRow } from '../api/agents'

const props = defineProps<{
  /** Selected agent id (parent-controlled). Empty string = no selection. */
  modelValue: string
  placeholder?: string
}>()
const emit = defineEmits<{
  (e: 'update:modelValue', v: string): void
  /** Fires with the full row when a pick happens — useful for chips + labels. */
  (e: 'picked', row: AgentListRow | null): void
}>()

const q = ref('')
const open = ref(false)
const results = ref<AgentListRow[]>([])
const loading = ref(false)
const highlighted = ref(0)
let debounceTimer: number | undefined
let fetchToken = 0

// Track the label of the currently-selected agent so the input can
// show "AG12345 — สมชาย ใจดี" instead of the raw id.
const selectedLabel = ref<string>('')

async function search(query: string): Promise<void> {
  loading.value = true
  const myToken = ++fetchToken
  try {
    const res = await fetchAgentList({
      q: query.trim() || undefined,
      activeOnly: true,
      perPage: 20,
    })
    if (myToken !== fetchToken) return
    results.value = res.data
    highlighted.value = 0
  } catch {
    results.value = []
  } finally {
    loading.value = false
  }
}

watch(q, (v) => {
  window.clearTimeout(debounceTimer)
  debounceTimer = window.setTimeout(() => { void search(v) }, 200)
})

function onFocus(): void {
  open.value = true
  if (results.value.length === 0) void search('')
}
function onBlur(): void {
  // 150ms so a mousedown on a suggestion has time to fire pick().
  window.setTimeout(() => { open.value = false }, 150)
}

function pick(row: AgentListRow | null): void {
  if (row === null) {
    selectedLabel.value = ''
    emit('update:modelValue', '')
    emit('picked', null)
  } else {
    selectedLabel.value = `${row.agentCode} — ${row.firstName} ${row.lastName}`.trim()
    q.value = selectedLabel.value
    emit('update:modelValue', row.id)
    emit('picked', row)
  }
  open.value = false
}

function clear(): void { pick(null); q.value = '' }

function onKeydown(e: KeyboardEvent): void {
  if (!results.value.length) return
  if (e.key === 'ArrowDown') { e.preventDefault(); highlighted.value = Math.min(highlighted.value + 1, results.value.length - 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted.value = Math.max(highlighted.value - 1, 0) }
  else if (e.key === 'Enter') { e.preventDefault(); const r = results.value[highlighted.value]; if (r) pick(r) }
  else if (e.key === 'Escape') { open.value = false }
}

// When parent clears the model externally, clear our local state.
watch(() => props.modelValue, (id) => {
  if (id === '') { selectedLabel.value = ''; q.value = '' }
})

const showClear = computed(() => props.modelValue !== '' || q.value !== '')
onBeforeUnmount(() => window.clearTimeout(debounceTimer))
</script>

<template>
  <div class="relative">
    <div class="relative">
      <input v-model="q"
        :placeholder="placeholder ?? 'ค้นหารหัส/ชื่อตัวแทน…'"
        class="w-full border border-slate-200 rounded-lg pl-9 pr-8 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
        @focus="onFocus" @blur="onBlur" @keydown="onKeydown" />
      <i class="pi pi-user absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
      <button v-if="showClear" type="button" @mousedown.prevent="clear"
        class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 p-1">
        <i class="pi pi-times text-xs" />
      </button>
    </div>
    <div v-if="open" class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg z-30 max-h-64 overflow-y-auto">
      <div v-if="loading" class="px-3 py-2 text-xs text-slate-500">
        <i class="pi pi-spin pi-spinner mr-1" /> Searching…
      </div>
      <div v-else-if="results.length === 0" class="px-3 py-3 text-xs text-slate-500 text-center">ไม่พบตัวแทน</div>
      <button v-for="(r, i) in results" :key="r.id" type="button"
        @mousedown.prevent="pick(r)" @mouseenter="highlighted = i"
        :class="[
          'w-full text-left px-3 py-1.5 border-b border-slate-100 last:border-b-0',
          highlighted === i ? 'bg-brand-50' : 'hover:bg-slate-50',
        ]">
        <div class="flex items-center gap-2">
          <span class="font-mono text-xs text-slate-500 shrink-0">{{ r.agentCode }}</span>
          <span class="text-sm text-slate-900 truncate flex-1">{{ r.firstName }} {{ r.lastName }}</span>
        </div>
      </button>
    </div>
  </div>
</template>
