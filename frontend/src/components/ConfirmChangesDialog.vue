<script setup lang="ts">
import { useI18n } from 'vue-i18n'

export interface ConfirmChangeRow {
  key: string
  label: string
  from: string
  to: string
}

defineProps<{
  open: boolean
  rows: ConfirmChangeRow[]
  saving?: boolean
  error?: string | null
  errorList?: string[]
}>()

const emit = defineEmits<{
  (e: 'confirm'): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()
</script>

<template>
  <div v-if="open" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4"
    data-testid="confirm-changes-dialog" @click.self="!saving && emit('cancel')">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col">
      <header class="px-5 pt-5 pb-3 border-b border-slate-100">
        <h3 class="text-lg font-semibold text-slate-900">{{ t('customers.ui.confirmTitle') }}</h3>
        <p class="text-xs text-slate-500 mt-0.5">{{ t('customers.ui.confirmSubtitle') }} ({{ rows.length }})</p>
      </header>
      <div class="px-5 py-3 overflow-y-auto flex-1">
        <div v-if="!rows.length" class="text-sm text-slate-500 py-4 text-center">{{ t('customers.ui.noChanges') }}</div>
        <table v-else class="w-full text-sm">
          <thead class="text-xs text-slate-500">
            <tr>
              <th class="text-left font-medium py-1.5 pr-3 w-1/4">{{ t('customers.ui.fieldCol') }}</th>
              <th class="text-left font-medium py-1.5 pr-3">{{ t('customers.ui.fromCol') }}</th>
              <th class="w-6"></th>
              <th class="text-left font-medium py-1.5">{{ t('customers.ui.toCol') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in rows" :key="r.key" :data-testid="`confirm-row-${r.key}`">
              <td class="py-2 pr-3 text-slate-600 align-top">{{ r.label }}</td>
              <td class="py-2 pr-3 align-top">
                <span class="text-slate-500 line-through decoration-slate-300 break-words">{{ r.from || '—' }}</span>
              </td>
              <td class="py-2 text-slate-400 align-top"><i class="pi pi-arrow-right text-[10px]" /></td>
              <td class="py-2 align-top">
                <span class="text-emerald-700 font-medium break-words">{{ r.to || '—' }}</span>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="error" class="mt-3 p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">
          <div class="font-medium">{{ error }}</div>
          <ul v-if="errorList && errorList.length" class="list-disc ml-4 mt-1 space-y-0.5">
            <li v-for="(m, i) in errorList" :key="i">{{ m }}</li>
          </ul>
        </div>
      </div>
      <footer class="px-5 py-3 border-t border-slate-100 flex items-center justify-end gap-2">
        <button type="button" class="px-3 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100"
          :disabled="saving" @click="emit('cancel')">{{ t('customers.ui.cancel') }}</button>
        <button type="button" data-testid="confirm-changes-save"
          class="px-3 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50 flex items-center gap-1.5"
          :disabled="saving || !rows.length" @click="emit('confirm')">
          <i v-if="saving" class="pi pi-spin pi-spinner text-xs" />
          <i v-else class="pi pi-check text-xs" />
          {{ t('customers.ui.confirmSave') }}
        </button>
      </footer>
    </div>
  </div>
</template>
