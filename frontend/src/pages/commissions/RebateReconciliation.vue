<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import {
  fetchRebateReconciliation,
  type RebateReconciliationRow,
  type RebateLeg,
} from '../../api/reports'
import { ApiError } from '../../api/client'
import EditableRebateCell from './EditableRebateCell.vue'

const rows = ref<RebateReconciliationRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const page = ref(1)
const perPage = ref(50)
const total = ref(0)
const lastPage = ref(1)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchRebateReconciliation({ page: page.value, perPage: perPage.value })
    rows.value = res.data
    const meta = (res.meta ?? {}) as { total?: number; lastPage?: number }
    total.value = meta.total ?? rows.value.length
    lastPage.value = meta.lastPage ?? 1
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'ไม่สามารถโหลด rebate reconciliation'
    rows.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)

function fmtBaht(n: number | null): string {
  if (n === null) return '—'
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 2 }).format(n)
}

function deltaClass(d: number | null): string {
  if (d === null) return 'text-slate-400'
  if (d > 0) return 'text-emerald-600'
  if (d < 0) return 'text-rose-600'
  return 'text-slate-500'
}

const mismatchCount = computed(() =>
  rows.value.filter((r) => hasSignificantDelta(r.inh) || hasSignificantDelta(r.ov) || hasSignificantDelta(r.ag)).length,
)

function hasSignificantDelta(leg: RebateLeg): boolean {
  return leg.delta !== null && Math.abs(leg.delta) > 1
}

/** Recompute delta locally after an inline edit. */
function recompute(leg: RebateLeg): void {
  if (leg.calculated !== null && leg.actual !== null) {
    leg.delta = leg.actual - leg.calculated
  } else {
    leg.delta = null
  }
}

function updateInhCalc(r: RebateReconciliationRow, v: number | null) { r.inh.calculated = v; recompute(r.inh) }
function updateInhActual(r: RebateReconciliationRow, v: number | null) { r.inh.actual = v; recompute(r.inh) }
function updateOvCalc(r: RebateReconciliationRow, v: number | null) { r.ov.calculated = v; recompute(r.ov) }
function updateOvActual(r: RebateReconciliationRow, v: number | null) { r.ov.actual = v; recompute(r.ov) }
function updateAgCalc(r: RebateReconciliationRow, v: number | null) { r.ag.calculated = v; recompute(r.ag) }
function updateAgActual(r: RebateReconciliationRow, v: number | null) { r.ag.actual = v; recompute(r.ag) }

function nextPage() {
  if (page.value < lastPage.value) {
    page.value += 1
    void load()
  }
}
function prevPage() {
  if (page.value > 1) {
    page.value -= 1
    void load()
  }
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">Rebate Reconciliation</h1>
      <p class="text-slate-500 text-sm mt-1">
        ตรวจสอบ Calculated vs Actual บนสามขา — In-house / Overriding / Agent.
        คลิกที่ตัวเลขเพื่อแก้ไข — กด Enter บันทึก, Esc ยกเลิก
      </p>
    </header>

    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Rows on this page</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ rows.length.toLocaleString() }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Total ledger entries</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ total.toLocaleString() }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Mismatches on page</div>
        <div class="text-2xl font-semibold text-amber-700 mt-1">{{ mismatchCount.toLocaleString() }}</div>
        <div class="text-xs text-slate-500 mt-1">|Δ| &gt; 1 THB on any leg</div>
      </div>
    </section>

    <section v-if="error" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">
      {{ error }}
    </section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">Earn date</th>
              <th class="px-4 py-2 text-left">Application</th>
              <th class="px-4 py-2 text-left">Agent</th>
              <th class="px-4 py-2 text-right">InH calc</th>
              <th class="px-4 py-2 text-right">InH actual</th>
              <th class="px-4 py-2 text-right">Δ</th>
              <th class="px-4 py-2 text-right">OV calc</th>
              <th class="px-4 py-2 text-right">OV actual</th>
              <th class="px-4 py-2 text-right">Δ</th>
              <th class="px-4 py-2 text-right">AG calc</th>
              <th class="px-4 py-2 text-right">AG actual</th>
              <th class="px-4 py-2 text-right">Δ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in rows" :key="r.rebateId" class="hover:bg-slate-50">
              <td class="px-4 py-2 whitespace-nowrap">{{ r.earnDate ?? '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.applicationNo ?? '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.agentCode ?? '—' }}</td>

              <!-- InH -->
              <td class="px-4 py-2">
                <EditableRebateCell :rebate-id="r.rebateId" field="calculatedAmount"
                  :value="r.inh.calculated" align="right"
                  @update="(v) => updateInhCalc(r, v)" />
              </td>
              <td class="px-4 py-2">
                <EditableRebateCell :rebate-id="r.rebateId" field="actualAmount"
                  :value="r.inh.actual" align="right"
                  @update="(v) => updateInhActual(r, v)" />
              </td>
              <td class="px-4 py-2 text-right font-medium" :class="deltaClass(r.inh.delta)">{{ fmtBaht(r.inh.delta) }}</td>

              <!-- OV -->
              <td class="px-4 py-2">
                <EditableRebateCell :rebate-id="r.rebateId" field="calculatedOv"
                  :value="r.ov.calculated" align="right"
                  @update="(v) => updateOvCalc(r, v)" />
              </td>
              <td class="px-4 py-2">
                <EditableRebateCell :rebate-id="r.rebateId" field="actualOv"
                  :value="r.ov.actual" align="right"
                  @update="(v) => updateOvActual(r, v)" />
              </td>
              <td class="px-4 py-2 text-right font-medium" :class="deltaClass(r.ov.delta)">{{ fmtBaht(r.ov.delta) }}</td>

              <!-- AG -->
              <td class="px-4 py-2">
                <EditableRebateCell :rebate-id="r.rebateId" field="calculatedAgentAmount"
                  :value="r.ag.calculated" align="right"
                  @update="(v) => updateAgCalc(r, v)" />
              </td>
              <td class="px-4 py-2">
                <EditableRebateCell :rebate-id="r.rebateId" field="actualAgentAmount"
                  :value="r.ag.actual" align="right"
                  @update="(v) => updateAgActual(r, v)" />
              </td>
              <td class="px-4 py-2 text-right font-medium" :class="deltaClass(r.ag.delta)">{{ fmtBaht(r.ag.delta) }}</td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td colspan="12" class="px-4 py-6 text-center text-slate-500">ไม่มีข้อมูล rebate</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
        <span class="text-slate-500">Page {{ page }} of {{ lastPage }}</span>
        <div class="flex items-center gap-2">
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="page <= 1 || loading" @click="prevPage">Prev</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="page >= lastPage || loading" @click="nextPage">Next</button>
        </div>
      </div>
    </section>
  </div>
</template>
