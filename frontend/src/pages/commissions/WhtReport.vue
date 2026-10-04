<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { certificatePath, fetchWhtReport, whtReportCsvPath, type WhtReport } from '../../api/commissionPayouts'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'
import { downloadAuthed, openPdf } from '../../util/fileDownload'

const router = useRouter()
const month = ref(new Date().toLocaleDateString('sv-SE').slice(0, 7))
const form = ref<'pnd3' | 'pnd53'>('pnd3')
const report = ref<WhtReport | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const busy = ref<string | null>(null)

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const tin = (v: string | null) => {
  const d = (v ?? '').replace(/\D/g, '')
  return d.length === 13 ? `${d[0]}-${d.slice(1, 5)}-${d.slice(5, 10)}-${d.slice(10, 12)}-${d[12]}` : (v || '—')
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    report.value = (await fetchWhtReport(month.value, form.value)).data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดรายงานไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function pick(f: 'pnd3' | 'pnd53'): void {
  form.value = f
  void load()
}

async function csv(): Promise<void> {
  busy.value = 'csv'
  try {
    await downloadAuthed(whtReportCsvPath(month.value, form.value), `${report.value?.formLabel ?? form.value}-${month.value}.csv`)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'ดาวน์โหลดไม่สำเร็จ'
  } finally {
    busy.value = null
  }
}

async function cert(batchId: string, payeeId: string): Promise<void> {
  busy.value = payeeId
  try {
    await openPdf(certificatePath(batchId, payeeId))
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'เปิดหนังสือรับรองไม่สำเร็จ'
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-6">
    <button type="button" class="mb-3 text-sm text-slate-500 hover:text-slate-700" @click="router.push({ name: 'commission-payouts' })">
      <i class="pi pi-arrow-left mr-1" /> กลับไปทำจ่ายค่าคอม
    </button>
    <header class="mb-4">
      <h1 class="text-xl font-semibold text-slate-800">รายงานภาษีหัก ณ ที่จ่าย — ค่าคอมตัวแทน</h1>
      <p class="mt-1 text-sm text-slate-500">ใบแนบ ภ.ง.ด.3 (บุคคลธรรมดา) / ภ.ง.ด.53 (นิติบุคคล) รายเดือน ตามวันที่จ่ายจริง ส่งออก CSV เพื่อยื่นแบบ</p>
    </header>

    <section class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
      <div>
        <label class="mb-1 block text-xs text-slate-500">เดือนที่จ่าย</label>
        <input v-model="month" type="month" data-testid="wht-month" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" @change="load" />
      </div>
      <div class="inline-flex overflow-hidden rounded-lg border border-slate-200">
        <button type="button" data-testid="form-pnd3" :class="['px-4 py-2 text-sm', form === 'pnd3' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']" @click="pick('pnd3')">ภ.ง.ด.3</button>
        <button type="button" data-testid="form-pnd53" :class="['px-4 py-2 text-sm', form === 'pnd53' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']" @click="pick('pnd53')">ภ.ง.ด.53</button>
      </div>
      <button type="button" :disabled="busy !== null || !report?.rows.length" class="ml-auto rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="csv">
        <i :class="['pi mr-1', busy === 'csv' ? 'pi-spin pi-spinner' : 'pi-file-excel']" /> ส่งออก CSV
      </button>
    </section>

    <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <div v-if="report" class="mb-4 grid grid-cols-3 gap-3">
      <div class="rounded-xl border border-slate-200 bg-white p-4"><div class="text-xs text-slate-500">จำนวนราย</div><div class="mt-1 text-xl font-semibold text-slate-800">{{ report.totals.count }}</div></div>
      <div class="rounded-xl border border-slate-200 bg-white p-4"><div class="text-xs text-slate-500">เงินได้ที่จ่าย</div><div class="mt-1 text-xl font-semibold text-slate-800">฿{{ money(report.totals.amount) }}</div></div>
      <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><div class="text-xs text-emerald-700">ภาษีที่หักนำส่ง</div><div class="mt-1 text-xl font-semibold text-emerald-800">฿{{ money(report.totals.wht) }}</div></div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="min-w-full divide-y divide-slate-200 text-sm" data-testid="wht-table">
        <thead class="bg-slate-50 text-left text-xs text-slate-500">
          <tr>
            <th class="px-3 py-3">ลำดับ</th>
            <th class="px-3 py-3">เลขประจำตัวผู้เสียภาษี</th>
            <th class="px-3 py-3">ชื่อผู้มีเงินได้ / ที่อยู่</th>
            <th class="px-3 py-3">วันที่จ่าย</th>
            <th class="px-3 py-3">ประเภทเงินได้</th>
            <th class="px-3 py-3 text-right">อัตรา</th>
            <th class="px-3 py-3 text-right">จำนวนเงินที่จ่าย</th>
            <th class="px-3 py-3 text-right">ภาษีที่หัก</th>
            <th class="px-3 py-3">เงื่อนไข</th>
            <th class="px-3 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="10" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
          <tr v-else-if="!report?.rows.length"><td colspan="10" class="px-4 py-10 text-center text-slate-400">ไม่มีรายการหักภาษีในเดือนนี้</td></tr>
          <tr v-for="r in report?.rows ?? []" :key="r.payeeId">
            <td class="px-3 py-2 text-slate-500">{{ r.seq }}</td>
            <td class="px-3 py-2 font-mono text-xs text-slate-700">{{ tin(r.taxId) }}</td>
            <td class="px-3 py-2">
              <div class="font-medium text-slate-800">{{ r.name }} <span class="text-xs text-slate-400">{{ r.agentCode }}</span></div>
              <div class="max-w-[22rem] truncate text-[11px] text-slate-400" :title="r.address ?? ''">{{ r.address || '—' }}</div>
            </td>
            <td class="px-3 py-2 text-slate-600">{{ fmtDate(r.date) }}</td>
            <td class="px-3 py-2 text-slate-600">{{ r.incomeType }}</td>
            <td class="px-3 py-2 text-right text-slate-600">{{ r.rate }}%</td>
            <td class="px-3 py-2 text-right text-slate-700">฿{{ money(r.amount) }}</td>
            <td class="px-3 py-2 text-right font-medium text-slate-800">฿{{ money(r.wht) }}</td>
            <td class="px-3 py-2 text-xs text-slate-500">{{ r.condition }} หัก ณ ที่จ่าย</td>
            <td class="whitespace-nowrap px-3 py-2 text-right">
              <button type="button" class="text-xs text-brand-600 hover:underline" @click="cert(r.batchId, r.payeeId)">
                <i :class="['pi text-[10px]', busy === r.payeeId ? 'pi-spin pi-spinner' : 'pi-verified']" /> 50 ทวิ {{ r.certificate }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
