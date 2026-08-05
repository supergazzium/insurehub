<script setup lang="ts">
// Public agent-registration form — creates a paired Agent+User row with
// approval_status=pending. POSTs to /api/v1/auth/register.
//
// UX model: every field has an inline validator that runs on every keystroke.
// Errors don't render on a field until the user has "touched" it (blurred
// out of it once) OR they've clicked submit once — that way a fresh form
// isn't a wall of red. Field-level errors from the server clear the moment
// the user edits the field again. Form data survives all errors (server
// or client) — nothing is ever wiped on a failed submit.
import { reactive, ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, ApiError } from '../../api/client'
import { isThaiName, isThaiId13, isThaiMobile } from '../../utils/thaiValidation'
import DateInput from '../../components/DateInput.vue'
import { toIsoDate } from '../../util/dateFormat'

interface RecruitLinkInfo {
  valid: boolean
  recruiterAgentCode?: string
  recruiterName?: string
  message?: string
}

type FieldName =
  | 'juristicName'
  | 'firstName'
  | 'lastName'
  | 'email'
  | 'password'
  | 'passwordConfirmation'
  | 'idCard'
  | 'birthDate'
  | 'phone'
  | 'termsAccepted'

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
// Field errors returned from the server; cleared per-field on next edit.
const fieldErrors = ref<Record<string, string[]>>({})

// ── Email OTP verification state ────────────────────────────────────────
// Flow: user types email → clicks Send code → 6-digit code arrives → user
// enters it → auto-verify on 6th digit → we hold a token proving verification.
// If the user edits the email, all OTP state resets (token invalidates).
const otpSending = ref(false)
const otpVerifying = ref(false)
const otpVisible = ref(false)
const otpCode = ref('')
const otpToken = ref<string | null>(null)
const otpVerifiedEmail = ref<string | null>(null)
const otpError = ref<string | null>(null)
const otpInfo = ref<string | null>(null)
const otpCooldown = ref(0) // seconds until user may resend
const otpDevCode = ref<string | null>(null) // shown only in local dev
let cooldownTimer: number | null = null

// ── Uniqueness checks (email + national ID) ─────────────────────────────
// Debounced probes against POST /auth/check-availability. Result is:
//   null    → not checked yet (or value changed since last check)
//   true    → available, safe to proceed
//   { message } → taken; message renders inline like any other field error
// Kept as a per-value cache so re-checking the same value is free.
type Availability = { available: true } | { available: false; message: string } | null
const emailAvailability = ref<Availability>(null)
const idCardAvailability = ref<Availability>(null)
let emailCheckTimer: number | null = null
let idCardCheckTimer: number | null = null
let lastEmailChecked = ''
let lastIdCardChecked = ''

// Known server-issued messages from AuthController::register mapped to
// Thai. Anything not matched is passed through unchanged — better an
// English fallback than a mistranslation.
function translateServerFieldError(field: string, message: string): string {
  const m = message.trim()
  if (field === 'email' && (m === 'email_taken' || /already been taken/i.test(m) || /already registered/i.test(m))) {
    return t('agentRegister.availability.emailTaken')
  }
  if (field === 'idCard') {
    if (/already registered/i.test(m)) return t('agentRegister.availability.idCardTaken')
    if (/13 digits/i.test(m)) return t('agentRegister.availability.idCardInvalidFormat')
    if (/valid 13-digit/i.test(m)) return t('agentRegister.availability.idCardInvalidFormat')
  }
  if (field === 'emailOtpToken' && /invalid or has expired/i.test(m)) {
    return t('agentRegister.otp.errors.expired')
  }
  return m
}

// Server code → Thai/English i18n key under agentRegister.availability.
function availabilityMessage(code: string | null | undefined): string {
  switch (code) {
    case 'email_taken': return t('agentRegister.availability.emailTaken')
    case 'id_card_taken': return t('agentRegister.availability.idCardTaken')
    case 'id_card_invalid_format': return t('agentRegister.availability.idCardInvalidFormat')
    default: return t('agentRegister.availability.emailTaken')
  }
}

