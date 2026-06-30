<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { MODULES } from '../types/modules'

const route = useRoute()
const { t } = useI18n()

const mod = computed(() => {
  return MODULES.find((m) => m.routeName === route.name) ?? MODULES[0]
})

const moduleName = computed(() => t(`modules.${mod.value.i18nKey}.name`))
const moduleDescription = computed(() => t(`modules.${mod.value.i18nKey}.description`))
</script>

<template>
  <div class="space-y-6">
    <header class="card p-6">
      <div class="flex items-start gap-4">
        <div class="w-12 h-12 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center text-2xl">
          <i :class="mod.icon" />
        </div>
        <div class="flex-1">
          <div class="flex items-center gap-3 text-xs text-slate-500 mb-1">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-slate-100 rounded-md">
              {{ t('page.placeholder.moduleNumber') }} {{ mod.number }}
            </span>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-amber-50 text-amber-700 rounded-md">
              <i class="pi pi-clock text-[10px]" />
              {{ t('page.placeholder.title') }}
            </span>
          </div>
          <h1 class="text-2xl font-semibold text-slate-900">{{ moduleName }}</h1>
          <p class="text-slate-500 mt-1">{{ moduleDescription }}</p>
        </div>
      </div>
    </header>

    <section class="card p-6">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-semibold text-slate-900">
          {{ t('page.placeholder.functionsHeader') }}
        </h2>
        <span class="text-xs text-slate-500">
          {{ t('page.placeholder.functionCount') }}: {{ mod.functions.length }}
        </span>
      </div>

      <ol class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2">
        <li
          v-for="(fn, i) in mod.functions"
          :key="i"
          class="flex items-start gap-3 py-2 border-b border-slate-100 last:border-b-0"
        >
          <span class="text-xs text-slate-400 font-mono w-6 pt-0.5">
            {{ String(i + 1).padStart(2, '0') }}
          </span>
          <span class="text-sm text-slate-700 leading-relaxed">{{ fn }}</span>
        </li>
      </ol>
    </section>
  </div>
</template>
