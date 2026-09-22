<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { fetchPendingAgents, fetchRankPromotions } from '../../api/agents'

const tabs = [
  { name: 'agents', to: '/agents', label: 'รายชื่อ', icon: 'pi pi-users' },
  { name: 'agents-approvals', to: '/agents/approvals', label: 'รออนุมัติ', icon: 'pi pi-verified', badge: true },
  { name: 'agents-recruitment', to: '/agents/recruitment', label: 'การรับสมัคร', icon: 'pi pi-share-alt' },
  { name: 'agents-level-progress', to: '/agents/level-progress', label: 'เลื่อนระดับ', icon: 'pi pi-chart-line' },
]

// Combined pending count (agents awaiting approval + promotions awaiting approval).
const pendingCount = ref(0)
onMounted(async () => {
  try {
    const [ag, promo] = await Promise.all([
      fetchPendingAgents().catch(() => ({ data: [] })),
      fetchRankPromotions('pending').catch(() => ({ data: [], meta: { pendingCount: 0 } })),
    ])
    pendingCount.value = ag.data.length + (promo.meta?.pendingCount ?? 0)
  } catch { /* badge stays 0 */ }
})
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
      {{ tk.label }}
      <span v-if="tk.badge && pendingCount > 0" class="rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] leading-none text-white">{{ pendingCount }}</span>
    </RouterLink>
  </div>
</template>
