<script setup lang="ts">
// #2 — shared login form used by the two SEPARATE login pages:
//   - staff login (/login)        → variant="staff", rejects agents
//   - agent login (/agent/login)  → variant="agent", rejects internal users
// Keeps one implementation of the credentials + MFA flow; the variant only
// changes which user type is allowed and where a mismatched user is sent.
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../../stores/auth'
import { ApiError, setToken } from '../../api/client'
import { fetchLineLoginUrl } from '../../api/portal'

const props = defineProps<{
  /** Which audience this login page serves. */
  variant: 'staff' | 'agent'
}>()

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const form = reactive({ email: '', password: '', remember: true })
const showPassword = ref(false)
const submitting = ref(false)
const errorMsg = ref('')
const infoMsg = ref('')

const step = ref<'creds' | 'mfa'>('creds')
const mfaCode = ref(['', '', '', '', '', ''])
const mfaInputs = ref<HTMLInputElement[]>([])

const emailValid = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email))
const canSubmit = computed(() => emailValid.value && form.password.length >= 6 && !submitting.value)
const mfaComplete = computed(() => mfaCode.value.every((d) => /^\d$/.test(d)))

/** Where this variant sends a correctly-typed user after login. */
function homeRoute(): { name: string } {
  return { name: props.variant === 'agent' ? 'portal-dashboard' : 'dashboard' }
}

/** Reject a user who logged in on the wrong page: sign them back out and
 *  show a message pointing them at the correct login URL. */
function wrongAudience(): boolean {
  const isAgent = authStore.user?.role === 'agent'
  if (props.variant === 'agent' && !isAgent) {
    authStore.logout()
    errorMsg.value = t('auth.login.useStaffLogin')
    return true
  }
  if (props.variant === 'staff' && isAgent) {
    authStore.logout()
    errorMsg.value = t('auth.login.useAgentLogin')
    return true
  }
  return false
}

