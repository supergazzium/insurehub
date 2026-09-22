<script setup lang="ts">
import { computed } from 'vue'
import { useAgentStore, type Agent } from '../../stores/agents'

/** Per-agent rollup entry (from GET /agents/hierarchy-rollup). */
export interface RollupEntry {
  ownPremium: number
  ownPolicyCount: number
  subtreePremium: number
  subtreePolicyCount: number
}

const props = defineProps<{
  agent: Agent
  expanded: Set<string>
  rollup: Record<string, RollupEntry>
}>()

const emit = defineEmits<{
  toggle: [id: string]
  select: [id: string]
  reparent: [id: string]
}>()

const store = useAgentStore()

const children = computed(() => store.getDirectDownline(props.agent.id))
const totalDownline = computed(() => store.getAllDownline(props.agent.id).length)
const isOpen = computed(() => props.expanded.has(props.agent.id))
const roll = computed<RollupEntry | undefined>(() => props.rollup[props.agent.id])

const money = (n: number) => n.toLocaleString('th-TH', { maximumFractionDigits: 0 })

// Level badge classes now cover l1–l10.
const LEVEL_CLASS: Record<string, string> = {
  l1: 'bg-slate-100 text-slate-600', l2: 'bg-sky-50 text-sky-700',
  l3: 'bg-violet-50 text-violet-700', l4: 'bg-amber-50 text-amber-700',
  l5: 'bg-rose-50 text-rose-700', l6: 'bg-orange-50 text-orange-700',
  l7: 'bg-emerald-50 text-emerald-700', l8: 'bg-teal-50 text-teal-700',
  l9: 'bg-indigo-50 text-indigo-700', l10: 'bg-fuchsia-50 text-fuchsia-700',
}
const levelClass = computed(() => LEVEL_CLASS[props.agent.level] ?? 'bg-slate-100 text-slate-600')
const levelShort = computed(() => (props.agent.level ? props.agent.level.toUpperCase() : '—'))
</script>

<template>
  <div class="relative">
    <div class="flex items-center gap-2 py-1.5">
      <!-- Toggle / leaf -->
      <button v-if="children.length" type="button" @click="emit('toggle', agent.id)"
        class="w-5 h-5 shrink-0 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded">
        <i :class="isOpen ? 'pi pi-chevron-down text-[10px]' : 'pi pi-chevron-right text-[10px]'" />
      </button>
      <span v-else class="w-5 h-5 shrink-0 flex items-center justify-center">
        <span class="w-1.5 h-1.5 rounded-full bg-slate-300" />
      </span>

      <!-- Avatar -->
      <div class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-medium shrink-0">
        {{ agent.firstName.charAt(0) }}{{ agent.lastName.charAt(0) }}
      </div>

      <!-- Body -->
      <button type="button" @click="emit('select', agent.id)"
        class="flex-1 min-w-0 flex items-center gap-3 px-2 py-1 rounded-md hover:bg-slate-50 text-left">
        <div class="min-w-0 flex-1">
          <div class="text-sm font-medium text-slate-900 truncate">
            {{ agent.firstName }} {{ agent.lastName }}
            <span v-if="agent.nickname" class="text-slate-400 font-normal ml-1">({{ agent.nickname }})</span>
          </div>
          <div class="text-xs text-slate-500 font-mono flex items-center gap-1.5">
            {{ agent.agentCode }}
          </div>
        </div>

        <!-- Level -->
        <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium shrink-0', levelClass]">
          {{ levelShort }}
        </span>

        <!-- Premium rollup (own · subtree) -->
        <span v-if="roll" class="hidden md:flex flex-col items-end text-[10px] leading-tight shrink-0">
          <span class="text-slate-700">฿{{ money(roll.ownPremium) }}</span>
          <span v-if="roll.subtreePremium > roll.ownPremium" class="text-slate-400">
            สาย ฿{{ money(roll.subtreePremium) }}
          </span>
        </span>

        <!-- Downline counts -->
        <span v-if="children.length" class="text-xs text-slate-400 shrink-0 hidden lg:inline">
          {{ children.length }} ตรง / {{ totalDownline }} รวม
        </span>
        <span v-if="!agent.active" class="text-[10px] text-slate-400 italic shrink-0">[ปิดใช้งาน]</span>
      </button>

      <!-- Re-parent (move สายงาน) -->
      <button type="button" title="ย้ายสายงาน"
        class="w-6 h-6 shrink-0 flex items-center justify-center rounded text-slate-300 hover:bg-slate-100 hover:text-sky-600"
        @click.stop="emit('reparent', agent.id)">
        <i class="pi pi-arrows-alt text-[11px]" />
      </button>
    </div>

    <!-- Recursive children -->
    <div v-if="children.length && isOpen" class="ml-6 pl-4 border-l border-slate-200 relative">
      <AgentTreeNode v-for="child in children" :key="child.id" :agent="child"
        :expanded="expanded" :rollup="rollup"
        @toggle="(id) => emit('toggle', id)"
        @select="(id) => emit('select', id)"
        @reparent="(id) => emit('reparent', id)" />
    </div>
  </div>
</template>
