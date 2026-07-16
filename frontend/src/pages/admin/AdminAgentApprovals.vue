<script setup lang="ts">
// Admin queue for reviewing self-registered agent applications.
// Each row expands to show the submitted profile + audit trail.
import { onMounted, reactive, ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  approveAgent, rejectAgent, fetchPendingAgents, fetchAgentAudit,
  type AuditEntry,
} from '../../api/adminAgents'
import type { MyAgent } from '../../api/portal'
import { ApiError } from '../../api/client'

const { t } = useI18n()

const pending = ref<MyAgent[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const expandedId = ref<string | null>(null)
const auditByAgent = reactive<Record<string, AuditEntry[]>>({})
const auditLoading = ref<string | null>(null)

const rejectingId = ref<string | null>(null)
const rejectNote = ref('')
const actionSaving = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchPendingAgents()
    pending.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load pending agents.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function toggleExpand(id: string): Promise<void> {
  if (expandedId.value === id) { expandedId.value = null; return }
  expandedId.value = id
  if (!auditByAgent[id]) {
    auditLoading.value = id
    try {
      const res = await fetchAgentAudit(id)
      auditByAgent[id] = res.data
    } catch { /* silent */ }
    finally { auditLoading.value = null }
  }
}

async function doApprove(a: MyAgent): Promise<void> {
  if (!window.confirm(`Approve ${a.firstName} ${a.lastName} (${a.agentCode})?`)) return
  actionSaving.value = a.id
  try {
    await approveAgent(a.id)
    pending.value = pending.value.filter((x) => x.id !== a.id)
    delete auditByAgent[a.id]
    if (expandedId.value === a.id) expandedId.value = null
  } catch (e: unknown) {
    alert(e instanceof ApiError ? e.message : 'Approve failed')
  } finally {
    actionSaving.value = null
  }
}

function startReject(id: string): void {
  rejectingId.value = id
  rejectNote.value = ''
}

async function submitReject(a: MyAgent): Promise<void> {
  if (!rejectNote.value.trim()) return
  actionSaving.value = a.id
  try {
    await rejectAgent(a.id, rejectNote.value.trim())
    pending.value = pending.value.filter((x) => x.id !== a.id)
    delete auditByAgent[a.id]
    rejectingId.value = null
    rejectNote.value = ''
  } catch (e: unknown) {
    alert(e instanceof ApiError ? e.message : 'Reject failed')
  } finally {
    actionSaving.value = null
  }
}

const totalPending = computed(() => pending.value.length)
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('adminAgents.title') }}</h1>
        <p class="text-sm text-slate-500 mt-1">
          {{ t('adminAgents.subtitle', { n: totalPending }) }}
        </p>
      </div>
      <button type="button" class="text-sm text-slate-500 hover:text-brand-600 flex items-center gap-1"
        @click="load">
        <i :class="loading ? 'pi pi-spin pi-spinner' : 'pi pi-refresh'" class="text-xs" />
        {{ t('adminAgents.refresh') }}
      </button>
    </header>

    <div v-if="error" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <div v-if="loading && !pending.length" class="card p-6 text-slate-500 text-sm">Loading…</div>

    <div v-else-if="!pending.length" class="card p-6 text-center text-slate-500 text-sm">
      <i class="pi pi-check-circle text-2xl text-emerald-500 mb-2" />
      <div>{{ t('adminAgents.empty') }}</div>
    </div>

    <div v-else class="space-y-3">
      <div v-for="a in pending" :key="a.id" class="card">
        <!-- Row -->
        <div class="p-4 flex items-center gap-4">
          <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400">
            <i class="pi pi-user" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="font-medium text-slate-900">{{ a.firstName }} {{ a.lastName }}</span>
              <span class="font-mono text-xs text-slate-500">{{ a.agentCode }}</span>
              <span v-if="a.signupType === 'corporate'" class="inline-flex px-1.5 py-0.5 rounded bg-violet-50 text-violet-700 text-[10px]">corporate</span>
            </div>
            <div class="text-xs text-slate-500 mt-0.5">
              {{ a.email }} · {{ a.phone || '—' }} · {{ t('adminAgents.joinedAt') }}: {{ a.joinedAt || '—' }}
            </div>
          </div>
          <button type="button" class="text-xs text-slate-500 hover:text-brand-600 flex items-center gap-1"
            @click="toggleExpand(a.id)">
            <i :class="expandedId === a.id ? 'pi pi-chevron-up' : 'pi pi-chevron-down'" class="text-[10px]" />
            {{ t('adminAgents.details') }}
          </button>
          <div class="flex items-center gap-2">
            <button type="button"
              class="px-3 py-1.5 rounded-md text-xs bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50"
              :disabled="actionSaving === a.id"
              @click="doApprove(a)">
              <i v-if="actionSaving === a.id" class="pi pi-spin pi-spinner mr-1 text-[10px]" />
              <i v-else class="pi pi-check text-[10px] mr-1" />
              {{ t('adminAgents.approve') }}
            </button>
            <button type="button"
              class="px-3 py-1.5 rounded-md text-xs border border-rose-300 text-rose-700 hover:bg-rose-50"
              @click="startReject(a.id)">
              <i class="pi pi-times text-[10px] mr-1" />
              {{ t('adminAgents.reject') }}
            </button>
          </div>
        </div>

        <!-- Expanded details -->
        <div v-if="expandedId === a.id" class="border-t border-slate-100 p-4 space-y-4 bg-slate-50/50">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><div class="text-xs text-slate-400">{{ t('portal.field.idCardCurrent') }}</div>
              <div class="font-mono text-slate-700">{{ a.idCardMasked || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">{{ t('portal.field.birthDate') }}</div>
              <div class="text-slate-700">{{ a.birthDate || '—' }}</div></div>
            <div><div class="text-xs text-slate-400">{{ t('portal.field.lineId') }}</div>
              <div class="text-slate-700">{{ a.lineId || '—' }}</div></div>
            <div v-if="a.juristicName">
              <div class="text-xs text-slate-400">{{ t('agentRegister.juristicName') }}</div>
              <div class="text-slate-700">{{ a.juristicName }}</div>
            </div>
          </div>

          <div>
            <div class="text-xs uppercase tracking-wider text-slate-400 mb-2">{{ t('adminAgents.auditTrail') }}</div>
            <div v-if="auditLoading === a.id" class="text-xs text-slate-500">Loading…</div>
            <ul v-else-if="auditByAgent[a.id]?.length" class="text-xs space-y-1.5">
              <li v-for="e in auditByAgent[a.id]" :key="e.id" class="flex items-center gap-2 text-slate-600">
                <i class="pi pi-circle-fill text-slate-300 text-[6px]" />
                <span class="font-mono">{{ e.occurredAt?.slice(0, 19).replace('T', ' ') }}</span>
                <span class="font-medium text-slate-800">{{ e.action }}</span>
                <span>by {{ e.actor }}</span>
              </li>
            </ul>
            <div v-else class="text-xs text-slate-400">{{ t('adminAgents.noAudit') }}</div>
          </div>
        </div>

        <!-- Reject modal (inline for simplicity) -->
        <div v-if="rejectingId === a.id" class="border-t border-slate-100 p-4 bg-rose-50/50">
          <div class="text-sm font-medium text-rose-800 mb-2">{{ t('adminAgents.rejectTitle') }}</div>
          <textarea v-model="rejectNote" rows="3"
            :placeholder="t('adminAgents.rejectNotePlaceholder')"
            class="w-full border border-rose-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-rose-400" />
          <div class="mt-2 flex items-center justify-end gap-2">
            <button type="button" class="px-3 py-1.5 text-xs text-slate-500 hover:text-slate-700"
              @click="rejectingId = null">
              {{ t('common.cancel') }}
            </button>
            <button type="button"
              class="px-3 py-1.5 rounded-md text-xs bg-rose-600 text-white hover:bg-rose-700 disabled:opacity-50"
              :disabled="!rejectNote.trim() || actionSaving === a.id"
              @click="submitReject(a)">
              <i v-if="actionSaving === a.id" class="pi pi-spin pi-spinner mr-1 text-[10px]" />
              {{ t('adminAgents.confirmReject') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
