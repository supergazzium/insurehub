<script setup lang="ts">
// Shape-shifting commission-rate editor for products.
//
// Six shapes, chosen by the operator (with a smart default per product type):
//   • skip        — no rates now, add later.
//   • flat        — arbitrary map of installment_term → three parties. Fits
//                    Rider and anything with a single fixed rate per party.
//   • installment — fixed grid main/3/6/12 → three parties. Same physical
//                    shape as flat; separate picker so the operator sees a
//                    familiar template for installment-driven products.
//   • per-year    — Y1..Y5 + Y6+, three parties. Fits Whole Life / Endowment /
//                    Annuity when age doesn't affect the rate (rare).
//   • band        — repeatable rows (min, max, installment_term, parties).
//                    Fits Health/PA/CI where rate varies by sum-assured tier.
//   • age-year    — repeatable entry-age brackets, each with a full Y1..Y6+ ×
//                    three-party grid. Default for Life → ประเภทสามัญ
//                    (Whole Life / Endowment / Annuity / Term).
//
// Emits `update:modelValue` with the CommissionRatesPayload the caller passes
// straight into the product create/update payload.

import { computed, ref, watch } from 'vue'
import type { AgeBracket, BandRow, CommissionRatesPayload, MatrixDimension, MatrixYear, RateTriple } from '../../api/products'

type Shape = 'skip' | 'flat' | 'installment' | 'per-year' | 'band' | 'age-year' | 'life-matrix'
const FIXED_INSTALLMENT_TERMS = ['main', '3', '6', '12'] as const
// age-year and life-matrix both require an insured-age concept, which non-
// life products don't have. Filter them out unless the carrier is Life.
const LIFE_ONLY_SHAPES: Shape[] = ['age-year', 'life-matrix']

const props = defineProps<{
  /** Product type from the create/edit form. Used to pick the default shape. */
  productType?: string | null
  /** Product category from the create/edit form. Combined with productType to
   *  pick the default shape — Life + ประเภทสามัญ triggers the age-year shape. */
  productCategory?: string | null
  /** Insurance type ('life' | 'non-life' | 'tax'). Non-life products have
   *  no notion of insured age, so the age-year shape is hidden and the
   *  picker default is defended to non-age shapes. */
  insureType?: string | null
  /** Prefill for edit mode. Undefined on create. */
  initial?: CommissionRatesPayload | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: CommissionRatesPayload): void
}>()

// True for products where the insured's age is a meaningful axis — Life
// carriers only. Non-life and tax products have no entry-age concept
// (Motor rates depend on vehicle, Health on sum-assured band, etc.), so
// age-year is hidden and defaults are constrained.
function isLifeInsureType(insure: string | null | undefined): boolean {
  return (insure ?? '').toLowerCase() === 'life'
}

// Suggested default shape per product type + category + insure type.
// Operators can always change it — this only picks the initial radio.
function defaultShape(
  type: string | null | undefined,
  category: string | null | undefined,
  insure: string | null | undefined,
): Shape {
  const t = (type ?? '').toLowerCase()
  const c = (category ?? '').toLowerCase()
  // Life ประเภทสามัญ (Whole Life / Endowment / Annuity / Term) rates in
  // the source PDFs vary across three axes simultaneously: age × sum-
  // assured × policy year. life-matrix is the shape that expresses all
  // three. Route the default there so operators land on the right editor.
  if (isLifeInsureType(insure) && t === 'life' && (category ?? '').includes('สามัญ')) return 'life-matrix'
  if (t.includes('life') && !t.includes('rider')) return 'per-year'
  if (t.includes('endowment') || t.includes('annuity')) return 'per-year'
  if (t.includes('health') || c.includes('health') || t === 'pa' || c.includes('ci')) return 'band'
  if (t.includes('motor')) return 'installment'
  return 'flat'
}

const shape = ref<Shape>(props.initial?.shape ?? defaultShape(props.productType, props.productCategory, props.insureType))