async function submitCredentials() {
  if (!canSubmit.value) return
  errorMsg.value = ''
  submitting.value = true
  try {
    await authStore.login(form.email, form.password)
    if (wrongAudience()) return
    // Honor ?redirect only when it matches this audience's area.
    const explicit = typeof route.query.redirect === 'string' ? route.query.redirect : ''
    const explicitAllowed = explicit
      && (props.variant === 'agent' ? explicit.includes('/portal') : !explicit.includes('/portal'))
    if (explicitAllowed) router.push(explicit)
    else router.push(homeRoute())
  } catch (err) {
    if (err instanceof ApiError && err.status === 422) {
      const code = err.body?.errors?.email?.[0] ?? ''
      errorMsg.value =
        code === 'email_not_found' ? t('auth.login.emailNotFound')
        : code === 'invalid_password' ? t('auth.login.invalidPassword')
        : code === 'account_pending_approval' ? t('auth.login.accountPendingApproval')
        : code === 'account_inactive' ? t('auth.login.accountInactive')
        : t('auth.login.invalidCreds')
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
  if (wrongAudience()) { step.value = 'creds'; return }
  router.push(homeRoute())
}

function onMfaInput(idx: number, e: Event) {
  const val = (e.target as HTMLInputElement).value.replace(/\D/g, '').slice(-1)
  mfaCode.value[idx] = val
  if (val && idx < 5) mfaInputs.value[idx + 1]?.focus()
}
function onMfaKeydown(idx: number, e: KeyboardEvent) {
  if (e.key === 'Backspace' && !mfaCode.value[idx] && idx > 0) mfaInputs.value[idx - 1]?.focus()
}
function onMfaPaste(e: ClipboardEvent) {
  const text = e.clipboardData?.getData('text') ?? ''
  const digits = text.replace(/\D/g, '').slice(0, 6).split('')
  if (!digits.length) return
  e.preventDefault()
  digits.forEach((d, i) => (mfaCode.value[i] = d))
  mfaInputs.value[Math.min(digits.length, 5)]?.focus()
}

// ── #1 LINE Login (agent variant only) ──────────────────────────────────────
const lineBusy = ref(false)
const LINE_ERRORS: Record<string, string> = {
  not_configured: 'LINE Login ยังไม่ได้ตั้งค่าในระบบ',
  cancelled: 'ยกเลิกการเข้าสู่ระบบด้วย LINE',
  not_linked: 'บัญชี LINE นี้ยังไม่ได้เชื่อมกับบัญชีตัวแทน — กรุณาเข้าสู่ระบบด้วยอีเมลแล้วเชื่อม LINE ในหน้าตั้งค่าก่อน',
  exchange_failed: 'เชื่อมต่อ LINE ไม่สำเร็จ ลองใหม่อีกครั้ง',
  bad_state: 'คำขอหมดอายุ กรุณาลองใหม่',
  no_profile: 'อ่านข้อมูลจาก LINE ไม่สำเร็จ',
}

async function lineLogin(): Promise<void> {
  errorMsg.value = ''
  lineBusy.value = true
  try {
    const { url } = await fetchLineLoginUrl()
    window.location.href = url
  } catch (err) {
    errorMsg.value = err instanceof ApiError && err.status === 503
      ? LINE_ERRORS.not_configured
      : 'เริ่มการเข้าสู่ระบบด้วย LINE ไม่สำเร็จ'
    lineBusy.value = false
  }
}

// On return from LINE: a token arrives in the URL fragment (#line_token=…) on
// success, or ?line_error=… on failure. Handle both, then clean the URL.
onMounted(async () => {
  // First LINE login auto-creates a pending agent; a prior unapproved account
  // returns here too. Both show an informational "waiting for approval" notice.
  if (route.query.line_registered === 'pending' || route.query.line_pending === '1') {
    infoMsg.value = route.query.line_registered === 'pending'
      ? 'สร้างบัญชีตัวแทนด้วย LINE เรียบร้อยแล้ว — รอผู้ดูแลอนุมัติก่อนจึงจะเข้าสู่ระบบได้ เราจะแจ้งเตือนเมื่ออนุมัติแล้ว'
      : 'บัญชีตัวแทนของคุณกำลังรอการอนุมัติจากผู้ดูแล กรุณารอการอนุมัติก่อนเข้าสู่ระบบ'
    history.replaceState(null, '', route.path)
    return
  }
  const err = typeof route.query.line_error === 'string' ? route.query.line_error : ''
  if (err) {
    errorMsg.value = LINE_ERRORS[err] ?? 'เข้าสู่ระบบด้วย LINE ไม่สำเร็จ'
    history.replaceState(null, '', route.path)
    return
  }
  const hash = window.location.hash
  const m = hash.match(/line_token=([^&]+)/)
  if (m) {
    history.replaceState(null, '', route.path) // strip the token from the URL
    setToken(decodeURIComponent(m[1]))
    try {
      await authStore.restore()          // hydrate the user from the token
      router.push(homeRoute())
    } catch {
      errorMsg.value = 'เข้าสู่ระบบด้วย LINE ไม่สำเร็จ'
    }
  }
})
</script>

<template>
  <div>
    <!-- Credentials step -->
    <div v-if="step === 'creds'">
      <div class="mb-1 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-medium"
        :class="variant === 'agent' ? 'bg-emerald-50 text-emerald-700' : 'bg-brand-50 text-brand-700'">
        <i :class="variant === 'agent' ? 'pi pi-user' : 'pi pi-building'" class="text-[10px]" />
        {{ variant === 'agent' ? t('auth.login.agentBadge') : t('auth.login.staffBadge') }}
      </div>
      <h1 class="text-2xl font-semibold text-slate-900">
        {{ variant === 'agent' ? t('auth.login.agentTitle') : t('auth.login.staffTitle') }}
      </h1>
      <p class="text-slate-500 mt-1.5 text-sm">
        {{ variant === 'agent' ? t('auth.login.agentSubtitle') : t('auth.login.staffSubtitle') }}
      </p>

      <form class="mt-8 space-y-4" @submit.prevent="submitCredentials">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('auth.login.email') }}</label>
          <input v-model="form.email" type="email" autocomplete="email" required
            :placeholder="t('auth.login.emailPlaceholder')"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
        </div>

        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="block text-sm font-medium text-slate-700">{{ t('auth.login.password') }}</label>
            <RouterLink to="/forgot-password" class="text-xs text-brand-600 hover:text-brand-700 font-medium">{{ t('auth.login.forgot') }}</RouterLink>
          </div>
          <div class="relative">
            <input v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required
              class="w-full px-3.5 py-2.5 pr-10 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
            <button type="button" @click="showPassword = !showPassword"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              :title="showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'">
              <i :class="showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'" />
            </button>
          </div>
        </div>

        <label class="flex items-center gap-2 cursor-pointer select-none">
          <input v-model="form.remember" type="checkbox" class="w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
          <span class="text-sm text-slate-600">{{ t('auth.login.remember') }}</span>
        </label>

        <div v-if="infoMsg" class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-3 py-2 rounded-lg flex items-center gap-2">
          <i class="pi pi-info-circle" />{{ infoMsg }}
        </div>
        <div v-if="errorMsg" class="bg-rose-50 border border-rose-200 text-rose-700 text-sm px-3 py-2 rounded-lg flex items-center gap-2">
          <i class="pi pi-exclamation-circle" />{{ errorMsg }}
        </div>

        <button type="submit" :disabled="!canSubmit"
          class="w-full py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
          <i v-if="submitting" class="pi pi-spin pi-spinner" /><span>{{ t('auth.login.submit') }}</span>
        </button>
      </form>

      <!-- #1 — LINE Login (agent portal only). -->
      <template v-if="variant === 'agent'">
        <div class="my-5 flex items-center gap-3 text-xs text-slate-400">
          <span class="h-px flex-1 bg-slate-200" />หรือ<span class="h-px flex-1 bg-slate-200" />
        </div>
        <button type="button" :disabled="lineBusy" @click="lineLogin"
          class="w-full py-2.5 rounded-lg bg-[#06C755] text-white font-medium hover:bg-[#05b34c] transition disabled:opacity-50 flex items-center justify-center gap-2">
          <i v-if="lineBusy" class="pi pi-spin pi-spinner" />
          <i v-else class="pi pi-comment" />
          เข้าสู่ระบบด้วย LINE
        </button>
        <p class="mt-2 text-center text-[11px] text-slate-400">ต้องเชื่อม LINE กับบัญชีในหน้าตั้งค่าก่อนครั้งแรก</p>
      </template>

      <!-- Agent page: offer self-registration. Both pages cross-link. -->
      <p v-if="variant === 'agent'" class="text-center text-sm text-slate-500 mt-6">
        {{ t('auth.login.noAccount') }}
        <RouterLink to="/register-agent" class="text-brand-600 hover:text-brand-700 font-medium ml-1">{{ t('auth.login.register') }}</RouterLink>
      </p>
      <p class="text-center text-xs text-slate-400 mt-4">
        <template v-if="variant === 'agent'">
          {{ t('auth.login.notAgent') }}
          <RouterLink to="/login" class="text-brand-600 hover:text-brand-700 ml-1">{{ t('auth.login.goStaff') }}</RouterLink>
        </template>
        <template v-else>
          {{ t('auth.login.areAgent') }}
          <RouterLink to="/agent/login" class="text-brand-600 hover:text-brand-700 ml-1">{{ t('auth.login.goAgent') }}</RouterLink>
        </template>
      </p>
    </div>

    <!-- MFA step -->
    <div v-else>
      <button type="button" @click="step = 'creds'" class="text-sm text-slate-500 hover:text-slate-900 mb-6 flex items-center gap-1">
        <i class="pi pi-arrow-left text-xs" />{{ t('auth.login.backToLogin') }}
      </button>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('auth.login.mfaTitle') }}</h1>
      <p class="text-slate-500 mt-1.5 text-sm">{{ t('auth.login.mfaSubtitle') }}</p>

      <form class="mt-8 space-y-5" @submit.prevent="submitMfa">
        <div class="flex justify-between gap-2" @paste="onMfaPaste">
          <input v-for="(_, i) in mfaCode" :key="i"
            :ref="(el) => { if (el) mfaInputs[i] = el as HTMLInputElement }"
            v-model="mfaCode[i]" type="text" inputmode="numeric" maxlength="1"
            class="w-12 h-14 text-center text-xl font-semibold border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            @input="onMfaInput(i, $event)" @keydown="onMfaKeydown(i, $event)" />
        </div>
        <button type="submit" :disabled="!mfaComplete || submitting"
          class="w-full py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
          <i v-if="submitting" class="pi pi-spin pi-spinner" /><span>{{ t('auth.login.mfaSubmit') }}</span>
        </button>
        <button type="button" class="w-full text-sm text-slate-500 hover:text-slate-900">{{ t('auth.login.useBackup') }}</button>
      </form>
    </div>
  </div>
</template>
