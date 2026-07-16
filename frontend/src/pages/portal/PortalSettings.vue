<script setup lang="ts">
// Change-password form. The controller revokes all *other* Sanctum tokens
// on success, so the current session survives but other devices sign out.
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { changePassword } from '../../api/portal'
import { ApiError } from '../../api/client'

const { t } = useI18n()

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
</script>

<template>
  <div class="space-y-6 max-w-lg">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('portal.settings.title') }}</h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('portal.settings.subtitle') }}</p>
    </header>

    <form class="card p-5 space-y-4" @submit.prevent="submit">
      <h2 class="text-sm font-semibold text-slate-600">{{ t('portal.settings.changePassword') }}</h2>

      <div v-if="error" class="px-3 py-2 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        {{ error }}
      </div>
      <div v-if="done" class="px-3 py-2 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">
        {{ t('portal.settings.passwordChanged') }}
      </div>

      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.settings.currentPassword') }}</label>
        <input v-model="form.currentPassword" type="password" autocomplete="current-password"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
      </div>
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.settings.newPassword') }}</label>
        <input v-model="form.newPassword" type="password" autocomplete="new-password"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
      </div>
      <div>
        <label class="text-xs text-slate-500 mb-1 block">{{ t('portal.settings.confirmPassword') }}</label>
        <input v-model="form.confirmPassword" type="password" autocomplete="new-password"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" />
        <p v-if="form.confirmPassword && form.newPassword !== form.confirmPassword" class="text-xs text-rose-600 mt-1">
          {{ t('portal.settings.mismatch') }}
        </p>
      </div>

      <button type="submit" :disabled="!canSubmit || saving"
        class="w-full py-2.5 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50">
        <i v-if="saving" class="pi pi-spin pi-spinner mr-2" />
        {{ t('portal.settings.save') }}
      </button>
    </form>
  </div>
</template>
