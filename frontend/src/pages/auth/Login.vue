<script setup lang="ts">
import { ref, reactive, computed, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../../stores/auth'
import { ApiError } from '../../api/client'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const form = reactive({
  email: '',
  password: '',
  remember: true,
})

const showPassword = ref(false)
const submitting = ref(false)
const errorMsg = ref('')

const step = ref<'creds' | 'mfa'>('creds')
const mfaCode = ref(['', '', '', '', '', ''])
const mfaInputs = ref<HTMLInputElement[]>([])

const emailValid = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email))
const canSubmit = computed(() => emailValid.value && form.password.length >= 6 && !submitting.value)
const mfaComplete = computed(() => mfaCode.value.every((d) => /^\d$/.test(d)))

async function submitCredentials() {
  if (!canSubmit.value) return
  errorMsg.value = ''
  submitting.value = true
  try {
    await authStore.login(form.email, form.password)
    // Skip MFA — the backend doesn't enforce it yet. Honor ?redirect if present.
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    router.push(redirect)
  } catch (err) {
    if (err instanceof ApiError && err.status === 422) {
      errorMsg.value = err.body?.errors?.email?.[0] ?? t('auth.login.invalidCreds')
    } else if (err instanceof Error) {
      errorMsg.value = err.message
    } else {
      errorMsg.value = t('auth.login.invalidCreds')
    }
  } finally {
    submitting.value = false
  }
}

async function submitMfa() {
  if (!mfaComplete.value) return
  submitting.value = true
  await new Promise((r) => setTimeout(r, 500))
  submitting.value = false
  router.push('/')
}

function onMfaInput(idx: number, e: Event) {
  const val = (e.target as HTMLInputElement).value.replace(/\D/g, '').slice(-1)
  mfaCode.value[idx] = val
  if (val && idx < 5) mfaInputs.value[idx + 1]?.focus()
}

function onMfaKeydown(idx: number, e: KeyboardEvent) {
  if (e.key === 'Backspace' && !mfaCode.value[idx] && idx > 0) {
    mfaInputs.value[idx - 1]?.focus()
  }
}

function onMfaPaste(e: ClipboardEvent) {
  const text = e.clipboardData?.getData('text') ?? ''
  const digits = text.replace(/\D/g, '').slice(0, 6).split('')
  if (!digits.length) return
  e.preventDefault()
  digits.forEach((d, i) => (mfaCode.value[i] = d))
  const focusIdx = Math.min(digits.length, 5)
  mfaInputs.value[focusIdx]?.focus()
}
</script>

<template>
  <div>
    <!-- Credentials step -->
    <div v-if="step === 'creds'">
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('auth.login.title') }}</h1>
      <p class="text-slate-500 mt-1.5 text-sm">{{ t('auth.login.subtitle') }}</p>

      <form class="mt-8 space-y-4" @submit.prevent="submitCredentials">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.login.email') }}
          </label>
          <input
            v-model="form.email"
            type="email"
            autocomplete="email"
            required
            :placeholder="t('auth.login.emailPlaceholder')"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>

        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="block text-sm font-medium text-slate-700">
              {{ t('auth.login.password') }}
            </label>
            <RouterLink to="/forgot-password" class="text-xs text-brand-600 hover:text-brand-700 font-medium">
              {{ t('auth.login.forgot') }}
            </RouterLink>
          </div>
          <div class="relative">
            <input
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="current-password"
              required
              class="w-full px-3.5 py-2.5 pr-10 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
            <button
              type="button"
              @click="showPassword = !showPassword"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              :title="showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'"
            >
              <i :class="showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'" />
            </button>
          </div>
        </div>

        <label class="flex items-center gap-2 cursor-pointer select-none">
          <input
            v-model="form.remember"
            type="checkbox"
            class="w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
          />
          <span class="text-sm text-slate-600">{{ t('auth.login.remember') }}</span>
        </label>

        <div v-if="errorMsg" class="bg-rose-50 border border-rose-200 text-rose-700 text-sm px-3 py-2 rounded-lg flex items-center gap-2">
          <i class="pi pi-exclamation-circle" />
          {{ errorMsg }}
        </div>

        <button
          type="submit"
          :disabled="!canSubmit"
          class="w-full py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
        >
          <i v-if="submitting" class="pi pi-spin pi-spinner" />
          <span>{{ t('auth.login.submit') }}</span>
        </button>
      </form>

      <p class="text-center text-sm text-slate-500 mt-6">
        {{ t('auth.login.noAccount') }}
        <RouterLink to="/register" class="text-brand-600 hover:text-brand-700 font-medium ml-1">
          {{ t('auth.login.register') }}
        </RouterLink>
      </p>
    </div>

    <!-- MFA step -->
    <div v-else>
      <button
        type="button"
        @click="step = 'creds'"
        class="text-sm text-slate-500 hover:text-slate-900 mb-6 flex items-center gap-1"
      >
        <i class="pi pi-arrow-left text-xs" />
        {{ t('auth.login.backToLogin') }}
      </button>

      <h1 class="text-2xl font-semibold text-slate-900">{{ t('auth.login.mfaTitle') }}</h1>
      <p class="text-slate-500 mt-1.5 text-sm">{{ t('auth.login.mfaSubtitle') }}</p>

      <form class="mt-8 space-y-5" @submit.prevent="submitMfa">
        <div class="flex justify-between gap-2" @paste="onMfaPaste">
          <input
            v-for="(_, i) in mfaCode"
            :key="i"
            :ref="(el) => { if (el) mfaInputs[i] = el as HTMLInputElement }"
            v-model="mfaCode[i]"
            type="text"
            inputmode="numeric"
            maxlength="1"
            class="w-12 h-14 text-center text-xl font-semibold border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            @input="onMfaInput(i, $event)"
            @keydown="onMfaKeydown(i, $event)"
          />
        </div>

        <button
          type="submit"
          :disabled="!mfaComplete || submitting"
          class="w-full py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
        >
          <i v-if="submitting" class="pi pi-spin pi-spinner" />
          <span>{{ t('auth.login.mfaSubmit') }}</span>
        </button>

        <button
          type="button"
          class="w-full text-sm text-slate-500 hover:text-slate-900"
        >
          {{ t('auth.login.useBackup') }}
        </button>
      </form>
    </div>
  </div>
</template>
