<script setup lang="ts">
import { onMounted, ref, computed, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useAgentStore } from '../../stores/agents'
import {
  fetchTeams, fetchHierarchyRollup, updateAgentHierarchy,
  type TeamRow, type HierarchyRollupEntry,
} from '../../api/agents'
import { ApiError } from '../../api/client'
import AgentsSubnav from './AgentsSubnav.vue'
import { layoutForest, type LayoutNode } from './orgChartLayout'

const router = useRouter()
const store = useAgentStore()

// ── Data ────────────────────────────────────────────────────────────────────
const teams = ref<TeamRow[]>([])
const rollup = ref<Record<string, HierarchyRollupEntry>>({})
const loading = ref(true)
const error = ref<string | null>(null)
const flash = ref<{ ok: boolean; text: string } | null>(null)

function setFlash(ok: boolean, text: string) {
  flash.value = { ok, text }
  setTimeout(() => { flash.value = null }, 3500)
}

async function loadAll(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [t, r] = await Promise.all([fetchTeams(), fetchHierarchyRollup()])
    teams.value = t.data
    rollup.value = r.data
    await store.load()
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

const money1 = (n: number) =>
  n >= 1_000_000 ? `${(n / 1_000_000).toFixed(1)}M` : n >= 1000 ? `${Math.round(n / 1000)}K` : String(Math.round(n))

// ── Build the graph: HQ → teams → agents (by upline within team) ─────────────
// Node kinds: 'hq' (synthetic root), 'team', 'agent'.
type NodeKind = 'hq' | 'team' | 'agent'
interface OrgNode extends LayoutNode {
  kind: NodeKind
  label: string
  sub: string
  level?: string
  premium?: number
  active?: boolean
}

const HQ_ID = '__hq__'
const ORPHAN_ID = '__orphan__'
const showOrphans = ref(false)

// Two distinct hierarchies live in this data; show ONE at a time to avoid
// mixing team-boxes and people on the same tree (the old confusion).
//   'upline' = สายงาน MLM (parent_agent_id) — who recruited whom; drives commission
//   'team'   = ทีม grouping (team_id + parent_team_id) — org reporting units
type OrgMode = 'upline' | 'team'
const mode = ref<OrgMode>('team')

const graph = computed(() => {
  const nodes: Record<string, OrgNode> = {}
  const agents = store.agents
  const byId = new Map(agents.map((a) => [a.id, a]))

  nodes[HQ_ID] = { id: HQ_ID, parentId: null, children: [], kind: 'hq', label: 'InsureHub', sub: 'สำนักงานใหญ่' }

  const agentNode = (a: typeof agents[number]): OrgNode => ({
    id: `agent:${a.id}`, parentId: null, children: [], kind: 'agent',
    label: `${a.firstName} ${a.lastName}`.trim() || a.agentCode,
    sub: a.agentCode,
    level: a.level,
    premium: rollup.value[a.id]?.ownPremium ?? 0,
    active: a.active,
  })

  const orphanIds: string[] = []

  if (mode.value === 'team') {
    // ── Team hierarchy: HQ → teams (parent_team_id) → members (team_id) ──
    const teamById = new Map(teams.value.map((t) => [t.id, t]))
    for (const t of teams.value) {
      nodes[`team:${t.id}`] = {
        id: `team:${t.id}`, parentId: null, children: [], kind: 'team',
        label: t.name || t.code, sub: `${t.memberCount ?? 0} คน`,
      }
    }
    for (const t of teams.value) {
      const parent = t.parentTeamId && teamById.has(t.parentTeamId) ? `team:${t.parentTeamId}` : HQ_ID
      nodes[`team:${t.id}`].parentId = parent
      nodes[parent]?.children.push(`team:${t.id}`)
    }
    for (const a of agents) {
      nodes[`agent:${a.id}`] = agentNode(a)
      const self = `agent:${a.id}`
      if (a.teamId && nodes[`team:${a.teamId}`]) {
        nodes[self].parentId = `team:${a.teamId}`
        nodes[`team:${a.teamId}`].children.push(self)
      } else {
        orphanIds.push(self)
      }
    }
  } else {
    // ── Upline hierarchy: HQ → top agents (no upline) → downline chain ──
    for (const a of agents) nodes[`agent:${a.id}`] = agentNode(a)
    for (const a of agents) {
      const self = `agent:${a.id}`
      if (a.parentAgentId && byId.has(a.parentAgentId)) {
        nodes[self].parentId = `agent:${a.parentAgentId}`
        nodes[`agent:${a.parentAgentId}`].children.push(self)
      } else {
        // Top-of-chain agents hang directly off HQ (they ARE the tree roots).
        nodes[self].parentId = HQ_ID
        nodes[HQ_ID].children.push(self)
      }
    }
  }

  // Orphan cluster — only meaningful in team mode (no team assigned).
  if (orphanIds.length) {
    nodes[ORPHAN_ID] = {
      id: ORPHAN_ID, parentId: HQ_ID, children: showOrphans.value ? orphanIds : [],
      kind: 'team', label: 'ยังไม่มีทีม', sub: `${orphanIds.length} คน`,
    }
    nodes[HQ_ID].children.push(ORPHAN_ID)
    if (showOrphans.value) for (const o of orphanIds) nodes[o].parentId = ORPHAN_ID
  }

  return { nodes, orphanCount: orphanIds.length }
})

const layout = computed(() => layoutForest(graph.value.nodes, [HQ_ID], { nodeW: 168, nodeH: 58, hGap: 20, vGap: 64 }))

// Connector lines: parent-bottom → child-top.
const edges = computed(() => {
  const pos = layout.value.positions
  const out: { x1: number; y1: number; x2: number; y2: number }[] = []
  for (const n of Object.values(graph.value.nodes)) {
    if (n.parentId && pos[n.id] && pos[n.parentId]) {
      out.push({
        x1: pos[n.parentId].x + 84, y1: pos[n.parentId].y + 58,
        x2: pos[n.id].x + 84, y2: pos[n.id].y,
      })
    }
  }
  return out
})

// ── Pan / zoom ───────────────────────────────────────────────────────────────
const view = reactive({ x: 40, y: 20, scale: 0.9 })
const panning = ref(false)
let panStart = { x: 0, y: 0, vx: 0, vy: 0 }
function onBgPointerDown(e: PointerEvent) {
  if (dragId.value) return
  panning.value = true
  panStart = { x: e.clientX, y: e.clientY, vx: view.x, vy: view.y }
  ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
}
function onBgPointerMove(e: PointerEvent) {
  if (dragId.value) { onDragMove(e); return }
  if (!panning.value) return
  view.x = panStart.vx + (e.clientX - panStart.x)
  view.y = panStart.vy + (e.clientY - panStart.y)
}
function onBgPointerUp(e: PointerEvent) {
  if (dragId.value) { void onDrop(e); return }
  panning.value = false
}
function onWheel(e: WheelEvent) {
  e.preventDefault()
  const delta = e.deltaY < 0 ? 1.1 : 0.9
  view.scale = Math.min(2, Math.max(0.3, view.scale * delta))
}
function zoomBy(f: number) { view.scale = Math.min(2, Math.max(0.3, view.scale * f)) }
function resetView() { view.x = 40; view.y = 20; view.scale = 0.9 }

// ── Drag a node → drop onto another (re-parent / re-team) ───────────────────
const dragId = ref<string | null>(null)          // node id being dragged (agent only)
const dragPos = reactive({ x: 0, y: 0 })
const hoverTarget = ref<string | null>(null)
const saving = ref(false)

function onNodePointerDown(e: PointerEvent, nodeId: string, kind: NodeKind) {
  if (kind !== 'agent') return   // only agents move
  e.stopPropagation()
  dragId.value = nodeId
  dragPos.x = e.clientX
  dragPos.y = e.clientY
  ;(e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId)
}
function onDragMove(e: PointerEvent) {
  if (!dragId.value) return
  dragPos.x = e.clientX
  dragPos.y = e.clientY
  hoverTarget.value = hitTest(e.clientX, e.clientY)
}
function hitTest(clientX: number, clientY: number): string | null {
  // Which node is under the pointer (excluding the dragged one)?
  const el = document.elementFromPoint(clientX, clientY)
  const holder = el?.closest?.('[data-node-id]') as HTMLElement | null
  const id = holder?.dataset.nodeId ?? null
  return id && id !== dragId.value ? id : null
}
async function onDrop(e: PointerEvent) {
  const source = dragId.value
  const target = hitTest(e.clientX, e.clientY)
  dragId.value = null
  hoverTarget.value = null
  if (!source || !target || !source.startsWith('agent:')) return

  const agentId = source.slice('agent:'.length)
  try {
    saving.value = true
    if (target.startsWith('agent:')) {
      await updateAgentHierarchy(agentId, { parentAgentId: target.slice('agent:'.length) })
      setFlash(true, 'ย้ายต้นสายแล้ว')
    } else if (target.startsWith('team:')) {
      await updateAgentHierarchy(agentId, { teamId: target.slice('team:'.length), parentAgentId: null })
      setFlash(true, 'ย้ายเข้าทีมแล้ว')
    } else if (target === HQ_ID || target === ORPHAN_ID) {
      await updateAgentHierarchy(agentId, { parentAgentId: null, teamId: null })
      setFlash(true, 'ย้ายออกจากทีม/ต้นสายแล้ว')
    } else { return }
    await loadAll()
  } catch (err: unknown) {
    setFlash(false, err instanceof ApiError ? err.message : 'ย้ายล้มเหลว')
  } finally { saving.value = false }
}

function nodeClass(kind: NodeKind, active?: boolean): string {
  if (kind === 'hq') return 'bg-slate-800 text-white border-slate-800'
  // Team boxes: heavier 2px indigo border + tinted fill so they clearly read
  // as *containers/groups*, distinct from the flat white person cards.
  if (kind === 'team') return 'bg-indigo-50 text-indigo-800 border-2 border-indigo-300 border-dashed'
  return active === false ? 'bg-slate-50 text-slate-400 border-slate-200' : 'bg-white text-slate-800 border-slate-200'
}
const LEVEL_DOT: Record<string, string> = {
  l1: 'bg-slate-400', l2: 'bg-sky-400', l3: 'bg-violet-400', l4: 'bg-amber-400', l5: 'bg-rose-400',
  l6: 'bg-orange-400', l7: 'bg-emerald-400', l8: 'bg-teal-400', l9: 'bg-indigo-400', l10: 'bg-fuchsia-500',
}
function openAgent(nodeId: string) {
  if (nodeId.startsWith('agent:')) router.push({ name: 'agent-detail', params: { id: nodeId.slice('agent:'.length) } })
}

onMounted(loadAll)
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="px-4 py-3">
      <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 class="text-lg font-semibold text-slate-800">ผังสายงาน (Org Chart)</h1>
          <p class="text-[11px] text-slate-400">
            {{ mode === 'team'
              ? 'แสดงโครงสร้าง “ทีม” — จัดกลุ่มตัวแทนตามทีม (ลากการ์ดวางบนทีมเพื่อย้ายทีม)'
              : 'แสดงโครงสร้าง “สายงาน” — ใครแนะนำใคร ใช้คำนวณค่าคอม (ลากการ์ดวางบนคนเพื่อย้ายต้นสาย)' }}
          </p>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50" @click="zoomBy(1.1)">＋</button>
          <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50" @click="zoomBy(0.9)">－</button>
          <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50" @click="resetView">รีเซ็ต</button>
          <RouterLink to="/agents/hierarchy" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50"><i class="pi pi-list text-[10px]" /> ดูแบบต้นไม้</RouterLink>
        </div>
      </div>

      <!-- Mode toggle: one hierarchy at a time -->
      <div class="mb-2 flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 text-xs">
          <button type="button"
            :class="['rounded-md px-3 py-1 font-medium transition', mode === 'team' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50']"
            @click="mode = 'team'"><i class="pi pi-users text-[10px]" /> ทีม</button>
          <button type="button"
            :class="['rounded-md px-3 py-1 font-medium transition', mode === 'upline' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-50']"
            @click="mode = 'upline'"><i class="pi pi-sitemap text-[10px]" /> สายงาน (ต้นสาย)</button>
        </div>

        <!-- Legend -->
        <div class="flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
          <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded border border-indigo-200 bg-indigo-50"></span> กล่องทีม (กลุ่ม)</span>
          <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded border border-slate-200 bg-white"></span> ตัวแทน (บุคคล)</span>
          <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-rose-400"></span> จุดสี = ระดับ (Lv)</span>
        </div>

        <label v-if="mode === 'team'" class="ml-auto flex items-center gap-1 text-[11px] text-slate-500">
          <input type="checkbox" v-model="showOrphans" class="h-3.5 w-3.5" /> แสดงคนยังไม่มีทีม ({{ graph.orphanCount }})
        </label>
      </div>

      <!-- Orphan call-to-action (M-audit follow-up): surface the real data gap -->
      <div v-if="mode === 'team' && graph.orphanCount > 0"
        class="mb-2 flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        <i class="pi pi-exclamation-triangle"></i>
        <span><b>{{ graph.orphanCount }}</b> ตัวแทนยังไม่ได้กำหนดทีม — ลากการ์ดวางบนกล่องทีม หรือแก้ไขที่หน้ารายละเอียดตัวแทนเพื่อกำหนดทีม</span>
        <button type="button" class="ml-1 rounded border border-amber-300 px-2 py-0.5 font-medium hover:bg-amber-100" @click="showOrphans = !showOrphans">
          {{ showOrphans ? 'ซ่อนรายชื่อ' : 'แสดงรายชื่อ' }}
        </button>
      </div>

      <transition name="fade">
        <div v-if="flash" :class="['mb-2 rounded px-3 py-2 text-sm', flash.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']">{{ flash.text }}</div>
      </transition>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <div v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</div>

      <!-- Canvas -->
      <div v-else
        class="relative h-[70vh] overflow-hidden rounded-lg border border-slate-200 bg-slate-50 touch-none select-none"
        @pointerdown="onBgPointerDown" @pointermove="onBgPointerMove" @pointerup="onBgPointerUp" @pointerleave="onBgPointerUp"
        @wheel="onWheel">
        <div class="absolute left-0 top-0 origin-top-left"
          :style="{ transform: `translate(${view.x}px, ${view.y}px) scale(${view.scale})` }">
          <!-- Connectors -->
          <svg class="pointer-events-none absolute left-0 top-0 overflow-visible" :width="layout.width" :height="layout.height">
            <path v-for="(e, i) in edges" :key="i"
              :d="`M ${e.x1} ${e.y1} C ${e.x1} ${(e.y1 + e.y2) / 2}, ${e.x2} ${(e.y1 + e.y2) / 2}, ${e.x2} ${e.y2}`"
              fill="none" stroke="#cbd5e1" stroke-width="1.5" />
          </svg>

          <!-- Nodes -->
          <div v-for="(p, id) in layout.positions" :key="id"
            :data-node-id="id"
            class="absolute w-[168px]"
            :style="{ left: p.x + 'px', top: p.y + 'px' }">
            <div
              :class="['group rounded-lg border px-2.5 py-1.5 shadow-sm transition',
                nodeClass(graph.nodes[id].kind, graph.nodes[id].active),
                hoverTarget === id ? 'ring-2 ring-sky-400' : '',
                graph.nodes[id].kind === 'agent' ? 'cursor-grab active:cursor-grabbing' : '',
                dragId === id ? 'opacity-40' : '']"
              @pointerdown="onNodePointerDown($event, id, graph.nodes[id].kind)"
              @dblclick="openAgent(id)">
              <div class="flex items-center gap-1.5">
                <span v-if="graph.nodes[id].kind === 'agent' && graph.nodes[id].level"
                  :class="['h-2 w-2 shrink-0 rounded-full', LEVEL_DOT[graph.nodes[id].level!] ?? 'bg-slate-300']" />
                <i v-else-if="graph.nodes[id].kind === 'team'" class="pi pi-users text-[11px] text-indigo-500" />
                <i v-else-if="graph.nodes[id].kind === 'hq'" class="pi pi-building text-[11px]" />
                <span class="truncate text-xs font-medium">{{ graph.nodes[id].label }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="truncate text-[10px] opacity-70">{{ graph.nodes[id].sub }}</span>
                <span v-if="graph.nodes[id].kind === 'agent' && (graph.nodes[id].premium ?? 0) > 0"
                  class="text-[10px] font-medium text-emerald-600">฿{{ money1(graph.nodes[id].premium!) }}</span>
                <span v-if="graph.nodes[id].kind === 'agent' && graph.nodes[id].level"
                  class="ml-1 text-[9px] uppercase opacity-50">{{ graph.nodes[id].level }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Drag ghost -->
        <div v-if="dragId" class="pointer-events-none fixed z-50 rounded-lg border border-sky-400 bg-white px-2.5 py-1.5 text-xs shadow-lg"
          :style="{ left: dragPos.x + 12 + 'px', top: dragPos.y + 12 + 'px' }">
          {{ graph.nodes[dragId]?.label }}
        </div>

        <div v-if="saving" class="absolute bottom-2 right-2 rounded bg-slate-800 px-2 py-1 text-[10px] text-white">กำลังบันทึก…</div>
      </div>

      <p class="mt-1 text-[10px] text-slate-400">ดับเบิลคลิกที่การ์ดเพื่อเปิดหน้ารายละเอียดตัวแทน</p>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
