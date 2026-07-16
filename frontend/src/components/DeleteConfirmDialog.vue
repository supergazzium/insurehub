<script setup lang="ts">
// Small confirm dialog for destructive actions. Requires the user to type
// the entity's identifier before Delete becomes enabled.

import { computed, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  /** Human label, e.g. "policy A2001030001" */
  label: string
  /** The exact string the user must type to confirm — usually a code/id. */
  confirmToken: string
  loading?: boolean
  error?: string | null
}>()

const emit = defineEmits<{
  (e: 'confirm'): void
  (e: 'cancel'): void
}>()

const typed = ref('')
const canConfirm = computed(() => typed.value.trim() === props.confirmToken.trim() && !props.loading)

watch(
  () => props.open,
  (v) => {
    if (v) typed.value = ''
  },
)

function submit(): void {
  if (canConfirm.value) emit('confirm')
}
</script>

<template>
  <div v-if="open" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4" @click.self="emit('cancel')">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-5 space-y-4">
      <div>
        <div class="flex items-center gap-2 text-rose-600 text-sm font-medium">
          <i class="pi pi-exclamation-triangle" />
          Delete confirmation
        </div>
        <p class="text-sm text-slate-700 mt-2">
          You're about to delete <span class="font-medium text-slate-900">{{ label }}</span>.
          This is a soft-delete — records can be restored later.
        </p>
        <p class="text-xs text-slate-500 mt-2">
          Type <code class="bg-slate-100 px-1 rounded font-mono text-slate-700">{{ confirmToken }}</code> to confirm.
        </p>
      </div>
      <input
        v-model.trim="typed"
        type="text"
        class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-rose-400"
        :placeholder="confirmToken"
        @keydown.enter="submit"
        @keydown.escape="emit('cancel')"
      />
      <div v-if="error" class="text-xs text-rose-600">{{ error }}</div>
      <div class="flex items-center justify-end gap-2 pt-2">
        <button
          type="button"
          class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50"
          :disabled="loading"
          @click="emit('cancel')"
        >
          Cancel
        </button>
        <button
          type="button"
          class="px-3 py-1.5 rounded-lg bg-rose-600 text-white hover:bg-rose-700 disabled:bg-slate-300 disabled:cursor-not-allowed"
          :disabled="!canConfirm"
          @click="submit"
        >
          {{ loading ? 'Deleting…' : 'Delete' }}
        </button>
      </div>
    </div>
  </div>
</template>
