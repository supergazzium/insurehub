<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const tabs = [
  { name: 'customers', to: '/customers', i18n: 'customers.tabs.list', icon: 'pi pi-list' },
  { name: 'customers-referrals', to: '/customers/referrals', i18n: 'customers.tabs.referrals', icon: 'pi pi-share-alt' },
]
</script>

<template>
  <div class="border-b border-slate-200 flex items-center gap-1 overflow-x-auto">
    <RouterLink
      v-for="tk in tabs"
      :key="tk.name"
      :to="tk.to"
      class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition flex items-center gap-2"
      :class="$route.name === tk.name ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900'"
    >
      <i :class="tk.icon + ' text-xs'" />
      {{ t(tk.i18n) }}
    </RouterLink>
  </div>
</template>
