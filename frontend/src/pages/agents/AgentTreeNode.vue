<script setup lang="ts">
import { computed } from 'vue'
import { useAgentStore, type Agent } from '../../stores/agents'

const props = defineProps<{
  agent: Agent
  expanded: Set<string>
}>()

const emit = defineEmits<{
  toggle: [id: string]
  select: [id: string]
}>()

const store = useAgentStore()

const children = computed(() => store.getDirectDownline(props.agent.id))
const totalDownline = computed(() => store.getAllDownline(props.agent.id).length)
const isOpen = computed(() => props.expanded.has(props.agent.id))

const levelClass = computed(() => {
  return {
    l1: 'bg-slate-100 text-slate-600',
    l2: 'bg-sky-50 text-sky-700',
    l3: 'bg-violet-50 text-violet-700',
    l4: 'bg-amber-50 text-amber-700',
    l5: 'bg-rose-50 text-rose-700',
  }[props.agent.level]
})

const levelShort = computed(
  () => ({ l1: 'L1', l2: 'L2', l3: 'L3', l4: 'L4', l5: 'L5' })[props.agent.level],
)
</script>

<template>
  <div class="relative">
    <div class="flex items-center gap-2 py-1.5">
      <!-- Toggle / leaf -->
      <button
        v-if="children.length"
        type="button"
        @click="emit('toggle', agent.id)"
        class="w-5 h-5 shrink-0 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded"
      >
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
      <button
        type="button"
        @click="emit('select', agent.id)"
        class="flex-1 min-w-0 flex items-center gap-3 px-2 py-1 rounded-md hover:bg-slate-50 text-left"
      >
        <div class="min-w-0 flex-1">
          <div class="text-sm font-medium text-slate-900 truncate">
            {{ agent.firstName }} {{ agent.lastName }}
            <span v-if="agent.nickname" class="text-slate-400 font-normal ml-1">({{ agent.nickname }})</span>
          </div>
          <div class="text-xs text-slate-500 font-mono">{{ agent.agentCode }}</div>
        </div>
        <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium shrink-0', levelClass]">
          {{ levelShort }} · {{ agent.commissionPct }}%
        </span>
        <span v-if="children.length" class="text-xs text-slate-400 shrink-0 hidden sm:inline">
          {{ children.length }} ตรง / {{ totalDownline }} รวม
        </span>
        <span v-if="!agent.active" class="text-[10px] text-slate-400 italic shrink-0">[ปิดใช้งาน]</span>
      </button>
    </div>

    <!-- Recursive children -->
    <div v-if="children.length && isOpen" class="ml-6 pl-4 border-l border-slate-200 relative">
      <AgentTreeNode
        v-for="child in children"
        :key="child.id"
        :agent="child"
        :expanded="expanded"
        @toggle="(id) => emit('toggle', id)"
        @select="(id) => emit('select', id)"
      />
    </div>
  </div>
</template>
