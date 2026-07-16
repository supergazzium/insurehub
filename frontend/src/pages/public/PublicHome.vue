<script setup lang="ts">
// Marketing homepage — hero + benefits grid + FAQ accordion.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const openFaq = ref<number | null>(null)
function toggleFaq(i: number): void {
  openFaq.value = openFaq.value === i ? null : i
}

const benefits = [
  { icon: 'pi-th-large', key: 'products' },
  { icon: 'pi-dollar', key: 'income' },
  { icon: 'pi-graduation-cap', key: 'training' },
  { icon: 'pi-users', key: 'team' },
]
const faqs = [0, 1, 2, 3, 4]
</script>

<template>
  <!-- Hero -->
  <section class="bg-gradient-to-br from-brand-50 to-white py-20">
    <div class="max-w-4xl mx-auto text-center px-6">
      <h1 class="text-4xl md:text-5xl font-bold text-slate-900 leading-tight">
        {{ t('public.hero.title') }}
      </h1>
      <p class="text-slate-600 mt-4 text-lg max-w-2xl mx-auto">
        {{ t('public.hero.subtitle') }}
      </p>
      <div class="mt-8 flex items-center justify-center gap-3">
        <RouterLink to="/register-agent"
          class="px-6 py-3 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700">
          {{ t('public.hero.cta') }}
        </RouterLink>
        <RouterLink to="/login"
          class="px-6 py-3 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
          {{ t('public.hero.login') }}
        </RouterLink>
      </div>
    </div>
  </section>

  <!-- Benefits grid -->
  <section class="max-w-5xl mx-auto px-6 py-16">
    <h2 class="text-2xl font-semibold text-slate-900 text-center">{{ t('public.benefits.title') }}</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mt-10">
      <div v-for="b in benefits" :key="b.key"
        class="p-6 rounded-xl border border-slate-200 bg-white hover:border-brand-300 transition">
        <div class="w-10 h-10 rounded-md bg-brand-50 text-brand-600 flex items-center justify-center mb-4">
          <i :class="['pi', b.icon]" />
        </div>
        <h3 class="font-semibold text-slate-900">{{ t(`public.benefits.${b.key}.title`) }}</h3>
        <p class="text-sm text-slate-600 mt-1.5">{{ t(`public.benefits.${b.key}.body`) }}</p>
      </div>
    </div>
  </section>

  <!-- FAQ accordion -->
  <section class="bg-slate-50 py-16">
    <div class="max-w-3xl mx-auto px-6">
      <h2 class="text-2xl font-semibold text-slate-900 text-center">{{ t('public.faq.title') }}</h2>
      <div class="mt-8 divide-y divide-slate-200 border border-slate-200 rounded-xl bg-white">
        <div v-for="i in faqs" :key="i">
          <button type="button" class="w-full flex items-center justify-between px-5 py-4 text-left"
            @click="toggleFaq(i)">
            <span class="font-medium text-slate-900">{{ t(`public.faq.items.${i}.q`) }}</span>
            <i :class="['pi', openFaq === i ? 'pi-chevron-up' : 'pi-chevron-down', 'text-slate-400 text-xs']" />
          </button>
          <div v-if="openFaq === i" class="px-5 pb-4 text-sm text-slate-600">
            {{ t(`public.faq.items.${i}.a`) }}
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
