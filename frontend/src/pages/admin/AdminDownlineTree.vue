<script setup lang="ts">
// Admin oversight: recursive downline tree for any agent.
// Level 0 is the root agent, siblings clustered under each parent.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchDownlineTree, type DownlineNode } from '../../api/adminAgents'
import { ApiError } from '../../api/client'

const { t } = useI18n()

const rootIdInput = ref('')
const tree = ref<DownlineNode[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  if (!rootIdInput.value.trim()) return
  loading.value = true
  error.value = null
  tree.value = []
  try {
    const res = await fetchDownlineTree(rootIdInput.value.trim())
    tree.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load tree.'
  } finally {
    loading.value = false
  }
}

// Group flat rows by level for a simple indented display.
const byLevel = computed(() => {
  const out: Record<number, DownlineNode[]> = {}
  for (const n of tree.value) {
    if (!out[n.level]) out[n.level] = []
    out[n.level].push(n)
  }
  return out
})
const maxLevel = computed(() => Math.max(0, ...tree.value.map((n) => n.level)))
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('adminDownline.title') }}</h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('adminDownline.subtitle') }}</p>
    </header>

    <section class="card p-4 flex items-end gap-3">
      <div class="flex-1">
        <label class="text-xs text-slate-500 mb-1 block">{{ t('adminDownline.rootId') }}</label>
        <input v-model.trim="rootIdInput" placeholder="e.g. 1"
          class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono" />
      </div>
      <button type="button" class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
        :disabled="loading || !rootIdInput.trim()" @click="load">
        <i v-if="loading" class="pi pi-spin pi-spinner mr-2" />
        {{ t('adminDownline.load') }}
      </button>
    </section>

    <div v-if="error" class="card p-3 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </div>

    <section v-if="tree.length" class="card p-4">
      <div class="text-xs text-slate-500 mb-3">
        {{ t('adminDownline.summary', { total: tree.length, depth: maxLevel }) }}
      </div>
      <div class="space-y-3">
        <div v-for="level in maxLevel + 1" :key="level - 1">
          <div class="text-xs uppercase tracking-wider text-slate-400 mb-1">
            {{ t('adminDownline.level') }} {{ level - 1 }}
            <span class="text-slate-500 normal-case">({{ byLevel[level - 1]?.length || 0 }})</span>
          </div>
          <ul class="text-sm space-y-1">
            <li v-for="n in byLevel[level - 1]" :key="n.id" class="flex items-center gap-3">
              <span class="font-mono text-xs text-slate-500" :style="{ paddingLeft: `${n.level * 16}px` }">
                <i v-if="n.level > 0" class="pi pi-arrow-right text-[10px] mr-1 text-slate-300" />
                {{ n.agentCode }}
              </span>
              <span class="text-slate-800">{{ n.firstName }} {{ n.lastName }}</span>
              <span class="text-xs text-slate-400">{{ n.email }}</span>
              <span :class="{
                'bg-emerald-50 text-emerald-700': n.approvalStatus === 'approved',
                'bg-amber-50 text-amber-700': n.approvalStatus === 'pending',
                'bg-rose-50 text-rose-700': n.approvalStatus === 'rejected',
              }" class="inline-flex px-1.5 py-0.5 rounded text-[10px] ml-auto">{{ n.approvalStatus }}</span>
            </li>
          </ul>
        </div>
      </div>
    </section>
  </div>
</template>