async function runAvailabilityCheck(field: 'email' | 'idCard', value: string): Promise<void> {
  try {
    const res = await api.post<{ available: boolean; code?: string | null }>(
      'auth/check-availability',
      { field, value },
    )
    const result: Availability = res.available
      ? { available: true }
      : { available: false, message: availabilityMessage(res.code) }
    if (field === 'email' && value === form.email.trim().toLowerCase()) {
      emailAvailability.value = result
    } else if (field === 'idCard' && value === form.idCard.trim()) {
      idCardAvailability.value = result
    }
  } catch {
    // Network / server error — leave the availability unknown so the
    // user can still try, and the backend will reject on final submit.
  }
}

function scheduleEmailCheck(): void {
  if (emailCheckTimer !== null) window.clearTimeout(emailCheckTimer)
  const raw = form.email.trim().toLowerCase()
  // Reset state whenever the value changes so stale results don't linger.
  emailAvailability.value = null
  if (raw === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw)) return
  if (raw === lastEmailChecked) return
  emailCheckTimer = window.setTimeout(() => {
    lastEmailChecked = raw
    void runAvailabilityCheck('email', raw)
  }, 400)
}

function scheduleIdCardCheck(): void {
  if (idCardCheckTimer !== null) window.clearTimeout(idCardCheckTimer)
  const raw = form.idCard.trim()
  idCardAvailability.value = null
  if (!isThaiId13(raw)) return
  if (raw === lastIdCardChecked) return
  idCardCheckTimer = window.setTimeout(() => {
    lastIdCardChecked = raw
    void runAvailabilityCheck('idCard', raw)
  }, 400)
}

watch(() => form.email, scheduleEmailCheck)
watch(() => form.idCard, scheduleIdCardCheck)

function startCooldown(seconds: number): void {
  otpCooldown.value = seconds
  if (cooldownTimer !== null) window.clearInterval(cooldownTimer)
  cooldownTimer = window.setInterval(() => {
    otpCooldown.value = Math.max(0, otpCooldown.value - 1)
    if (otpCooldown.value === 0 && cooldownTimer !== null) {
      window.clearInterval(cooldownTimer)
      cooldownTimer = null
    }
  }, 1000)
}

// Server-issued OTP error codes → localized message.
function otpErrorMessage(code: string | null | undefined, fallbackKey: 'sendFailed' | 'verifyFailed'): string {
  switch (code) {
    case 'email_taken': return t('agentRegister.otp.errors.emailTaken')
    case 'otp_cooldown': return t('agentRegister.otp.errors.cooldown')
    case 'otp_email_hourly_limit': return t('agentRegister.otp.errors.emailHourlyLimit')
    case 'otp_ip_hourly_limit': return t('agentRegister.otp.errors.ipHourlyLimit')
    case 'otp_expired': return t('agentRegister.otp.errors.expired')
    case 'otp_too_many_attempts': return t('agentRegister.otp.errors.tooManyAttempts')
    case 'otp_incorrect': return t('agentRegister.otp.errors.incorrect')
    default: return t(`agentRegister.otp.errors.${fallbackKey}`)
  }
}

async function sendOtp(): Promise<void> {
  if (emailError.value !== null || otpCooldown.value > 0 || otpSending.value) return
  otpSending.value = true
  otpError.value = null
  otpInfo.value = null
  otpDevCode.value = null
  try {
    const res = await api.post<{ sent: boolean; ttlMinutes: number; cooldownSeconds: number; devCode?: string }>(
      'auth/email-otp/send',
      { email: form.email },
    )
    otpVisible.value = true
    otpInfo.value = t('agentRegister.otp.sent', { minutes: res.ttlMinutes })
    startCooldown(res.cooldownSeconds ?? 60)
    if (res.devCode) otpDevCode.value = res.devCode
    otpCode.value = ''
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const body = (e.body && typeof e.body === 'object' ? e.body : {}) as {
        retryAfter?: number
        code?: string
        errors?: Record<string, string[]>
      }
      const retry = Number(body.retryAfter ?? 0)
      if (retry > 0) startCooldown(retry)
      const localized = otpErrorMessage(body.code, 'sendFailed')
      // Laravel validation errors (e.g. "email already registered") — bind
      // them to the field so the inline UI renders them beside the input.
      if (body.errors && body.errors.email && body.errors.email.length > 0) {
        fieldErrors.value = { ...fieldErrors.value, email: [localized] }
        otpError.value = null
      } else {
        otpError.value = localized
      }
    } else {
      otpError.value = t('agentRegister.otp.errors.sendFailed')
    }
  } finally {
    otpSending.value = false
  }
}

