<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const email = ref('')
const submitting = ref(false)
const sent = ref(false)

const valid = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value))

async function submit() {
  if (!valid.value) return
  submitting.value = true
  await new Promise((r) => setTimeout(r, 600))
  submitting.value = false
  sent.value = true
}
</script>

<template>
  <div>
    <RouterLink to="/login" class="text-sm text-slate-500 hover:text-slate-900 mb-6 inline-flex items-center gap-1">
      <i class="pi pi-arrow-left text-xs" />
      {{ t('auth.forgot.backToLogin') }}
    </RouterLink>

    <div v-if="!sent">
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('auth.forgot.title') }}</h1>
      <p class="text-slate-500 mt-1.5 text-sm">{{ t('auth.forgot.subtitle') }}</p>

      <form class="mt-8 space-y-4" @submit.prevent="submit">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">
            {{ t('auth.forgot.email') }}
          </label>
          <input
            v-model="email"
            type="email"
            required
            autocomplete="email"
            placeholder="name@agency.co.th"
            class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
          />
        </div>

        <button
          type="submit"
          :disabled="!valid || submitting"
          class="w-full py-2.5 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
        >
          <i v-if="submitting" class="pi pi-spin pi-spinner" />
          <span>{{ t('auth.forgot.submit') }}</span>
        </button>
      </form>
    </div>

    <div v-else class="text-center py-6">
      <div class="w-16 h-16 mx-auto bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mb-4">
        <i class="pi pi-envelope text-2xl" />
      </div>
      <h2 class="text-xl font-semibold text-slate-900">ตรวจสอบอีเมลของคุณ</h2>
      <p class="text-slate-500 mt-2 text-sm">{{ t('auth.forgot.sent') }}</p>
      <p class="text-slate-400 text-xs mt-4">{{ email }}</p>
    </div>
  </div>
</template>
