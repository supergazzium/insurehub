<script setup lang="ts">
// Searchable product dropdown — used for rider selection inside the Riders
// array. Fetches products from GET /products filtered by carrier +
// insure_type + mainRider, with debounced text search. Emits the selected
// product's display name (v-model) plus a `select` event carrying the full
// row so the caller can capture id/code/premium context.
//
// Kept generic (props drive the filters) so it can back any "pick a product"
// field, not just riders.
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { fetchProductList, type ProductListRow, type ProductListFilters } from '../api/products'

const props = defineProps<{
  modelValue: string | undefined
  carrierId?: string | null
  insureType?: 'life' | 'non-life' | 'tax' | null
  mainRider?: string          // e.g. 'Rider'
  placeholder?: string
  disabled?: boolean
  disabledHint?: string       // shown when disabled (e.g. "เลือกสินค้าหลักก่อน")
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: string): void
  (e: 'select', row: ProductListRow): void
}>()

const open = ref(false)
const query = ref('')
const options = ref<ProductListRow[]>([])
const loading = ref(false)
let timer: number | undefined
const root = ref<HTMLElement | null>(null)

function filters(q: string): ProductListFilters {
  const f: ProductListFilters = { activeOnly: true, perPage: 50 }
  if (q) f.q = q
  if (props.carrierId) f.carrierId = props.carrierId
  if (props.insureType) f.insureType = props.insureType
  if (props.mainRider) f.mainRider = props.mainRider
  return f
}

async function load(q: string): Promise<void> {
  if (props.disabled) { options.value = []; return }
  loading.value = true
  try {
    const res = await fetchProductList(filters(q))
    options.value = res.data
  } catch {
    options.value = []
  } finally {
    loading.value = false
  }
}

function onInput(v: string): void {
  query.value = v
  open.value = true
  if (timer) window.clearTimeout(timer)
  timer = window.setTimeout(() => void load(v), 250)
}

function choose(row: ProductListRow): void {
  emit('update:modelValue', row.name)
  emit('select', row)
  query.value = ''
  open.value = false
}

function focusOpen(): void {
  if (props.disabled) return
  open.value = true
  if (options.value.length === 0) void load('')
}

// Re-prime when the carrier/insureType context changes (e.g. main product
// switched to a different carrier).
watch(() => [props.carrierId, props.insureType, props.mainRider], () => {
  options.value = []
  if (open.value) void load(query.value)
})

function onDocClick(e: MouseEvent): void {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div ref="root" class="relative" data-product-search>
    <input
      type="text"
      :disabled="disabled"
      :placeholder="disabled ? (disabledHint ?? placeholder) : placeholder"
      :value="open ? query : (modelValue ?? '')"
      @focus="focusOpen"
      @input="onInput(($event.target as HTMLInputElement).value)"
      class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400 disabled:bg-slate-50 disabled:text-slate-400"
    />
    <ul
      v-if="open && !disabled"
      class="absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-md border border-slate-200 bg-white shadow-lg text-sm"
    >
      <li v-if="loading" class="px-3 py-2 text-slate-400">กำลังค้นหา…</li>
      <li v-else-if="options.length === 0" class="px-3 py-2 text-slate-400">ไม่พบสัญญาเพิ่มเติม</li>
      <li
        v-for="row in options" :key="row.id"
        @click="choose(row)"
        class="px-3 py-2 hover:bg-brand-50 cursor-pointer"
      >
        <span class="text-slate-400 text-xs mr-1">{{ row.code }}</span>{{ row.name }}
      </li>
    </ul>
  </div>
</template>
