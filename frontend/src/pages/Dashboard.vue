<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { MODULES, MODULE_GROUPS } from '../types/modules'

const { t } = useI18n()

const stats = [
  { key: 'activePolicies', value: '1,284', delta: '+5.2%', icon: 'pi pi-file', tone: 'sky' },
  { key: 'newCustomers', value: '146', delta: '+12%', icon: 'pi pi-user-plus', tone: 'emerald' },
  { key: 'premiumVolume', value: '฿24.6M', delta: '+8.4%', icon: 'pi pi-chart-line', tone: 'violet' },
  { key: 'pendingPayouts', value: '฿1.92M', delta: '32 ตัวแทน', icon: 'pi pi-wallet', tone: 'amber' },
]

const toneClasses: Record<string, string> = {
  sky: 'bg-sky-50 text-sky-700',
  emerald: 'bg-emerald-50 text-emerald-700',
  violet: 'bg-violet-50 text-violet-700',
  amber: 'bg-amber-50 text-amber-700',
}

const groupedModules = MODULE_GROUPS.map((g) => ({
  ...g,
  modules: MODULES.filter((m) => m.group === g.key),
}))
</script>

<template>
  <div class="space-y-8">
    <section>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('dashboard.welcome') }}</h1>
      <p class="text-slate-500 mt-1">{{ t('app.tagline') }}</p>
    </section>

    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div v-for="s in stats" :key="s.key" class="card p-5">
        <div class="flex items-center justify-between">
          <div :class="['w-10 h-10 rounded-lg flex items-center justify-center', toneClasses[s.tone]]">
            <i :class="s.icon" />
          </div>
          <span class="text-xs text-emerald-600 font-medium">{{ s.delta }}</span>
        </div>
        <div class="mt-4">
          <div class="text-2xl font-semibold text-slate-900">{{ s.value }}</div>
          <div class="text-sm text-slate-500 mt-0.5">
            {{ t(`dashboard.stats.${s.key}`) }}
          </div>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-lg font-semibold text-slate-900 mb-3">{{ t('nav.modules') }}</h2>
      <div class="space-y-6">
        <div v-for="group in groupedModules" :key="group.key">
          <h3 class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-2">
            {{ group.labelTh }}
          </h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <RouterLink
              v-for="m in group.modules"
              :key="m.key"
              :to="m.routePath"
              class="card p-4 hover:border-brand-300 hover:shadow-md transition group"
            >
              <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-lg bg-slate-50 group-hover:bg-brand-50 text-slate-600 group-hover:text-brand-600 flex items-center justify-center transition">
                  <i :class="m.icon" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 text-xs text-slate-400 mb-0.5">
                    <span>#{{ m.number }}</span>
                    <span>·</span>
                    <span>{{ m.functions.length }} ฟังก์ชัน</span>
                  </div>
                  <div class="font-medium text-slate-900 group-hover:text-brand-700 transition">
                    {{ t(`modules.${m.i18nKey}.name`) }}
                  </div>
                  <div class="text-xs text-slate-500 mt-1 line-clamp-2">
                    {{ t(`modules.${m.i18nKey}.description`) }}
                  </div>
                </div>
              </div>
            </RouterLink>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
