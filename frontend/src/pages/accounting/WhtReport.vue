<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchWhtReport, whtExportCsvUrl, type WhtRow, type WhtTotals } from '../../api/whtReport'
import { ApiError, getToken } from '../../api/client'

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// Default the range to the current month (common filing window).
function firstOfMonth(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}
function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const fromDate = ref(firstOfMonth())
const toDate = ref(today())
const rows = ref<WhtRow[]>([])
const totals = ref<WhtTotals | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const rangeLabel = computed(() => {
  if (!fromDate.value && !toDate.value) return 'ทั้งหมด'
  return `${fromDate.value || '…'} ถึง ${toDate.value || '…'}`
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchWhtReport(fromDate.value || undefined, toDate.value || undefined)
    rows.value = res.rows
    totals.value = res.totals
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดรายงานไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
function clearRange(): void { fromDate.value = ''; toDate.value = ''; void load() }

const csvBusy = ref(false)
async function exportCsv(): Promise<void> {
  csvBusy.value = true
  error.value = null
  try {
    const res = await fetch(whtExportCsvUrl(fromDate.value || undefined, toDate.value || undefined), {
      headers: { Authorization: `Bearer ${getToken() ?? ''}`, Accept: 'text/csv' },
    })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `WHT-Report-${fromDate.value || 'all'}-${toDate.value || 'all'}.csv`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'ส่งออก CSV ไม่สำเร็จ'
  } finally {
    csvBusy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-6">
    <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-800">รายงานภาษีหัก ณ ที่จ่าย (3%)</h1>
        <p class="mt-1 text-sm text-slate-500">สรุปภาษีหัก ณ ที่จ่ายจากค่าคอมที่จ่ายให้ตัวแทน สำหรับยื่น ภ.ง.ด.53 และออกหนังสือรับรอง 50 ทวิ</p>
      </div>
      <button
        type="button" :disabled="csvBusy || rows.length === 0"
        class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
        @click="exportCsv"
      >
        <i :class="['pi mr-1', csvBusy ? 'pi-spin pi-spinner' : 'pi-file-excel']" /> ส่งออก CSV (ภ.ง.ด.53)
      </button>
    </header>

    <!-- KPI strip -->
    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ผู้ถูกหักภาษี</div>
        <div class="mt-1 text-2xl font-semibold text-slate-800">{{ totals?.payeeCount ?? 0 }}</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ค่าคอมก่อนหัก</div>
        <div class="mt-1 text-xl font-semibold text-slate-700">฿{{ money(totals?.grossCommission ?? 0) }}</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ภาษีหัก ณ ที่จ่ายรวม</div>
        <div class="mt-1 text-xl font-semibold text-rose-700">฿{{ money(totals?.whtAmount ?? 0) }}</div>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">ยอดจ่ายสุทธิ</div>
        <div class="mt-1 text-xl font-semibold text-emerald-700">฿{{ money(totals?.netPaid ?? 0) }}</div>
      </div>
    </div>

    <!-- Filter -->
    <section class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-xs text-slate-500">จากวันที่จ่าย</label>
          <input v-model="fromDate" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">ถึงวันที่จ่าย</label>
          <input v-model="toDate" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <button type="button" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="load">
          <i class="pi pi-filter mr-1" /> กรอง
        </button>
        <button v-if="fromDate || toDate" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50" @click="clearRange">
          ล้าง (ดูทั้งหมด)
        </button>
        <span class="ml-auto text-xs text-slate-400"><i class="pi pi-info-circle" /> อ้างอิงวันที่จ่ายจริง · เฉพาะรอบที่จ่ายแล้วและไม่ถูกย้อนกลับ</span>
      </div>
    </section>

    <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <!-- Report table -->
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">รหัส</th>
            <th class="px-4 py-3">ผู้ถูกหักภาษี</th>
            <th class="px-4 py-3">เลขผู้เสียภาษี / บัตร ปชช.</th>
            <th class="px-4 py-3 text-right">รอบจ่าย</th>
            <th class="px-4 py-3 text-right">ค่าคอมก่อนหัก</th>
            <th class="px-4 py-3 text-right">หัก 3%</th>
            <th class="px-4 py-3 text-right">จ่ายสุทธิ</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="7" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
          <tr v-else-if="rows.length === 0"><td colspan="7" class="px-4 py-10 text-center text-slate-400">ไม่พบข้อมูลการหักภาษีในช่วงที่เลือก</td></tr>
          <tr v-for="r in rows" :key="r.agentId ?? r.agentCode ?? ''" class="hover:bg-slate-50">
            <td class="px-4 py-3 font-medium text-slate-700">{{ r.agentCode ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-700">
              {{ r.payeeName }}
              <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">{{ r.payeeType === 'corporate' ? 'นิติบุคคล' : 'บุคคลธรรมดา' }}</span>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ r.taxId ?? '—' }}</td>
            <td class="px-4 py-3 text-right text-slate-600">{{ r.payoutCount }}</td>
            <td class="px-4 py-3 text-right text-slate-600">฿{{ money(r.grossCommission) }}</td>
            <td class="px-4 py-3 text-right font-medium text-rose-700">฿{{ money(r.whtAmount) }}</td>
            <td class="px-4 py-3 text-right text-slate-800">฿{{ money(r.netPaid) }}</td>
          </tr>
        </tbody>
        <tfoot v-if="rows.length" class="bg-slate-50">
          <tr class="font-semibold">
            <td class="px-4 py-3" colspan="4">รวม ({{ totals?.payeeCount ?? 0 }} ราย) · {{ rangeLabel }}</td>
            <td class="px-4 py-3 text-right text-slate-700">฿{{ money(totals?.grossCommission ?? 0) }}</td>
            <td class="px-4 py-3 text-right text-rose-700">฿{{ money(totals?.whtAmount ?? 0) }}</td>
            <td class="px-4 py-3 text-right text-slate-800">฿{{ money(totals?.netPaid ?? 0) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</template>
