<script setup lang="ts">
// Minimal admin user-account page — read-only identity + change-password.
// Reuses the Phase 2 changePassword() endpoint (POST /auth/change-password).
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../../stores/auth'
import { changePassword } from '../../api/portal'
import { ApiError } from '../../api/client'

const { t } = useI18n()
const auth = useAuthStore()

const form = reactive({
  currentPassword: '',
  newPassword: '',
  confirmPassword: '',
})
const saving = ref(false)
const done = ref(false)
const error = ref<string | null>(null)

const canSubmit = computed(() =>
  form.currentPassword !== '' &&
  form.newPassword.length >= 8 &&
  form.newPassword === form.confirmPassword,
)

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  saving.value = true
  error.value = null
  done.value = false
  try {
    await changePassword(form.currentPassword, form.newPassword)
    done.value = true
    Object.assign(form, { currentPassword: '', newPassword: '', confirmPassword: '' })
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Password change failed.'
  } finally {
    saving.value = false
    setTimeout(() => { done.value = false }, 3000)
  }
}

const roleBadgeClass = computed(() => {
  const r = auth.user?.role
  if (r === 'super_admin') return 'bg-violet-50 text-violet-700'
  if (r === 'admin') return 'bg-brand-50 text-brand-700'
  if (r === 'staff') return 'bg-slate-100 text-slate-700'
  if (r === 'agent') return 'bg-emerald-50 text-emerald-700'
  return 'bg-slate-100 text-slate-500'
})
</script>

<template>
  <div class="space-y-6 max-w-2xl">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('userAccount.title') }}</h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('userAccount.subtitle') }}</p>
    </header>

    <!-- Identity card (read-only) -->
    <section class="card p-5">
      <h2 class="text-sm font-semibold text-slate-600 mb-4">{{ t('userAccount.identity') }}</h2>
      <div class="flex items-center gap-4 mb-4">
        <div class="w-16 h-16 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center text-2xl font-semibold">
          {{ (auth.user?.name || '?').charAt(0).toUpperCase() }}
        </div>
        <div>
          <div class="font-semibold text-slate-900 text-lg">{{ auth.user?.name || '—' }}</div>
          <div class="text-sm text-slate-500 mt-0.5">{{ auth.user?.email || '—' }}</div>
        </div>
      </div>
      <dl class="grid grid-cols-2 gap-4 text-sm">
        <div><dt class="text-xs text-slate-400">{{ t('userAccount.role') }}</dt>
          <dd class="mt-1"><span :class="['inline-flex px-2 py-0.5 rounded text-xs', roleBadgeClass]">{{ auth.user?.role || '—' }}</span></dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('userAccount.locale') }}</dt>
          <dd class="text-slate-900 mt-1">{{ auth.user?.locale || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('userAccount.tenantId') }}</dt>
          <dd class="text-slate-900 mt-1 font-mono text-xs">{{ auth.user?.tenantId || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-400">{{ t('userAccount.userId') }}</dt>
          <dd class="text-slate-900 mt-1 font-mono text-xs">{{ auth.user?.id || '—' }}</dd></div>
      </dl>
      <p class="text-xs text-slate-400 mt-4">{{ t('userAccount.identityHint') }}</p>
    </section>

    <!-- Change password -->
    <form class="card p-5 space-y-4" @submit.prevent="submit">
      <h2 class="text-sm font-semibold text-slate-600">{{ t('userAccount.changePassword') }}</h2>

      <div v-if="error" class="px-3 py-2 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        {{ error }}
      </div>
      <div v-if="done" class="px-3 py-2 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">
        {{ t('userAccount.passwordChanged') }}
      </div>

      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('userAccount.currentPassword') }}</label>
        <input v-model="form.currentPassword" type="password" autocomplete="current-password"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
      </div>
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('userAccount.newPassword') }}</label>
        <input v-model="form.newPassword" type="password" autocomplete="new-password"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
      </div>
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('userAccount.confirmPassword') }}</label>
        <input v-model="form.confirmPassword" type="password" autocomplete="new-password"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
        <p v-if="form.confirmPassword && form.newPassword !== form.confirmPassword" class="text-xs text-rose-600 mt-1">
          {{ t('userAccount.mismatch') }}
        </p>
      </div>

      <button type="submit" :disabled="!canSubmit || saving"
        class="w-full py-2.5 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50">
        <i v-if="saving" class="pi pi-spin pi-spinner mr-2" />
        {{ t('userAccount.save') }}
      </button>
    </form>
  </div>
</template>
