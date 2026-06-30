<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()

// Demo: pretend invitation lookup. Real impl will fetch by token.
const invitation = computed(() => ({
  inviterName: 'สมชาย แก้วประเสริฐ',
  inviterEmail: 'somchai@abc-insure.co.th',
  agencyName: 'บริษัท เอบีซี อินชัวรันส์ จำกัด',
  recipientEmail: 'newuser@abc-insure.co.th',
  role: 'agent' as const,
  token: route.query.token ?? 'demo-token',
  expired: false,
}))

const password = ref('')
const confirmPassword = ref('')
const submitting = ref(false)

const passwordStrong = computed(
  () =>
    password.value.length >= 8 &&
    /[A-Z]/.test(password.value) &&
    /[0-9]/.test(password.value) &&
    /[^A-Za-z0-9]/.test(password.value),
)

const match = computed(() => password.value.length > 0 && password.value === confirmPassword.value)
const canAccept = computed(() => passwordStrong.value && match.value && !submitting.value)

async function accept() {
  if (!canAccept.value) return
  submitting.value = true
  await new Promise((r) => setTimeout(r, 600))
  submitting.value = false
  router.push('/')
}
</script>

<template>
  <div v-if="invitation.expired" class="text-center py-8">
    <div class="w-16 h-16 mx-auto bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mb-4">
      <i class="pi pi-times-circle text-3xl" />
    </div>
    <h2 class="text-xl font-semibold text-slate-900">{{ t('auth.invite.expired') }}</h2>
    <RouterLink to="/login" class="text-brand-600 text-sm mt-4 inline-block">
      {{ t('auth.forgot.backToLogin') }}
    </RouterLink>
  </div>

  <div v-else>
    <h1 class="text-2xl font-semibold text-slate-900">{{ t('auth.invite.title') }}</h1>

    <div class="mt-6 card p-5 space-y-3">
      <div class="flex items-center gap-3 pb-3 border-b border-slate-100">
        <div class="w-10 h-10 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center font-medium">
          {{ invitation.inviterName.charAt(0) }}
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-xs text-slate-500">{{ t('auth.invite.invitedBy') }}</div>
          <div class="text-sm text-slate-900 font-medium truncate">{{ invitation.inviterName }}</div>
          <div class="text-xs text-slate-500 truncate">{{ invitation.inviterEmail }}</div>
        </div>
      </div>

      <dl class="grid grid-cols-3 gap-y-2 text-sm">
        <dt class="text-slate-500 col-span-1">{{ t('auth.invite.agency') }}</dt>
        <dd class="col-span-2 text-slate-900 font-medium">{{ invitation.agencyName }}</dd>
        <dt class="text-slate-500 col-span-1">{{ t('auth.login.email') }}</dt>
        <dd class="col-span-2 text-slate-900">{{ invitation.recipientEmail }}</dd>
        <dt class="text-slate-500 col-span-1">{{ t('auth.invite.role') }}</dt>
        <dd class="col-span-2">
          <span class="inline-flex px-2 py-0.5 rounded-md bg-brand-50 text-brand-700 text-xs font-medium">
            {{ t(`auth.users.roles.${invitation.role}`) }}
          </span>
        </dd>
      </dl>
    </div>

    <div class="mt-6">
      <h3 class="text-sm font-medium text-slate-900 mb-3">{{ t('auth.invite.setPassword') }}</h3>
      <form class="space-y-3" @submit.prevent="accept">
        <input
          v-model="password"
          type="password"
          required
          :placeholder="t('auth.register.owner.password')"
          autocomplete="new-password"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
        <p class="text-xs" :class="passwordStrong ? 'text-emerald-600' : 'text-slate-500'">
          <i :class="passwordStrong ? 'pi pi-check-circle' : 'pi pi-info-circle'" class="mr-1" />
          {{ t('auth.register.owner.passwordHint') }}
        </p>
        <input
          v-model="confirmPassword"
          type="password"
          required
          :placeholder="t('auth.register.owner.confirmPassword')"
          autocomplete="new-password"
          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
        />
        <p v-if="confirmPassword && !match" class="text-xs text-rose-600">
          <i class="pi pi-times-circle mr-1" />
          {{ t('auth.reset.mismatch') }}
        </p>

        <div class="grid grid-cols-3 gap-2 pt-3">
          <button
            type="button"
            class="col-span-1 py-2.5 rounded-lg border border-slate-300 text-slate-600 font-medium text-sm hover:bg-slate-50 transition"
          >
            {{ t('auth.invite.decline') }}
          </button>
          <button
            type="submit"
            :disabled="!canAccept"
            class="col-span-2 py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
          >
            <i v-if="submitting" class="pi pi-spin pi-spinner" />
            <span>{{ t('auth.invite.accept') }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