async function verifyOtp(): Promise<void> {
  if (otpCode.value.length !== 6 || otpVerifying.value) return
  otpVerifying.value = true
  otpError.value = null
  try {
    const res = await api.post<{ verified: boolean; emailOtpToken: string }>(
      'auth/email-otp/verify',
      { email: form.email, code: otpCode.value },
    )
    otpToken.value = res.emailOtpToken
    otpVerifiedEmail.value = form.email
    otpInfo.value = t('agentRegister.otp.verified')
    otpVisible.value = false
    otpDevCode.value = null
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const body = (e.body && typeof e.body === 'object' ? e.body : {}) as {
        code?: string
        attemptsRemaining?: number
      }
      // "Incorrect code" specifically gets the remaining-attempts variant.
      if (body.code === 'otp_incorrect' && typeof body.attemptsRemaining === 'number') {
        otpError.value = t('agentRegister.otp.wrongWithRemaining', { n: body.attemptsRemaining })
      } else {
        otpError.value = otpErrorMessage(body.code, 'verifyFailed')
      }
    } else {
      otpError.value = t('agentRegister.otp.errors.verifyFailed')
    }
    otpCode.value = ''
  } finally {
    otpVerifying.value = false
  }
}

// Any edit to the email address invalidates a prior verification.
watch(() => form.email, (next) => {
  if (otpVerifiedEmail.value !== null && next !== otpVerifiedEmail.value) {
    otpToken.value = null
    otpVerifiedEmail.value = null
    otpVisible.value = false
    otpCode.value = ''
    otpInfo.value = null
    otpError.value = null
    otpDevCode.value = null
  }
})

// Auto-verify the moment the user types the 6th digit — one less click.
watch(otpCode, (next) => {
  if (next.length === 6 && /^\d{6}$/.test(next) && otpToken.value === null) {
    void verifyOtp()
  }
})

const emailVerified = computed(() => otpToken.value !== null && otpVerifiedEmail.value === form.email)
// A field is "touched" once the user has blurred out of it — or once they
// press the submit button (which touches every field at once).
const touched = reactive<Record<FieldName, boolean>>({
  juristicName: false,
  firstName: false,
  lastName: false,
  email: false,
  password: false,
  passwordConfirmation: false,
  idCard: false,
  birthDate: false,
  phone: false,
  termsAccepted: false,
})
const attemptedSubmit = ref(false)

function markTouched(field: FieldName): void {
  touched[field] = true
}

// If the user edits a field the server rejected, clear that inline error
// so it doesn't linger after they've fixed it.
function clearServerError(field: string): void {
  if (fieldErrors.value[field]) {
    const { [field]: _, ...rest } = fieldErrors.value
    fieldErrors.value = rest
  }
}

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
    if (e instanceof ApiError && e.body && typeof e.body === 'object' && 'valid' in e.body) {
      recruiter.value = e.body as unknown as RecruitLinkInfo
    } else {
      recruiter.value = { valid: false, message: e instanceof Error ? e.message : 'Unknown error' }
    }
  } finally {
    recruiterLoading.value = false
  }
}

onMounted(() => { void loadRecruiter(referralToken.value) })
watch(referralToken, (tok) => { void loadRecruiter(tok) })

// ── Per-field validators ──────────────────────────────────────────────
// Each returns null when the field is valid, or a translated error message.
// Kept as computeds so they re-evaluate reactively as the user types AND
// so the labels swap correctly when signupType flips personal↔corporate.

const juristicNameError = computed<string | null>(() => {
  if (form.signupType !== 'corporate') return null
  if (form.juristicName.trim() === '') return t('agentRegister.missing.juristicName')
  return null
})

const firstNameError = computed<string | null>(() => {
  const missingKey = form.signupType === 'corporate' ? 'contactFirstName' : 'firstName'
  const thaiKey = form.signupType === 'corporate' ? 'contactFirstNameThai' : 'firstNameThai'
  if (form.firstName.trim() === '') return t(`agentRegister.missing.${missingKey}`)
  if (!isThaiName(form.firstName)) return t(`agentRegister.missing.${thaiKey}`)
  return null
})

