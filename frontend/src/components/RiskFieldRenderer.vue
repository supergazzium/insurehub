<script setup lang="ts">
// C-13 — Dynamic Step-3 renderer for the 5-step wizard (B4 §3).
// Walks schema.sections + section.fields and produces the right input
// widget per field.type. All values are held in a single flat bag
// keyed by "section.field" (see riskSchema.ts::valueKey) so the wizard
// can serialize the payload with splitSchemaPayload without walking
// the tree.
//
// Contract:
//   props.schema     — the RiskSchema (from product.productType.riskSchema)
//   v-model          — flat value bag { "section.field": value, ... }
//   props.errors     — { "section.field": "message" } from validation
//   props.locale     — 'th' | 'en' picks which label_* field to show
//
// The renderer is deliberately narrow: no validation, no submit logic,
// no autofill hooks. Wizard owns those. This component only renders
// widgets + emits value changes.

import { computed, onMounted, reactive, ref, watch } from 'vue'
import type { RiskField, RiskSchema, RiskSection } from '../utils/riskSchema'
import { valueKey } from '../utils/riskSchema'
import { api } from '../api/client'
import { isThaiMobile } from '../utils/thaiValidation'
import DateInput from './DateInput.vue'
import FormField from './FormField.vue'

const props = defineProps<{
  schema: RiskSchema | null
  modelValue: Record<string, unknown>
  errors?: Record<string, string>
  locale?: 'th' | 'en'
  // Render only these section keys (whitelist). Omit for all.
  only?: string[]
  // Render every section EXCEPT these keys (blacklist). Omit for none.
  exclude?: string[]
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: Record<string, unknown>): void
}>()

const locale = computed(() => props.locale ?? 'th')

/** Sections to render in THIS instance. `only` (whitelist) and `exclude`
 *  (blacklist) let the wizard split one schema across two cards — e.g.
 *  render beneficiaries + riders in the Product section and everything else
 *  in the Risk section. Filtering happens only at render time; the priming
 *  and cascade watchers still walk the full schema so nothing breaks. */
const visibleSections = computed(() => {
  const all = props.schema?.sections ?? []
  return all.filter((sec) => {
    if (props.only && !props.only.includes(sec.key)) return false
    if (props.exclude && props.exclude.includes(sec.key)) return false
    return true
  })
})

function label(f: { label_th: string; label_en: string }): string {
  return locale.value === 'en' ? f.label_en : f.label_th
}

function help(f: RiskField): string | undefined {
  return locale.value === 'en' ? f.help_en : f.help_th
}

/** Emit an updated value bag with one key replaced. Immutable update
 *  matches the "no mutation" rule from the shared coding style. */
function setValue(key: string, v: unknown): void {
  emit('update:modelValue', { ...props.modelValue, [key]: v })
}

/** Type-safe getter for the array_of_objects row bag. Handles the
 *  never-set case by returning an empty array so v-for works. */
function rows(key: string): Record<string, unknown>[] {
  const v = props.modelValue[key]
  return Array.isArray(v) ? (v as Record<string, unknown>[]) : []
}

function updateRow(key: string, idx: number, patch: Record<string, unknown>): void {
  const arr = rows(key).slice()
  arr[idx] = { ...arr[idx], ...patch }
  setValue(key, arr)
}

function addRow(key: string, field: RiskField): void {
  const arr = rows(key).slice()
  if (field.max_rows !== undefined && arr.length >= field.max_rows) return
  arr.push({})
  setValue(key, arr)
}

function removeRow(key: string, idx: number): void {
  const arr = rows(key).slice()
  arr.splice(idx, 1)
  setValue(key, arr)
}

/** Tri-state for phone-field visual feedback:
 *  - 'empty'   → user hasn't typed → neutral border, no icon
 *  - 'valid'   → matches Thai mobile regex → green border + ✓
 *  - 'invalid' → non-empty and fails → red border + ✗ + inline hint
 *  Kept as a pure function so the template stays declarative. */
function phoneState(v: unknown): 'empty' | 'valid' | 'invalid' {
  const s = typeof v === 'string' ? v.trim() : ''
  if (s === '') return 'empty'
  return isThaiMobile(s) ? 'valid' : 'invalid'
}

/** Evaluate a field's show_when gate against the current value bag.
 *  Returns true when the gate is absent (unconditional). Comparisons
 *  coerce to string so an option value of '1' matches a saved 1. */
