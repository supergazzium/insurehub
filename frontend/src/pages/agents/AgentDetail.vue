<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchAgent, fetchTeams, fetchRanks, updateAgentHierarchy, fetchRankPromotions,
  type TeamRow, type RankRow, type RankPromotionRow,
} from '../../api/agents'
import { ApiError } from '../../api/client'
import SearchSelect, { type SearchOption } from '../../components/SearchSelect.vue'
import AgentPicker from '../../components/AgentPicker.vue'
import AgentsSubnav from './AgentsSubnav.vue'
import { fmtDate } from '../../util/dateFormat'

const route = useRoute()
const router = useRouter()
const agentId = computed(() => String(route.params.id))

const agent = ref<Record<string, unknown> | null>(null)
const teams = ref<TeamRow[]>([])
const ranks = ref<RankRow[]>([])
const promotions = ref<RankPromotionRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const saving = ref(false)
const flash = ref<{ ok: boolean; text: string } | null>(null)

// Editable สายงาน + level form (hydrated from the agent).
const form = ref<{ teamId: string; parentAgentId: string; level: string }>({
  teamId: '', parentAgentId: '', level: '',
})

const money = (n: number) => n.toLocaleString('th-TH', { maximumFractionDigits: 0 })

function s(key: string): string {
  const v = agent.value?.[key]
  return v == null ? '' : String(v)
}
const agentName = computed(() => `${s('firstName')} ${s('lastName')}`.trim() || s('agentCode') || '—')

const teamOptions = computed<SearchOption[]>(() => [
  { value: '', label: '— ไม่มีสายงาน —' },
  ...teams.value.map((t) => ({
    value: t.id,
    label: `${t.code}${t.parentTeamId ? ` (ภายใต้ ${teams.value.find((x) => x.id === t.parentTeamId)?.code ?? '—'})` : ''} · ${t.memberCount} คน`,
  })),
])
const levelOptions = computed<SearchOption[]>(() => [
  { value: '', label: '— ไม่กำหนด —' },
  ...ranks.value.map((r) => ({
    value: r.levelKey,
    label: `${r.nameTh} (${r.code})${r.licenseRequired ? ' · ต้องมีใบอนุญาต' : ''}`,
  })),
])

