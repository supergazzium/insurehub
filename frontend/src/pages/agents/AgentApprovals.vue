<script setup lang="ts">
// รออนุมัติ — combined inbox: agent registrations + rank promotions.
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchPendingAgents, approveAgent, rejectAgent, type AgentListRow,
  fetchRankPromotions, approveRankPromotion, rejectRankPromotion, type RankPromotionRow,
} from '../../api/agents'
import { ApiError } from '../../api/client'
import AgentsSubnav from './AgentsSubnav.vue'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()
const agents = ref<AgentListRow[]>([])
const promos = ref<RankPromotionRow[]>([])
const loading = ref(true)
const busy = ref<string | null>(null)
const flash = ref<{ ok: boolean; text: string } | null>(null)
const money = (n: number) => n.toLocaleString('th-TH', { maximumFractionDigits: 0 })

function setFlash(ok: boolean, text: string) { flash.value = { ok, text }; setTimeout(() => flash.value = null, 3500) }
function aName(a: AgentListRow) { return `${a.firstName ?? ''} ${a.lastName ?? ''}`.trim() || a.agentCode }

async function load(): Promise<void> {
  loading.value = true
  try {
    const [ag, pr] = await Promise.all([
      fetchPendingAgents().catch(() => ({ data: [] as AgentListRow[] })),
      fetchRankPromotions('pending').catch(() => ({ data: [] as RankPromotionRow[], meta: { pendingCount: 0 } })),
    ])
    agents.value = ag.data
    promos.value = pr.data
  } finally { loading.value = false }
}

// ── Agent registration approvals ──────────────────────────────────────────
async function approveAg(a: AgentListRow) {
  busy.value = 'ag:' + a.id
  try { await approveAgent(a.id); setFlash(true, `อนุมัติ ${aName(a)} แล้ว`); await load() }
  catch (e: unknown) { setFlash(false, e instanceof ApiError ? e.message : 'อนุมัติล้มเหลว') }
  finally { busy.value = null }
}
async function rejectAg(a: AgentListRow) {
  const note = window.prompt(`เหตุผลที่ปฏิเสธ ${aName(a)}:`)
  if (note === null || note.trim() === '') return
  busy.value = 'ag:' + a.id
  try { await rejectAgent(a.id, note.trim()); setFlash(true, `ปฏิเสธ ${aName(a)} แล้ว`); await load() }
  catch (e: unknown) { setFlash(false, e instanceof ApiError ? e.message : 'ปฏิเสธล้มเหลว') }
  finally { busy.value = null }
}

// ── Promotion approvals ────────────────────────────────────────────────────
async function approvePromo(p: RankPromotionRow) {
  busy.value = 'promo:' + p.id
  try { await approveRankPromotion(p.id); setFlash(true, `อนุมัติเลื่อนระดับ ${p.agentName} แล้ว`); await load() }
  catch (e: unknown) { setFlash(false, e instanceof ApiError ? e.message : 'อนุมัติล้มเหลว') }
  finally { busy.value = null }
}
async function rejectPromo(p: RankPromotionRow) {
  const note = window.prompt(`เหตุผลที่ปฏิเสธการเลื่อนระดับของ ${p.agentName}:`) ?? ''
  busy.value = 'promo:' + p.id
  try { await rejectRankPromotion(p.id, note || undefined); setFlash(true, `ปฏิเสธการเลื่อนระดับแล้ว`); await load() }
  catch (e: unknown) { setFlash(false, e instanceof ApiError ? e.message : 'ปฏิเสธล้มเหลว') }
  finally { busy.value = null }
}

function openAgent(id: string) { router.push({ name: 'agent-detail', params: { id } }) }

onMounted(load)
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-4xl px-4 py-5">
      <h1 class="mb-3 text-lg font-semibold text-slate-800">รออนุมัติ</h1>

      <transition name="fade">
        <div v-if="flash" :class="['mb-3 rounded px-3 py-2 text-sm', flash.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']">{{ flash.text }}</div>
      </transition>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <template v-else>
        <div v-if="!agents.length && !promos.length" class="rounded-lg border border-slate-200 bg-white py-12 text-center text-sm text-slate-400">
          <i class="pi pi-check-circle text-emerald-400" /> ไม่มีรายการรออนุมัติ
        </div>

        <!-- Agent registrations -->
        <section v-if="agents.length" class="mb-5">
          <h2 class="mb-2 text-sm font-semibold text-slate-700"><i class="pi pi-user-plus text-[11px] text-sky-500" /> ตัวแทนใหม่รออนุมัติ ({{ agents.length }})</h2>
          <ul class="space-y-2">
            <li v-for="a in agents" :key="a.id" class="rounded-lg border border-slate-200 bg-white p-3">
              <div class="flex items-center justify-between gap-3">
                <button type="button" class="text-left" @click="openAgent(a.id)">
                  <div class="text-sm font-medium text-slate-800 hover:text-brand-700">{{ aName(a) }} <span class="text-xs text-slate-400">{{ a.agentCode }}</span></div>
                  <div class="text-xs text-slate-400">{{ a.email || a.phone || '—' }} · {{ a.agentType }}</div>
                </button>
                <div class="flex shrink-0 gap-1.5">
                  <button type="button" class="rounded bg-emerald-600 px-2.5 py-1 text-xs text-white disabled:opacity-50" :disabled="busy === 'ag:'+a.id" @click="approveAg(a)">อนุมัติ</button>
                  <button type="button" class="rounded border border-rose-300 px-2.5 py-1 text-xs text-rose-600 disabled:opacity-50" :disabled="busy === 'ag:'+a.id" @click="rejectAg(a)">ปฏิเสธ</button>
                </div>
              </div>
            </li>
          </ul>
        </section>

        <!-- Promotions -->
        <section v-if="promos.length">
          <h2 class="mb-2 text-sm font-semibold text-slate-700"><i class="pi pi-arrow-up text-[11px] text-indigo-500" /> เลื่อนระดับรออนุมัติ ({{ promos.length }})</h2>
          <ul class="space-y-2">
            <li v-for="p in promos" :key="p.id" class="rounded-lg border border-slate-200 bg-white p-3">
              <div class="flex items-start justify-between gap-3">
                <button type="button" class="min-w-0 text-left" @click="openAgent(p.agentId)">
                  <div class="text-sm font-medium text-slate-800 hover:text-brand-700">{{ p.agentName }} <span class="text-xs text-slate-400">{{ p.agentCode }}</span></div>
                  <div class="text-xs text-slate-600">{{ p.fromRankLabel ?? '—' }} → <span class="font-medium">{{ p.toRankLabel ?? '—' }}</span></div>
                  <div class="text-[10px] text-slate-400">ยอดสะสม 3 เดือน ฿{{ money(p.qualifyingVolume) }} · เสนอ {{ fmtDate(p.requestedAt) }}</div>
                </button>
                <div class="flex shrink-0 gap-1.5">
                  <button type="button" class="rounded bg-emerald-600 px-2.5 py-1 text-xs text-white disabled:opacity-50" :disabled="busy === 'promo:'+p.id" @click="approvePromo(p)">อนุมัติ</button>
                  <button type="button" class="rounded border border-rose-300 px-2.5 py-1 text-xs text-rose-600 disabled:opacity-50" :disabled="busy === 'promo:'+p.id" @click="rejectPromo(p)">ปฏิเสธ</button>
                </div>
              </div>
            </li>
          </ul>
        </section>
      </template>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
