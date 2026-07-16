<script setup lang="ts">
// Public agent-registration form — creates a paired Agent+User row with
// approval_status=pending. POSTs to /api/v1/auth/register.
import { reactive, ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, ApiError } from '../../api/client'

interface RecruitLinkInfo {
  valid: boolean
  recruiterAgentCode?: string
  recruiterName?: string
  message?: string
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const form = reactive({
  signupType: 'personal' as 'personal' | 'corporate',
  firstName: '',
  lastName: '',
  email: '',
  password: '',
  passwordConfirmation: '',
  idCard: '',
  birthDate: '',
  phone: '',
  lineId: '',
  juristicName: '',
  termsAccepted: false,
})

const submitting = ref(false)
const done = ref<{ agentCode: string } | null>(null)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

const referralToken = computed(() => (typeof route.query.ref === 'string' ? route.query.ref : ''))
const recruiter = ref<RecruitLinkInfo | null>(null)
const recruiterLoading = ref(false)

async function loadRecruiter(token: string): Promise<void> {
  if (!token) { recruiter.value = null; return }
  recruiterLoading.value = true
  try {
    // 404 lands in ApiError with body { valid: false, message: ... }.
    const res = await api.get<RecruitLinkInfo>(`public/recruit/${encodeURIComponent(token)}`)
    recruiter.value = res
  } catch (e: unknown) {
    // Invalid / revoked token: show a soft warning, don't block registration.
    if (e instanceof ApiError && e.details && typeof e.details === 'object' && 'valid' in e.details) {
      recruiter.value = e.details as RecruitLinkInfo
    } else {
      recruiter.value = { valid: false, message: e instanceof Error ? e.message : 'Unknown error' }
    }
  } finally {
    recruiterLoading.value = false
  }
}

onMounted(() => { void loadRecruiter(referralToken.value) })
watch(referralToken, (t) => { void loadRecruiter(t) })

/**
 * List of human-readable reasons the form can't submit. Empty list = ready.
 * Kept as a computed so the "why disabled" hint updates live as the user types.
 */
const missing = computed<string[]>(() => {
  const reasons: string[] = []
  if (form.signupType === 'corporate' && form.juristicName.trim() === '') reasons.push(t('agentRegister.missing.juristicName'))
  if (form.firstName.trim() === '') reasons.push(t('agentRegister.missing.firstName'))
  if (form.lastName.trim() === '') reasons.push(t('agentRegister.missing.lastName'))
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) reasons.push(t('agentRegister.missing.email'))
  if (form.password.length < 8) reasons.push(t('agentRegister.missing.password'))
  if (form.password !== form.passwordConfirmation) reasons.push(t('agentRegister.missing.passwordMismatch'))
  if (form.idCard.trim().length < 5) reasons.push(t('agentRegister.missing.idCard'))
  if (form.birthDate === '') reasons.push(t('agentRegister.missing.birthDate'))
  if (form.phone.trim().length < 8) reasons.push(t('agentRegister.missing.phone'))
  if (!form.termsAccepted) reasons.push(t('agentRegister.missing.terms'))
  return reasons
})
const canSubmit = computed(() => missing.value.length === 0)

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  submitting.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    const res = await api.post<{ agentCode: string }>('auth/register', {
      signupType: form.signupType,
      firstName: form.firstName,
      lastName: form.lastName,
      email: form.email,
      password: form.password,
      password_confirmation: form.passwordConfirmation,
      idCard: form.idCard.trim(),
      birthDate: form.birthDate,
      phone: form.phone,
      lineId: form.lineId || null,
      juristicName: form.signupType === 'corporate' ? form.juristicName : null,
      termsAccepted: form.termsAccepted,
      referralToken: recruiter.value?.valid ? referralToken.value : null,
    })
    done.value = { agentCode: res.agentCode }
    setTimeout(() => router.push('/login'), 3500)
  } catch (e: unknown) {
    if (e instanceof ApiError && e.details && typeof e.details === 'object') {
      const d = e.details as { errors?: Record<string, string[]> }
      if (d.errors) fieldErrors.value = d.errors
      error.value = e.message
    } else {
      error.value = e instanceof Error ? e.message : 'Registration failed.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl mx-auto px-6 py-12">
    <div v-if="done" class="text-center py-8">
      <div class="w-16 h-16 mx-auto bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mb-4">
        <i class="pi pi-check text-3xl" />
      </div>
      <h2 class="text-xl font-semibold text-slate-900">{{ t('agentRegister.success.title') }}</h2>
      <p class="text-slate-500 mt-2 text-sm">
        {{ t('agentRegister.success.message', { code: done.agentCode }) }}
      </p>
    </div>

    <div v-else>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('agentRegister.title') }}</h1>
      <p class="text-slate-500 mt-1.5 text-sm">{{ t('agentRegister.subtitle') }}</p>

      <div v-if="referralToken && recruiterLoading" class="mt-4 px-3 py-2 rounded-md bg-slate-50 text-slate-500 text-xs">
        <i class="pi pi-spin pi-spinner mr-1" /> {{ t('agentRegister.referredLoading') }}
      </div>
      <div v-else-if="recruiter?.valid" class="mt-4 px-3 py-2 rounded-md bg-brand-50 text-brand-700 text-xs">
        <i class="pi pi-link mr-1" />
        {{ t('agentRegister.referredBy', { name: recruiter.recruiterName, code: recruiter.recruiterAgentCode }) }}
      </div>
      <div v-else-if="referralToken && recruiter && !recruiter.valid" class="mt-4 px-3 py-2 rounded-md bg-amber-50 text-amber-800 text-xs">
        <i class="pi pi-exclamation-triangle mr-1" />
        {{ t('agentRegister.referredInvalid') }}
      </div>

      <div v-if="error" class="mt-4 px-3 py-2 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        {{ error }}
      </div>

      <form class="mt-6 space-y-4" @submit.prevent="submit">
        <!-- Signup type -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.signupType') }}</label>
          <div class="flex items-center gap-4 text-sm">
            <label class="inline-flex items-center gap-2">
              <input type="radio" v-model="form.signupType" value="personal" /> {{ t('agentRegister.personal') }}
            </label>
            <label class="inline-flex items-center gap-2">
              <input type="radio" v-model="form.signupType" value="corporate" /> {{ t('agentRegister.corporate') }}
            </label>
          </div>
        </div>

        <div v-if="form.signupType === 'corporate'">
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.juristicName') }} <span class="text-rose-500">*</span></label>
          <input v-model.trim="form.juristicName"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.firstName') }} <span class="text-rose-500">*</span></label>
            <input v-model.trim="form.firstName"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.lastName') }} <span class="text-rose-500">*</span></label>
            <input v-model.trim="form.lastName"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.email') }} <span class="text-rose-500">*</span></label>
          <input v-model.trim="form.email" type="email" autocomplete="email"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
          <p v-if="fieldErrors.email" class="text-xs text-rose-600 mt-1">{{ fieldErrors.email[0] }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.password') }} <span class="text-rose-500">*</span></label>
            <input v-model="form.password" type="password" autocomplete="new-password"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.confirmPassword') }} <span class="text-rose-500">*</span></label>
            <input v-model="form.passwordConfirmation" type="password" autocomplete="new-password"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ form.signupType === 'corporate' ? t('agentRegister.taxId') : t('agentRegister.idCard') }}
            <span class="text-rose-500">*</span>
          </label>
          <input v-model.trim="form.idCard" inputmode="numeric"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
          <p v-if="fieldErrors.idCard" class="text-xs text-rose-600 mt-1">{{ fieldErrors.idCard[0] }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.birthDate') }} <span class="text-rose-500">*</span></label>
            <input v-model="form.birthDate" type="date"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.phone') }} <span class="text-rose-500">*</span></label>
            <input v-model.trim="form.phone" type="tel" placeholder="08x-xxx-xxxx"
              class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.lineId') }}</label>
          <input v-model.trim="form.lineId"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
        </div>

        <label class="flex items-start gap-2 pt-2 cursor-pointer">
          <input v-model="form.termsAccepted" type="checkbox" class="mt-0.5" />
          <span class="text-sm text-slate-600">{{ t('agentRegister.terms') }}</span>
        </label>

        <div v-if="missing.length" class="px-3 py-2 rounded-md bg-amber-50 border border-amber-200 text-amber-800 text-xs">
          <div class="font-medium mb-1">{{ t('agentRegister.missing.title') }}</div>
          <ul class="list-disc list-inside space-y-0.5">
            <li v-for="m in missing" :key="m">{{ m }}</li>
          </ul>
        </div>

        <button type="submit" :disabled="!canSubmit || submitting"
          class="w-full py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed">
          <i v-if="submitting" class="pi pi-spin pi-spinner mr-2" />
          {{ t('agentRegister.submit') }}
        </button>

        <p class="text-center text-sm text-slate-500 mt-4">
          {{ t('agentRegister.haveAccount') }}
          <RouterLink to="/login" class="text-brand-600 hover:text-brand-700 font-medium">{{ t('agentRegister.login') }}</RouterLink>
        </p>
      </form>
    </div>
  </div>
</template>