// Watch productType + category + insureType so the default flips when the
// operator picks a different combination after already opening the form.
// Guarded by shapeTouched so we don't overwrite a manual pick.
const shapeTouched = ref(props.initial != null)
watch(
  () => [props.productType, props.productCategory, props.insureType] as const,
  ([t, c, i]) => {
    // If the current shape is no longer available (e.g. operator switched
    // Life → Non-life while age-year or life-matrix was selected), reset
    // regardless of whether they touched the picker — we can't keep them
    // on a hidden option. Otherwise honor the manual pick.
    if (LIFE_ONLY_SHAPES.includes(shape.value) && !isLifeInsureType(i)) {
      shape.value = defaultShape(t, c, i)
      return
    }
    if (!shapeTouched.value) shape.value = defaultShape(t, c, i)
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

// ── Installment (fixed grid) shape state ──────────────────────────────────
// Same data shape as flat but keys are pinned to main/3/6/12.
const installment = ref<Record<string, RateTriple>>(
  props.initial?.shape === 'installment'
    ? Object.fromEntries(FIXED_INSTALLMENT_TERMS.map((t) => [t, props.initial!.shape === 'installment' ? props.initial!.installments[t] ?? emptyTriple() : emptyTriple()]))
    : Object.fromEntries(FIXED_INSTALLMENT_TERMS.map((t) => [t, emptyTriple()])),
)

// ── Band shape state ──────────────────────────────────────────────────────
const bands = ref<BandRow[]>(
  props.initial?.shape === 'band' && props.initial.bands.length
    ? [...props.initial.bands]
    : [emptyBand()],
)
function addBand(): void {
  // New band starts where the last one ended, so operators just fill "up to".
  const last = bands.value[bands.value.length - 1]
  const min = last?.maxSumAssure !== null && last?.maxSumAssure !== undefined
    ? Number(last.maxSumAssure) + 1
    : null
  bands.value = [...bands.value, { ...emptyBand(), minSumAssure: min }]
}
function removeBand(idx: number): void {
  if (bands.value.length <= 1) return
  const next = [...bands.value]
  next.splice(idx, 1)
  bands.value = next
}
function emptyBand(): BandRow {
  return {
    minSumAssure: null,
    maxSumAssure: null,
    installmentTerm: 'main',
    ...emptyTriple(),
  }
}

// ── Per-year shape state ──────────────────────────────────────────────────
// Fixed columns 1..5 + "6+" — matches the source PDFs and the Excel exports.
const YEAR_KEYS = ['1', '2', '3', '4', '5', '6'] as const
const years = ref<Record<string, RateTriple>>(
  props.initial?.shape === 'per-year'
    ? Object.fromEntries(YEAR_KEYS.map((y) => [y, props.initial!.shape === 'per-year' ? props.initial!.years[y] ?? emptyTriple() : emptyTriple()]))
    : Object.fromEntries(YEAR_KEYS.map((y) => [y, emptyTriple()])),
)

// ── Age-year shape state ──────────────────────────────────────────────────
// Repeatable entry-age brackets; each bracket carries the same Y1..Y6+ ×
// 3-party grid as per-year. Seeder maps one bracket → one wide-table row.
const ageBrackets = ref<AgeBracket[]>(
  props.initial?.shape === 'age-year' && props.initial.brackets.length
    ? props.initial.brackets.map((b) => ({
        minAge: b.minAge,
        maxAge: b.maxAge,
        // Ensure every year key exists even if the API returned partials.
        years: Object.fromEntries(YEAR_KEYS.map((y) => [y, b.years[y] ?? emptyTriple()])),
      }))
    : [emptyAgeBracket()],
)
function emptyAgeBracket(): AgeBracket {
  return {
    minAge: null,
    maxAge: null,
    years: Object.fromEntries(YEAR_KEYS.map((y) => [y, emptyTriple()])),
  }
}
function addAgeBracket(): void {
  // New bracket picks up where the last one ended so operators just fill "up to".
  const last = ageBrackets.value[ageBrackets.value.length - 1]
  const min = last?.maxAge !== null && last?.maxAge !== undefined
    ? Number(last.maxAge) + 1
    : null
  ageBrackets.value = [...ageBrackets.value, { ...emptyAgeBracket(), minAge: min }]
}
function removeAgeBracket(idx: number): void {
  if (ageBrackets.value.length <= 1) return
  const next = [...ageBrackets.value]
  next.splice(idx, 1)
  ageBrackets.value = next
}
function copyBracketYearAcross(bracketIdx: number, fromYear: string): void {
  const idx = YEAR_KEYS.indexOf(fromYear as (typeof YEAR_KEYS)[number])
  if (idx < 0 || idx >= YEAR_KEYS.length - 1) return
  const src = ageBrackets.value[bracketIdx].years[fromYear]
  const target = YEAR_KEYS[idx + 1]
  const next = [...ageBrackets.value]
  next[bracketIdx] = {
    ...next[bracketIdx],
    years: { ...next[bracketIdx].years, [target]: { ...src } },
  }
  ageBrackets.value = next
}

// ── Life-matrix shape state ───────────────────────────────────────────────
// Two nested loops: N age × sum-assured dimensions, each holding N year
// rows. Unlike per-year and age-year, `year` is a list not a map so an
// operator can add years beyond 6, or skip years (rare but possible).
const matrix = ref<MatrixDimension[]>(
  props.initial?.shape === 'life-matrix' && props.initial.dimensions.length
    ? props.initial.dimensions.map((d) => ({
        minAge: d.minAge,
        maxAge: d.maxAge,
        minSumAssure: d.minSumAssure,
        maxSumAssure: d.maxSumAssure,
        years: d.years.map((y) => ({ ...y })),
      }))
    : [emptyMatrixDimension()],
)
function emptyMatrixDimension(): MatrixDimension {
  // Fresh dimension seeds Y1..Y6 like the age-year shape, giving operators
  // a familiar starting grid. They can add/remove years freely.
  return {
    minAge: null,
    maxAge: null,
    minSumAssure: null,
    maxSumAssure: null,
    years: [1, 2, 3, 4, 5, 6].map((y) => ({ year: y, inh: null, ag: null, ov: null })),
  }
}
function addMatrixDimension(): void {
  // New dimension inherits the last one's age range as a starting point,
  // then the operator adjusts the sum-assured range. Faster than typing
  // 4 numbers into an empty row.
  const last = matrix.value[matrix.value.length - 1]
  const newDim = emptyMatrixDimension()
  if (last) {
    newDim.minAge = last.minAge
    newDim.maxAge = last.maxAge
    // Sum-assured auto-suggests "next range starts where last ended".
    newDim.minSumAssure = last.maxSumAssure !== null
      ? Number(last.maxSumAssure) + 1
      : null
  }
  matrix.value = [...matrix.value, newDim]
}
function removeMatrixDimension(idx: number): void {
  if (matrix.value.length <= 1) return
  const next = [...matrix.value]
  next.splice(idx, 1)
  matrix.value = next
}
function addMatrixYear(dimIdx: number): void {
  // Next year defaults to max(existing years) + 1. If the operator wants a
  // gap they can edit the number after adding.
  const dim = matrix.value[dimIdx]
  const nextYear = dim.years.length
    ? Math.max(...dim.years.map((y) => y.year)) + 1
    : 1
  const next = [...matrix.value]
  next[dimIdx] = {
    ...next[dimIdx],
    years: [...dim.years, { year: nextYear, inh: null, ag: null, ov: null }],
  }
  matrix.value = next
}
function removeMatrixYear(dimIdx: number, yearIdx: number): void {
  const dim = matrix.value[dimIdx]
  if (dim.years.length <= 1) return
  const nextYears = [...dim.years]
  nextYears.splice(yearIdx, 1)
  const next = [...matrix.value]
  next[dimIdx] = { ...dim, years: nextYears }
  matrix.value = next
}

// ── Emit ──────────────────────────────────────────────────────────────────
const payload = computed<CommissionRatesPayload>(() => {
  if (shape.value === 'skip') return { shape: 'skip' }
  if (shape.value === 'flat') return { shape: 'flat', installments: flat.value }
  if (shape.value === 'installment') return { shape: 'installment', installments: installment.value }
  if (shape.value === 'per-year') return { shape: 'per-year', years: years.value }
  if (shape.value === 'age-year') return { shape: 'age-year', brackets: ageBrackets.value }
  if (shape.value === 'life-matrix') return { shape: 'life-matrix', dimensions: matrix.value }
  return { shape: 'band', bands: bands.value }
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
  { value: 'flat', label: 'อัตราเดียว', hint: 'เหมาะกับ Rider' },
  { value: 'installment', label: 'ตามงวดชำระ', hint: 'เหมาะกับ Motor / รายเดือน–รายปี' },
  { value: 'per-year', label: 'ตามปีกรมธรรม์ (Y1–Y6+)', hint: 'อายุไม่เกี่ยว — Term ล้วน' },
  { value: 'age-year', label: 'ตามอายุและปีกรมธรรม์', hint: 'อายุ × ปี — สำหรับ Life แบบไม่ใช้ทุนประกัน' },
  { value: 'life-matrix', label: 'ตามอายุ × ทุนประกัน × ปี', hint: 'Life ประเภทสามัญเต็มรูปแบบ' },
  { value: 'band', label: 'ตามช่วงทุนประกัน', hint: 'เหมาะกับ Health / PA / CI' },
]

const visibleShapeOptions = computed(() =>
  SHAPE_OPTIONS.filter((opt) => !LIFE_ONLY_SHAPES.includes(opt.value) || isLifeInsureType(props.insureType)),
)

function fmtBaht(n: number | null): string {
  if (n === null) return ''
  return new Intl.NumberFormat('th-TH').format(n)
}
</script>

<template>
  <div class="space-y-3">
    <!-- Shape picker -->
    <div class="flex flex-wrap gap-2">
      <button v-for="opt in visibleShapeOptions" :key="opt.value" type="button"
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

    <!-- Installment (fixed grid main/3/6/12) -->
    <div v-else-if="shape === 'installment'" class="card p-3">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs text-slate-500">
            <th class="text-left font-medium py-1 w-32">งวดชำระ</th>
            <th v-for="p in PARTY_LABELS" :key="p.key" class="text-right font-medium py-1">
              {{ p.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="term in FIXED_INSTALLMENT_TERMS" :key="term" class="border-t border-slate-100">
            <td class="py-1.5 pr-2">
              <span class="text-xs font-mono px-1.5 py-0.5 rounded bg-slate-100 text-slate-700">{{ term }}</span>
              <span class="ml-2 text-[11px] text-slate-400">
                {{ term === 'main' ? '(รายปี)' : term === '3' ? '(3 เดือน)' : term === '6' ? '(6 เดือน)' : '(12 เดือน)' }}
              </span>
            </td>
            <td v-for="p in PARTY_LABELS" :key="p.key" class="py-1.5">
              <div class="relative">
                <input v-model.number="installment[term][p.key]" type="number" min="0" max="100" step="0.01"
                  class="w-full border border-slate-200 rounded-md pl-2 pr-6 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs">%</span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Per-year -->
    <div v-else-if="shape === 'per-year'" class="card p-3 overflow-x-auto">
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

    <!-- Band (sum-assured tiers) -->
    <div v-else-if="shape === 'band'" class="card p-3 space-y-2 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-xs text-slate-500">
            <th class="text-left font-medium py-1 pr-2">ทุนประกันขั้นต่ำ</th>
            <th class="text-left font-medium py-1 pr-2">ทุนประกันสูงสุด</th>
            <th class="text-left font-medium py-1 pr-2 w-20">งวดชำระ</th>
            <th v-for="p in PARTY_LABELS" :key="p.key" class="text-right font-medium py-1 px-1">
              {{ p.label }}
            </th>
            <th class="w-8" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, idx) in bands" :key="idx" class="border-t border-slate-100">
            <td class="py-1.5 pr-2">
              <input v-model.number="row.minSumAssure" type="number" min="0" step="1" placeholder="ไม่จำกัด"
                class="w-32 border border-slate-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
              <div v-if="row.minSumAssure !== null" class="text-[10px] text-slate-400 text-right pr-1">{{ fmtBaht(row.minSumAssure) }} ฿</div>
            </td>
            <td class="py-1.5 pr-2">
              <input v-model.number="row.maxSumAssure" type="number" min="0" step="1" placeholder="ไม่จำกัด"
                class="w-32 border border-slate-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
              <div v-if="row.maxSumAssure !== null" class="text-[10px] text-slate-400 text-right pr-1">{{ fmtBaht(row.maxSumAssure) }} ฿</div>
            </td>
            <td class="py-1.5 pr-2">
              <select v-model="row.installmentTerm"
                class="w-20 border border-slate-200 rounded-md px-1 py-1 text-xs bg-white focus:outline-none focus:border-brand-400">
                <option v-for="t in FIXED_INSTALLMENT_TERMS" :key="t" :value="t">{{ t }}</option>
              </select>
            </td>
            <td v-for="p in PARTY_LABELS" :key="p.key" class="py-1.5 px-1">
              <div class="relative">
                <input v-model.number="row[p.key]" type="number" min="0" max="100" step="0.01"
                  class="w-full border border-slate-200 rounded-md pl-1.5 pr-5 py-1 text-xs text-right focus:outline-none focus:border-brand-400" />
                <span class="absolute right-1.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]">%</span>
              </div>
            </td>
            <td class="text-center">
              <button v-if="bands.length > 1" type="button"
                @click="removeBand(idx)"
                class="text-slate-400 hover:text-rose-500 text-xs" title="ลบช่วงนี้">
                <i class="pi pi-times" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <div class="flex items-center justify-between">
        <button type="button" @click="addBand"
          class="text-xs text-brand-600 hover:text-brand-700 flex items-center gap-1">
          <i class="pi pi-plus text-[10px]" /> เพิ่มช่วงทุนประกัน
        </button>
        <p class="text-[10px] text-slate-400">
          เว้นว่างช่องต่ำสุด/สูงสุด = ไม่จำกัด (เป็นช่วง fallback)
        </p>
      </div>
    </div>

    <!-- Age-year (Life ประเภทสามัญ) — repeatable age brackets, each with a
         Y1..Y6+ × 3-party grid. Nested layout: one card per bracket. -->
    <div v-else-if="shape === 'age-year'" class="space-y-3">
      <div v-for="(bracket, bIdx) in ageBrackets" :key="bIdx"
        class="card p-3 space-y-2 overflow-x-auto">
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2">
            <span class="text-xs font-medium text-slate-500">ช่วงอายุผู้เอาประกัน:</span>
            <div class="relative">
              <input v-model.number="bracket.minAge" type="number" min="0" max="120" step="1"
                placeholder="0"
                class="w-20 border border-slate-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
            </div>
            <span class="text-xs text-slate-400">–</span>
            <div class="relative">
              <input v-model.number="bracket.maxAge" type="number" min="0" max="120" step="1"
                placeholder="120"
                class="w-20 border border-slate-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
            </div>
            <span class="text-[11px] text-slate-400">ปี</span>
          </div>
          <button v-if="ageBrackets.length > 1" type="button"
            @click="removeAgeBracket(bIdx)"
            class="text-slate-400 hover:text-rose-500 text-xs flex items-center gap-1" title="ลบช่วงอายุนี้">
            <i class="pi pi-times text-[10px]" /> ลบช่วง
          </button>
        </div>

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
                  <input v-model.number="bracket.years[y][p.key]" type="number" min="0" max="100" step="0.01"
                    class="w-full border border-slate-200 rounded-md pl-1.5 pr-5 py-1 text-xs text-right focus:outline-none focus:border-brand-400" />
                  <span class="absolute right-1.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]">%</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="flex items-center justify-end gap-2 text-[11px] text-slate-500">
          <span>เติมค่าจากปีก่อนหน้า:</span>
          <button v-for="y in YEAR_KEYS.slice(0, -1)" :key="y" type="button"
            @click="copyBracketYearAcross(bIdx, y)"
            class="px-1.5 py-0.5 rounded border border-slate-200 hover:bg-slate-50">
            ปี {{ y }} → ปี {{ YEAR_KEYS[YEAR_KEYS.indexOf(y as (typeof YEAR_KEYS)[number]) + 1] === '6' ? '6+' : YEAR_KEYS[YEAR_KEYS.indexOf(y as (typeof YEAR_KEYS)[number]) + 1] }}
          </button>
        </div>
      </div>

      <div class="flex items-center justify-between">
        <button type="button" @click="addAgeBracket"
          class="text-xs text-brand-600 hover:text-brand-700 flex items-center gap-1">
          <i class="pi pi-plus text-[10px]" /> เพิ่มช่วงอายุ
        </button>
        <p class="text-[10px] text-slate-400">
          เว้นว่างช่อง min/max อายุ = ไม่จำกัด (ช่วง fallback)
        </p>
      </div>
    </div>

    <!-- Life-matrix (Life ประเภทสามัญ full 3D) — one card per (age × sum-
         assured) dimension. Each card holds a variable-length year table
         (unlimited policy_year) × 3 parties. Add-year / add-dimension
         buttons at both levels. -->
    <div v-else class="space-y-3">
      <div v-for="(dim, dIdx) in matrix" :key="dIdx"
        class="card p-3 space-y-3 overflow-x-auto">
        <!-- Dimension header: 4 range inputs + remove button. Two rows so
             the header doesn't overflow on narrower drawers. -->
        <div class="flex items-start justify-between gap-2 flex-wrap">
          <div class="space-y-2">
            <div class="flex items-center gap-2">
              <span class="text-xs font-medium text-slate-500 w-24">ช่วงอายุ:</span>
              <input v-model.number="dim.minAge" type="number" min="0" max="120" step="1" placeholder="0"
                class="w-20 border border-slate-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
              <span class="text-xs text-slate-400">–</span>
              <input v-model.number="dim.maxAge" type="number" min="0" max="120" step="1" placeholder="120"
                class="w-20 border border-slate-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
              <span class="text-[11px] text-slate-400">ปี</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="text-xs font-medium text-slate-500 w-24">ช่วงทุน:</span>
              <input v-model.number="dim.minSumAssure" type="number" min="0" step="1" placeholder="ไม่จำกัด"
                class="w-28 border border-slate-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
              <span class="text-xs text-slate-400">–</span>
              <input v-model.number="dim.maxSumAssure" type="number" min="0" step="1" placeholder="ไม่จำกัด"
                class="w-28 border border-slate-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:border-brand-400" />
              <span class="text-[11px] text-slate-400">฿</span>
            </div>
          </div>
          <button v-if="matrix.length > 1" type="button"
            @click="removeMatrixDimension(dIdx)"
            class="text-slate-400 hover:text-rose-500 text-xs flex items-center gap-1" title="ลบมิตินี้">
            <i class="pi pi-times text-[10px]" /> ลบมิติ
          </button>
        </div>

        <!-- Year table. Columns are the years; rows are the three parties.
             Add-year button below expands the table horizontally. -->
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500">
              <th class="text-left font-medium py-1 pr-3 w-24">ฝ่าย</th>
              <th v-for="(y, yIdx) in dim.years" :key="yIdx" class="text-center font-medium py-1 px-1">
                <div class="flex items-center justify-center gap-1">
                  <span>ปี</span>
                  <input v-model.number="y.year" type="number" min="1" max="99" step="1"
                    class="w-12 border border-slate-200 rounded-md px-1 py-0.5 text-xs text-center focus:outline-none focus:border-brand-400" />
                  <button v-if="dim.years.length > 1" type="button"
                    @click="removeMatrixYear(dIdx, yIdx)"
                    class="text-slate-400 hover:text-rose-500 text-[10px]" title="ลบปีนี้">
                    <i class="pi pi-times" />
                  </button>
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in PARTY_LABELS" :key="p.key" class="border-t border-slate-100">
              <td class="py-1.5 pr-3">
                <div class="text-xs font-medium text-slate-700">{{ p.label }}</div>
              </td>
              <td v-for="(y, yIdx) in dim.years" :key="yIdx" class="py-1.5 px-1">
                <div class="relative">
                  <input v-model.number="y[p.key]" type="number" min="0" max="100" step="0.01"
                    class="w-full border border-slate-200 rounded-md pl-1.5 pr-5 py-1 text-xs text-right focus:outline-none focus:border-brand-400" />
                  <span class="absolute right-1.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]">%</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <button type="button" @click="addMatrixYear(dIdx)"
          class="text-xs text-brand-600 hover:text-brand-700 flex items-center gap-1">
          <i class="pi pi-plus text-[10px]" /> เพิ่มปีกรมธรรม์
        </button>
      </div>

      <div class="flex items-center justify-between">
        <button type="button" @click="addMatrixDimension"
          class="text-xs text-brand-600 hover:text-brand-700 flex items-center gap-1">
          <i class="pi pi-plus text-[10px]" /> เพิ่มมิติ (ช่วงอายุ × ช่วงทุน)
        </button>
        <p class="text-[10px] text-slate-400">
          เว้นว่างช่อง min/max = ไม่จำกัด (fallback)
        </p>
      </div>
    </div>
  </div>
</template>
