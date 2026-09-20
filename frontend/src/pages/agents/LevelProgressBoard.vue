<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchLevelProgressBoard, type LevelProgressBoardRow } from '../../api/agents'
import { ApiError } from '../../api/client'
import AgentsSubnav from './AgentsSubnav.vue'

const router = useRouter()
const rows = ref<LevelProgressBoardRow[]>([])
const month = ref('')
const loading = ref(true)
const error = ref<string | null>(null)

const money = (n: number) => n.toLocaleString('th-TH', { maximumFractionDigits: 0 })

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchLevelProgressBoard()
    rows.value = res.data
    month.value = res.meta.month
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function openAgent(id: string) { router.push({ name: 'agent-detail', params: { id } }) }

function barClass(pct: number): string {
  if (pct >= 100) return 'from-emerald-400 to-emerald-600'
  if (pct >= 75) return 'from-sky-400 to-sky-600'
  if (pct >= 40) return 'from-amber-300 to-amber-500'
  return 'from-slate-300 to-slate-400'
}

onMounted(load)
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-4xl px-4 py-5">
      <div class="mb-3">
        <h1 class="text-lg font-semibold text-slate-800">ใกล้เลื่อนระดับ</h1>
        <p class="text-[11px] text-slate-400">
          จัดอันดับตัวแทนตามความคืบหน้าสู่ระดับถัดไป · ยอดสะสม 3 เดือน (รอบ {{ month || '—' }})
        </p>
      </div>

      <div v-if="loading" class="py-16 text-center text-sm text-slate-400"><i class="pi pi-spin pi-spinner" /> กำลังโหลด…</div>
      <div v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</div>
      <div v-else-if="rows.length === 0" class="rounded-lg border border-slate-200 bg-white py-12 text-center text-sm text-slate-400">
        ยังไม่มีข้อมูลความคืบหน้า (ยังไม่มียอดขายในระบบ)
      </div>

      <ul v-else class="space-y-2">
        <li v-for="r in rows" :key="r.agentId"
          class="cursor-pointer rounded-lg border border-slate-200 bg-white p-3 hover:border-sky-300"
          @click="openAgent(r.agentId)">
          <div class="mb-1 flex items-baseline justify-between gap-2">
            <div class="min-w-0">
              <span class="text-sm font-medium text-slate-800">{{ r.agentName }}</span>
              <span class="ml-1 text-xs text-slate-400">{{ r.agentCode }}</span>
            </div>
            <div class="shrink-0 text-xs text-slate-600">
              {{ r.currentRankLabel ?? ('ระดับ ' + r.currentLevel) }}
              <i class="pi pi-arrow-right mx-0.5 text-[9px] text-slate-400" />
              <span class="text-sky-700">{{ r.nextRankLabel }}</span>
              <span class="ml-2 font-semibold text-slate-700">{{ r.progressPct }}%</span>
            </div>
          </div>
          <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
            <div :class="['h-full rounded-full bg-gradient-to-r transition-all', barClass(r.progressPct)]"
              :style="{ width: r.progressPct + '%' }" />
          </div>
          <div class="mt-1 flex items-center justify-between text-[10px] text-slate-400">
            <span>฿{{ money(r.currentVolume) }} / ฿{{ money(r.target) }}</span>
            <span v-if="r.licenseBlocked" class="text-rose-500">ต้องมีใบอนุญาต</span>
            <span v-else-if="r.remaining > 0">เหลือ ฿{{ money(r.remaining) }}</span>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>
