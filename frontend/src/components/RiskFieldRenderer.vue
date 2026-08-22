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

import { computed } from 'vue'
import type { RiskField, RiskSchema } from '../utils/riskSchema'
import { valueKey } from '../utils/riskSchema'
import DateInput from './DateInput.vue'
import FormField from './FormField.vue'

const props = defineProps<{
  schema: RiskSchema | null
  modelValue: Record<string, unknown>
  errors?: Record<string, string>
  locale?: 'th' | 'en'
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: Record<string, unknown>): void
}>()

const locale = computed(() => props.locale ?? 'th')

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
</script>

<template>
  <div v-if="schema && schema.sections.length > 0" class="space-y-6">
    <section v-for="section in schema.sections" :key="section.key" class="space-y-3">
      <h3 class="text-sm font-semibold text-slate-700">{{ label(section) }}</h3>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <template v-for="field in section.fields" :key="field.key">
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

            <!-- default: string / passport / phone all render as text inputs.
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
