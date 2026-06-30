<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgentStore } from '../../stores/agents'
import AgentsSubnav from './AgentsSubnav.vue'

const { t } = useI18n()
const store = useAgentStore()

onMounted(() => {
  void store.load()
})

const selectedAgentId = ref<string>(store.topLevelAgents[0]?.id ?? '')

// Set initial selection once the store loads.
watch(
  () => store.topLevelAgents,
  (tops) => {
    if (selectedAgentId.value === '' && tops.length > 0) {
      selectedAgentId.value = tops[0].id
    }
  },
)

const selectedAgent = computed(() => store.getAgent(selectedAgentId.value))
const currentLink = computed(() =>
  selectedAgentId.value ? store.getLinkForAgent(selectedAgentId.value) : null,
)
const allLinksForAgent = computed(() =>
  selectedAgentId.value ? store.links.filter((l) => l.agentId === selectedAgentId.value) : [],
)

const myRecruits = computed(() => {
  return selectedAgentId.value ? store.getDirectDownline(selectedAgentId.value) : []
})

const baseUrl = computed(() => (typeof window !== 'undefined' ? window.location.origin : 'https://app.insurehub.test'))

function linkUrl(token: string) {
  return `${baseUrl.value}/register?ref=${token}`
}

const copied = ref(false)
async function copyLink() {
  if (!currentLink.value) return
  try {
    await navigator.clipboard.writeText(linkUrl(currentLink.value.token))
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {
    /* ignore */
  }
}

const showRegenWarn = ref(false)
const showRevokeWarn = ref(false)

function doGenerate() {
  store.generateLink(selectedAgentId.value)
  showRegenWarn.value = false
}
function doRevoke() {
  if (!currentLink.value) return
  store.revokeLink(currentLink.value.id)
  showRevokeWarn.value = false
}

const conversionRate = computed(() => {
  if (!currentLink.value || currentLink.value.clicks === 0) return 0
  return (currentLink.value.signups / currentLink.value.clicks) * 100
})

// Demo: recent click log per token
const recentClicks = computed(() => {
  if (!currentLink.value) return []
  const seed = currentLink.value.clicks
  if (!seed) return []
  return [
    { time: '2026-06-05 11:42', source: 'Facebook', location: 'Bangkok', converted: true },
    { time: '2026-06-05 09:18', source: 'LINE', location: 'Chiang Mai', converted: false },
    { time: '2026-06-04 19:33', source: 'Direct', location: 'Chonburi', converted: false },
    { time: '2026-06-04 14:05', source: 'Instagram', location: 'Phuket', converted: true },
    { time: '2026-06-03 16:50', source: 'Facebook', location: 'Bangkok', converted: false },
  ].slice(0, Math.min(seed, 5))
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('agents.recruitment.title') }}</h1>
      <p class="text-slate-500 text-sm mt-1">{{ t('agents.recruitment.subtitle') }}</p>
    </header>

    <AgentsSubnav />

    <!-- Agent picker -->
    <div class="card p-4">
      <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ t('agents.hierarchy.selectAgent') }}</label>
      <select
        v-model="selectedAgentId"
        class="w-full md:max-w-md px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
      >
        <option v-for="a in store.agents" :key="a.id" :value="a.id">
          {{ a.firstName }} {{ a.lastName }} ({{ a.agentCode }}) — {{ t(`agents.levelShort.${a.level}`) }}
        </option>
      </select>
    </div>

    <div v-if="selectedAgent">
      <!-- Current link / generate -->
      <section class="card p-5 mb-6">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <div class="text-xs text-slate-500">{{ t('agents.recruitment.linkFor') }}</div>
            <div class="font-semibold text-slate-900 text-lg mt-0.5">
              {{ selectedAgent.firstName }} {{ selectedAgent.lastName }}
            </div>
            <div class="text-xs text-slate-400 font-mono">{{ selectedAgent.agentCode }}</div>
          </div>
          <div v-if="currentLink" class="text-xs text-slate-500">
            <span class="text-slate-400">{{ t('agents.recruitment.generatedAt') }}:</span>
            <span class="ml-1 font-mono text-slate-700">{{ currentLink.generatedAt }}</span>
          </div>
        </div>

        <div v-if="currentLink" class="mt-5">
          <div class="flex items-center gap-2">
            <code class="flex-1 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-lg font-mono text-sm text-slate-700 truncate">
              {{ linkUrl(currentLink.token) }}
            </code>
            <button
              type="button"
              @click="copyLink"
              class="px-3 py-2.5 border border-slate-300 rounded-lg hover:bg-slate-50 transition flex items-center gap-1.5 text-sm shrink-0"
            >
              <i :class="copied ? 'pi pi-check text-emerald-600' : 'pi pi-copy'" />
              <span class="hidden sm:inline">{{ copied ? t('agents.recruitment.copied') : t('agents.recruitment.copyLink') }}</span>
            </button>
          </div>

          <!-- Stats -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-5">
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="text-xs text-slate-500">{{ t('agents.recruitment.stats.clicks') }}</div>
              <div class="text-xl font-semibold text-slate-900 mt-1">{{ currentLink.clicks }}</div>
            </div>
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="text-xs text-slate-500">{{ t('agents.recruitment.stats.signups') }}</div>
              <div class="text-xl font-semibold text-emerald-600 mt-1">{{ currentLink.signups }}</div>
            </div>
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="text-xs text-slate-500">{{ t('agents.recruitment.stats.pending') }}</div>
              <div class="text-xl font-semibold text-amber-600 mt-1">{{ currentLink.pendingSignups }}</div>
            </div>
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="text-xs text-slate-500">{{ t('agents.recruitment.stats.conversionRate') }}</div>
              <div class="text-xl font-semibold text-brand-600 mt-1">{{ conversionRate.toFixed(1) }}%</div>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex flex-wrap items-center gap-2 mt-5 pt-4 border-t border-slate-100">
            <button
              type="button"
              @click="showRegenWarn = true"
              class="px-3 py-2 text-sm border border-slate-300 rounded-lg hover:bg-slate-50 transition flex items-center gap-2"
            >
              <i class="pi pi-refresh" />
              {{ t('agents.recruitment.regenerate') }}
            </button>
            <button
              type="button"
              @click="showRevokeWarn = true"
              class="px-3 py-2 text-sm text-rose-600 border border-rose-200 rounded-lg hover:bg-rose-50 transition flex items-center gap-2"
            >
              <i class="pi pi-ban" />
              {{ t('agents.recruitment.revoke') }}
            </button>
          </div>
        </div>

        <div v-else class="mt-5 text-center py-8 border-2 border-dashed border-slate-200 rounded-lg">
          <i class="pi pi-link text-slate-300 text-3xl block mb-2" />
          <p class="text-sm text-slate-500 mb-4">ตัวแทนคนนี้ยังไม่มีลิงก์รับสมัครที่ใช้งานอยู่</p>
          <button
            type="button"
            @click="doGenerate"
            class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition inline-flex items-center gap-2"
          >
            <i class="pi pi-plus" />
            {{ t('agents.recruitment.generateLink') }}
          </button>
        </div>
      </section>

      <!-- Recent clicks + my recruits side-by-side -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- My recruits -->
        <section class="card overflow-hidden">
          <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
              <i class="pi pi-users text-slate-400" />
              {{ t('agents.recruitment.myRecruits') }}
            </h3>
            <span class="text-xs text-slate-400">{{ myRecruits.length }}</span>
          </div>
          <div v-if="!myRecruits.length" class="px-5 py-10 text-center text-sm text-slate-400 italic">
            {{ t('agents.recruitment.noRecruits') }}
          </div>
          <div v-else class="divide-y divide-slate-100">
            <div v-for="r in myRecruits" :key="r.id" class="px-5 py-3 flex items-center gap-3 hover:bg-slate-50/50">
              <div class="w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs font-medium shrink-0">
                {{ r.firstName.charAt(0) }}{{ r.lastName.charAt(0) }}
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-slate-900 truncate">{{ r.firstName }} {{ r.lastName }}</div>
                <div class="text-xs text-slate-500">
                  <span class="font-mono">{{ r.agentCode }}</span>
                  <span class="mx-1">·</span>
                  <span>เริ่ม {{ r.joinedAt }}</span>
                </div>
              </div>
              <span
                class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium"
                :class="{
                  'bg-emerald-50 text-emerald-700': r.active,
                  'bg-slate-100 text-slate-500': !r.active,
                }"
              >
                {{ t(`agents.levelShort.${r.level}`) }}
              </span>
            </div>
          </div>
        </section>

        <!-- Recent clicks -->
        <section class="card overflow-hidden">
          <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
              <i class="pi pi-chart-bar text-slate-400" />
              {{ t('agents.recruitment.recentClicks') }}
            </h3>
          </div>
          <div v-if="!recentClicks.length" class="px-5 py-10 text-center text-sm text-slate-400 italic">
            ยังไม่มีการคลิก
          </div>
          <div v-else class="divide-y divide-slate-100">
            <div v-for="(c, i) in recentClicks" :key="i" class="px-5 py-3 flex items-center gap-3 hover:bg-slate-50/50">
              <div class="w-9 h-9 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center shrink-0">
                <i :class="{
                  'pi pi-facebook': c.source === 'Facebook',
                  'pi pi-instagram': c.source === 'Instagram',
                  'pi pi-comment': c.source === 'LINE',
                  'pi pi-globe': c.source === 'Direct',
                }" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-sm text-slate-900 truncate">{{ c.source }}</div>
                <div class="text-xs text-slate-500">{{ c.location }} · {{ c.time }}</div>
              </div>
              <span
                v-if="c.converted"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 text-xs font-medium"
              >
                <i class="pi pi-check text-[10px]" />
                สมัครแล้ว
              </span>
            </div>
          </div>
        </section>
      </div>

      <!-- Link history -->
      <section v-if="allLinksForAgent.length > 1" class="mt-6">
        <h3 class="text-sm font-semibold text-slate-900 mb-3">ประวัติลิงก์</h3>
        <div class="card overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
              <tr>
                <th class="text-left px-4 py-2 font-medium">โทเค็น</th>
                <th class="text-left px-4 py-2 font-medium">{{ t('agents.recruitment.generatedAt') }}</th>
                <th class="text-right px-4 py-2 font-medium">{{ t('agents.recruitment.stats.clicks') }}</th>
                <th class="text-right px-4 py-2 font-medium">{{ t('agents.recruitment.stats.signups') }}</th>
                <th class="text-left px-4 py-2 font-medium">{{ t('common.status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="l in allLinksForAgent" :key="l.id">
                <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ l.token }}</td>
                <td class="px-4 py-2 text-xs text-slate-500">{{ l.generatedAt }}</td>
                <td class="px-4 py-2 text-right font-medium">{{ l.clicks }}</td>
                <td class="px-4 py-2 text-right font-medium text-emerald-600">{{ l.signups }}</td>
                <td class="px-4 py-2">
                  <span
                    :class="[
                      'inline-flex px-2 py-0.5 rounded-md text-xs font-medium',
                      l.revoked ? 'bg-slate-100 text-slate-500' : 'bg-emerald-50 text-emerald-700',
                    ]"
                  >
                    {{ l.revoked ? t('agents.recruitment.status.revoked') : t('agents.recruitment.status.active') }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- Regen confirm -->
    <div v-if="showRegenWarn" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" @click.self="showRegenWarn = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-5 py-5">
          <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center mb-3">
            <i class="pi pi-refresh" />
          </div>
          <h3 class="font-semibold text-slate-900">{{ t('agents.recruitment.regenerate') }}</h3>
          <p class="text-sm text-slate-500 mt-1.5">{{ t('agents.recruitment.regenerateWarn') }}</p>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="showRegenWarn = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
            {{ t('common.cancel') }}
          </button>
          <button @click="doGenerate" class="px-4 py-2 text-sm rounded-lg bg-amber-600 text-white font-medium hover:bg-amber-700">
            {{ t('common.confirm') }}
          </button>
        </footer>
      </div>
    </div>

    <!-- Revoke confirm -->
    <div v-if="showRevokeWarn" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40" @click.self="showRevokeWarn = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-5 py-5">
          <div class="w-10 h-10 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mb-3">
            <i class="pi pi-ban" />
          </div>
          <h3 class="font-semibold text-slate-900">{{ t('agents.recruitment.revoke') }}</h3>
          <p class="text-sm text-slate-500 mt-1.5">{{ t('agents.recruitment.revokeWarn') }}</p>
        </div>
        <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
          <button @click="showRevokeWarn = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
            {{ t('common.cancel') }}
          </button>
          <button @click="doRevoke" class="px-4 py-2 text-sm rounded-lg bg-rose-600 text-white font-medium hover:bg-rose-700">
            {{ t('agents.recruitment.revoke') }}
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>