const lastNameError = computed<string | null>(() => {
  const missingKey = form.signupType === 'corporate' ? 'contactLastName' : 'lastName'
  const thaiKey = form.signupType === 'corporate' ? 'contactLastNameThai' : 'lastNameThai'
  if (form.lastName.trim() === '') return t(`agentRegister.missing.${missingKey}`)
  if (!isThaiName(form.lastName)) return t(`agentRegister.missing.${thaiKey}`)
  return null
})

const emailError = computed<string | null>(() => {
  if (form.email.trim() === '') return t('agentRegister.missing.email')
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) return t('agentRegister.missing.email')
  if (emailAvailability.value && emailAvailability.value.available === false) {
    return emailAvailability.value.message
  }
  return null
})

const passwordError = computed<string | null>(() => {
  if (form.password.length < 8) return t('agentRegister.missing.password')
  return null
})

const passwordConfirmationError = computed<string | null>(() => {
  if (form.passwordConfirmation === '') return t('agentRegister.missing.passwordMismatch')
  if (form.password !== form.passwordConfirmation) return t('agentRegister.missing.passwordMismatch')
  return null
})

const idCardError = computed<string | null>(() => {
  if (form.idCard.trim() === '') {
    return form.signupType === 'corporate'
      ? t('agentRegister.missing.taxIdInvalid')
      : t('agentRegister.missing.idCardInvalid')
  }
  if (!isThaiId13(form.idCard)) {
    return form.signupType === 'corporate'
      ? t('agentRegister.missing.taxIdInvalid')
      : t('agentRegister.missing.idCardInvalid')
  }
  if (idCardAvailability.value && idCardAvailability.value.available === false) {
    return idCardAvailability.value.message
  }
  return null
})

const birthDateError = computed<string | null>(() => {
  if (form.birthDate === '') return t('agentRegister.missing.birthDate')
  const d = new Date(form.birthDate)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  if (!(d instanceof Date) || isNaN(d.getTime())) return t('agentRegister.missing.birthDate')
  if (d >= today) return t('agentRegister.missing.birthDateFuture')
  return null
})

const phoneError = computed<string | null>(() => {
  if (form.phone.trim() === '') return t('agentRegister.missing.phoneInvalid')
  if (!isThaiMobile(form.phone)) return t('agentRegister.missing.phoneInvalid')
  return null
})

const termsError = computed<string | null>(() => {
  if (!form.termsAccepted) return t('agentRegister.missing.terms')
  return null
})

// Combine local + server errors per field. Server errors always show
// (they're already "touched" by virtue of a submit having happened),
// local errors show only after touched OR after a first submit attempt.
function fieldError(field: FieldName, localError: string | null): string | null {
  const serverMessages = fieldErrors.value[field]
  if (serverMessages && serverMessages.length > 0) return serverMessages[0]
  if (localError && (touched[field] || attemptedSubmit.value)) return localError
  return null
}

const juristicNameDisplay = computed(() => fieldError('juristicName', juristicNameError.value))
const firstNameDisplay = computed(() => fieldError('firstName', firstNameError.value))
const lastNameDisplay = computed(() => fieldError('lastName', lastNameError.value))
const emailDisplay = computed(() => fieldError('email', emailError.value))
const passwordDisplay = computed(() => fieldError('password', passwordError.value))
const passwordConfirmationDisplay = computed(() => fieldError('passwordConfirmation', passwordConfirmationError.value))
const idCardDisplay = computed(() => fieldError('idCard', idCardError.value))
const birthDateDisplay = computed(() => fieldError('birthDate', birthDateError.value))
const phoneDisplay = computed(() => fieldError('phone', phoneError.value))
const termsDisplay = computed(() => fieldError('termsAccepted', termsError.value))

// A field is "valid ✓" (green check) when it has been filled in and passes
// all local rules AND has no server error against it.
function isValid(field: FieldName, localError: string | null, filled: boolean): boolean {
  if (!filled) return false
  if (localError !== null) return false
  if (fieldErrors.value[field] && fieldErrors.value[field].length > 0) return false
  return true
}

