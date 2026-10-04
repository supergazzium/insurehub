<script setup lang="ts">
// รออนุมัติ — combined inbox: agent registrations + rank promotions.
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { levelBadgeClass, promotionTriggerLabel } from '../../utils/agentLevel'
import {
  fetchPendingAgents, approveAgent, rejectAgent, type AgentListRow,
  fetchRankPromotions, approveRankPromotion, rejectRankPromotion, type RankPromotionRow,
} from '../../api/agents'
import { ApiError } from '../../api/client'
import AgentsSubnav from './AgentsSubnav.vue'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()
const auth = useAuthStore()
const canDecide = computed(() => auth.hasPermission('agents.rank_approve'))
const agents = ref<AgentListRow[]>([])
const promos = ref<RankPromotionRow[]>([])
const loading = ref(true)
const refreshKey = ref(0)
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
    refreshKey.value++
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
const confirmId = ref<string | null>(null)
const rejectId = ref<string | null>(null)
const rejectNote = ref('')
const rejectError = ref<string | null>(null)

function startApprove(p: RankPromotionRow) { rejectId.value = null; confirmId.value = p.id }
function startReject(p: RankPromotionRow) { confirmId.value = null; rejectId.value = p.id; rejectNote.value = ''; rejectError.value = null }
function cancelDecision() { confirmId.value = null; rejectId.value = null; rejectError.value = null }
function licenseBlocked(p: RankPromotionRow) { return p.toLicenseRequired && !p.licenseValid }
function direction(p: RankPromotionRow): string {
  const from = p.currentLevel ?? p.fromLevel ?? 0
  if (p.toLevel === null) return ''
  return p.toLevel > from ? 'เลื่อนขึ้น' : 'ปรับลด'
}

