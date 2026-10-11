<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchLevelProgressBoard, fetchRanks, updateRank, type LevelProgressBoardRow, type RankRow } from '../../api/agents'
import { ApiError } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import { levelBadgeClass } from '../../utils/agentLevel'
import AgentsSubnav from './AgentsSubnav.vue'

const router = useRouter()
const rows = ref<LevelProgressBoardRow[]>([])
const month = ref('')
const loading = ref(true)
const error = ref<string | null>(null)

const money = (n: number) => n.toLocaleString('th-TH', { maximumFractionDigits: 0 })

const auth = useAuthStore()
const canEditRanks = computed(() => auth.hasPermission('admin.settings'))
const ranks = ref<RankRow[]>([])
const editingId = ref<string | null>(null)
const rankForm = reactive({ nameTh: '', threeMonthAccumTarget: 0, licenseRequired: false })
const rankError = ref<string | null>(null)
const rankSaving = ref(false)
const rankSaved = ref<string | null>(null)

async function loadRanks(): Promise<void> {
  try { ranks.value = (await fetchRanks()).data } catch { ranks.value = [] }
}

function startEditRank(r: RankRow): void {
  editingId.value = r.id
  rankForm.nameTh = r.nameTh
  rankForm.threeMonthAccumTarget = r.threeMonthAccumTarget
  rankForm.licenseRequired = r.licenseRequired
  rankError.value = null
  rankSaved.value = null
}

async function saveRank(r: RankRow): Promise<void> {
  rankSaving.value = true
  rankError.value = null
  try {
    await updateRank(r.id, {
      nameTh: rankForm.nameTh.trim() || r.nameTh,
      threeMonthAccumTarget: Number(rankForm.threeMonthAccumTarget) || 0,
      licenseRequired: rankForm.licenseRequired,
    })
    editingId.value = null
    rankSaved.value = `บันทึกเกณฑ์ ${r.code} แล้ว`
    await Promise.all([loadRanks(), load()])
  } catch (e: unknown) {
    if (e instanceof ApiError) {
      const errs = (e.body?.errors ?? {}) as Record<string, string[]>
      rankError.value = Object.values(errs)[0]?.[0] ?? e.message
    } else {
      rankError.value = 'บันทึกไม่สำเร็จ'
    }
  } finally { rankSaving.value = false }
}

function targetText(r: RankRow): string {
  if (r.level === 1) return 'ระดับเริ่มต้น'
  if (r.threeMonthAccumTarget <= 0) return 'มียอดขาย (มากกว่า ฿0)'
  return `฿${money(r.threeMonthAccumTarget)}`
}

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

onMounted(() => { void loadRanks(); void load() })
</script>

<template>
  <div>
    <AgentsSubnav />
    <div class="mx-auto max-w-4xl px-4 py-5">
      <section class="mb-5 rounded-lg border border-slate-200 bg-white p-4" data-testid="rank-criteria">
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="text-sm font-semibold text-slate-800">เกณฑ์ 10 ระดับ</h2>
          <span v-if="!canEditRanks" class="text-[11px] text-slate-400"><i class="pi pi-lock text-[9px]" /> แก้ไขได้เฉพาะผู้มีสิทธิ์จัดการตั้งค่าระบบ</span>
        </div>
        <table class="w-full text-xs">
          <thead class="text-left text-slate-500">
            <tr>
              <th class="py-1 pr-2">ระดับ</th>
              <th class="py-1 pr-2">ชื่อ</th>
              <th class="py-1 pr-2 text-right">ยอดทีมสะสม 3 เดือน</th>
              <th class="py-1 pr-2 text-center">ใบอนุญาต</th>
              <th v-if="canEditRanks" class="py-1" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in ranks" :key="r.id" :data-testid="`rank-row-${r.level}`">
              <td class="py-1.5 pr-2"><span :class="['rounded px-1.5 py-0.5 font-semibold', levelBadgeClass(r.level)]">{{ r.code }}</span></td>
              <template v-if="editingId === r.id">
                <td class="py-1.5 pr-2"><input v-model="rankForm.nameTh" maxlength="64" class="w-full rounded border border-slate-300 px-2 py-1 text-xs" /></td>
                <td class="py-1.5 pr-2 text-right">
                  <input v-model.number="rankForm.threeMonthAccumTarget" type="number" min="0" step="1000" :disabled="r.level === 1"
                    data-testid="rank-target-input" class="w-36 rounded border border-slate-300 px-2 py-1 text-right text-xs disabled:bg-slate-50" />
                </td>
                <td class="py-1.5 pr-2 text-center"><input v-model="rankForm.licenseRequired" type="checkbox" data-testid="rank-license-input" /></td>
                <td class="py-1.5 text-right whitespace-nowrap">
                  <button type="button" data-testid="rank-save" class="rounded bg-brand-600 px-2 py-1 text-white disabled:opacity-50" :disabled="rankSaving" @click="saveRank(r)">บันทึก</button>
                  <button type="button" class="ml-1 rounded border border-slate-300 px-2 py-1 text-slate-600" @click="editingId = null">ยกเลิก</button>
                </td>
              </template>
              <template v-else>
                <td class="py-1.5 pr-2 text-slate-700">{{ r.nameTh }}</td>
                <td class="py-1.5 pr-2 text-right text-slate-800">{{ targetText(r) }}</td>
                <td class="py-1.5 pr-2 text-center">
                  <span v-if="r.licenseRequired" class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-700">ต้องมี</span>
                  <span v-else class="text-slate-300">—</span>
                </td>
                <td v-if="canEditRanks" class="py-1.5 text-right">
                  <button type="button" :data-testid="`rank-edit-${r.level}`" class="text-[11px] text-brand-700 hover:underline" @click="startEditRank(r)">แก้ไข</button>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
        <p v-if="rankError" class="mt-2 text-xs text-rose-600" data-testid="rank-error">{{ rankError }}</p>
        <p v-if="rankSaved" class="mt-2 text-xs text-emerald-600">{{ rankSaved }}</p>
        <ul class="mt-3 list-disc space-y-0.5 pl-4 text-[11px] text-slate-500">
          <li>ยอดทีม = ยอดชำระเบี้ยของตัวเอง + ลูกทีมทุกชั้นที่ระดับต่ำกว่าตัวเอง (ลูกทีมระดับเท่ากันหรือสูงกว่าไม่นับ)</li>
          <li>นับรวม 3 เดือนปฏิทินล่าสุด (เดือนนี้ + 2 เดือนก่อน) ตามวันที่ชำระ</li>
          <li>ถึงเกณฑ์แล้วระบบส่งคำขอเลื่อนระดับ — ระดับเปลี่ยนเมื่อ InsureHub อนุมัติ · ไม่มีการลดระดับอัตโนมัติ</li>
          <li>ระดับที่ต้องมีใบอนุญาต ต้องมีใบอนุญาตชีวิตหรือวินาศที่ยังไม่หมดอายุ</li>
        </ul>
      </section>

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
            <span>฿{{ money(r.currentVolume) }} / {{ r.target > 0 ? `฿${money(r.target)}` : 'มียอดขาย' }}</span>
            <span v-if="r.licenseBlocked" class="text-rose-500">ต้องมีใบอนุญาต</span>
            <span v-else-if="r.remaining > 0">เหลือ ฿{{ money(r.remaining) }}</span>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>