const juristicNameValid = computed(() =>
  form.signupType === 'corporate' && isValid('juristicName', juristicNameError.value, form.juristicName.trim() !== ''),
)
const firstNameValid = computed(() => isValid('firstName', firstNameError.value, form.firstName.trim() !== ''))
const lastNameValid = computed(() => isValid('lastName', lastNameError.value, form.lastName.trim() !== ''))
const emailValid = computed(() => isValid('email', emailError.value, form.email.trim() !== ''))
const passwordValid = computed(() => isValid('password', passwordError.value, form.password.length > 0))
const passwordConfirmationValid = computed(() =>
  isValid('passwordConfirmation', passwordConfirmationError.value, form.passwordConfirmation.length > 0),
)
const idCardValid = computed(() => isValid('idCard', idCardError.value, form.idCard.trim() !== ''))
const phoneValid = computed(() => isValid('phone', phoneError.value, form.phone.trim() !== ''))

// Overall submit-ability: all validators must pass AND email must be OTP-verified.
const canSubmit = computed(() =>
  juristicNameError.value === null &&
  firstNameError.value === null &&
  lastNameError.value === null &&
  emailError.value === null &&
  emailVerified.value &&
  passwordError.value === null &&
  passwordConfirmationError.value === null &&
  idCardError.value === null &&
  birthDateError.value === null &&
  phoneError.value === null &&
  termsError.value === null,
)

// Count of remaining blockers, shown in the submit button so the user
// knows exactly how many more fields need attention.
const remainingCount = computed(() => {
  const errors = [
    juristicNameError.value,
    firstNameError.value,
    lastNameError.value,
    emailError.value,
    passwordError.value,
    passwordConfirmationError.value,
    idCardError.value,
    birthDateError.value,
    phoneError.value,
    termsError.value,
  ]
  const missing = errors.filter((e) => e !== null).length
  return missing + (emailVerified.value ? 0 : 1)
})

// Class helper — tint the input border red / green / neutral based on
// whether an error is currently displayed for the field.
function inputClass(displayError: string | null, valid: boolean): string {
  const base = 'w-full px-3.5 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2'
  if (displayError) return `${base} border-rose-400 focus:border-rose-500 focus:ring-rose-100`
  if (valid) return `${base} border-emerald-400 focus:border-emerald-500 focus:ring-emerald-100`
  return `${base} border-slate-300 focus:border-brand-500 focus:ring-brand-100`
}

