<script setup lang="ts">
// Marketing / public-facing shell used by the homepage, agent-register, and
// any future non-authenticated pages that need a real header/footer instead
// of the plain AuthLayout used by login/forgot.
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <div class="min-h-screen flex flex-col bg-white">
    <header class="border-b border-slate-200">
      <div class="max-w-6xl mx-auto flex items-center justify-between px-6 py-4">
        <RouterLink to="/home" class="flex items-center">
          <img src="/brand/logo.png" alt="InsureHub" class="h-9 w-auto object-contain" />
        </RouterLink>
        <nav class="flex items-center gap-6 text-sm">
          <RouterLink to="/home" class="text-slate-700 hover:text-brand-600" active-class="text-brand-700 font-medium">
            {{ t('public.nav.home') }}
          </RouterLink>
          <RouterLink to="/register-agent" class="text-slate-700 hover:text-brand-600" active-class="text-brand-700 font-medium">
            {{ t('public.nav.registerAgent') }}
          </RouterLink>
          <RouterLink to="/login" class="px-3 py-1.5 rounded-md bg-brand-600 text-white hover:bg-brand-700">
            {{ t('public.nav.login') }}
          </RouterLink>
        </nav>
      </div>
    </header>

    <main class="flex-1">
      <router-view />
    </main>

    <footer class="border-t border-slate-200 mt-16">
      <div class="max-w-6xl mx-auto px-6 py-8 text-sm text-slate-500 grid grid-cols-1 md:grid-cols-3 gap-6">
        <div>
          <div class="font-semibold text-slate-900 mb-1">InsureHub Broker</div>
          <p>{{ t('public.footer.tagline') }}</p>
        </div>
        <div>
          <div class="font-semibold text-slate-900 mb-1">{{ t('public.footer.registration') }}</div>
          <p>{{ t('public.footer.taxId') }}: 0-0000-00000-00-0</p>
          <p>{{ t('public.footer.oicLicense') }}: ค-0000-0000</p>
        </div>
        <div>
          <div class="font-semibold text-slate-900 mb-1">{{ t('public.footer.contact') }}</div>
          <p>ops@insurehub.co.th</p>
          <p>02-XXX-XXXX</p>
        </div>
      </div>
      <div class="border-t border-slate-100 py-4 text-center text-xs text-slate-400">
        © 2026 InsureHub. All rights reserved.
      </div>
    </footer>
  </div>
</template>
