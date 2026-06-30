<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgentStore, type Agent } from '../../stores/agents'
import AgentsSubnav from './AgentsSubnav.vue'
import AgentTreeNode from './AgentTreeNode.vue'

const { t } = useI18n()
const store = useAgentStore()

onMounted(() => {
  void store.load()
})

const selectedAgentId = ref<string>(store.topLevelAgents[0]?.id ?? '')
const expanded = ref<Set<string>>(new Set([selectedAgentId.value]))
const directOnly = ref(false)

// When the store finishes loading, pick the first top-level agent if nothing is selected yet.
watch(
  () => store.topLevelAgents,
  (tops) => {
    if (selectedAgentId.value === '' && tops.length > 0) {
      selectedAgentId.value = tops[0].id
      expanded.value = new Set([tops[0].id])
    }
  },
)

const selectedAgent = computed(() => store.getAgent(selectedAgentId.value))
const uplineChain = computed(() => (selectedAgent.value ? store.getUplineChain(selectedAgent.value.id) : []))
const directDownline = computed(() => (selectedAgent.value ? store.getDirectDownline(selectedAgent.value.id) : []))
const allDownline = computed(() => (selectedAgent.value ? store.getAllDownline(selectedAgent.value.id) : []))
const maxDepth = computed(() => (selectedAgent.value ? store.getMaxDownlineDepth(selectedAgent.value.id) : 0))

function toggleNode(id: string) {
  const next = new Set(expanded.value)
  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }
  expanded.value = next
}

function expandAll() {
  if (!selectedAgent.value) return
  expanded.value = new Set([
    selectedAgent.value.id,
    ...store.getAllDownline(selectedAgent.value.id).map((a) => a.id),
  ])
}

function collapseAll() {
  expanded.value = new Set(selectedAgent.value ? [selectedAgent.value.id] : [])
}

function selectAgent(id: string) {
  selectedAgentId.value = id
  expanded.value = new Set([id])
}

