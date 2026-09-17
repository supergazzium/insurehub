<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchRankPromotions, approveRankPromotion, rejectRankPromotion,
  type RankPromotionRow, type PromotionStatus,
} from '../../api/agents'
import { ApiError } from '../../api/client'
import AgentsSubnav from './AgentsSubnav.vue'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()
const rows = ref<RankPromotionRow[]>([])
const pendingCount = ref(0)
const loading = ref(true)
const error = ref<string | null>(null)
const busy = ref<string | null>(null)
const flash = ref<{ ok: boolean; text: string } | null>(null)
const filter = ref<PromotionStatus | 'all'>('pending')

const money = (n: number) => n.toLocaleString('th-TH', { maximumFractionDigits: 0 })

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchRankPromotions(filter.value)
    rows.value = res.data
    pendingCount.value = res.meta.pendingCount
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function setFlash(ok: boolean, text: string) {
  flash.value = { ok, text }
  setTimeout(() => { flash.value = null }, 3500)
}

async function approve(p: RankPromotionRow): Promise<void> {
  busy.value = p.id
  try {
    await approveRankPromotion(p.id)
    setFlash(true, `อนุมัติเลื่อนระดับ ${p.agentName} แล้ว`)
    await load()
  } catch (e: unknown) {
    setFlash(false, e instanceof ApiError ? e.message : 'อนุมัติล้มเหลว')
  } finally { busy.value = null }
}
async function reject(p: RankPromotionRow): Promise<void> {
  const note = window.prompt(`เหตุผลที่ปฏิเสธการเลื่อนระดับของ ${p.agentName} (ไม่บังคับ):`) ?? ''
  busy.value = p.id
  try {
    await rejectRankPromotion(p.id, note || undefined)
    setFlash(true, `ปฏิเสธการเลื่อนระดับ ${p.agentName} แล้ว`)
    await load()
  } catch (e: unknown) {
    setFlash(false, e instanceof ApiError ? e.message : 'ปฏิเสธล้มเหลว')
  } finally { busy.value = null }
}

function openAgent(id: string) { router.push({ name: 'agent-detail', params: { id } }) }

function statusBadge(st: string): string {
  return st === 'approved' ? 'bg-emerald-50 text-emerald-700'
    : st === 'rejected' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'
}
function statusLabel(st: string): string {
  return st === 'approved' ? 'อนุมัติแล้ว' : st === 'rejected' ? 'ปฏิเสธ' : 'รออนุมัติ'
}

const TABS: { key: PromotionStatus | 'all'; label: string }[] = [
  { key: 'pending', label: 'รออนุมัติ' },
  { key: 'approved', label: 'อนุมัติแล้ว' },
  { key: 'rejected', label: 'ปฏิเสธ' },
  { key: 'all', label: 'ทั้งหมด' },
]
function setFilter(k: PromotionStatus | 'all') { filter.value = k; void load() }

onMounted(load)
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-4xl px-4 py-5">
      <div class="mb-3 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-800">
          อนุมัติการเลื่อนระดับ
          <span v-if="pendingCount > 0" class="ml-1 rounded-full bg-amber-500 px-2 py-0.5 text-xs text-white">{{ pendingCount }}</span>
        </h1>
      </div>

      <div class="mb-3 flex gap-1">
        <button v-for="t in TABS" :key="t.key" type="button"
          :class="['rounded px-2.5 py-1 text-xs', filter === t.key ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200']"
          @click="setFilter(t.key)">{{ t.label }}</button>
      </div>

      <transition name="fade">
        <div v-if="flash" :class="['mb-3 rounded px-3 py-2 text-sm', flash.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']">
          {{ flash.text }}
        </div>
      </transition>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <div v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</div>
      <div v-else-if="rows.length === 0" class="rounded-lg border border-slate-200 bg-white py-12 text-center text-sm text-slate-400">
        ไม่มีรายการ
      </div>

      <ul v-else class="space-y-2">
        <li v-for="p in rows" :key="p.id" class="rounded-lg border border-slate-200 bg-white p-3">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <button type="button" class="text-sm font-medium text-slate-800 hover:text-sky-600" @click="openAgent(p.agentId)">
                {{ p.agentName }} <span class="text-xs text-slate-400">{{ p.agentCode }}</span>
              </button>
              <div class="mt-0.5 text-xs text-slate-600">
                {{ p.fromRankLabel ?? '—' }} → <span class="font-medium">{{ p.toRankLabel ?? '—' }}</span>
                <span class="ml-1 text-[10px] text-slate-400">({{ p.trigger === 'auto' ? 'อัตโนมัติ' : 'กำหนดเอง' }})</span>
              </div>
              <div class="mt-0.5 text-[10px] text-slate-400">
                ยอดสะสม 3 เดือน {{ money(p.qualifyingVolume) }} บาท · รอบ {{ p.qualifyingPeriod ?? '—' }} · เสนอ {{ fmtDate(p.requestedAt) }}
              </div>
              <div v-if="p.notes" class="mt-0.5 text-[10px] text-slate-400">หมายเหตุ: {{ p.notes }}</div>
            </div>
            <div class="flex shrink-0 flex-col items-end gap-1.5">
              <span :class="['rounded px-1.5 py-0.5 text-[10px]', statusBadge(p.status)]">{{ statusLabel(p.status) }}</span>
              <div v-if="p.status === 'pending'" class="flex gap-1.5">
                <button type="button" class="rounded bg-emerald-600 px-2.5 py-1 text-xs text-white disabled:opacity-50"
                  :disabled="busy === p.id" @click="approve(p)">อนุมัติ</button>
                <button type="button" class="rounded border border-rose-300 px-2.5 py-1 text-xs text-rose-600 disabled:opacity-50"
                  :disabled="busy === p.id" @click="reject(p)">ปฏิเสธ</button>
              </div>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