function fieldVisible(section: RiskSection, field: RiskField): boolean {
  if (!field.show_when) return true
  const gateVal = props.modelValue[valueKey(section.key, field.show_when.field)]
  const eq = field.show_when.equals
  const target = Array.isArray(eq) ? eq : [eq]
  return gateVal !== undefined && gateVal !== null && target.includes(String(gateVal))
}

// ── remote_select typeahead state ────────────────────────────────────────
// Keyed by valueKey(section, field). Each entry caches the currently-shown
// options and the loading flag. Options are refetched on q-input debounce
// so the dropdown filters against the server, not the last cached page.

interface RemoteOption { id: string; label: string; labelEn?: string }
interface RemoteState { options: RemoteOption[]; loading: boolean; query: string; open: boolean }
const remoteCache = reactive<Record<string, RemoteState>>({})

function ensureRemote(key: string): RemoteState {
  if (!remoteCache[key]) {
    remoteCache[key] = { options: [], loading: false, query: '', open: false }
  }
  return remoteCache[key]
}

/** Build the URL with any remote_deps params appended. Returns the URL
 *  AND a `ready` flag — false when any required dep value is empty so
 *  callers can skip the fetch (e.g. don't fetch models until brand set). */
function buildRemoteUrl(section: RiskSection, field: RiskField): { url: string; ready: boolean } {
  if (!field.remote_url) return { url: '', ready: false }
  const deps = field.remote_deps ?? []
  if (deps.length === 0) return { url: field.remote_url, ready: true }
  const params: string[] = []
  for (const dep of deps) {
    const v = props.modelValue[valueKey(section.key, dep.field)]
    if (v === undefined || v === null || v === '') {
      return { url: field.remote_url, ready: false }
    }
    params.push(`${encodeURIComponent(dep.param)}=${encodeURIComponent(String(v))}`)
  }
  const sep = field.remote_url.includes('?') ? '&' : '?'
  return { url: `${field.remote_url}${sep}${params.join('&')}`, ready: true }
}

async function fetchRemote(key: string, url: string, q: string): Promise<void> {
  const state = ensureRemote(key)
  state.loading = true
  try {
    const sep = url.includes('?') ? '&' : '?'
    const path = q ? `${url}${sep}q=${encodeURIComponent(q)}` : url
    const res = await api.get<{ data: RemoteOption[] }>(path)
    state.options = res.data
  } catch {
    state.options = []
  } finally {
    state.loading = false
  }
}

// Debounced query watcher — one shared timer per remote-select field.
const remoteTimers: Record<string, number> = {}
function scheduleRemoteFetch(key: string, url: string, q: string): void {
  window.clearTimeout(remoteTimers[key])
  remoteTimers[key] = window.setTimeout(() => { void fetchRemote(key, url, q) }, 250)
}

/** Pre-warm the option list for a remote_select field so the initial
 *  dropdown open shows options immediately (no wait-for-fetch dead time).
 *  Respects remote_deps: skips priming when any dep is unfilled. */
function primeRemote(section: RiskSection, field: RiskField): void {
  const key = valueKey(section.key, field.key)
  const { url, ready } = buildRemoteUrl(section, field)
  const state = ensureRemote(key)
  if (!ready) {
    // Clear stale options so the dropdown shows the "pick parent first" state.
    if (state.options.length > 0) state.options = []
    return
  }
  if (state.options.length === 0 && !state.loading) {
    void fetchRemote(key, url, '')
  }
}

// Prime every remote_select the schema declares as soon as the schema
// mounts. Cheap: 1–2 requests, cached until the component unmounts.
onMounted(() => {
  if (!props.schema) return
  for (const section of props.schema.sections) {
    for (const field of section.fields) {
      if (field.type === 'remote_select' && field.remote_url) {
        primeRemote(section, field)
      }
    }
  }
})

watch(() => props.schema, (s) => {
  if (!s) return
  for (const section of s.sections) {
    for (const field of section.fields) {
      if (field.type === 'remote_select' && field.remote_url) {
        primeRemote(section, field)
      }
    }
  }
})

// Cascade dep watcher — when a parent field's value changes, clear the
// child's value + refetch its options. Runs against the whole schema on
// every modelValue change; O(schema fields × deps) but the schema is tiny
// so the cost is negligible.
watch(() => props.modelValue, (newV, oldV) => {
  if (!props.schema) return
  for (const section of props.schema.sections) {
    for (const field of section.fields) {
      if (field.type !== 'remote_select' || !field.remote_deps || field.remote_deps.length === 0) continue
      const childKey = valueKey(section.key, field.key)
      let changed = false
      for (const dep of field.remote_deps) {
        const depKey = valueKey(section.key, dep.field)
        if (newV[depKey] !== oldV?.[depKey]) { changed = true; break }
      }
      if (!changed) continue
      // Clear stale option cache so buildRemoteUrl-based prime refetches.
      const state = ensureRemote(childKey)
      state.options = []
      state.query = ''
      // Clear the child's own value (a model of the old brand is no
      // longer valid for the new brand).
      if (newV[childKey] !== undefined && newV[childKey] !== '' && newV[childKey] !== null) {
        setValue(childKey, '')
      }
      primeRemote(section, field)
    }
  }
}, { deep: true })