function levelBadgeClass(lv: Agent['level']) {
  return {
    l1: 'bg-slate-100 text-slate-600',
    l2: 'bg-sky-50 text-sky-700',
    l3: 'bg-violet-50 text-violet-700',
    l4: 'bg-amber-50 text-amber-700',
    l5: 'bg-rose-50 text-rose-700',
  }[lv]
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('agents.hierarchy.title') }}</h1>
      <p class="text-slate-500 text-sm mt-1">{{ t('agents.hierarchy.subtitle') }}</p>
    </header>

    <AgentsSubnav />

    <!-- Agent picker -->
    <div class="card p-4">
      <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('agents.hierarchy.selectAgent') }}</label>
      <select
        :value="selectedAgentId"
        @change="selectAgent(($event.target as HTMLSelectElement).value)"
        class="w-full md:max-w-md px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
      >
        <option v-for="a in store.agents" :key="a.id" :value="a.id">
          {{ a.firstName }} {{ a.lastName }} ({{ a.agentCode }}) — {{ t(`agents.levelShort.${a.level}`) }}
        </option>
      </select>
    </div>

    <div v-if="selectedAgent">
      <!-- Stats -->
      <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="card p-4">
          <div class="text-xs text-slate-500">{{ t('agents.hierarchy.directCount') }}</div>
          <div class="text-2xl font-semibold text-slate-900 mt-1">{{ directDownline.length }}</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-slate-500">{{ t('agents.hierarchy.totalCount') }}</div>
          <div class="text-2xl font-semibold text-brand-600 mt-1">{{ allDownline.length }}</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-slate-500">{{ t('agents.hierarchy.maxDepth') }}</div>
          <div class="text-2xl font-semibold text-violet-600 mt-1">{{ maxDepth }}</div>
        </div>
      </div>

      <!-- Upline chain -->
      <section class="card p-5 mb-6">
        <h3 class="text-sm font-semibold text-slate-900 mb-3 flex items-center gap-2">
          <i class="pi pi-chevron-up text-slate-400 text-xs" />
          {{ t('agents.hierarchy.uplineChain') }}
        </h3>
        <div v-if="uplineChain.length" class="flex flex-wrap items-center gap-1.5">
          <template v-for="u in [...uplineChain].reverse()" :key="u.id">
            <button
              type="button"
              @click="selectAgent(u.id)"
              class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-sm transition"
            >
              <span :class="['w-2 h-2 rounded-full', levelBadgeClass(u.level).split(' ')[0]]" />
              <span class="text-slate-900">{{ u.firstName }} {{ u.lastName }}</span>
              <span class="text-xs text-slate-400 font-mono">{{ u.agentCode }}</span>
            </button>
            <i class="pi pi-angle-right text-slate-300 text-xs" />
          </template>
          <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-brand-50 text-brand-700 text-sm font-medium">
            <i class="pi pi-user" />
            {{ selectedAgent.firstName }} {{ selectedAgent.lastName }}
          </span>
        </div>
        <p v-else class="text-sm text-slate-400 italic">{{ t('agents.hierarchy.topOfChain') }}</p>
      </section>

      <!-- Downline tree -->
      <section class="card overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
            <i class="pi pi-chevron-down text-slate-400 text-xs" />
            {{ t('agents.hierarchy.downlineTree') }}
          </h3>
          <div class="flex items-center gap-3">
            <label class="flex items-center gap-2 text-xs text-slate-600 cursor-pointer">
              <input v-model="directOnly" type="checkbox" class="w-4 h-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
              {{ t('agents.hierarchy.directOnly') }}
            </label>
            <div class="flex items-center gap-1 bg-slate-50 border border-slate-200 rounded-lg p-0.5">
              <button
                type="button"
                @click="expandAll"
                :disabled="directOnly"
                :class="['px-2.5 py-1 text-xs text-slate-600 hover:text-slate-900 hover:bg-white rounded transition flex items-center gap-1', directOnly && 'opacity-40 cursor-not-allowed']"
              >
                <i class="pi pi-plus text-[10px]" />
                {{ t('agents.hierarchy.expand') }}
              </button>
              <button
                type="button"
                @click="collapseAll"
                :disabled="directOnly"
                :class="['px-2.5 py-1 text-xs text-slate-600 hover:text-slate-900 hover:bg-white rounded transition flex items-center gap-1', directOnly && 'opacity-40 cursor-not-allowed']"
              >
                <i class="pi pi-minus text-[10px]" />
                {{ t('agents.hierarchy.collapse') }}
              </button>
            </div>
          </div>
        </div>

        <div class="px-5 py-4">
          <!-- Direct only view -->
          <div v-if="directOnly">
            <div v-if="!directDownline.length" class="text-sm text-slate-400 italic py-8 text-center">
              {{ t('agents.hierarchy.noDownline') }}
            </div>
            <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
              <button
                v-for="d in directDownline"
                :key="d.id"
                type="button"
                @click="selectAgent(d.id)"
                class="text-left p-3 border border-slate-200 rounded-lg hover:border-brand-300 hover:bg-brand-50/30 transition"
              >
                <div class="flex items-center gap-2 mb-1">
                  <div class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-medium">
                    {{ d.firstName.charAt(0) }}{{ d.lastName.charAt(0) }}
                  </div>
                  <span :class="['inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium', levelBadgeClass(d.level)]">
                    {{ t(`agents.levelShort.${d.level}`) }}
                  </span>
                </div>
                <div class="text-sm font-medium text-slate-900 truncate">{{ d.firstName }} {{ d.lastName }}</div>
                <div class="text-xs text-slate-500 font-mono">{{ d.agentCode }}</div>
                <div class="text-xs text-slate-400 mt-1">
                  สายงาน: {{ store.getAllDownline(d.id).length }} คน
                </div>
              </button>
            </div>
          </div>

          <!-- Recursive tree view -->
          <div v-else>
            <div v-if="!directDownline.length" class="text-sm text-slate-400 italic py-8 text-center">
              {{ t('agents.hierarchy.noDownline') }}
            </div>
            <AgentTreeNode
              v-else
              :agent="selectedAgent"
              :expanded="expanded"
              @toggle="toggleNode"
              @select="selectAgent"
            />
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
