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
  type CommissionTier, type ProductType,
} from '../../api/mgm'

const { t } = useI18n()

const types = ref<ProductType[]>([])
const tiers = ref<CommissionTier[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const savingId = ref<string | null>(null)
const deletingId = ref<string | null>(null)

const newRow = reactive({
  code: '',
  nameTh: '',
  nameEn: '',
  subOf: '',
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
      tierId: Number(newRow.tierId),
      active: newRow.active,
      notes: newRow.notes.trim() || null,
    })
    types.value.push(res.data)
    // Reset new row (keep tierId + subOf for rapid entry)
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
  </div>
</template>
