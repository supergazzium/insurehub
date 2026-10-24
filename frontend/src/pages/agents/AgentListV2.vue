<script setup lang="ts">
// Server-side paginated agent list.
import { onMounted, reactive, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAgentStore } from '../../stores/agents'
import {
  fetchPendingAgents, fetchRankPromotions, fetchLevelProgressBoard,
  approveAgent, setAgentActive, type AgentListRow,
} from '../../api/agents'
import { ApiError } from '../../api/client'
import { fmtDateThai } from '../../util/dateFormat'
import { AGENT_LEVELS, levelBadgeClass } from '../../utils/agentLevel'
import AgentActiveDialog from './AgentActiveDialog.vue'
import AgentsSubnav from './AgentsSubnav.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
// Same-tab navigation to the agent detail page (unlike policy/customer lists
// which open a new tab) — per the agents-module UX decision.
function openAgent(id: string): void { router.push({ name: 'agent-detail', params: { id } }) }
const agentStore = useAgentStore()

// ── Management actions (create / approve / deactivate) ─────────────────────
const pending = ref<AgentListRow[]>([])
const promoPendingCount = ref(0)
const nearPromotionCount = ref(0)
const busy = ref<string | null>(null)
const flash = ref<{ ok: boolean; text: string } | null>(null)
const rowMenu = ref<string | null>(null)
function setFlash(ok: boolean, text: string) { flash.value = { ok, text }; setTimeout(() => flash.value = null, 3500) }
function aName(a: { firstName?: string; lastName?: string; agentCode: string; kind?: string; juristicName?: string }): string {
  if (a.kind === 'corporate' && a.juristicName) return a.juristicName
  return `${a.firstName ?? ''} ${a.lastName ?? ''}`.trim() || a.agentCode
}
// Combined approval count (applicants + pending promotions) — SAME number as
// the subnav badge so the two never disagree.
const pendingCount = computed(() => pending.value.length + promoPendingCount.value)
async function loadPending(): Promise<void> {
  try { const r = await fetchPendingAgents(); pending.value = r.data } catch { pending.value = [] }
  try { const p = await fetchRankPromotions('pending'); promoPendingCount.value = p.meta?.pendingCount ?? 0 } catch { promoPendingCount.value = 0 }
  try {
    const b = await fetchLevelProgressBoard()
    // "ใกล้เลื่อนระดับ" = has a next level and is ≥80% of the way there.
    nearPromotionCount.value = b.data.filter((r) => r.nextLevel !== null && r.progressPct >= 80).length
  } catch { nearPromotionCount.value = 0 }
}
function createNew(): void { router.push({ name: 'agent-new' }) }
async function approve(a: AgentListRow): Promise<void> {
  busy.value = a.id
  try { await approveAgent(a.id); setFlash(true, `อนุมัติ ${aName(a)} แล้ว`); await load(); await loadPending() }
  catch (e: unknown) { setFlash(false, e instanceof ApiError ? e.message : 'อนุมัติล้มเหลว') }
  finally { busy.value = null }
}
const activeTarget = ref<AgentListRow | null>(null)
const activeError = ref<string | null>(null)
function toggleActive(a: AgentListRow): void {
  activeError.value = null
  activeTarget.value = a
}
async function confirmActive(): Promise<void> {
  const a = activeTarget.value
  if (!a) return
  const next = !a.active
  busy.value = a.id
  try {
    await setAgentActive(a.id, next)
    activeTarget.value = null
    setFlash(true, next ? `เปิดใช้งาน ${aName(a)} แล้ว` : `ปิดใช้งาน ${aName(a)} แล้ว`)
    await load()
  }
  catch (e: unknown) { activeError.value = e instanceof ApiError ? e.message : 'ดำเนินการล้มเหลว' }
  finally { busy.value = null }
}

const filters = reactive({
  q: '',
  agentType: '' as '' | 'AG' | 'IN',
  level: '' as string,
  licenseType: '' as '' | 'life' | 'non_life',
  licenseStatus: '' as '' | 'valid' | 'expired' | 'expiring60d' | 'none',
  activeOnly: false,
  perPage: 25,
})

const page = ref(1)

