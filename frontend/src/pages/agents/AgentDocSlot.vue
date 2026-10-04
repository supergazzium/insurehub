<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { deleteAgentDocument, fetchAgentDocumentBlob, uploadAgentDocument, type AgentDocKind } from '../../api/agents'
import { ApiError } from '../../api/client'

const props = defineProps<{
  agentId: string
  kind: AgentDocKind
  label: string
  present: boolean
  canManage: boolean
}>()
const emit = defineEmits<{ (e: 'changed', data: Record<string, unknown>): void }>()

const url = ref('')
const isPdf = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const confirmDelete = ref(false)
const input = ref<HTMLInputElement | null>(null)

function revoke(): void {
  if (url.value) URL.revokeObjectURL(url.value)
  url.value = ''
}

async function loadBlob(): Promise<void> {
  revoke()
  if (!props.present || !props.canManage) return
  const blob = await fetchAgentDocumentBlob(props.agentId, props.kind).catch(() => null)
  if (!blob || blob.size === 0) return
  isPdf.value = blob.type === 'application/pdf'
  url.value = URL.createObjectURL(blob)
}

async function onFile(ev: Event): Promise<void> {
  const file = (ev.target as HTMLInputElement).files?.[0]
  ;(ev.target as HTMLInputElement).value = ''
  if (!file) return
  busy.value = true
  error.value = null
  try {
    const res = await uploadAgentDocument(props.agentId, props.kind, file)
    emit('changed', res.data)
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      error.value = Object.values(errs)[0]?.[0] ?? e.message
    } else {
      error.value = 'อัปโหลดไม่สำเร็จ'
    }
  } finally { busy.value = false }
}

async function remove(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    const res = await deleteAgentDocument(props.agentId, props.kind)
    confirmDelete.value = false
    emit('changed', res.data)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'ลบไม่สำเร็จ'
  } finally { busy.value = false }
}

function open(): void {
  if (url.value) window.open(url.value, '_blank')
}

watch(() => [props.present, props.agentId, props.canManage], () => { void loadBlob() }, { immediate: true })
onBeforeUnmount(revoke)
</script>

<template>
  <div class="rounded-lg border border-slate-200 p-2.5" :data-testid="`doc-${kind}`">
    <div class="mb-1.5 flex items-center justify-between gap-2">
      <span class="text-xs font-medium text-slate-600">{{ label }}</span>
      <span :class="['rounded px-1.5 py-0.5 text-[10px]', present ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500']">
        {{ present ? 'มีไฟล์แล้ว' : 'ยังไม่มี' }}
      </span>
    </div>

    <template v-if="canManage">
      <button v-if="url && !isPdf" type="button" class="block w-full overflow-hidden rounded border border-slate-100 bg-slate-50" @click="open">
        <img :src="url" :alt="label" class="mx-auto max-h-32 object-contain" />
      </button>
      <button v-else-if="url && isPdf" type="button" class="flex w-full items-center gap-2 rounded border border-slate-100 bg-slate-50 px-2 py-3 text-xs text-slate-600 hover:bg-slate-100" @click="open">
        <i class="pi pi-file-pdf text-rose-500" /> เปิดไฟล์ PDF
      </button>
      <div v-else-if="present" class="py-3 text-center text-[11px] text-slate-400"><i class="pi pi-spin pi-spinner text-[10px]" /> กำลังโหลด…</div>
      <div v-else class="rounded border border-dashed border-slate-200 py-3 text-center text-[11px] text-slate-400">ยังไม่ได้อัปโหลด</div>

      <input ref="input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="hidden" :data-testid="`doc-input-${kind}`" @change="onFile" />
      <div v-if="!confirmDelete" class="mt-2 flex gap-1.5">
        <button type="button" class="rounded border border-brand-200 px-2 py-1 text-[11px] text-brand-700 hover:bg-brand-50 disabled:opacity-50" :disabled="busy" @click="input?.click()">
          <i :class="busy ? 'pi pi-spin pi-spinner' : 'pi pi-upload'" class="text-[10px]" /> {{ present ? 'แทนที่' : 'อัปโหลด' }}
        </button>
        <button v-if="present" type="button" class="rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-500 hover:bg-slate-50 disabled:opacity-50" :disabled="busy" @click="confirmDelete = true">ลบ</button>
      </div>
      <div v-else class="mt-2 flex items-center gap-1.5 text-[11px]">
        <span class="text-rose-600">ลบไฟล์นี้?</span>
        <button type="button" class="rounded bg-rose-600 px-2 py-0.5 text-white disabled:opacity-50" :disabled="busy" @click="remove">ลบ</button>
        <button type="button" class="rounded border border-slate-300 px-2 py-0.5 text-slate-600" @click="confirmDelete = false">ยกเลิก</button>
      </div>
      <p v-if="error" class="mt-1 text-[11px] text-rose-600">{{ error }}</p>
    </template>
    <p v-else class="text-[11px] text-slate-400"><i class="pi pi-lock text-[9px]" /> ดูไฟล์ได้เฉพาะผู้มีสิทธิ์แก้ไขตัวแทน</p>
  </div>
</template>