async function approvePromo(p: RankPromotionRow) {
  busy.value = 'promo:' + p.id
  try {
    await approveRankPromotion(p.id)
    setFlash(true, `อนุมัติ ${p.agentName} เป็นระดับ ${p.toLevel} แล้ว`)
    confirmId.value = null
    await load()
  }
  catch (e: unknown) { setFlash(false, e instanceof ApiError ? e.message : 'อนุมัติล้มเหลว') }
  finally { busy.value = null }
}
async function rejectPromo(p: RankPromotionRow) {
  const note = rejectNote.value.trim()
  if (!note) { rejectError.value = 'กรุณาระบุเหตุผลที่ไม่อนุมัติ'; return }
  busy.value = 'promo:' + p.id
  try {
    await rejectRankPromotion(p.id, note)
    setFlash(true, `ไม่อนุมัติคำขอของ ${p.agentName} แล้ว`)
    rejectId.value = null
    await load()
  }
  catch (e: unknown) { rejectError.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ' }
  finally { busy.value = null }
}

function openAgent(id: string) { router.push({ name: 'agent-detail', params: { id } }) }

onMounted(load)
</script>

<template>
  <div>
    <AgentsSubnav :refresh-key="refreshKey" />
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
          <h2 class="mb-2 text-sm font-semibold text-slate-700"><i class="pi pi-arrow-up text-[11px] text-indigo-500" /> คำขอเลื่อน/ปรับระดับรออนุมัติ ({{ promos.length }})</h2>
          <p v-if="!canDecide" class="mb-2 rounded bg-slate-50 px-3 py-2 text-xs text-slate-500" data-testid="promo-no-permission">
            <i class="pi pi-lock text-[10px]" /> ดูได้อย่างเดียว — ต้องมีสิทธิ์ "อนุมัติเลื่อนระดับ" จึงจะอนุมัติหรือไม่อนุมัติได้
          </p>
          <ul class="space-y-2">
            <li v-for="p in promos" :key="p.id" class="rounded-lg border border-slate-200 bg-white p-3" :data-testid="`promo-row-${p.id}`">
              <div class="flex items-start justify-between gap-3">
                <button type="button" class="min-w-0 text-left" @click="openAgent(p.agentId)">
                  <div class="text-sm font-medium text-slate-800 hover:text-brand-700">{{ p.agentName }} <span class="text-xs text-slate-400">{{ p.agentCode }}</span></div>
                  <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                    <span :class="['rounded px-1.5 py-0.5 text-[11px] font-medium', levelBadgeClass(p.currentLevel ?? p.fromLevel)]">ระดับ {{ p.currentLevel ?? p.fromLevel ?? '—' }}</span>
                    <i class="pi pi-arrow-right text-[9px] text-slate-400" />
                    <span :class="['rounded px-1.5 py-0.5 text-[11px] font-semibold', levelBadgeClass(p.toLevel)]">ระดับ {{ p.toLevel ?? '—' }}</span>
                    <span class="text-[11px] text-slate-500">{{ direction(p) }}</span>
                    <span :class="['rounded-full px-2 py-0.5 text-[10px]', p.trigger === 'manual' ? 'bg-indigo-50 text-indigo-700' : 'bg-sky-50 text-sky-700']">{{ promotionTriggerLabel(p.trigger, p.requestedByName) }}</span>
                  </div>
                  <div v-if="p.requestNote" class="mt-1 text-xs text-slate-600">เหตุผล: {{ p.requestNote }}</div>
                  <div class="mt-0.5 text-[11px] text-slate-400">ยอดสะสม 3 เดือน ฿{{ money(p.qualifyingVolume) }} ({{ p.qualifyingPeriod }}) · ส่งเมื่อ {{ fmtDate(p.requestedAt) }}</div>
                  <div v-if="p.toLicenseRequired" :class="['mt-0.5 text-[11px]', p.licenseValid ? 'text-emerald-600' : 'text-rose-600']">
                    <i :class="['pi text-[9px]', p.licenseValid ? 'pi-check-circle' : 'pi-exclamation-triangle']" />
                    {{ p.licenseValid ? 'มีใบอนุญาตที่ยังไม่หมดอายุ' : 'ระดับนี้ต้องมีใบอนุญาตชีวิตหรือวินาศที่ยังไม่หมดอายุ — อนุมัติไม่ได้' }}
                  </div>
                </button>
                <div v-if="canDecide && confirmId !== p.id && rejectId !== p.id" class="flex shrink-0 gap-1.5">
                  <button type="button" data-testid="promo-approve" class="rounded bg-emerald-600 px-2.5 py-1 text-xs text-white disabled:opacity-50" :disabled="busy === 'promo:'+p.id || licenseBlocked(p)" @click="startApprove(p)">อนุมัติ</button>
                  <button type="button" data-testid="promo-reject" class="rounded border border-rose-300 px-2.5 py-1 text-xs text-rose-600 disabled:opacity-50" :disabled="busy === 'promo:'+p.id" @click="startReject(p)">ไม่อนุมัติ</button>
                </div>
              </div>
              <div v-if="confirmId === p.id" class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded bg-emerald-50 px-3 py-2 text-xs text-emerald-800" data-testid="promo-approve-panel">
                <span>ยืนยันให้ {{ p.agentName }} เป็นระดับ {{ p.toLevel }} ทันที?</span>
                <span class="flex gap-1.5">
                  <button type="button" data-testid="promo-approve-confirm" class="rounded bg-emerald-600 px-2.5 py-1 text-white disabled:opacity-50" :disabled="busy === 'promo:'+p.id" @click="approvePromo(p)">ยืนยันอนุมัติ</button>
                  <button type="button" class="rounded border border-slate-300 bg-white px-2.5 py-1 text-slate-600" @click="cancelDecision">ยกเลิก</button>
                </span>
              </div>
              <div v-if="rejectId === p.id" class="mt-2 space-y-1.5 rounded bg-rose-50 px-3 py-2 text-xs" data-testid="promo-reject-panel">
                <label class="block text-rose-800">เหตุผลที่ไม่อนุมัติ (จำเป็น)</label>
                <textarea v-model="rejectNote" rows="2" maxlength="1000" data-testid="promo-reject-note" class="w-full rounded border border-rose-200 bg-white px-2 py-1 text-xs" />
                <p v-if="rejectError" class="text-rose-600">{{ rejectError }}</p>
                <span class="flex justify-end gap-1.5">
                  <button type="button" data-testid="promo-reject-confirm" class="rounded bg-rose-600 px-2.5 py-1 text-white disabled:opacity-50" :disabled="busy === 'promo:'+p.id" @click="rejectPromo(p)">ยืนยันไม่อนุมัติ</button>
                  <button type="button" class="rounded border border-slate-300 bg-white px-2.5 py-1 text-slate-600" @click="cancelDecision">ยกเลิก</button>
                </span>
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