const currentRank = computed(() => ranks.value.find((r) => r.levelKey === form.value.level) ?? null)
const nextRank = computed(() => {
  if (!currentRank.value) return ranks.value[0] ?? null
  return ranks.value.find((r) => r.level === currentRank.value!.level + 1) ?? null
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [a, t, r, p] = await Promise.all([
      fetchAgent(agentId.value),
      fetchTeams(),
      fetchRanks(),
      fetchRankPromotions('all').catch(() => ({ data: [] as RankPromotionRow[], meta: { pendingCount: 0 } })),
    ])
    agent.value = a.data
    teams.value = t.data
    ranks.value = r.data
    promotions.value = p.data.filter((x) => x.agentId === agentId.value)
    form.value = {
      teamId: s('teamId'),
      parentAgentId: s('parentAgentId'),
      level: s('level'),
    }
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

async function saveHierarchy(): Promise<void> {
  saving.value = true
  flash.value = null
  try {
    const res = await updateAgentHierarchy(agentId.value, {
      teamId: form.value.teamId || null,
      parentAgentId: form.value.parentAgentId || null,
      level: form.value.level || null,
    })
    // Reflect returned canonical values.
    if (agent.value) {
      agent.value.teamId = res.data.teamId
      agent.value.parentAgentId = res.data.parentAgentId
      agent.value.level = res.data.level
      agent.value.rankId = res.data.rankId
    }
    flash.value = { ok: true, text: 'บันทึกสายงานและระดับแล้ว' }
  } catch (e: unknown) {
    const msg = e instanceof ApiError ? e.message : 'บันทึกล้มเหลว'
    flash.value = { ok: false, text: msg }
  } finally {
    saving.value = false
    setTimeout(() => { flash.value = null }, 3500)
  }
}

const dirty = computed(() =>
  form.value.teamId !== s('teamId') ||
  form.value.parentAgentId !== s('parentAgentId') ||
  form.value.level !== s('level'),
)

function backToList() { router.push({ name: 'agents' }) }

function statusBadge(st: string): string {
  return st === 'approved' ? 'bg-emerald-50 text-emerald-700'
    : st === 'rejected' ? 'bg-rose-50 text-rose-700'
    : 'bg-amber-50 text-amber-700'
}
function statusLabel(st: string): string {
  return st === 'approved' ? 'อนุมัติแล้ว' : st === 'rejected' ? 'ปฏิเสธ' : 'รออนุมัติ'
}

onMounted(load)
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-4xl px-4 py-5">
      <button type="button" class="mb-2 text-xs text-slate-500 hover:text-slate-700" @click="backToList">
        <i class="pi pi-arrow-left text-[10px]" /> กลับไปรายชื่อตัวแทน
      </button>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400">
        <i class="pi pi-spin pi-spinner" /> กำลังโหลด…
      </div>
      <div v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</div>

      <template v-else-if="agent">
        <h1 class="text-lg font-semibold text-slate-800">{{ agentName }}</h1>
        <p class="mb-4 text-xs text-slate-500">
          {{ s('agentCode') }}
          <span v-if="s('level')" class="ml-1 rounded bg-indigo-50 px-1.5 py-0.5 text-indigo-700">
            {{ ranks.find((r) => r.levelKey === s('level'))?.nameTh ?? s('level') }}
          </span>
        </p>

        <transition name="fade">
          <div v-if="flash"
            :class="['mb-3 rounded px-3 py-2 text-sm', flash.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']">
            {{ flash.text }}
          </div>
        </transition>

        <!-- สายงาน & Level editor -->
        <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">สายงาน &amp; ระดับ</h2>
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="text-[11px] text-slate-500">สายงาน (ทีม)</label>
              <SearchSelect v-model="form.teamId" :options="teamOptions" placeholder="เลือกทีม" />
            </div>
            <div>
              <label class="text-[11px] text-slate-500">ระดับ (Level)</label>
              <SearchSelect v-model="form.level" :options="levelOptions" placeholder="เลือกระดับ" />
            </div>
            <div class="sm:col-span-2">
              <label class="text-[11px] text-slate-500">ต้นสาย (Upline)</label>
              <AgentPicker v-model="form.parentAgentId" placeholder="ค้นหาตัวแทนต้นสาย" />
              <p v-if="s('parentAgentName')" class="mt-1 text-[10px] text-slate-400">
                ปัจจุบัน: {{ s('parentAgentName') }} ({{ s('parentAgentCode') }})
              </p>
            </div>
          </div>
          <div class="mt-3 flex items-center gap-2">
            <button type="button"
              class="rounded bg-sky-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
              :disabled="saving || !dirty" @click="saveHierarchy">
              {{ saving ? 'กำลังบันทึก…' : 'บันทึก' }}
            </button>
            <span v-if="dirty" class="text-[10px] text-amber-600">มีการเปลี่ยนแปลงที่ยังไม่บันทึก</span>
          </div>
        </div>

        <!-- Level progress -->
        <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-2 text-sm font-semibold text-slate-700">เป้าหมายระดับถัดไป</h2>
          <div v-if="nextRank" class="text-xs text-slate-600">
            เพื่อเลื่อนสู่ <span class="font-medium">{{ nextRank.nameTh }}</span> ต้องมียอดสะสม 3 เดือน
            <span class="font-medium">{{ money(nextRank.threeMonthAccumTarget) }}</span> บาท
            <span v-if="nextRank.licenseRequired" class="text-rose-600"> · ต้องมีใบอนุญาต</span>
            <p class="mt-1 text-[10px] text-slate-400">
              ระบบจะเสนอเลื่อนระดับอัตโนมัติเมื่อยอดถึงเป้า แต่ต้องได้รับการอนุมัติจากผู้ดูแลก่อน
            </p>
          </div>
          <p v-else class="text-xs text-slate-400">อยู่ที่ระดับสูงสุดแล้ว</p>
        </div>

        <!-- Promotion history for this agent -->
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-2 text-sm font-semibold text-slate-700">ประวัติการเลื่อนระดับ</h2>
          <ul v-if="promotions.length" class="space-y-1.5">
            <li v-for="p in promotions" :key="p.id"
              class="flex items-center justify-between gap-2 rounded border border-slate-100 px-2 py-1.5 text-xs">
              <span class="text-slate-700">
                {{ p.fromRankLabel ?? '—' }} → {{ p.toRankLabel ?? '—' }}
                <span class="text-[10px] text-slate-400">({{ p.trigger === 'auto' ? 'อัตโนมัติ' : 'กำหนดเอง' }})</span>
              </span>
              <span class="flex items-center gap-2">
                <span class="text-[10px] text-slate-400">{{ fmtDate(p.decidedAt ?? p.requestedAt) }}</span>
                <span :class="['rounded px-1.5 py-0.5 text-[10px]', statusBadge(p.status)]">{{ statusLabel(p.status) }}</span>
              </span>
            </li>
          </ul>
          <p v-else class="text-xs text-slate-400">ยังไม่มีประวัติการเลื่อนระดับ</p>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