async function load(): Promise<void> {
  await agentStore.loadPage({
    q: filters.q || undefined,
    agentType: filters.agentType || undefined,
    level: filters.level || undefined,
    licenseType: filters.licenseType || undefined,
    licenseStatus: filters.licenseStatus || undefined,
    activeOnly: filters.activeOnly || undefined,
    page: page.value,
    perPage: filters.perPage,
  })
}

// Prefill the search from ?q= so cross-page links (e.g. "click agent code
// in customer detail") land on this list already scoped to that agent.
onMounted(() => {
  const q = route.query.q
  if (typeof q === 'string' && q.trim() !== '') filters.q = q.trim()
  void load()
  void loadPending()
})

let debounceTimer: number | undefined
watch(
  () => filters.q,
  () => {
    window.clearTimeout(debounceTimer)
    debounceTimer = window.setTimeout(() => { page.value = 1; void load() }, 300)
  },
)
watch(
  () => [filters.agentType, filters.level, filters.licenseType, filters.licenseStatus, filters.activeOnly, filters.perPage],
  () => { page.value = 1; void load() },
)

function goPage(next: number): void {
  const meta = agentStore.listMeta
  if (!meta) return
  const target = Math.max(1, Math.min(meta.lastPage, next))
  if (target === page.value) return
  page.value = target
  void load()
}

const rangeText = computed(() => {
  const meta = agentStore.listMeta
  if (!meta) return ''
  const from = (meta.currentPage - 1) * meta.perPage + 1
  const to = Math.min(meta.total, meta.currentPage * meta.perPage)
  return `${from.toLocaleString()}–${to.toLocaleString()} จาก ${meta.total.toLocaleString()}`
})


// ── Chip filters (replace the old approvals banner) ──────────────────────
function goApprovals(): void { router.push({ name: 'agents-approvals' }) }
function goNearPromotion(): void { router.push({ name: 'agents-level-progress' }) }
function toggleExpiringChip(): void {
  filters.licenseStatus = filters.licenseStatus === 'expiring60d' ? '' : 'expiring60d'
}