async function submit(): Promise<void> {
  attemptedSubmit.value = true
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
      emailOtpToken: otpToken.value,
    })
    done.value = { agentCode: res.agentCode }
    setTimeout(() => router.push('/login'), 3500)
  } catch (e: unknown) {
    if (e instanceof ApiError && e.body?.errors) {
      // Best-effort translation for known server messages so the field-
      // level errors show in Thai. Unknown messages pass through unchanged.
      const localized: Record<string, string[]> = {}
      for (const [field, messages] of Object.entries(e.body.errors as Record<string, string[]>)) {
        localized[field] = messages.map((m) => translateServerFieldError(field, m))
      }
      fieldErrors.value = localized
      error.value = t('agentRegister.submitFailed')
    } else {
      error.value = t('agentRegister.submitFailed')
    }
    // Form data is preserved — the user only needs to fix the flagged fields.
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
        <div>{{ error }}</div>
        <div class="text-xs mt-1 text-rose-600">{{ t('agentRegister.dataPreserved') }}</div>
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
          <div class="relative">
            <input v-model.trim="form.juristicName"
              :placeholder="t('agentRegister.placeholders.juristicName')"
              :class="inputClass(juristicNameDisplay, juristicNameValid)"
              @blur="markTouched('juristicName')"
              @input="clearServerError('juristicName')" />
            <i v-if="juristicNameValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2" />
          </div>
          <p v-if="juristicNameDisplay" class="text-xs text-rose-600 mt-1">{{ juristicNameDisplay }}</p>
          <p v-else class="text-xs text-slate-500 mt-1">{{ t('agentRegister.hints.juristicName') }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">
              {{ form.signupType === 'corporate' ? t('agentRegister.contactFirstName') : t('agentRegister.firstName') }}
              <span class="text-rose-500">*</span>
            </label>
            <div class="relative">
              <input v-model.trim="form.firstName" lang="th"
                :class="inputClass(firstNameDisplay, firstNameValid)"
                @blur="markTouched('firstName')"
                @input="clearServerError('firstName')" />
              <i v-if="firstNameValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2" />
            </div>
            <p v-if="firstNameDisplay" class="text-xs text-rose-600 mt-1">{{ firstNameDisplay }}</p>
            <p v-else class="text-xs text-slate-500 mt-1">{{ t('agentRegister.hints.nameThai') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">
              {{ form.signupType === 'corporate' ? t('agentRegister.contactLastName') : t('agentRegister.lastName') }}
              <span class="text-rose-500">*</span>
            </label>
            <div class="relative">
              <input v-model.trim="form.lastName" lang="th"
                :class="inputClass(lastNameDisplay, lastNameValid)"
                @blur="markTouched('lastName')"
                @input="clearServerError('lastName')" />
              <i v-if="lastNameValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2" />
            </div>
            <p v-if="lastNameDisplay" class="text-xs text-rose-600 mt-1">{{ lastNameDisplay }}</p>
            <p v-else class="text-xs text-slate-500 mt-1">{{ t('agentRegister.hints.nameThai') }}</p>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('agentRegister.email') }} <span class="text-rose-500">*</span>
            <span v-if="emailVerified" class="ml-2 inline-flex items-center gap-1 text-xs font-medium text-emerald-600">
              <i class="pi pi-verified" /> {{ t('agentRegister.otp.verifiedBadge') }}
            </span>
          </label>
          <div class="flex gap-2">
            <div class="relative flex-1">
              <input v-model.trim="form.email" type="email" autocomplete="email"
                :class="inputClass(emailDisplay, emailValid || emailVerified)"
                @blur="markTouched('email')"
                @input="clearServerError('email')" />
              <i v-if="emailVerified" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2" />
            </div>
            <button v-if="!emailVerified" type="button"
              :disabled="emailError !== null || otpSending || otpCooldown > 0"
              @click="sendOtp"
              class="px-4 py-2.5 rounded-lg border border-brand-500 text-brand-600 text-sm font-medium hover:bg-brand-50 disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
              <i v-if="otpSending" class="pi pi-spin pi-spinner mr-1" />
              <span v-if="otpSending">{{ t('agentRegister.otp.sending') }}</span>
              <span v-else-if="otpCooldown > 0">{{ t('agentRegister.otp.resendIn', { s: otpCooldown }) }}</span>
              <span v-else-if="otpVisible">{{ t('agentRegister.otp.resend') }}</span>
              <span v-else>{{ t('agentRegister.otp.send') }}</span>
            </button>
          </div>
          <p v-if="emailDisplay" class="text-xs text-rose-600 mt-1">{{ emailDisplay }}</p>
          <p v-else-if="!emailVerified && !otpVisible" class="text-xs text-slate-500 mt-1">{{ t('agentRegister.otp.hint') }}</p>

          <div v-if="otpVisible && !emailVerified" class="mt-3 p-3 rounded-lg bg-brand-50 border border-brand-200">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.otp.enterCode') }}</label>
            <div class="flex items-center gap-2">
              <input v-model="otpCode" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                autocomplete="one-time-code" placeholder="123456"
                class="flex-1 px-3.5 py-2.5 border border-slate-300 rounded-lg text-lg font-mono tracking-widest text-center focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
              <button type="button"
                :disabled="otpCode.length !== 6 || otpVerifying"
                @click="verifyOtp"
                class="px-4 py-2.5 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 disabled:opacity-50">
                <i v-if="otpVerifying" class="pi pi-spin pi-spinner mr-1" />
                <span v-if="otpVerifying">{{ t('agentRegister.otp.verifying') }}</span>
                <span v-else>{{ t('agentRegister.otp.verify') }}</span>
              </button>
            </div>
            <p v-if="otpDevCode" class="text-xs text-amber-700 mt-2 font-mono">
              <i class="pi pi-info-circle mr-1" /> DEV: code is <strong>{{ otpDevCode }}</strong>
            </p>
            <p v-if="otpInfo && !otpError" class="text-xs text-brand-700 mt-2">{{ otpInfo }}</p>
            <p v-if="otpError" class="text-xs text-rose-600 mt-2">{{ otpError }}</p>
          </div>
          <p v-else-if="otpError && !otpVisible" class="text-xs text-rose-600 mt-1">{{ otpError }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.password') }} <span class="text-rose-500">*</span></label>
            <input v-model="form.password" type="password" autocomplete="new-password"
              :class="inputClass(passwordDisplay, passwordValid)"
              @blur="markTouched('password')"
              @input="clearServerError('password')" />
            <p v-if="passwordDisplay" class="text-xs text-rose-600 mt-1">{{ passwordDisplay }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.confirmPassword') }} <span class="text-rose-500">*</span></label>
            <input v-model="form.passwordConfirmation" type="password" autocomplete="new-password"
              :class="inputClass(passwordConfirmationDisplay, passwordConfirmationValid)"
              @blur="markTouched('passwordConfirmation')"
              @input="clearServerError('password_confirmation')" />
            <p v-if="passwordConfirmationDisplay" class="text-xs text-rose-600 mt-1">{{ passwordConfirmationDisplay }}</p>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ form.signupType === 'corporate' ? t('agentRegister.taxId') : t('agentRegister.idCard') }}
            <span class="text-rose-500">*</span>
          </label>
          <div class="relative">
            <input v-model.trim="form.idCard" inputmode="numeric" maxlength="13"
              :placeholder="form.signupType === 'corporate' ? t('agentRegister.placeholders.taxId') : t('agentRegister.placeholders.idCard')"
              :class="inputClass(idCardDisplay, idCardValid)"
              @blur="markTouched('idCard')"
              @input="clearServerError('idCard')" />
            <i v-if="idCardValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2" />
          </div>
          <p v-if="idCardDisplay" class="text-xs text-rose-600 mt-1">{{ idCardDisplay }}</p>
          <p v-else class="text-xs text-slate-500 mt-1">{{ t('agentRegister.hints.thaiId13') }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.birthDate') }} <span class="text-rose-500">*</span></label>
            <DateInput v-model="form.birthDate"
              :max="toIsoDate(new Date())"
              @update:model-value="() => { markTouched('birthDate'); clearServerError('birthDate') }" />
            <p v-if="birthDateDisplay" class="text-xs text-rose-600 mt-1">{{ birthDateDisplay }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.phone') }} <span class="text-rose-500">*</span></label>
            <div class="relative">
              <input v-model.trim="form.phone" type="tel" inputmode="tel" maxlength="14" placeholder="08x-xxx-xxxx"
                :class="inputClass(phoneDisplay, phoneValid)"
                @blur="markTouched('phone')"
                @input="clearServerError('phone')" />
              <i v-if="phoneValid" class="pi pi-check text-emerald-500 absolute right-3 top-1/2 -translate-y-1/2" />
            </div>
            <p v-if="phoneDisplay" class="text-xs text-rose-600 mt-1">{{ phoneDisplay }}</p>
            <p v-else class="text-xs text-slate-500 mt-1">{{ t('agentRegister.hints.thaiMobile') }}</p>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">{{ t('agentRegister.lineId') }}</label>
          <input v-model.trim="form.lineId"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
        </div>

        <label class="flex items-start gap-2 pt-2 cursor-pointer">
          <input v-model="form.termsAccepted" type="checkbox" class="mt-0.5"
            @change="markTouched('termsAccepted')" />
          <span class="text-sm text-slate-600">{{ t('agentRegister.terms') }}</span>
        </label>
        <p v-if="termsDisplay" class="text-xs text-rose-600 -mt-2 ml-6">{{ termsDisplay }}</p>

        <button type="submit" :disabled="submitting"
          class="w-full py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-60 disabled:cursor-not-allowed">
          <i v-if="submitting" class="pi pi-spin pi-spinner mr-2" />
          <span v-if="submitting">{{ t('agentRegister.submitting') }}</span>
          <span v-else-if="canSubmit">{{ t('agentRegister.submit') }}</span>
          <span v-else>{{ t('agentRegister.submitWithRemaining', { n: remainingCount }) }}</span>
        </button>

        <p class="text-center text-sm text-slate-500 mt-4">
          {{ t('agentRegister.haveAccount') }}
          <RouterLink to="/login" class="text-brand-600 hover:text-brand-700 font-medium">{{ t('agentRegister.login') }}</RouterLink>
        </p>
      </form>
    </div>
  </div>
</template>
