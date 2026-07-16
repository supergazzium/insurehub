<script setup lang="ts">
import { onMounted, reactive, ref, watch, computed } from 'vue'
import {
  fetchImportFailures,
  fetchImportFailuresSummary,
  resolveImportFailure,
  type ImportFailure,
  type ImportFailureReason,
  type ImportFailureSummaryRow,
} from '../../api/importFailures'
import { ApiError } from '../../api/client'

const filters = reactive<{ reason: ImportFailureReason | ''; resolved: 'all' | 'open' | 'resolved'; q: string }>({
  reason: '',
  resolved: 'open',
  q: '',
})

const rows = ref<ImportFailure[]>([])
const summary = ref<ImportFailureSummaryRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const page = ref(1)
const perPage = ref(50)
const total = ref(0)
const lastPage = ref(1)

const activeRow = ref<ImportFailure | null>(null)
const noteDraft = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchImportFailures({
      reason: filters.reason || undefined,
      resolved: filters.resolved === 'all' ? undefined : filters.resolved === 'resolved',
      q: filters.q || undefined,
      page: page.value,
      perPage: perPage.value,
    })
    rows.value = res.data
    const meta = (res.meta ?? {}) as { total?: number; lastPage?: number }
    total.value = meta.total ?? rows.value.length
    lastPage.value = meta.lastPage ?? 1
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load import failures'
  } finally {
    loading.value = false
  }
}

async function loadSummary(): Promise<void> {
  try {
    const res = await fetchImportFailuresSummary()
    summary.value = res.data
  } catch {
    /* summary is best-effort */
  }
}

onMounted(async () => {
  await Promise.all([load(), loadSummary()])
})

watch(
  () => [filters.reason, filters.resolved],
  () => {
    page.value = 1
    void load()
  },
)

function openTriage(row: ImportFailure): void {
  activeRow.value = row
  noteDraft.value = row.resolutionNotes ?? ''
}

function closeTriage(): void {
  activeRow.value = null
  noteDraft.value = ''
}

async function submitResolution(): Promise<void> {
  if (!activeRow.value) return
  try {
    await resolveImportFailure(activeRow.value.id, noteDraft.value.trim() || null)
    closeTriage()
    await Promise.all([load(), loadSummary()])
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Failed to save resolution'
  }
}

function reasonBadge(reason: ImportFailureReason): string {
  const map: Record<ImportFailureReason, string> = {
    missing_client: 'bg-rose-50 text-rose-700 border-rose-200',
    missing_agent: 'bg-amber-50 text-amber-700 border-amber-200',
    missing_product: 'bg-violet-50 text-violet-700 border-violet-200',
    missing_company: 'bg-sky-50 text-sky-700 border-sky-200',
    other: 'bg-slate-100 text-slate-600 border-slate-200',
  }
  return map[reason]
}

