<script setup lang="ts">
// Shape-shifting commission-rate editor for products.
//
// Three shapes, chosen by the operator (with a smart default per product type):
//   • skip     — no rates now, add later.
//   • flat     — one row per (party × installment_term). Fits Term, Motor,
//                Riders, PA, health-without-band.
//   • per-year — Y1..Y5 + Y6+, three parties. Fits Whole Life, Endowment,
//                Annuity, and anything else with a maturity curve.
//
// Emits `update:modelValue` with the CommissionRatesPayload the caller passes
// straight into the product create/update payload.

import { computed, ref, watch } from 'vue'
import type { CommissionRatesPayload, RateTriple } from '../../api/products'

type Shape = 'skip' | 'flat' | 'per-year'

const props = defineProps<{
  /** Product type from the create/edit form. Used to pick the default shape. */
  productType?: string | null
  /** Prefill for edit mode. Undefined on create. */
  initial?: CommissionRatesPayload | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: CommissionRatesPayload): void
}>()

// Suggested default shape per product type. Operators can always change it.
function defaultShape(type: string | null | undefined): Shape {
  const t = (type ?? '').toLowerCase()
  if (t.includes('life') && !t.includes('rider')) return 'per-year'
  if (t.includes('endowment') || t.includes('annuity')) return 'per-year'
  return 'flat'
}

const shape = ref<Shape>(props.initial?.shape ?? defaultShape(props.productType))

// Watch productType so the default flips when the operator picks a life
// product after already opening the form — but only if the operator hasn't
// touched the picker yet (indicated by initial-only defaults).
const shapeTouched = ref(props.initial != null)
watch(
  () => props.productType,
  (t) => {
    if (!shapeTouched.value) shape.value = defaultShape(t)
  },
)
function pickShape(s: Shape): void {
  shape.value = s
  shapeTouched.value = true
}

// ── Flat shape state ──────────────────────────────────────────────────────
const flat = ref<Record<string, RateTriple>>(
  props.initial?.shape === 'flat'
    ? { ...props.initial.installments }
    : { main: emptyTriple() },
)
const flatTerms = computed(() => Object.keys(flat.value))
function addFlatTerm(): void {
  const suggestions = ['3', '6', '12']
  const next = suggestions.find((s) => !(s in flat.value)) ?? String(Object.keys(flat.value).length + 1)
  flat.value = { ...flat.value, [next]: emptyTriple() }
}
function removeFlatTerm(term: string): void {
  if (term === 'main') return
  const next = { ...flat.value }
  delete next[term]
  flat.value = next
}

// ── Per-year shape state ──────────────────────────────────────────────────
// Fixed columns 1..5 + "6+" — matches the source PDFs and the Excel exports.
const YEAR_KEYS = ['1', '2', '3', '4', '5', '6'] as const
const years = ref<Record<string, RateTriple>>(
  props.initial?.shape === 'per-year'
    ? Object.fromEntries(YEAR_KEYS.map((y) => [y, props.initial!.shape === 'per-year' ? props.initial!.years[y] ?? emptyTriple() : emptyTriple()]))
    : Object.fromEntries(YEAR_KEYS.map((y) => [y, emptyTriple()])),
)

// ── Emit ──────────────────────────────────────────────────────────────────
const payload = computed<CommissionRatesPayload>(() => {
  if (shape.value === 'skip') return { shape: 'skip' }
  if (shape.value === 'flat') return { shape: 'flat', installments: flat.value }
  return { shape: 'per-year', years: years.value }
})
watch(payload, (v) => emit('update:modelValue', v), { immediate: true, deep: true })

function emptyTriple(): RateTriple {
  return { inh: null, ag: null, ov: null }
}
function copyFromPreviousYear(y: string): void {
  const idx = YEAR_KEYS.indexOf(y as (typeof YEAR_KEYS)[number])
  if (idx <= 0) return
  const prev = years.value[YEAR_KEYS[idx - 1]]
  years.value = { ...years.value, [y]: { ...prev } }
}

// Label helpers.
const PARTY_LABELS: Array<{ key: keyof RateTriple; label: string; helper: string }> = [
  { key: 'inh', label: 'บริษัท (InH)', helper: 'ส่วนของบริษัทประกัน' },
  { key: 'ag', label: 'เอเจนต์ (AG)', helper: 'ค่าคอมของเอเจนต์ผู้ขาย' },
  { key: 'ov', label: 'Upline (OV)', helper: 'ค่าคอมของ upline / ทีม' },
]

