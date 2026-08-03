<script setup lang="ts">
// Searchable product picker used by the "Copy from…" banner in
// ProductCreateModal. Debounced server-side search across code / Thai
// name / English name. Keyboard nav: ↑/↓ to move, Enter to pick, Esc to
// close. Emits `pick` with the chosen product's id.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { fetchProductList, type ProductListRow } from '../../api/products'

const props = defineProps<{
  /** When true, the dropdown is rendered. Parent owns visibility. */
  open: boolean
}>()
const emit = defineEmits<{
  (e: 'pick', row: ProductListRow): void
  (e: 'close'): void
}>()

const q = ref('')
const results = ref<ProductListRow[]>([])
const loading = ref(false)
const highlighted = ref(0)
let debounceTimer: number | undefined

async function search(query: string): Promise<void> {
  if (query.trim() === '') { results.value = []; return }
  loading.value = true
  try {
    const res = await fetchProductList({ q: query.trim(), perPage: 20, activeOnly: true })
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
watch(() => props.open, (v) => {
  if (v) { q.value = ''; results.value = []; highlighted.value = 0 }
})
onBeforeUnmount(() => { window.clearTimeout(debounceTimer) })

function onKeydown(e: KeyboardEvent): void {
  if (!results.value.length) return
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    highlighted.value = Math.min(highlighted.value + 1, results.value.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    highlighted.value = Math.max(highlighted.value - 1, 0)
  } else if (e.key === 'Enter') {
    e.preventDefault()
    const row = results.value[highlighted.value]
    if (row) emit('pick', row)
  } else if (e.key === 'Escape') {
    emit('close')
  }
}

const empty = computed(() => !loading.value && q.value.trim() !== '' && results.value.length === 0)
</script>

<template>
  <div v-if="open" class="border border-slate-200 rounded-lg bg-white shadow-sm">
    <div class="relative border-b border-slate-100">
      <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs" />
      <input v-model.trim="q"
        autofocus
        placeholder="ค้นหาด้วย code / ชื่อ / carrier ..."
        class="w-full pl-9 pr-8 py-2 text-sm border-0 rounded-t-lg focus:outline-none"
        @keydown="onKeydown" />
      <button type="button" @click="emit('close')"
        class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 p-1">
        <i class="pi pi-times text-xs" />
      </button>
    </div>
    <div class="max-h-64 overflow-y-auto">
      <div v-if="loading" class="px-3 py-2 text-xs text-slate-500">
        <i class="pi pi-spin pi-spinner mr-1" /> Searching…
      </div>
      <div v-else-if="empty" class="px-3 py-3 text-xs text-slate-500 text-center">
        ไม่พบผลิตภัณฑ์ตามคำค้นหา
      </div>
      <div v-else-if="q.trim() === ''" class="px-3 py-3 text-xs text-slate-400 text-center">
        พิมพ์เพื่อค้นหา — เช่น "iCare", "PDAIA", "กสิกร"
      </div>
      <button v-for="(r, i) in results" :key="r.id" type="button"
        @click="emit('pick', r)"
        @mouseenter="highlighted = i"
        :class="[
          'w-full text-left px-3 py-2 border-b border-slate-100 last:border-b-0',
          highlighted === i ? 'bg-brand-50' : 'hover:bg-slate-50',
        ]">
        <div class="flex items-center gap-2">
          <span class="font-mono text-xs text-slate-500 shrink-0">{{ r.code }}</span>
          <span class="text-sm text-slate-900 truncate flex-1">{{ r.name }}</span>
          <span v-if="r.carrierCode" class="text-xs text-slate-500 shrink-0">{{ r.carrierCode }}</span>
        </div>
        <div class="text-xs text-slate-500 mt-0.5">
          <span v-if="r.type" class="mr-2">{{ r.type }}</span>
          <span v-if="r.mainRider" class="mr-2">· {{ r.mainRider }}</span>
          <span v-if="r.category">· {{ r.category }}</span>
        </div>
      </button>
    </div>
  </div>
</template>
