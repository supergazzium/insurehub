<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const router = useRouter()

const step = ref<1 | 2 | 3>(1)

// Fake otpauth URL used to render a QR (using a public QR service for the demo).
const issuer = 'InsureHub'
const account = 'admin@insurehub.test'
const secret = 'JBSWY3DPEHPK3PXP'
const otpauthUrl = computed(
  () => `otpauth://totp/${encodeURIComponent(issuer)}:${encodeURIComponent(account)}?secret=${secret}&issuer=${encodeURIComponent(issuer)}`,
)
const qrUrl = computed(
  () =>
    `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(otpauthUrl.value)}`,
)

const code = ref(['', '', '', '', '', ''])
const codeInputs = ref<HTMLInputElement[]>([])
const codeComplete = computed(() => code.value.every((d) => /^\d$/.test(d)))

const keyCopied = ref(false)
async function copyKey() {
  try {
    await navigator.clipboard.writeText(secret)
    keyCopied.value = true
    setTimeout(() => (keyCopied.value = false), 1500)
  } catch {
    /* ignore */
  }
}

function onCodeInput(idx: number, e: Event) {
  const val = (e.target as HTMLInputElement).value.replace(/\D/g, '').slice(-1)
  code.value[idx] = val
  if (val && idx < 5) codeInputs.value[idx + 1]?.focus()
}
function onCodeKeydown(idx: number, e: KeyboardEvent) {
  if (e.key === 'Backspace' && !code.value[idx] && idx > 0) codeInputs.value[idx - 1]?.focus()
}
function onCodePaste(e: ClipboardEvent) {
  const text = e.clipboardData?.getData('text') ?? ''
  const digits = text.replace(/\D/g, '').slice(0, 6).split('')
  if (!digits.length) return
  e.preventDefault()
  digits.forEach((d, i) => (code.value[i] = d))
  codeInputs.value[Math.min(digits.length, 5)]?.focus()
}

const submitting = ref(false)
async function verifyAndAdvance() {
  if (!codeComplete.value) return
  submitting.value = true
  await new Promise((r) => setTimeout(r, 500))
  submitting.value = false
  step.value = 3
}

// Demo backup codes
const backupCodes = [
  '4F2A-9D81', 'B7C3-12E5', '8H6N-4P0R', 'X9Y2-Q3L1',
  '7K5M-N2VW', '0G8B-T4ZA', 'R6S1-J3DM', 'P9C4-W8XF',
]

