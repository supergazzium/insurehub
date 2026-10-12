<script setup lang="ts">
// Change-password form. The controller revokes all *other* Sanctum tokens
// on success, so the current session survives but other devices sign out.
import { computed, reactive, ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { changePassword, fetchLineConnectUrl, disconnectLine } from '../../api/portal'
import { ApiError } from '../../api/client'
import { useAuthStore } from '../../stores/auth'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

// #1 — LINE connect/disconnect. lineLinked comes from the auth user payload.
const lineLinked = computed(() => auth.user?.lineLinked ?? false)
const lineBusy = ref(false)
const lineMsg = ref<{ ok: boolean; text: string } | null>(null)

async function connectLine(): Promise<void> {
  lineBusy.value = true
  lineMsg.value = null
  try {
    const { url } = await fetchLineConnectUrl()
    window.location.href = url
  } catch (e) {
    lineMsg.value = { ok: false, text: e instanceof ApiError && e.status === 503 ? 'LINE Login ยังไม่ได้ตั้งค่า' : 'เริ่มเชื่อม LINE ไม่สำเร็จ' }
    lineBusy.value = false
  }
}
async function unlinkLine(): Promise<void> {
  lineBusy.value = true
  lineMsg.value = null
  try {
    await disconnectLine()
    await auth.restore()        // refresh lineLinked
    lineMsg.value = { ok: true, text: 'ยกเลิกการเชื่อม LINE แล้ว' }
  } catch {
    lineMsg.value = { ok: false, text: 'ยกเลิกการเชื่อมไม่สำเร็จ' }
  } finally {
    lineBusy.value = false
  }
}

// Handle the redirect back from LINE connect.
onMounted(async () => {
  if (route.query.line_connected === '1') {
    await auth.restore()
    lineMsg.value = { ok: true, text: 'เชื่อม LINE สำเร็จ — ครั้งถัดไปเข้าสู่ระบบด้วย LINE ได้เลย' }
    history.replaceState(null, '', route.path)
  } else if (route.query.line_error === 'already_linked') {
    lineMsg.value = { ok: false, text: 'บัญชี LINE นี้ถูกเชื่อมกับบัญชีอื่นแล้ว' }
    history.replaceState(null, '', route.path)
  }
})

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

    <!-- #1 — LINE Login connection -->
    <section class="card p-5 space-y-3">
      <h2 class="text-sm font-semibold text-slate-600">เข้าสู่ระบบด้วย LINE</h2>
      <p class="text-xs text-slate-500">เชื่อมบัญชี LINE เพื่อเข้าสู่ระบบด้วย LINE ได้โดยไม่ต้องกรอกรหัสผ่าน</p>

      <div v-if="lineMsg" :class="['px-3 py-2 rounded-md text-sm', lineMsg.ok ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-rose-50 border border-rose-200 text-rose-700']">
        {{ lineMsg.text }}
      </div>

      <div class="flex items-center justify-between gap-3">
        <span class="inline-flex items-center gap-2 text-sm"
          :class="lineLinked ? 'text-emerald-700' : 'text-slate-500'">
          <i :class="lineLinked ? 'pi pi-check-circle' : 'pi pi-link'" />
          {{ lineLinked ? 'เชื่อม LINE แล้ว' : 'ยังไม่ได้เชื่อม LINE' }}
        </span>
        <button v-if="!lineLinked" type="button" :disabled="lineBusy" @click="connectLine"
          class="px-4 py-2 rounded-lg bg-[#06C755] text-white text-sm hover:bg-[#05b34c] disabled:opacity-50 inline-flex items-center gap-2">
          <i v-if="lineBusy" class="pi pi-spin pi-spinner" /><i v-else class="pi pi-comment" /> เชื่อม LINE
        </button>
        <button v-else type="button" :disabled="lineBusy" @click="unlinkLine"
          class="px-4 py-2 rounded-lg border border-rose-200 text-rose-600 text-sm hover:bg-rose-50 disabled:opacity-50">
          ยกเลิกการเชื่อม
        </button>
      </div>
    </section>
  </div>
</template>
