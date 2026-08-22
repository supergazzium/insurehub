<script setup lang="ts">
// C-8 — Issue Policy modal. Handles the Approved → Issued transition.
// See docs/audit-2026-08-21/B5-issue-modal.md for the full spec.
//
// Trigger points (rendered elsewhere; see PolicyDetailDrawer.vue +
// PolicyListV2.vue rowActions):
//   - Drawer header action "ออกกรมธรรม์" when policy.status === 'approved'
//   - List row kebab menu item when row.status === 'approved'
//
// Flow:
//   1. POST /policies/{id}/issue → 200 (status flips + fields set + event
//      written atomically)
//   2. If a cert file was selected: POST /policies/{id}/documents/upload
//      type=policy. Failure raises a non-blocking warning banner but does
//      NOT roll back the issue.
//   3. Emit @issued(updatedPolicy) so the caller can refresh cached state.

import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DateInput from '../../components/DateInput.vue'
import FormField from '../../components/FormField.vue'
import { fetchPolicy, issuePolicy, uploadPolicyDocument, type IssuePolicyPayload,
  type DuplicatePolicyNoError } from '../../api/policies'
import { ApiError } from '../../api/client'
import type { Policy } from '../../stores/policies'

const { t } = useI18n()

const props = defineProps<{
  open: boolean
  policyId: string | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'issued', policy: Policy): void
}>()

// ── Modal state ──────────────────────────────────────────────────────────

const loading = ref(false)
const submitting = ref(false)
const error = ref<string | null>(null)
const uploadWarn = ref<string | null>(null)
const duplicate = ref<DuplicatePolicyNoError['existing'] | null>(null)

// Underlying policy — loaded on open when policyId is set. Provides the
// prefill defaults for issue_date/period_paid_end/policy_end.
const policy = ref<Policy | null>(null)

// Form state
const form = reactive<{
  policyNo: string
  issueDate: string
  periodPaidEnd: string
  policyEnd: string
  mailingAddByPolicy: string
  mailingDate: string
  mailingNote: string
}>({
  policyNo: '',
  issueDate: '',
  periodPaidEnd: '',
  policyEnd: '',
  mailingAddByPolicy: '',
  mailingDate: '',
  mailingNote: '',
})

// Touched flags so auto-fills only fire when the operator hasn't edited
// the field yet (mirrors CustomerCreateModal pattern).
const touched = reactive({ periodPaidEnd: false, policyEnd: false })

// Certificate file (optional; uploaded in a second call after issue).
const certFile = ref<File | null>(null)

// ── Prefill on open ──────────────────────────────────────────────────────

async function loadPolicy(): Promise<void> {
  if (props.policyId === null) return
  loading.value = true
  error.value = null
  try {
    const res = await fetchPolicy(props.policyId)
    policy.value = res.data as unknown as Policy
    applyDefaults()
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('policyIssue.error.generic')
  } finally {
    loading.value = false
  }
}

function applyDefaults(): void {
  const p = policy.value
  if (!p) return
  // issue_date defaults to today (Asia/Bangkok is the browser tz on
  // operator machines; the backend accepts any ISO date and stores it
  // as-is per the DATE column type).
  form.issueDate = toIsoDate(new Date())
  // period_paid_end / policy_end mirror expiryDate by default. If a
  // multi-year product is picked, the operator can bump.
  form.periodPaidEnd = p.expiryDate ?? ''
  form.policyEnd = p.expiryDate ?? ''
  // Prefill mailing from customer address if the policy already has
  // one; otherwise leave blank for the operator.
  const nested = (p as unknown as { mailing?: { address?: string } }).mailing
  form.mailingAddByPolicy = nested?.address ?? ''
  // Reset the rest.
  form.policyNo = ''
  form.mailingDate = ''
  form.mailingNote = ''
  certFile.value = null
  touched.periodPaidEnd = false
  touched.policyEnd = false
  duplicate.value = null
  uploadWarn.value = null
}

function toIsoDate(d: Date): string {
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
}

// Re-fetch whenever the modal opens with a new id.
watch(() => [props.open, props.policyId] as const, ([open]) => {
  if (open) void loadPolicy()
})

// Recompute period_paid_end / policy_end when issue_date changes AND the
// operator hasn't manually edited those fields.
watch(() => form.issueDate, () => {
  const p = policy.value
  if (!p) return
  if (!touched.periodPaidEnd && p.expiryDate) form.periodPaidEnd = p.expiryDate
  if (!touched.policyEnd && p.expiryDate) form.policyEnd = p.expiryDate
})

// ── Validation ────────────────────────────────────────────────────────────

const errPolicyNo = computed<string | null>(() => {
  if (form.policyNo.trim() === '') return t('policyIssue.error.policyNoRequired')
  return null
})
const errIssueDate = computed<string | null>(() => {
  if (form.issueDate === '') return t('policyIssue.error.issueDateRequired')
  const today = toIsoDate(new Date())
  if (form.issueDate > today) return t('policyIssue.error.issueDateFuture')
  return null
})

const canSubmit = computed<boolean>(() =>
  errPolicyNo.value === null && errIssueDate.value === null && !submitting.value,
)

// ── Submit ────────────────────────────────────────────────────────────────