// When resolving a saved value's label, first try the cached options; if
// missing (typical on resume when the option isn't on the first page),
// echo the id so the user sees SOMETHING rather than a blank pill.
function remoteLabel(key: string, value: string): string {
  const state = remoteCache[key]
  const hit = state?.options.find((o) => o.id === value)
  return hit ? hit.label : value
}

// Track which remote_select is currently open so a page-level click
// outside can close it. We use a mounted-body listener to keep the
// template simple.
const openRemoteKey = ref<string | null>(null)
function openRemote(section: RiskSection, field: RiskField): void {
  openRemoteKey.value = valueKey(section.key, field.key)
  primeRemote(section, field)
}
function closeRemote(): void { openRemoteKey.value = null }

onMounted(() => {
  document.addEventListener('click', (e) => {
    const t = e.target as HTMLElement | null
    if (!t) return
    if (!t.closest('[data-remote-select]')) closeRemote()
  })
})
</script>

<template>
  <div v-if="schema && visibleSections.length > 0" class="space-y-6">
    <section v-for="section in visibleSections" :key="section.key" class="space-y-3">
      <h3 class="text-sm font-semibold text-slate-700">{{ label(section) }}</h3>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <template v-for="field in section.fields" :key="field.key">
          <!-- show_when gate — hide the field entirely when its dependency
               isn't satisfied. Applies uniformly across all render branches
               below. -->
          <template v-if="fieldVisible(section, field)">
          <!-- array_of_objects (life beneficiaries / riders) spans both columns -->
          <div v-if="field.type === 'array_of_objects'" class="md:col-span-2 space-y-2 border-l-2 border-slate-100 pl-3">
            <div class="flex items-center justify-between">
              <div class="text-xs font-medium text-slate-500">{{ label(field) }}</div>
              <button type="button"
                class="text-xs text-brand-600 hover:text-brand-800 disabled:text-slate-300"
                :disabled="field.max_rows !== undefined && rows(valueKey(section.key, field.key)).length >= field.max_rows"
                @click="addRow(valueKey(section.key, field.key), field)"
              >
                <i class="pi pi-plus text-[10px]" /> เพิ่มแถว
              </button>
            </div>
            <div v-for="(row, idx) in rows(valueKey(section.key, field.key))" :key="idx"
              class="p-3 rounded-lg bg-slate-50 border border-slate-200 space-y-2">
              <div class="flex items-center justify-between">
                <div class="text-[10px] text-slate-400 uppercase tracking-wider">#{{ idx + 1 }}</div>
                <button type="button" class="text-xs text-rose-500 hover:text-rose-700"
                  @click="removeRow(valueKey(section.key, field.key), idx)">
                  <i class="pi pi-trash text-[10px]" />
                </button>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <FormField v-for="sub in (field.fields ?? [])" :key="sub.key"
                  :label="label(sub)" :required="sub.required">
                  <!-- Nested row fields only support scalar widgets — the
                       recursive array_of_objects case isn't in any current
                       schema and adding it would require a self-import. -->
                  <input v-if="sub.type === 'number'"
                    type="number"
                    :min="sub.validation?.min"
                    :max="sub.validation?.max"
                    :value="(row[sub.key] as number | undefined)"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400"
                    @input="updateRow(valueKey(section.key, field.key), idx, { [sub.key]: ($event.target as HTMLInputElement).valueAsNumber })"
                  />
                  <input v-else
                    type="text"
                    :placeholder="sub.placeholder"
                    :maxlength="sub.validation?.max"
                    :value="(row[sub.key] as string | undefined)"
                    class="w-full border border-slate-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:border-brand-400"
                    @input="updateRow(valueKey(section.key, field.key), idx, { [sub.key]: ($event.target as HTMLInputElement).value })"
                  />
                </FormField>
              </div>
            </div>
            <p v-if="rows(valueKey(section.key, field.key)).length === 0" class="text-xs text-slate-400">
              (ยังไม่มีแถว)
            </p>
          </div>

          <!-- Scalar fields -->
          <FormField v-else :label="label(field)" :required="field.required" :hint="help(field)"
            :error-key="valueKey(section.key, field.key)"
            :errors="errors ? { [valueKey(section.key, field.key)]: [errors[valueKey(section.key, field.key)]] } as any : undefined">
            <DateInput v-if="field.type === 'date'"
              :model-value="(modelValue[valueKey(section.key, field.key)] as string | null | undefined) ?? ''"
              @update:model-value="setValue(valueKey(section.key, field.key), $event)"
            />

            <select v-else-if="field.type === 'select'"
              :value="(modelValue[valueKey(section.key, field.key)] as string | undefined) ?? ''"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-brand-400"
              @change="setValue(valueKey(section.key, field.key), ($event.target as HTMLSelectElement).value)"
            >
              <option value="">—</option>
              <option v-for="opt in (field.options ?? [])" :key="opt.value" :value="opt.value">
                {{ locale === 'en' ? opt.label_en : opt.label_th }}
              </option>
            </select>

            <!-- radio group — pill-style buttons matching the wizard's
                 New/Renew toggle. Value = option.value (string). -->
            <div v-else-if="field.type === 'radio'" class="flex items-center gap-2 flex-wrap">
              <button v-for="opt in (field.options ?? [])" :key="opt.value"
                type="button"
                :class="[
                  'px-3 py-1.5 rounded-md text-sm border transition-colors',
                  (modelValue[valueKey(section.key, field.key)] as string | undefined) === opt.value
                    ? 'bg-brand-600 text-white border-brand-600'
                    : 'bg-white text-slate-700 border-slate-300 hover:border-brand-400',
                ]"
                @click="setValue(valueKey(section.key, field.key), opt.value)"
              >
                {{ locale === 'en' ? opt.label_en : opt.label_th }}
              </button>
            </div>

            <!-- remote_select — searchable dropdown backed by an API. On
                 focus the cached options open; typing debounces a fresh
                 fetch against the endpoint's ?q= param. Value stored is
                 the option's id (string). Cascade-dependent fields (see
                 field.remote_deps) refetch + clear their value when a
                 parent field changes; when a required parent is empty
                 the input is disabled with a "pick parent first" hint. -->
            <div v-else-if="field.type === 'remote_select' && field.remote_url"
              class="relative" data-remote-select>
              <input type="text"
                :placeholder="buildRemoteUrl(section, field).ready
                  ? (field.placeholder ?? 'พิมพ์เพื่อค้นหา...')
                  : (field.placeholder_disabled ?? 'เลือกค่าอ้างอิงก่อน')"
                :disabled="!buildRemoteUrl(section, field).ready"
                :value="openRemoteKey === valueKey(section.key, field.key)
                  ? ensureRemote(valueKey(section.key, field.key)).query
                  : remoteLabel(valueKey(section.key, field.key), (modelValue[valueKey(section.key, field.key)] as string | undefined) ?? '')"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 pr-8 text-sm bg-white focus:outline-none focus:border-brand-400 disabled:bg-slate-50 disabled:text-slate-400 disabled:cursor-not-allowed"
                @focus="openRemote(section, field)"
                @input="(e) => {
                  const state = ensureRemote(valueKey(section.key, field.key))
                  state.query = (e.target as HTMLInputElement).value
                  openRemoteKey = valueKey(section.key, field.key)
                  const built = buildRemoteUrl(section, field)
                  if (built.ready) scheduleRemoteFetch(valueKey(section.key, field.key), built.url, state.query)
                }"
              />
              <button v-if="modelValue[valueKey(section.key, field.key)]"
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-rose-500"
                @click.stop="setValue(valueKey(section.key, field.key), ''); ensureRemote(valueKey(section.key, field.key)).query = ''"
              >
                <i class="pi pi-times text-xs" />
              </button>
              <ul v-if="openRemoteKey === valueKey(section.key, field.key) && buildRemoteUrl(section, field).ready"
                class="absolute left-0 right-0 top-full mt-1 z-20 bg-white border border-slate-200 rounded-lg shadow-lg max-h-56 overflow-auto">
                <li v-if="ensureRemote(valueKey(section.key, field.key)).loading" class="px-3 py-2 text-xs text-slate-400">
                  <i class="pi pi-spin pi-spinner text-[10px]" /> Loading…
                </li>
                <li v-else-if="ensureRemote(valueKey(section.key, field.key)).options.length === 0" class="px-3 py-2 text-xs text-slate-400">
                  ไม่พบข้อมูล
                </li>
                <li v-for="opt in ensureRemote(valueKey(section.key, field.key)).options" :key="opt.id"
                  class="px-3 py-1.5 text-sm hover:bg-brand-50 cursor-pointer"
                  @click="setValue(valueKey(section.key, field.key), opt.id); ensureRemote(valueKey(section.key, field.key)).query = ''; closeRemote()"
                >
                  {{ locale === 'en' && opt.labelEn ? opt.labelEn : opt.label }}
                </li>
              </ul>
            </div>

            <input v-else-if="field.type === 'number'"
              type="number"
              :min="field.validation?.min"
              :max="field.validation?.max"
              :value="(modelValue[valueKey(section.key, field.key)] as number | undefined)"
              :placeholder="field.placeholder"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="setValue(valueKey(section.key, field.key), ($event.target as HTMLInputElement).valueAsNumber)"
            />

            <input v-else-if="field.type === 'boolean'"
              type="checkbox"
              :checked="!!modelValue[valueKey(section.key, field.key)]"
              class="accent-brand-500"
              @change="setValue(valueKey(section.key, field.key), ($event.target as HTMLInputElement).checked)"
            />

            <textarea v-else-if="field.type === 'text'"
              rows="2"
              :maxlength="field.validation?.max"
              :placeholder="field.placeholder"
              :value="(modelValue[valueKey(section.key, field.key)] as string | undefined) ?? ''"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="setValue(valueKey(section.key, field.key), ($event.target as HTMLTextAreaElement).value)"
            />

            <!-- phone — Thai mobile with green/red visual state.
                 Reuses isThaiMobile so validation stays in sync with the
                 customer form and portal profile. Empty stays neutral;
                 filled+invalid shows red border + ✗; filled+valid shows
                 green border + ✓. Inline hint message on invalid state. -->
            <div v-else-if="field.type === 'phone'" class="relative">
              <input type="tel"
                :placeholder="field.placeholder ?? '08x-xxx-xxxx'"
                :maxlength="field.validation?.max ?? 20"
                :value="(modelValue[valueKey(section.key, field.key)] as string | undefined) ?? ''"
                :class="[
                  'w-full border rounded-lg px-3 py-1.5 pr-9 text-sm focus:outline-none',
                  phoneState(modelValue[valueKey(section.key, field.key)]) === 'valid'
                    ? 'border-emerald-400 focus:border-emerald-500 bg-emerald-50/30'
                    : phoneState(modelValue[valueKey(section.key, field.key)]) === 'invalid'
                    ? 'border-rose-400 focus:border-rose-500 bg-rose-50/30'
                    : 'border-slate-200 focus:border-brand-400',
                ]"
                @input="setValue(valueKey(section.key, field.key), ($event.target as HTMLInputElement).value)"
              />
              <i v-if="phoneState(modelValue[valueKey(section.key, field.key)]) === 'valid'"
                class="pi pi-check-circle absolute right-2 top-1/2 -translate-y-1/2 text-emerald-500 text-sm" />
              <i v-else-if="phoneState(modelValue[valueKey(section.key, field.key)]) === 'invalid'"
                class="pi pi-times-circle absolute right-2 top-1/2 -translate-y-1/2 text-rose-500 text-sm" />
              <p v-if="phoneState(modelValue[valueKey(section.key, field.key)]) === 'invalid'"
                class="mt-1 text-xs text-rose-600">
                เบอร์โทรศัพท์ต้องเป็นเบอร์มือถือไทยที่ถูกต้อง (เช่น 08x-xxx-xxxx)
              </p>
            </div>

            <!-- default: string / passport all render as text inputs.
                 The type name still matters for the payload builder + wizard
                 autofill routing (passport = customer.passport prefill, etc). -->
            <input v-else
              type="text"
              :placeholder="field.placeholder"
              :maxlength="field.validation?.max"
              :value="(modelValue[valueKey(section.key, field.key)] as string | undefined) ?? ''"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400"
              @input="setValue(valueKey(section.key, field.key), ($event.target as HTMLInputElement).value)"
            />
          </FormField>
          </template>
        </template>
      </div>
    </section>
  </div>

  <!-- misc kind (empty sections) → placeholder. Matches the "nothing to
       fill here" affordance from B4 §2.6 so the operator sees an intent
       instead of a blank card. -->
  <div v-else-if="schema" class="p-4 rounded-lg border border-dashed border-slate-200 text-center text-sm text-slate-500">
    ไม่มีข้อมูลเพิ่มเติมสำหรับผลิตภัณฑ์นี้
  </div>
</template>