const SHAPE_OPTIONS: Array<{ value: Shape; label: string; hint: string }> = [
  { value: 'skip', label: 'ไม่กำหนดตอนนี้', hint: 'เพิ่มค่าคอมภายหลังในหน้ารายละเอียดสินค้า' },
  { value: 'flat', label: 'อัตราเดียวทุกปี', hint: 'เหมาะกับ Rider, Motor, PA, Health' },
  { value: 'per-year', label: 'ตามปีกรมธรรม์ (Y1–Y6+)', hint: 'เหมาะกับ Whole Life, Endowment, Annuity' },
]
</script>

<template>
  <div class="space-y-3">
    <!-- Shape picker -->
    <div class="flex flex-wrap gap-2">
      <button v-for="opt in SHAPE_OPTIONS" :key="opt.value" type="button"
        @click="pickShape(opt.value)"
        :class="[
          'flex-1 min-w-[9rem] flex flex-col items-start gap-0.5 px-3 py-2 rounded-lg border text-left text-xs transition-colors',
          shape === opt.value
            ? 'border-brand-500 bg-brand-50 text-brand-700'
            : 'border-slate-200 hover:bg-slate-50 text-slate-700',
        ]">
        <span class="font-semibold text-sm">{{ opt.label }}</span>
        <span class="text-[11px] text-slate-500">{{ opt.hint }}</span>
      </button>
    </div>

    <!-- Skip: nothing to show -->
    <p v-if="shape === 'skip'" class="text-xs text-slate-500">
      คุณจะเพิ่มอัตราค่าคอมได้ในภายหลังที่หน้ารายละเอียดสินค้า
    </p>

    <!-- Flat -->
    <div v-else-if="shape === 'flat'" class="card p-3 space-y-2">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs text-slate-500">
            <th class="text-left font-medium py-1 w-32">งวดชำระ</th>
            <th v-for="p in PARTY_LABELS" :key="p.key" class="text-right font-medium py-1">
              {{ p.label }}
            </th>
            <th class="w-8" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="term in flatTerms" :key="term" class="border-t border-slate-100">
            <td class="py-1.5 pr-2">
              <span class="text-xs font-mono px-1.5 py-0.5 rounded bg-slate-100 text-slate-700">{{ term }}</span>
              <span v-if="term === 'main'" class="ml-2 text-[11px] text-slate-400">(รายปี)</span>
            </td>
            <td v-for="p in PARTY_LABELS" :key="p.key" class="py-1.5">
              <div class="relative">
                <input v-model.number="flat[term][p.key]" type="number" min="0" max="100" step="0.01"
                  class="w-full border border-slate-200 rounded-md pl-2 pr-6 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs">%</span>
              </div>
            </td>
            <td class="text-center">
              <button v-if="term !== 'main'" type="button"
                @click="removeFlatTerm(term)"
                class="text-slate-400 hover:text-rose-500 text-xs" title="ลบแถวนี้">
                <i class="pi pi-times" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <button type="button" @click="addFlatTerm"
        class="text-xs text-brand-600 hover:text-brand-700 flex items-center gap-1">
        <i class="pi pi-plus text-[10px]" /> เพิ่มงวดชำระ
      </button>
    </div>

    <!-- Per-year -->
    <div v-else class="card p-3 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-xs text-slate-500">
            <th class="text-left font-medium py-1 pr-3 w-28">ฝ่าย</th>
            <th v-for="y in YEAR_KEYS" :key="y" class="text-right font-medium py-1 px-1">
              {{ y === '6' ? 'ปี 6+' : `ปี ${y}` }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in PARTY_LABELS" :key="p.key" class="border-t border-slate-100">
            <td class="py-1.5 pr-3">
              <div class="text-xs font-medium text-slate-700">{{ p.label }}</div>
              <div class="text-[10px] text-slate-400">{{ p.helper }}</div>
            </td>
            <td v-for="y in YEAR_KEYS" :key="y" class="py-1.5 px-1">
              <div class="relative">
                <input v-model.number="years[y][p.key]" type="number" min="0" max="100" step="0.01"
                  class="w-full border border-slate-200 rounded-md pl-1.5 pr-5 py-1 text-xs text-right focus:outline-none focus:border-brand-400" />
                <span class="absolute right-1.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]">%</span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <div class="mt-2 flex items-center justify-end gap-2 text-[11px] text-slate-500">
        <span>เติมค่าจากปีก่อนหน้า:</span>
        <button v-for="y in YEAR_KEYS.slice(1)" :key="y" type="button"
          @click="copyFromPreviousYear(y)"
          class="px-1.5 py-0.5 rounded border border-slate-200 hover:bg-slate-50">
          → ปี {{ y === '6' ? '6+' : y }}
        </button>
      </div>
    </div>
  </div>
</template>