const summaryTiles = computed(() => {
  const byReason: Record<string, number> = {}
  for (const row of summary.value) {
    if (row.resolved) continue
    byReason[row.reason] = (byReason[row.reason] ?? 0) + row.count
  }
  const openTotal = Object.values(byReason).reduce((a, b) => a + b, 0)
  const resolvedTotal = summary.value.filter((r) => r.resolved).reduce((a, b) => a + b.count, 0)
  return { byReason, openTotal, resolvedTotal }
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">Import Failures</h1>
      <p class="text-slate-500 text-sm mt-1">
        Legacy applications ที่ไม่สามารถ import ได้เพราะ FK ไม่ resolve (ลูกค้า / ตัวแทน / สินค้า / บริษัท)
      </p>
    </header>

    <section class="grid grid-cols-2 md:grid-cols-5 gap-4">
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Open</div>
        <div class="text-2xl font-semibold text-rose-700 mt-1">{{ summaryTiles.openTotal.toLocaleString() }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Resolved</div>
        <div class="text-2xl font-semibold text-emerald-700 mt-1">{{ summaryTiles.resolvedTotal.toLocaleString() }}</div>
      </div>
      <div v-for="key in (['missing_client','missing_agent','missing_product','missing_company','other'] as ImportFailureReason[])"
        :key="key" class="card p-4" v-show="summaryTiles.byReason[key] || key === 'missing_agent'">
        <div class="text-xs uppercase tracking-wider text-slate-400">{{ key.replace('_', ' ') }}</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">
          {{ (summaryTiles.byReason[key] ?? 0).toLocaleString() }}
        </div>
      </div>
    </section>

    <section class="card p-4 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Reason</label>
        <select v-model="filters.reason" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">All</option>
          <option value="missing_client">missing_client</option>
          <option value="missing_agent">missing_agent</option>
          <option value="missing_product">missing_product</option>
          <option value="missing_company">missing_company</option>
          <option value="other">other</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-medium text-slate-500 mb-1 block">Status</label>
        <select v-model="filters.resolved" class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="open">Open</option>
          <option value="resolved">Resolved</option>
          <option value="all">All</option>
        </select>
      </div>
      <div class="md:col-span-2">
        <label class="text-xs font-medium text-slate-500 mb-1 block">Search application code</label>
        <div class="flex gap-2">
          <input v-model.trim="filters.q" placeholder="A2001030001"
            class="flex-1 border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white" />
          <button class="px-4 py-1.5 bg-brand-600 text-white rounded-lg text-sm hover:bg-brand-700"
            :disabled="loading" @click="page = 1; load()">
            {{ loading ? 'Loading…' : 'Apply' }}
          </button>
        </div>
      </div>
    </section>

    <section v-if="error" class="card p-4 bg-rose-50 border-rose-200 text-rose-700 text-sm">{{ error }}</section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">Application code</th>
              <th class="px-4 py-2 text-left">Reason</th>
              <th class="px-4 py-2 text-left">Detail</th>
              <th class="px-4 py-2 text-left">Imported at</th>
              <th class="px-4 py-2 text-left">Status</th>
              <th class="px-4 py-2 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in rows" :key="r.id" class="hover:bg-slate-50">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.applicationCode }}</td>
              <td class="px-4 py-2">
                <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs border', reasonBadge(r.reason)]">
                  {{ r.reason }}
                </span>
              </td>
              <td class="px-4 py-2 text-slate-600">{{ r.detail ?? '—' }}</td>
              <td class="px-4 py-2 text-slate-600">{{ r.importedAt ?? '—' }}</td>
              <td class="px-4 py-2">
                <span v-if="r.resolved" class="inline-flex px-2 py-0.5 rounded-md text-xs bg-emerald-50 text-emerald-700 border border-emerald-200">
                  Resolved
                </span>
                <span v-else class="inline-flex px-2 py-0.5 rounded-md text-xs bg-slate-100 text-slate-600 border border-slate-200">
                  Open
                </span>
              </td>
              <td class="px-4 py-2 text-right">
                <button class="text-brand-600 hover:text-brand-700 text-sm font-medium" @click="openTriage(r)">
                  {{ r.resolved ? 'View' : 'Triage' }}
                </button>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td colspan="6" class="px-4 py-6 text-center text-slate-500">ไม่พบ import failures ที่ตรงเงื่อนไข</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="flex items-center justify-between px-4 py-3 border-t border-slate-100 text-sm">
        <span class="text-slate-500">Page {{ page }} of {{ lastPage }} · {{ total.toLocaleString() }} total</span>
        <div class="flex items-center gap-2">
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="page <= 1 || loading" @click="page -= 1; load()">Prev</button>
          <button class="px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            :disabled="page >= lastPage || loading" @click="page += 1; load()">Next</button>
        </div>
      </div>
    </section>

    <!-- Triage dialog -->
    <div v-if="activeRow" class="fixed inset-0 bg-slate-900/40 flex items-center justify-center z-50 p-4"
      @click.self="closeTriage">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">
        <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <div>
            <div class="text-xs uppercase text-slate-400">Import failure</div>
            <div class="font-mono text-slate-900">{{ activeRow.applicationCode }}</div>
          </div>
          <button class="text-slate-400 hover:text-slate-700" @click="closeTriage"><i class="pi pi-times" /></button>
        </header>
        <div class="p-5 space-y-4 overflow-y-auto">
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div>
              <div class="text-xs uppercase text-slate-400">Reason</div>
              <span :class="['inline-flex px-2 py-0.5 rounded-md text-xs border mt-1', reasonBadge(activeRow.reason)]">
                {{ activeRow.reason }}
              </span>
            </div>
            <div>
              <div class="text-xs uppercase text-slate-400">Status</div>
              <div class="text-slate-700 mt-1">{{ activeRow.resolved ? 'Resolved' : 'Open' }}</div>
            </div>
          </div>
          <div>
            <div class="text-xs uppercase text-slate-400 mb-1">Raw source row</div>
            <pre class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs overflow-x-auto max-h-64">{{ JSON.stringify(activeRow.raw, null, 2) }}</pre>
          </div>
          <div>
            <label class="text-xs uppercase text-slate-400 mb-1 block">Resolution notes</label>
            <textarea v-model="noteDraft" rows="3"
              class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm"
              placeholder="เช่น สร้างตัวแทน IN220267 แล้ว, สินค้า PDXXX ยกเลิก ให้ mark resolved" />
          </div>
        </div>
        <footer class="px-5 py-3 border-t border-slate-200 flex items-center justify-end gap-2">
          <button class="px-4 py-1.5 rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50" @click="closeTriage">
            Cancel
          </button>
          <button v-if="!activeRow.resolved"
            class="px-4 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700"
            @click="submitResolution">
            Mark resolved
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>