async function submit(force = false): Promise<void> {
  if (!canSubmit.value || props.policyId === null) return
  submitting.value = true
  error.value = null
  uploadWarn.value = null
  if (!force) duplicate.value = null

  const payload: IssuePolicyPayload = {
    policyNo: form.policyNo.trim(),
    issueDate: form.issueDate,
    periodPaidEnd: form.periodPaidEnd || null,
    policyEnd: form.policyEnd || null,
    mailingAddByPolicy: form.mailingAddByPolicy.trim() || null,
    mailingDate: form.mailingDate || null,
    mailingNote: form.mailingNote.trim() || null,
  }

  try {
    const res = await issuePolicy(props.policyId, payload, { force })
    const issued = res.data as unknown as Policy

    // Second call: upload certificate if attached. Non-blocking failure —
    // operator can retry via the attachments modal.
    if (certFile.value !== null) {
      try {
        await uploadPolicyDocument(props.policyId, 'policy', certFile.value)
      } catch {
        uploadWarn.value = t('policyIssue.error.uploadFailed')
      }
    }

    emit('issued', issued)
    if (uploadWarn.value === null) emit('close')
  } catch (e) {
    if (e instanceof ApiError) {
      const body = e.body as { code?: string; existing?: DuplicatePolicyNoError['existing']; message?: string } | undefined
      if (body?.code === 'duplicate_policy_no' && body.existing) {
        duplicate.value = body.existing
      } else if (body?.code === 'invalid_transition') {
        error.value = t('policyIssue.error.notApproved')
      } else {
        error.value = body?.message ?? e.message ?? t('policyIssue.error.generic')
      }
    } else {
      error.value = e instanceof Error ? e.message : t('policyIssue.error.generic')
    }
  } finally {
    submitting.value = false
  }
}

function confirmDuplicate(): void {
  void submit(true)
}

function pickFile(e: Event): void {
  const target = e.target as HTMLInputElement
  certFile.value = target.files?.[0] ?? null
}
</script>

<template>
  <div v-if="open" class="fixed inset-0 bg-slate-900/40 flex items-center justify-center z-50 p-4" @click.self="emit('close')">
    <div class="bg-white w-full max-w-lg rounded-xl shadow-xl overflow-hidden flex flex-col max-h-[90vh]">
      <header class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-900">{{ t('policyIssue.title') }}</h2>
        <button class="text-slate-400 hover:text-slate-700 p-2" @click="emit('close')">
          <i class="pi pi-times" />
        </button>
      </header>

      <div class="flex-1 overflow-y-auto p-6 space-y-4">
        <div v-if="loading" class="text-slate-500 text-sm">{{ t('common.loading') }}</div>

        <div v-else-if="error" class="p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
          {{ error }}
        </div>

        <div v-if="duplicate" class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm space-y-2">
          <div>{{ t('policyIssue.error.duplicatePolicyNo', { ref: duplicate.applicationNo || duplicate.quoteNo || duplicate.id }) }}</div>
          <button type="button" @click="confirmDuplicate"
            class="text-xs px-2 py-1 rounded bg-amber-600 text-white hover:bg-amber-700">
            {{ t('policyIssue.confirmDuplicate') }}
          </button>
        </div>

        <div v-if="uploadWarn" class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
          {{ uploadWarn }}
        </div>

        <template v-if="!loading && policy">
          <FormField :label="t('policyIssue.f.policyNo') + ' *'" :hint="t('policyIssue.hint.policyNo')">
            <input v-model="form.policyNo" type="text" maxlength="64"
              class="w-full border rounded-lg px-3 py-1.5 text-sm focus:outline-none"
              :class="errPolicyNo && form.policyNo !== '' ? 'border-rose-400' : 'border-slate-200 focus:border-brand-400'" />
          </FormField>

          <FormField :label="t('policyIssue.f.issueDate') + ' *'" :hint="t('policyIssue.hint.issueDate')">
            <DateInput v-model="form.issueDate" :max="toIsoDate(new Date())" />
          </FormField>

          <div class="grid grid-cols-2 gap-4">
            <FormField :label="t('policyIssue.f.periodPaidEnd')" :hint="t('policyIssue.hint.periodPaidEnd')">
              <DateInput v-model="form.periodPaidEnd" @update:model-value="touched.periodPaidEnd = true" />
            </FormField>
            <FormField :label="t('policyIssue.f.policyEnd')" :hint="t('policyIssue.hint.policyEnd')">
              <DateInput v-model="form.policyEnd" @update:model-value="touched.policyEnd = true" />
            </FormField>
          </div>

          <FormField :label="t('policyIssue.f.mailingAddByPolicy')" :hint="t('policyIssue.hint.mailingAddByPolicy')">
            <textarea v-model="form.mailingAddByPolicy" rows="2" maxlength="255"
              class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
          </FormField>

          <div class="grid grid-cols-2 gap-4">
            <FormField :label="t('policyIssue.f.mailingDate')">
              <DateInput v-model="form.mailingDate" />
            </FormField>
            <FormField :label="t('policyIssue.f.mailingNote')">
              <input v-model="form.mailingNote" type="text"
                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-brand-400" />
            </FormField>
          </div>

          <FormField :label="t('policyIssue.f.certificate')">
            <input type="file" accept="application/pdf,image/*" @change="pickFile"
              class="text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" />
            <p v-if="certFile" class="text-xs text-slate-500 mt-1">{{ certFile.name }}</p>
          </FormField>
        </template>
      </div>

      <footer class="px-6 py-4 border-t border-slate-200 flex justify-end gap-3">
        <button type="button" class="px-4 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-100"
          :disabled="submitting" @click="emit('close')">
          {{ t('policyIssue.cancel') }}
        </button>
        <button type="button"
          class="px-4 py-1.5 rounded-lg text-sm bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="!canSubmit" @click="submit(false)">
          {{ submitting ? t('policyIssue.submitting') : t('policyIssue.submit') }}
        </button>
      </footer>
    </div>
  </div>
</template>
