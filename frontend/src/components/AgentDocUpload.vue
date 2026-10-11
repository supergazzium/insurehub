<script setup lang="ts">
// One document-photo slot on the admin agent form (#22 license life/non-life,
// #24 id-card + book-bank). Pick an image → POST to the authenticated admin
// endpoint → show a thumbnail. Photos live on the backend's private `local`
// disk, so the preview is fetched with the bearer token and wrapped in a
// blob URL (same approach as the agent portal).
import { ref, onBeforeUnmount, watch } from 'vue'
import { uploadAgentPhoto, type AgentPhotoKind } from '../api/agents'
import { getToken, API_BASE_URL, ApiError } from '../api/client'

const props = defineProps<{
  agentId: string | null        // null in create mode → upload disabled
  kind: AgentPhotoKind
  label: string
  uploaded: boolean             // from AgentResource.photos.*
}>()

const emit = defineEmits<{ (e: 'uploaded'): void }>()

const busy = ref(false)
const error = ref<string | null>(null)
const blobUrl = ref('')
const has = ref(props.uploaded)
const fileInput = ref<HTMLInputElement | null>(null)

watch(() => props.uploaded, (v) => { has.value = v })

async function refreshPreview(): Promise<void> {
  if (blobUrl.value) { URL.revokeObjectURL(blobUrl.value); blobUrl.value = '' }
  if (!props.agentId || !has.value) return
  const token = getToken()
  if (!token) return
  try {
    const res = await fetch(`${API_BASE_URL}/agents/${props.agentId}/photo/${props.kind}?v=${Date.now()}`, {
      headers: { Authorization: `Bearer ${token}` },
      cache: 'no-store',
    })
    if (!res.ok) return
    const blob = await res.blob()
    if (blob.size === 0) return
    blobUrl.value = URL.createObjectURL(blob)
  } catch { /* preview is best-effort */ }
}

watch(() => [props.agentId, has.value], () => { void refreshPreview() }, { immediate: true })

function pick(): void { fileInput.value?.click() }

async function onFile(e: Event): Promise<void> {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file || !props.agentId) return
  busy.value = true
  error.value = null
  try {
    await uploadAgentPhoto(props.agentId, props.kind, file)
    has.value = true
    await refreshPreview()
    emit('uploaded')
  } catch (err: unknown) {
    error.value = err instanceof ApiError ? err.message : 'อัปโหลดไม่สำเร็จ'
  } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

onBeforeUnmount(() => { if (blobUrl.value) URL.revokeObjectURL(blobUrl.value) })
</script>

<template>
  <div class="rounded-lg border border-slate-200 p-3">
    <div class="mb-2 flex items-center justify-between gap-2">
      <span class="text-xs font-medium text-slate-600">{{ label }}</span>
      <span v-if="has" class="inline-flex items-center gap-1 text-[11px] text-emerald-600">
        <i class="pi pi-check-circle text-[10px]" /> อัปโหลดแล้ว
      </span>
    </div>

    <div v-if="blobUrl" class="mb-2">
      <img :src="blobUrl" alt="" class="max-h-28 rounded border border-slate-200 object-contain" />
    </div>

    <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFile" />

    <button
      type="button"
      :disabled="!agentId || busy"
      class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50"
      @click="pick"
    >
      <i :class="['pi text-[10px]', busy ? 'pi-spin pi-spinner' : (has ? 'pi-refresh' : 'pi-upload')]" />
      {{ has ? 'เปลี่ยนรูป' : 'อัปโหลดรูป' }}
    </button>

    <p v-if="!agentId" class="mt-1.5 text-[11px] text-slate-400">บันทึกตัวแทนก่อนจึงอัปโหลดเอกสารได้</p>
    <p v-if="error" class="mt-1.5 text-[11px] text-rose-600">{{ error }}</p>
  </div>
</template>
