<script setup lang="ts">
// MGM product-types admin — full CRUD. The taxonomy of product-types
// (Sheet2 R54 columns + Life types), each assigned to exactly one
// commission tier.
//
// Layout: list grouped by sub_of (Motor / Fire / Health / Life / ...),
// with inline editable columns for name_th, tier assignment, active flag.
// Add-new via inline row at the bottom. Delete via icon (blocked if any
// product references the type, surfaced as a 422 from the backend).

import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '../../api/client'
import {
  createProductType, deleteProductType, fetchCommissionTiers,
  fetchProductTypes, updateProductType,
  type CommissionTier, type ProductType, type ProductTypeKind,
} from '../../api/mgm'

const { t } = useI18n()

const types = ref<ProductType[]>([])
const tiers = ref<CommissionTier[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const savingId = ref<string | null>(null)
const deletingId = ref<string | null>(null)

// C-9 — the 6 canonical wizard-branch kinds. Values mirror B4 §2 +
// PolicyRiskShim::FIELDS keys. Null means "no branch" (custom taxonomy).
const KIND_OPTIONS: { value: ProductTypeKind | ''; label: string }[] = [
  { value: '', label: '—' },
  { value: 'motor', label: 'Motor' },
  { value: 'travel', label: 'Travel' },
  { value: 'fire', label: 'Fire / Property' },
  { value: 'health', label: 'Health' },
  { value: 'life', label: 'Life' },
  { value: 'misc', label: 'Misc' },
]

// Risk-schema editor modal state — one modal shared across every row.
// `editingRow` holds the current row; the textarea is bound to
// `schemaDraft` so the operator can edit without touching row.riskSchema
// until Save (which parses + validates then PATCHes).
const editingRow = ref<ProductType | null>(null)
const schemaDraft = ref<string>('')
const schemaError = ref<string | null>(null)
const schemaSaving = ref(false)

function openSchemaEditor(row: ProductType): void {
  editingRow.value = row
  schemaDraft.value = row.riskSchema
    ? JSON.stringify(row.riskSchema, null, 2)
    : '{\n  "version": 1,\n  "kind": "misc",\n  "sections": []\n}'
  schemaError.value = null
}

function closeSchemaEditor(): void {
  editingRow.value = null
  schemaDraft.value = ''
  schemaError.value = null
}

async function saveSchema(): Promise<void> {
  if (!editingRow.value) return
  const row = editingRow.value
  schemaError.value = null

  const trimmed = schemaDraft.value.trim()
  let parsed: Record<string, unknown> | null = null
  if (trimmed !== '') {
    try {
      parsed = JSON.parse(trimmed)
    } catch (e) {
      schemaError.value = e instanceof Error ? e.message : 'Invalid JSON'
      return
    }
  }

  schemaSaving.value = true
  try {
    const res = await updateProductType(row.id, { riskSchema: parsed })
    Object.assign(row, res.data)
    closeSchemaEditor()
  } catch (e: unknown) {
    schemaError.value = e instanceof ApiError ? e.message : 'Save failed.'
  } finally {
    schemaSaving.value = false
  }
}

async function clearSchema(): Promise<void> {
  if (!editingRow.value) return
  const row = editingRow.value
  if (!confirm(t('adminProductTypes.confirmClearSchema'))) return
  schemaSaving.value = true
  try {
    const res = await updateProductType(row.id, { riskSchema: null })
    Object.assign(row, res.data)
    closeSchemaEditor()
  } catch (e: unknown) {
    schemaError.value = e instanceof ApiError ? e.message : 'Clear failed.'
  } finally {
    schemaSaving.value = false
  }
}

const newRow = reactive({
  code: '',
  nameTh: '',
  nameEn: '',
  subOf: '',
  kind: '' as ProductTypeKind | '',
  tierId: '',
  active: true,
  notes: '',
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [typesRes, tiersRes] = await Promise.all([
      fetchProductTypes(false),
      fetchCommissionTiers(),
    ])
    types.value = typesRes.data
    tiers.value = tiersRes.data
    if (tiers.value.length && !newRow.tierId) {
      newRow.tierId = tiers.value[0].id
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ── Save a single field on an existing row (inline PATCH) ────────────────
async function saveField(row: ProductType, field: keyof ProductType): Promise<void> {
  savingId.value = row.id
  try {
    // The backend expects `tierId` as an int, everything else as-is.
    const payload: Record<string, unknown> = {}
    switch (field) {
      case 'code': payload.code = row.code; break
      case 'nameTh': payload.nameTh = row.nameTh; break
      case 'nameEn': payload.nameEn = row.nameEn; break
      case 'subOf': payload.subOf = row.subOf || null; break
      // C-9: `kind` accepts the empty string from the select as "no
      // branch" — send NULL to the backend so the ProductKind::derive
      // fallback fires for wizard consumers.
      case 'kind': payload.kind = row.kind || null; break
      case 'tierId': payload.tierId = Number(row.tierId); break
      case 'sortOrder': payload.sortOrder = row.sortOrder; break
      case 'active': payload.active = row.active; break
      case 'notes': payload.notes = row.notes || null; break
      default: return
    }
    const res = await updateProductType(row.id, payload)
    Object.assign(row, res.data)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Save failed.'
    void load()
  } finally {
    savingId.value = null
  }
}

// ── Create + delete ──────────────────────────────────────────────────────
async function createRow(): Promise<void> {
  if (!newRow.code || !newRow.nameTh || !newRow.nameEn || !newRow.tierId) {
    error.value = t('adminProductTypes.errorRequired')
    return
  }
  try {
    const res = await createProductType({
      code: newRow.code.trim(),
      nameTh: newRow.nameTh.trim(),
      nameEn: newRow.nameEn.trim(),
      subOf: newRow.subOf.trim() || null,
      kind: newRow.kind || null,
      tierId: Number(newRow.tierId),
      active: newRow.active,
      notes: newRow.notes.trim() || null,
    })
    types.value.push(res.data)
    // Reset new row (keep tierId + subOf + kind for rapid entry)
    newRow.code = ''
    newRow.nameTh = ''
    newRow.nameEn = ''
    newRow.notes = ''
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Create failed.'
  }
}

async function removeRow(row: ProductType): Promise<void> {
  if (!confirm(t('adminProductTypes.confirmDelete', { code: row.code }))) return
  deletingId.value = row.id
  try {
    await deleteProductType(row.id)
    types.value = types.value.filter((t) => t.id !== row.id)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Delete failed.'
  } finally {
    deletingId.value = null
  }
}

// ── Grouping by sub_of ───────────────────────────────────────────────────
interface Group {
  key: string
  label: string
  rows: ProductType[]
}
const grouped = computed<Group[]>(() => {
  const map = new Map<string, ProductType[]>()
  for (const row of types.value) {
    const key = row.subOf ?? '(Uncategorized)'
    if (!map.has(key)) map.set(key, [])
    map.get(key)!.push(row)
  }
  const groups: Group[] = []
  for (const [key, rows] of map.entries()) {
    rows.sort((a, b) => a.sortOrder - b.sortOrder || a.code.localeCompare(b.code))
    groups.push({ key, label: key, rows })
  }
  groups.sort((a, b) => a.label.localeCompare(b.label))
  return groups
})
</script>

<template>
  <div class="space-y-6 max-w-6xl">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('adminProductTypes.title') }}</h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('adminProductTypes.subtitle') }}</p>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <div v-if="loading" class="text-sm text-slate-500">{{ t('adminProductTypes.loading') }}</div>

    <template v-else>
      <section
        v-for="group in grouped"
        :key="group.key"
        class="card overflow-hidden"
      >
        <div class="bg-slate-100 px-4 py-2 text-xs font-medium text-slate-600 uppercase tracking-wider">
          {{ group.label }} · {{ group.rows.length }}
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
            <tr>
              <th class="px-4 py-2 text-left w-40">{{ t('adminProductTypes.code') }}</th>
              <th class="px-4 py-2 text-left">{{ t('adminProductTypes.nameTh') }}</th>
              <th class="px-4 py-2 text-left">{{ t('adminProductTypes.nameEn') }}</th>
              <th class="px-4 py-2 text-left w-32">{{ t('adminProductTypes.kind') }}</th>
              <th class="px-4 py-2 text-left w-24">{{ t('adminProductTypes.riskSchema') }}</th>
              <th class="px-4 py-2 text-left w-48">{{ t('adminProductTypes.tier') }}</th>
              <th class="px-4 py-2 text-center w-20">{{ t('adminProductTypes.active') }}</th>
              <th class="px-4 py-2 w-8" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="row in group.rows"
              :key="row.id"
              :class="{ 'opacity-60': savingId === row.id || deletingId === row.id }"
            >
              <td class="px-4 py-2">
                <input
                  v-model.trim="row.code"
                  type="text"
                  class="w-full border-b border-transparent hover:border-slate-200 focus:border-brand-400 focus:outline-none bg-transparent text-xs font-mono"
                  @change="saveField(row, 'code')"
                />
              </td>
              <td class="px-4 py-2">
                <input
                  v-model.trim="row.nameTh"
                  type="text"
                  class="w-full border-b border-transparent hover:border-slate-200 focus:border-brand-400 focus:outline-none bg-transparent"
                  @change="saveField(row, 'nameTh')"
                />
              </td>
              <td class="px-4 py-2 text-slate-600">
                <input
                  v-model.trim="row.nameEn"
                  type="text"
                  class="w-full border-b border-transparent hover:border-slate-200 focus:border-brand-400 focus:outline-none bg-transparent"
                  @change="saveField(row, 'nameEn')"
                />
              </td>
              <td class="px-4 py-2">
                <select
                  v-model="row.kind"
                  class="w-full border border-slate-200 rounded px-2 py-1 text-xs bg-white focus:outline-none focus:border-brand-400"
                  @change="saveField(row, 'kind')"
                >
                  <option v-for="opt in KIND_OPTIONS" :key="opt.value || 'none'" :value="opt.value || null">
                    {{ opt.label }}
                  </option>
                </select>
              </td>
              <td class="px-4 py-2 text-center">
                <button
                  type="button"
                  class="text-xs px-2 py-1 rounded"
                  :class="row.riskSchema
                    ? 'text-brand-700 hover:bg-brand-50'
                    : 'text-slate-400 hover:bg-slate-100'"
                  :title="row.riskSchema ? t('adminProductTypes.schemaEditTitle') : t('adminProductTypes.schemaSetTitle')"
                  @click="openSchemaEditor(row)"
                >
                  <i :class="row.riskSchema ? 'pi pi-code' : 'pi pi-pencil'" class="text-[10px]" />
                  {{ row.riskSchema ? t('adminProductTypes.schemaSet') : t('adminProductTypes.schemaEmpty') }}
                </button>
              </td>
              <td class="px-4 py-2">
                <select
                  v-model="row.tierId"
                  class="w-full border border-slate-200 rounded px-2 py-1 text-sm bg-white focus:outline-none focus:border-brand-400"
                  @change="saveField(row, 'tierId')"
                >
                  <option v-for="tier in tiers" :key="tier.id" :value="tier.id">
                    {{ tier.nameTh }} ({{ tier.code }})
                  </option>
                </select>
              </td>
              <td class="px-4 py-2 text-center">
                <input
                  v-model="row.active"
                  type="checkbox"
                  class="accent-brand-500"
                  @change="saveField(row, 'active')"
                />
              </td>
              <td class="px-4 py-2 text-right">
                <button
                  type="button"
                  class="text-slate-400 hover:text-rose-500 text-xs"
                  :title="t('adminProductTypes.deleteTitle')"
                  @click="removeRow(row)"
                >
                  <i class="pi pi-trash" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Add-new row -->
      <section class="card p-4 space-y-3">
        <h3 class="text-sm font-semibold text-slate-700">{{ t('adminProductTypes.addNew') }}</h3>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
          <div>
            <label class="text-xs text-slate-500 block mb-1">{{ t('adminProductTypes.code') }}</label>
            <input
              v-model.trim="newRow.code"
              type="text"
              placeholder="MOTOR_NEW"
              class="w-full border border-slate-200 rounded px-2 py-1 text-sm font-mono focus:outline-none focus:border-brand-400"
            />
          </div>
          <div>
            <label class="text-xs text-slate-500 block mb-1">{{ t('adminProductTypes.nameTh') }}</label>
            <input
              v-model.trim="newRow.nameTh"
              type="text"
              class="w-full border border-slate-200 rounded px-2 py-1 text-sm focus:outline-none focus:border-brand-400"
            />
          </div>
          <div>
            <label class="text-xs text-slate-500 block mb-1">{{ t('adminProductTypes.nameEn') }}</label>
            <input
              v-model.trim="newRow.nameEn"
              type="text"
              class="w-full border border-slate-200 rounded px-2 py-1 text-sm focus:outline-none focus:border-brand-400"
            />
          </div>
          <div>
            <label class="text-xs text-slate-500 block mb-1">{{ t('adminProductTypes.group') }}</label>
            <input
              v-model.trim="newRow.subOf"
              type="text"
              placeholder="Motor"
              class="w-full border border-slate-200 rounded px-2 py-1 text-sm focus:outline-none focus:border-brand-400"
            />
          </div>
          <div>
            <label class="text-xs text-slate-500 block mb-1">{{ t('adminProductTypes.kind') }}</label>
            <select
              v-model="newRow.kind"
              class="w-full border border-slate-200 rounded px-2 py-1 text-sm bg-white focus:outline-none focus:border-brand-400"
            >
              <option v-for="opt in KIND_OPTIONS" :key="opt.value || 'none'" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div>
            <label class="text-xs text-slate-500 block mb-1">{{ t('adminProductTypes.tier') }}</label>
            <select
              v-model="newRow.tierId"
              class="w-full border border-slate-200 rounded px-2 py-1 text-sm bg-white focus:outline-none focus:border-brand-400"
            >
              <option v-for="tier in tiers" :key="tier.id" :value="tier.id">
                {{ tier.nameTh }}
              </option>
            </select>
          </div>
          <div class="flex items-end">
            <button
              type="button"
              class="w-full px-3 py-1.5 rounded bg-brand-500 text-white text-sm hover:bg-brand-600"
              @click="createRow"
            >
              <i class="pi pi-plus mr-1" /> {{ t('adminProductTypes.addBtn') }}
            </button>
          </div>
        </div>
      </section>
    </template>

    <!-- C-9 risk-schema editor modal. Full-width textarea because the
         JSON is 100-200 lines for a real schema; syntax highlighting is
         out of scope for the MVP (Monaco/CodeMirror bloat the bundle by
         500 KB+ each). Parse errors surface inline. Ship (b) form-based
         editor later per B4 §8 if authoring becomes frequent. -->
    <div v-if="editingRow"
      class="fixed inset-0 bg-slate-900/40 flex items-center justify-center z-50 p-4"
      @click.self="closeSchemaEditor">
      <div class="bg-white w-full max-w-3xl rounded-xl shadow-xl flex flex-col max-h-[90vh]">
        <header class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
          <div>
            <h2 class="text-lg font-semibold text-slate-900">
              {{ t('adminProductTypes.schemaModalTitle') }}
            </h2>
            <p class="text-xs text-slate-500 mt-0.5 font-mono">{{ editingRow.code }}</p>
          </div>
          <button class="text-slate-400 hover:text-slate-700 p-2" @click="closeSchemaEditor">
            <i class="pi pi-times" />
          </button>
        </header>

        <div class="flex-1 overflow-y-auto p-6 space-y-3">
          <div v-if="schemaError"
            class="p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs font-mono whitespace-pre-wrap">
            {{ schemaError }}
          </div>
          <p class="text-xs text-slate-500">
            {{ t('adminProductTypes.schemaHint') }}
          </p>
          <textarea
            v-model="schemaDraft"
            spellcheck="false"
            class="w-full h-96 border border-slate-200 rounded-lg p-3 text-xs font-mono focus:outline-none focus:border-brand-400"
          />
        </div>

        <footer class="px-6 py-4 border-t border-slate-200 flex justify-between gap-3">
          <button v-if="editingRow.riskSchema"
            type="button"
            class="px-3 py-1.5 rounded-lg text-sm text-rose-600 hover:bg-rose-50"
            :disabled="schemaSaving"
            @click="clearSchema"
          >
            <i class="pi pi-trash text-xs mr-1" /> {{ t('adminProductTypes.schemaClear') }}
          </button>
          <div v-else class="flex-1" />
          <div class="flex gap-3">
            <button type="button"
              class="px-4 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100"
              :disabled="schemaSaving"
              @click="closeSchemaEditor"
            >
              {{ t('adminProductTypes.cancel') }}
            </button>
            <button type="button"
              class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50"
              :disabled="schemaSaving"
              @click="saveSchema"
            >
              {{ schemaSaving ? t('adminProductTypes.saving') : t('adminProductTypes.save') }}
            </button>
          </div>
        </footer>
      </div>
    </div>
  </div>
</template>
