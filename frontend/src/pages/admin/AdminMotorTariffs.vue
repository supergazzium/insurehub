<script setup lang="ts">
// Phase 9 — admin CRUD for motor ACT tariffs. Tiny surface: list + inline
// edit-in-place for premium/active + add row + delete.
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  fetchMotorTariffs, createMotorTariff, updateMotorTariff, deleteMotorTariff,
  type MotorActTariff,
} from '../../api/motorTariffs'
import { ApiError } from '../../api/client'

const { t } = useI18n()
const rows = ref<MotorActTariff[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const savingId = ref<string | null>(null)

const add = reactive({ vehicleTypeCode: '', labelTh: '', labelEn: '', premium: 0 })
const adding = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchMotorTariffs()
    rows.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function savePremium(r: MotorActTariff): Promise<void> {
  savingId.value = r.id
  try {
    const res = await updateMotorTariff(r.id, { premium: r.premium })
    Object.assign(r, res.data)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Save failed.'
  } finally { savingId.value = null }
}

async function toggleActive(r: MotorActTariff): Promise<void> {
  savingId.value = r.id
  try {
    const res = await updateMotorTariff(r.id, { active: !r.active })
    Object.assign(r, res.data)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Save failed.'
  } finally { savingId.value = null }
}

async function doDelete(r: MotorActTariff): Promise<void> {
  if (!window.confirm(t('adminTariffs.confirmDelete', { code: r.vehicleTypeCode }))) return
  savingId.value = r.id
  try {
    await deleteMotorTariff(r.id)
    rows.value = rows.value.filter((x) => x.id !== r.id)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Delete failed.'
  } finally { savingId.value = null }
}

async function doAdd(): Promise<void> {
  if (!add.vehicleTypeCode.trim() || !add.labelTh.trim()) return
  adding.value = true
  error.value = null
  try {
    const res = await createMotorTariff({
      vehicleTypeCode: add.vehicleTypeCode.trim(),
      labelTh: add.labelTh.trim(),
      labelEn: add.labelEn.trim() || undefined,
      premium: Number(add.premium) || 0,
    })
    rows.value.push(res.data)
    Object.assign(add, { vehicleTypeCode: '', labelTh: '', labelEn: '', premium: 0 })
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Create failed.'
  } finally { adding.value = false }
}
</script>

<template>
  <div class="space-y-6 max-w-3xl">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('adminTariffs.title') }}</h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('adminTariffs.subtitle') }}</p>
    </header>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">{{ error }}</div>

    <section class="card overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th class="px-4 py-2 text-left w-20">{{ t('adminTariffs.code') }}</th>
            <th class="px-4 py-2 text-left">{{ t('adminTariffs.labelTh') }}</th>
            <th class="px-4 py-2 text-left">{{ t('adminTariffs.labelEn') }}</th>
            <th class="px-4 py-2 text-right w-32">{{ t('adminTariffs.premium') }}</th>
            <th class="px-4 py-2 text-center w-24">{{ t('adminTariffs.active') }}</th>
            <th class="px-4 py-2 w-16"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!rows.length && !loading">
            <td colspan="6" class="text-center py-8 text-slate-400 text-xs">{{ t('adminTariffs.empty') }}</td>
          </tr>
          <tr v-for="r in rows" :key="r.id" :class="{ 'opacity-60': savingId === r.id, 'bg-slate-50': !r.active }">
            <td class="px-4 py-2 font-mono text-xs">{{ r.vehicleTypeCode }}</td>
            <td class="px-4 py-2">{{ r.labelTh }}</td>
            <td class="px-4 py-2 text-slate-500 text-xs">{{ r.labelEn || '—' }}</td>
            <td class="px-4 py-2 text-right">
              <input v-model.number="r.premium" type="number" step="0.01" min="0"
                class="w-24 border border-slate-200 rounded px-2 py-1 text-right text-sm font-mono"
                @change="savePremium(r)" />
            </td>
            <td class="px-4 py-2 text-center">
              <button type="button"
                :class="r.active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                class="inline-flex px-2 py-0.5 rounded text-xs" @click="toggleActive(r)">
                {{ r.active ? t('adminTariffs.on') : t('adminTariffs.off') }}
              </button>
            </td>
            <td class="px-4 py-2 text-right">
              <button type="button" class="text-slate-400 hover:text-rose-600 p-1" @click="doDelete(r)">
                <i class="pi pi-trash text-xs" />
              </button>
            </td>
          </tr>
          <tr class="bg-slate-50/50 border-t-2 border-slate-200">
            <td class="px-4 py-2">
              <input v-model.trim="add.vehicleTypeCode" placeholder="EV1"
                class="w-full border border-slate-200 rounded px-2 py-1 text-xs font-mono" />
            </td>
            <td class="px-4 py-2">
              <input v-model.trim="add.labelTh" placeholder="รถยนต์ไฟฟ้า"
                class="w-full border border-slate-200 rounded px-2 py-1 text-sm" />
            </td>
            <td class="px-4 py-2">
              <input v-model.trim="add.labelEn" placeholder="Electric Vehicle"
                class="w-full border border-slate-200 rounded px-2 py-1 text-sm" />
            </td>
            <td class="px-4 py-2">
              <input v-model.number="add.premium" type="number" step="0.01" min="0"
                class="w-full border border-slate-200 rounded px-2 py-1 text-right text-sm font-mono" />
            </td>
            <td class="px-4 py-2"></td>
            <td class="px-4 py-2 text-right">
              <button type="button" class="px-2 py-1 rounded bg-brand-600 text-white text-xs hover:bg-brand-700 disabled:opacity-50"
                :disabled="adding || !add.vehicleTypeCode.trim() || !add.labelTh.trim()"
                @click="doAdd">
                <i class="pi pi-plus text-[10px]" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
