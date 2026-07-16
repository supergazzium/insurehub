<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, ApiError } from '../../api/client'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const password = ref('')
const confirmPassword = ref('')
const submitting = ref(false)
const done = ref(false)
const error = ref<string | null>(null)

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const emailFromLink = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))

const strength = computed(() => {
  let score = 0
  if (password.value.length >= 8) score++
  if (password.value.length >= 12) score++
  if (/[A-Z]/.test(password.value)) score++
  if (/[0-9]/.test(password.value)) score++
  if (/[^A-Za-z0-9]/.test(password.value)) score++
  return Math.min(score, 4)
})

const strengthLabel = computed(() => {
  if (strength.value <= 1) return t('auth.reset.strength.weak')
  if (strength.value === 2) return t('auth.reset.strength.medium')
  if (strength.value === 3) return t('auth.reset.strength.strong')
  return t('auth.reset.strength.veryStrong')
})

const strengthColor = computed(() => {
  if (strength.value <= 1) return 'bg-rose-500'
  if (strength.value === 2) return 'bg-amber-500'
  if (strength.value === 3) return 'bg-lime-500'
  return 'bg-emerald-500'
})

const match = computed(() => password.value.length > 0 && password.value === confirmPassword.value)
const canSubmit = computed(() => strength.value >= 3 && match.value && !submitting.value)

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  if (!token.value || !emailFromLink.value) {
    error.value = t('auth.reset.invalidLink')
    return
  }
  submitting.value = true
  error.value = null
  try {
    await api.post('auth/reset-password', {
      token: token.value,
      email: emailFromLink.value,
      password: password.value,
      password_confirmation: confirmPassword.value,
    })
    done.value = true
    setTimeout(() => router.push('/login'), 2000)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Reset failed.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div v-if="done" class="text-center py-8">
    <div class="w-16 h-16 mx-auto bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mb-4">
      <i class="pi pi-check text-3xl" />
    </div>
    <h2 class="text-xl font-semibold text-slate-900">เปลี่ยนรหัสผ่านสำเร็จ</h2>
    <p class="text-slate-500 mt-2 text-sm">กำลังพาคุณกลับสู่หน้าเข้าสู่ระบบ...</p>
  </div>

  <div v-else>
    <h1 class="text-2xl font-semibold text-slate-900">{{ t('auth.reset.title') }}</h1>
    <p class="text-slate-500 mt-1.5 text-sm">{{ t('auth.reset.subtitle') }}</p>

    <div v-if="error" class="mt-4 px-3 py-2 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>
    <form class="mt-8 space-y-4" @submit.prevent="submit">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.reset.newPassword') }}
        </label>
        <input
          v-model="password"
          type="password"
          required
          autocomplete="new-password"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />

        <div v-if="password" class="mt-2 flex items-center gap-2">
          <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden flex gap-0.5">
            <div
              v-for="i in 4"
              :key="i"
              :class="['flex-1 transition-colors', i <= strength ? strengthColor : 'bg-slate-100']"
            />
          </div>
          <span class="text-xs font-medium text-slate-600 w-16 text-right">{{ strengthLabel }}</span>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">
          {{ t('auth.reset.confirmPassword') }}
        </label>
        <input
          v-model="confirmPassword"
          type="password"
          required
          autocomplete="new-password"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
        <p v-if="confirmPassword && !match" class="text-xs mt-1.5 text-rose-600">
          <i class="pi pi-times-circle mr-1" />
          {{ t('auth.reset.mismatch') }}
        </p>
      </div>

      <button
        type="submit"
        :disabled="!canSubmit"
        class="w-full py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
      >
        <i v-if="submitting" class="pi pi-spin pi-spinner" />
        <span>{{ t('auth.reset.submit') }}</span>
      </button>
    </form>
  </div>
</template>