function licenseStatus(expiry: string | null): { cls: string; label: string } {
  if (!expiry) return { cls: 'bg-slate-100 text-slate-500', label: 'ไม่มี' }
  const now = new Date().toISOString().slice(0, 10)
  const in60 = new Date(); in60.setDate(in60.getDate() + 60)
  if (expiry < now) return { cls: 'bg-rose-50 text-rose-700', label: 'หมดอายุ' }
  if (expiry < in60.toISOString().slice(0, 10)) return { cls: 'bg-amber-50 text-amber-700', label: '<60 วัน' }
  return { cls: 'bg-emerald-50 text-emerald-700', label: 'valid' }
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ t('modules.agents.name') }}</h1>
        <p class="text-slate-500 text-sm mt-1">{{ t('modules.agents.description') }}</p>
      </div>
      <div class="flex items-center gap-3">
        <span v-if="agentStore.listMeta" class="text-sm text-slate-500">{{ rangeText }}</span>
        <button type="button" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700" @click="createNew">
          <i class="pi pi-plus text-[10px]" /> เพิ่มตัวแทน
        </button>
      </div>
    </header>

    <AgentsSubnav />

    <!-- Flash -->
    <transition name="fade">
      <div v-if="flash" :class="['rounded px-3 py-2 text-sm', flash.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']">{{ flash.text }}</div>
    </transition>

    <!-- Chip filters — quick jumps to the attention queues (replaces the old
         inline approvals banner, which double-counted vs the subnav badge). -->
    <div class="flex flex-wrap items-center gap-2">
      <button v-if="pendingCount > 0" type="button" data-testid="chip-pending"
        class="inline-flex items-center gap-1.5 rounded-full border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100"
        @click="goApprovals">
        <i class="pi pi-clock text-[10px]" /> รออนุมัติ {{ pendingCount }}
      </button>
      <button type="button" data-testid="chip-expiring"
        :class="['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium', filters.licenseStatus === 'expiring60d' ? 'border-rose-400 bg-rose-100 text-rose-800' : 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100']"
        @click="toggleExpiringChip">
        <i class="pi pi-id-card text-[10px]" /> ใบอนุญาตใกล้หมด
      </button>
      <button v-if="nearPromotionCount > 0" type="button" data-testid="chip-near-promotion"
        class="inline-flex items-center gap-1.5 rounded-full border border-sky-300 bg-sky-50 px-3 py-1 text-xs font-medium text-sky-800 hover:bg-sky-100"
        @click="goNearPromotion">
        <i class="pi pi-chart-line text-[10px]" /> ใกล้เลื่อนระดับ {{ nearPromotionCount }}
      </button>
    </div>

    <section class="card p-4 grid grid-cols-1 md:grid-cols-7 gap-3 items-end">
      <div class="md:col-span-2">
        <label class="text-xs font-medium text-slate-500 mb-1 block">ค้นหา (ชื่อ / ชื่อนิติบุคคล / รหัส / เลขผู้เสียภาษี / อีเมล / โทร)</label>
        <div class="relative">
          <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
          <input v-model.trim="filters.q" placeholder="IN260684, ชื่อ, บริษัท…, 0105…" data-testid="agent-search"
            class="w-full border border-slate-200 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white" />
        </div>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">ประเภท</label>
        <select v-model="filters.agentType" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="AG">AG</option>
          <option value="IN">IN</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Level</label>
        <select v-model="filters.level" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option v-for="n in AGENT_LEVELS" :key="n" :value="`l${n}`">L{{ n }}</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">ใบอนุญาต</label>
        <select v-model="filters.licenseType" data-testid="filter-license-type" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">ทุกประเภท</option>
          <option value="life">ชีวิต</option>
          <option value="non_life">วินาศภัย</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">สถานะใบอนุญาต</label>
        <select v-model="filters.licenseStatus" data-testid="filter-license-status" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">ทั้งหมด</option>
          <option value="valid">ใช้ได้</option>
          <option value="expiring60d">ใกล้หมด (&lt; 60 วัน)</option>
          <option value="expired">หมดอายุ</option>
          <option v-if="filters.licenseType" value="none">ไม่มี</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Per page</label>
        <select v-model.number="filters.perPage" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option :value="25">25</option>
          <option :value="50">50</option>
          <option :value="100">100</option>
        </select>
      </div>
    </section>

    <section v-if="agentStore.listError" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ agentStore.listError }}
    </section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">Code</th>
              <th class="px-4 py-2 text-left">ชื่อ</th>
              <th class="px-4 py-2 text-left">ประเภท / Level</th>
              <th class="px-4 py-2 text-left">Upline</th>
              <th class="px-4 py-2 text-left">License Life</th>
              <th class="px-4 py-2 text-left">License Non-Life</th>
              <th class="px-4 py-2 text-left">Status</th>
              <th class="px-4 py-2 text-left">ติดตามล่าสุด</th>
              <th class="px-4 py-2 text-right">จัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="a in agentStore.list" :key="a.id" class="cursor-pointer hover:bg-slate-50" @click="openAgent(a.id)">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ a.agentCode }}</td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ aName(a) }}</div>
                <!-- Email only in the list — hide phone to reduce bulk-copying of
                     contact numbers (#25). Corporate agents get a kind badge. -->
                <div class="text-xs text-slate-500">
                  <span v-if="a.kind === 'corporate'" class="mr-1 rounded bg-slate-100 px-1 text-[10px] text-slate-600">นิติบุคคล</span>{{ a.email || '—' }}
                </div>
              </td>
              <td class="px-4 py-2">
                <div class="flex items-center gap-1.5">
                  <span class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">{{ a.agentType }}</span>
                  <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs font-medium', levelBadgeClass(a.level)]">{{ a.level.toUpperCase() }}</span>
                </div>
              </td>
              <td class="px-4 py-2">
                <div v-if="a.parentAgentCode" class="text-slate-900">
                  {{ a.parentAgentName || a.parentAgentCode }}
                  <div class="text-xs text-slate-500">{{ a.parentAgentCode }}</div>
                </div>
                <span v-else class="text-xs text-slate-400">—</span>
              </td>

              <td class="px-4 py-2">
                <div class="font-mono text-xs text-slate-700">{{ a.licenseLifeNo || '—' }}</div>
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-[10px] mt-0.5', licenseStatus(a.licenseLifeExpiry).cls]">
                  {{ licenseStatus(a.licenseLifeExpiry).label }}
                  <span v-if="a.licenseLifeExpiry" class="ml-1">— {{ a.licenseLifeExpiry }}</span>
                </span>
              </td>
              <td class="px-4 py-2">
                <div class="font-mono text-xs text-slate-700">{{ a.licenseNonLifeNo || '—' }}</div>
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-[10px] mt-0.5', licenseStatus(a.licenseNonLifeExpiry).cls]">
                  {{ licenseStatus(a.licenseNonLifeExpiry).label }}
                  <span v-if="a.licenseNonLifeExpiry" class="ml-1">— {{ a.licenseNonLifeExpiry }}</span>
                </span>
              </td>
              <td class="px-4 py-2">
                <span v-if="a.approvalStatus === 'pending'" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-amber-50 text-amber-700">รออนุมัติ</span>
                <span v-else-if="a.active" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700">เปิดใช้งาน</span>
                <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600">ปิดใช้งาน</span>
              </td>
              <td class="px-4 py-2">
                <span v-if="a.lastNoteAt" class="text-xs text-slate-600">
                  {{ fmtDateThai(a.lastNoteAt) }}
                  <span v-if="(a.noteCount ?? 0) > 1" class="text-[10px] text-slate-400">({{ a.noteCount }} โน้ต)</span>
                </span>
                <span v-else class="text-xs text-slate-300">—</span>
              </td>
              <td class="px-4 py-2 text-right" @click.stop>
                <div class="flex items-center justify-end gap-1.5">
                  <button v-if="a.approvalStatus === 'pending'" type="button" class="rounded bg-emerald-600 px-2 py-1 text-xs text-white disabled:opacity-50" :disabled="busy === a.id" @click="approve(a)">อนุมัติ</button>
                  <!-- Dangerous/secondary actions tucked behind ⋮ so the row-click
                       (open agent) is the obvious primary action. -->
                  <div class="relative">
                    <button type="button" :data-testid="`agent-menu-${a.id}`" class="rounded border border-slate-200 px-1.5 py-1 text-slate-500 hover:bg-slate-50" @click="rowMenu = rowMenu === a.id ? null : a.id">
                      <i class="pi pi-ellipsis-v text-xs" />
                    </button>
                    <div v-if="rowMenu === a.id" class="absolute right-0 z-10 mt-1 w-36 rounded-lg border border-slate-200 bg-white py-1 text-left text-xs shadow-lg" @click="rowMenu = null">
                      <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50" @click="openAgent(a.id)"><i class="pi pi-eye text-[10px]" /> เปิดดูรายละเอียด</button>
                      <button type="button"
                        :class="['block w-full px-3 py-1.5 text-left hover:bg-slate-50', a.active ? 'text-rose-600' : 'text-emerald-600']"
                        :disabled="busy === a.id" @click="toggleActive(a)">{{ a.active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}</button>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <tr v-if="!agentStore.listLoading && agentStore.list.length === 0">
              <td colspan="10" class="px-4 py-6 text-center text-slate-500">ไม่พบตัวแทน</td>
            </tr>
            <tr v-if="agentStore.listLoading && agentStore.list.length === 0">
              <td colspan="10" class="px-4 py-6 text-center text-slate-500">Loading…</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="agentStore.listMeta" class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
        <span class="text-slate-500">
          Page {{ agentStore.listMeta.currentPage }} / {{ agentStore.listMeta.lastPage }} · {{ agentStore.listMeta.total.toLocaleString() }} total
        </span>
        <div class="flex items-center gap-2">
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="agentStore.listLoading || page <= 1" @click="goPage(1)">« First</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="agentStore.listLoading || page <= 1" @click="goPage(page - 1)">Prev</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="agentStore.listLoading || page >= (agentStore.listMeta?.lastPage ?? 1)" @click="goPage(page + 1)">Next</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="agentStore.listLoading || page >= (agentStore.listMeta?.lastPage ?? 1)" @click="goPage(agentStore.listMeta?.lastPage ?? 1)">Last »</button>
        </div>
      </div>
    </section>
    <AgentActiveDialog :open="activeTarget !== null" :agent-name="activeTarget ? aName(activeTarget) : ''" :active="activeTarget?.active ?? true"
      :busy="busy !== null" :error="activeError" @cancel="activeTarget = null" @confirm="confirmActive" />
  </div>
</template>
