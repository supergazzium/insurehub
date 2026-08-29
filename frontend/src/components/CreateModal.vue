<script setup lang="ts">
// Generic create-modal shell. Wraps a slotted form body and handles the
// POST + validation-error surfacing. Each specific create form (customer,
// policy, etc.) is a thin wrapper that fills in the slot + emits the
// payload via `submit(payload)`.

import { onBeforeUnmount, ref, watch } from 'vue'
import { api, ApiError } from '../api/client'

const props = defineProps<{
  open: boolean
  /** Entity path, e.g. "policies", "customers" */
  entity: string
  /** Human title, e.g. "New Customer" */
  title: string
  /** Payload to POST when the form's Save button is clicked. */
  payload: Record<string, unknown>
  /** True if the form's required fields are filled. */
  canSubmit: boolean
  /**
   * Optional pre-submit gate. Returning a non-empty array of messages
   * blocks the POST and surfaces the messages via `window.alert()` so
   * the operator sees WHY the save was refused instead of a silently
   * disabled button. When provided, the Save buttons stay clickable
   * even while `canSubmit` is false so this gate can run.
   */
  validate?: () => string[]
  /**
   * Hard block — force the Save buttons disabled regardless of `validate`.
   * Use for unrecoverable states (e.g. a duplicate customer) where clicking
   * should be impossible, not just gated by an alert. Defaults false.
   */
  hardBlock?: boolean
}>()

const emit = defineEmits<{
  (e: 'close'): void
  /** Fires after a successful POST; parent should reload the list. */
  (e: 'created', row: Record<string, unknown>): void
}>()

const saving = ref(false)
const error = ref<string | null>(null)
const flash = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

async function submit(keepOpen = false): Promise<void> {
  if (saving.value || props.hardBlock) return
  if (props.validate) {
    const problems = props.validate()
    if (problems.length > 0) {
      window.alert(`กรอกข้อมูลไม่ครบหรือไม่ถูกต้อง:\n\n• ${problems.join('\n• ')}`)
      return
    }
  }
  if (!props.canSubmit) return
  saving.value = true
  error.value = null
  flash.value = null
  fieldErrors.value = {}
  try {
    const res = await api.post<{ data: Record<string, unknown> }>(props.entity, props.payload)
    emit('created', res.data ?? res)
    if (keepOpen) {
      // Show a short confirmation and leave the modal open so the parent
      // can reset just the fields it wants and the user can keep typing.
      flash.value = 'บันทึกแล้ว — เพิ่มรายการถัดไปได้เลย'
      setTimeout(() => { flash.value = null }, 2200)
    } else {
      emit('close')
    }
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      error.value = e.body?.message ?? `HTTP ${e.status}`
      if (e.body?.errors) fieldErrors.value = e.body.errors
    } else {
      error.value = e instanceof Error ? e.message : 'Save failed'
    }
  } finally {
    saving.value = false
  }
}

// Enter submits (unless a textarea is focused so line breaks still work);
// Esc closes. Only bound while the modal is open.
function onKey(e: KeyboardEvent): void {
  if (!props.open) return
  if (e.key === 'Enter' && !(e.target instanceof HTMLTextAreaElement)) {
    e.preventDefault()
    void submit(false)
  } else if (e.key === 'Escape') {
    e.preventDefault()
    emit('close')
  }
}
window.addEventListener('keydown', onKey)
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// Reset error state when opening.
watch(
  () => props.open,
  (v) => {
    if (v) {
      error.value = null
      flash.value = null
      fieldErrors.value = {}
    }
  },
)
</script>

<template>
  <!-- Close only via the X in the header — no backdrop-close, so a stray click can't lose typed data. -->
  <div v-if="open" class="fixed inset-0 bg-slate-900/50 flex items-center justify-center z-[60] p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col">
      <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <div class="text-lg font-semibold text-slate-900">{{ title }}</div>
        <button type="button" class="text-slate-400 hover:text-slate-700 p-1" @click="emit('close')">
          <i class="pi pi-times" />
        </button>
      </header>

      <div class="flex-1 overflow-y-auto p-5">
        <!-- Slot receives fieldErrors so the child can surface per-field messages. -->
        <slot :fieldErrors="fieldErrors" />
        <div v-if="flash"
          class="mt-3 p-3 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700 text-sm flex items-center gap-2">
          <i class="pi pi-check-circle" /> {{ flash }}
        </div>
        <div v-if="error && Object.keys(fieldErrors).length === 0"
          class="mt-3 p-3 bg-rose-50 border border-rose-200 rounded-lg text-rose-700 text-sm">
          {{ error }}
        </div>
      </div>

      <footer class="px-5 py-3 border-t border-slate-200 flex items-center justify-between">
        <div class="text-xs text-slate-400">
          Enter บันทึก · Esc ปิด
        </div>
        <div class="flex items-center gap-2">
          <button type="button"
            class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 text-sm"
            :disabled="saving" @click="emit('close')">
            Cancel
          </button>
          <button type="button"
            class="px-3 py-1.5 rounded-lg border border-brand-500 text-brand-600 hover:bg-brand-50 text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1.5"
            :disabled="(!canSubmit && !validate) || hardBlock || saving" @click="submit(true)"
            title="บันทึกแล้วเปิดฟอร์มใหม่โดยคงประเภทประกันและบริษัทไว้">
            <i class="pi pi-plus text-xs" />
            บันทึกและเพิ่มอีก
          </button>
          <button type="button"
            class="px-4 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm disabled:bg-slate-300 disabled:cursor-not-allowed flex items-center gap-1.5"
            :disabled="(!canSubmit && !validate) || hardBlock || saving" @click="submit(false)">
            <i class="pi pi-check text-xs" v-if="!saving" />
            <i class="pi pi-spin pi-spinner text-xs" v-else />
            {{ saving ? 'Saving…' : 'Create' }}
          </button>
        </div>
      </footer>
    </div>
  </div>
</template>