function downloadBackup() {
  const text = `InsureHub – รหัสสำรอง (Backup codes)\nบัญชี: ${account}\nวันที่: ${new Date().toLocaleString('th-TH')}\n\n${backupCodes.join('\n')}\n\nเก็บรหัสเหล่านี้ในที่ปลอดภัย ใช้ได้เพียงครั้งเดียวต่อรหัส.\n`
  const blob = new Blob([text], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'insurehub-backup-codes.txt'
  a.click()
  URL.revokeObjectURL(url)
}

async function finish() {
  submitting.value = true
  await new Promise((r) => setTimeout(r, 400))
  submitting.value = false
  router.push('/auth')
}

function goStep2() {
  step.value = 2
  nextTick(() => codeInputs.value[0]?.focus())
}
</script>

<template>
  <div class="max-w-2xl mx-auto space-y-6">
    <header>
      <div class="text-xs text-slate-500 mb-1">
        <RouterLink to="/auth" class="hover:text-slate-900">{{ t('modules.auth.short') }}</RouterLink>
        <span class="mx-1 text-slate-300">/</span>
        <span class="text-slate-900">MFA</span>
      </div>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('auth.mfa.setupTitle') }}</h1>
      <p class="text-slate-500 mt-1 text-sm">{{ t('auth.mfa.setupSubtitle') }}</p>
    </header>

    <!-- Stepper -->
    <div class="flex items-center gap-2">
      <template v-for="n in 3" :key="n">
        <div
          :class="[
            'w-7 h-7 rounded-full text-xs font-semibold flex items-center justify-center shrink-0',
            step >= (n as 1 | 2 | 3) ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-400 border border-slate-200',
          ]"
        >
          <i v-if="step > (n as 1 | 2 | 3)" class="pi pi-check text-[10px]" />
          <span v-else>{{ n }}</span>
        </div>
        <div v-if="n < 3" class="flex-1 h-px bg-slate-200" />
      </template>
    </div>

    <!-- Step 1 -->
    <section v-if="step === 1" class="card p-6 space-y-5">
      <h2 class="font-semibold text-slate-900">{{ t('auth.mfa.step1') }}</h2>
      <p class="text-sm text-slate-500">{{ t('auth.mfa.step1Hint') }}</p>

      <div class="flex flex-col sm:flex-row gap-6 items-center sm:items-start">
        <div class="w-[200px] h-[200px] bg-white border border-slate-200 rounded-lg p-2 shrink-0">
          <img :src="qrUrl" alt="MFA QR" class="w-full h-full" />
        </div>

        <div class="flex-1 min-w-0 w-full">
          <div class="text-xs text-slate-500 mb-1.5">{{ t('auth.mfa.manualKey') }}</div>
          <div class="flex items-center gap-2">
            <code class="flex-1 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-sm tracking-wider text-slate-900 truncate">
              {{ secret }}
            </code>
            <button
              type="button"
              @click="copyKey"
              class="px-3 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50 transition flex items-center gap-1.5"
            >
              <i :class="keyCopied ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" />
              <span class="hidden sm:inline">{{ keyCopied ? t('auth.mfa.copied') : t('auth.mfa.copyKey') }}</span>
            </button>
          </div>

          <div class="mt-4 text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg p-3">
            <div class="font-medium text-slate-700 mb-1">บัญชี</div>
            <div>{{ account }}</div>
          </div>
        </div>
      </div>

      <div class="flex justify-end">
        <button
          type="button"
          @click="goStep2"
          class="px-5 py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition"
        >
          {{ t('common.next') }}
        </button>
      </div>
    </section>

    <!-- Step 2 -->
    <section v-else-if="step === 2" class="card p-6 space-y-5">
      <h2 class="font-semibold text-slate-900">{{ t('auth.mfa.step2') }}</h2>

      <div class="flex justify-center gap-2" @paste="onCodePaste">
        <input
          v-for="(_, i) in code"
          :key="i"
          :ref="(el) => { if (el) codeInputs[i] = el as HTMLInputElement }"
          v-model="code[i]"
          type="text"
          inputmode="numeric"
          maxlength="1"
          class="w-12 h-14 text-center text-xl font-semibold border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          @input="onCodeInput(i, $event)"
          @keydown="onCodeKeydown(i, $event)"
        />
      </div>

      <div class="flex items-center justify-between gap-3">
        <button
          type="button"
          @click="step = 1"
          class="px-4 py-2.5 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition"
        >
          {{ t('common.back') }}
        </button>
        <button
          type="button"
          :disabled="!codeComplete || submitting"
          @click="verifyAndAdvance"
          class="px-5 py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
        >
          <i v-if="submitting" class="pi pi-spin pi-spinner" />
          <span>{{ t('auth.mfa.confirmSetup') }}</span>
        </button>
      </div>
    </section>

    <!-- Step 3 -->
    <section v-else class="card p-6 space-y-5">
      <h2 class="font-semibold text-slate-900">{{ t('auth.mfa.step3') }}</h2>
      <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-3 flex items-start gap-2">
        <i class="pi pi-exclamation-triangle mt-0.5" />
        <span>{{ t('auth.mfa.step3Hint') }}</span>
      </p>

      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <code
          v-for="c in backupCodes"
          :key="c"
          class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-sm text-center text-slate-900"
        >
          {{ c }}
        </code>
      </div>

      <div class="flex items-center justify-between gap-3">
        <button
          type="button"
          @click="downloadBackup"
          class="px-4 py-2.5 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition flex items-center gap-2"
        >
          <i class="pi pi-download" />
          {{ t('auth.mfa.downloadBackup') }}
        </button>
        <button
          type="button"
          :disabled="submitting"
          @click="finish"
          class="px-5 py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 flex items-center gap-2"
        >
          <i v-if="submitting" class="pi pi-spin pi-spinner" />
          <span>เสร็จสิ้น</span>
        </button>
      </div>
    </section>
  </div>
</template>
