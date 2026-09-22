<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchPayoutBatches, previewPayout, createPayoutBatch,
  type BatchRow, type BatchStatus, type PreviewResult,
} from '../../api/commissionPayouts'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const router = useRouter()

const batches = ref<BatchRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const STATUS_LABEL: Record<BatchStatus, string> = {
  DRAFT: 'ร่าง', GENERATED: 'สร้างเอกสารแล้ว', APPROVED: 'อนุมัติแล้ว', PAID: 'จ่ายแล้ว', CANCELLED: 'ยกเลิก',
}
const STATUS_BADGE: Record<BatchStatus, string> = {
  DRAFT: 'bg-slate-100 text-slate-600',
  GENERATED: 'bg-sky-50 text-sky-700',
  APPROVED: 'bg-violet-50 text-violet-700',
  PAID: 'bg-emerald-50 text-emerald-700',
  CANCELLED: 'bg-rose-50 text-rose-700',
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchPayoutBatches('all')
    batches.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

// ── Create / Preview panel ──────────────────────────────────────────────
const showCreate = ref(false)
const fromDate = ref('')
const toDate = ref('')
const note = ref('')
const preview = ref<PreviewResult | null>(null)
const previewing = ref(false)
const creating = ref(false)
const createError = ref<string | null>(null)

async function runPreview(): Promise<void> {
  if (!fromDate.value || !toDate.value) return
  previewing.value = true
  createError.value = null
  preview.value = null
  try {
    preview.value = await previewPayout(fromDate.value, toDate.value)
  } catch (e: unknown) {
    createError.value = e instanceof ApiError ? e.message : 'ดูตัวอย่างไม่สำเร็จ'
  } finally {
    previewing.value = false
  }
}

async function doCreate(): Promise<void> {
  if (!fromDate.value || !toDate.value) return
  creating.value = true
  createError.value = null
  try {
    const res = await createPayoutBatch(fromDate.value, toDate.value, note.value || undefined)
    router.push({ name: 'commission-payout-detail', params: { id: res.data.id } })
  } catch (e: unknown) {
    createError.value = e instanceof ApiError ? e.message : 'สร้าง batch ไม่สำเร็จ'
  } finally {
    creating.value = false
  }
}

function openDetail(b: BatchRow): void {
  router.push({ name: 'commission-payout-detail', params: { id: b.id } })
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-6">
    <header class="mb-5 flex items-start justify-between">
      <div>
        <h1 class="text-xl font-semibold text-slate-800">ทำจ่ายค่าคอม — ตัวแทน</h1>
        <p class="mt-1 text-sm text-slate-500">สร้างรอบจ่ายค่าคอมประจำเดือน ตรวจรายการ และยืนยันการจ่าย</p>
      </div>
      <button
        type="button"
        class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
        @click="showCreate = !showCreate"
      >
        <i class="pi pi-plus mr-1" /> สร้างรอบจ่ายใหม่
      </button>
    </header>

    <!-- Create / preview panel -->
    <section v-if="showCreate" class="mb-6 rounded-xl border border-slate-200 bg-white p-5">
      <h2 class="mb-3 font-semibold text-slate-800">สร้างรอบจ่ายค่าคอม</h2>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
        <div>
          <label class="mb-1 block text-xs text-slate-500">วันแจ้งงาน (จาก)</label>
          <input v-model="fromDate" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">วันแจ้งงาน (ถึง)</label>
          <input v-model="toDate" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <div class="sm:col-span-2">
          <label class="mb-1 block text-xs text-slate-500">หมายเหตุ (ถ้ามี)</label>
          <input v-model="note" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
      </div>
      <div class="mt-3 flex gap-2">
        <button
          type="button" :disabled="!fromDate || !toDate || previewing"
          class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          @click="runPreview"
        >
          <i v-if="previewing" class="pi pi-spin pi-spinner mr-1" /> ดูตัวอย่าง
        </button>
        <button
          type="button" :disabled="!preview || (preview?.totals.totalItems ?? 0) === 0 || creating"
          class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
          @click="doCreate"
        >
          <i v-if="creating" class="pi pi-spin pi-spinner mr-1" /> สร้าง batch
        </button>
      </div>

      <div v-if="createError" class="mt-3 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700">{{ createError }}</div>

      <div v-if="preview" class="mt-4 rounded-lg bg-slate-50 p-4">
        <div class="flex flex-wrap gap-6 text-sm">
          <div><span class="text-slate-500">ตัวแทน</span> <b>{{ preview.totals.totalAgents }}</b></div>
          <div><span class="text-slate-500">รายการ</span> <b>{{ preview.totals.totalItems }}</b></div>
          <div><span class="text-slate-500">ยอดรวม</span> <b>฿{{ money(preview.totals.totalAmount) }}</b></div>
        </div>
        <p v-if="preview.totals.totalItems === 0" class="mt-2 text-sm text-amber-700">ไม่พบรายการที่เข้าเงื่อนไขในช่วงวันที่นี้</p>
        <p v-else-if="preview.totals.totalAmount === 0" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
          <i class="pi pi-info-circle" /> พบ {{ preview.totals.totalItems }} รายการ แต่ยอดค่าคอมรวมเป็น ฿0 — เนื่องจากกรมธรรม์เหล่านี้ยังไม่ได้บันทึกค่าคอมตัวแทน (ตรวจสอบที่หน้าแก้ไขกรมธรรม์ หรือดูรายการใน "งานค้าง → ยังไม่บันทึกค่าคอม")
        </p>
        <details v-if="preview.warnings.length" class="mt-2 text-xs text-amber-700">
          <summary class="cursor-pointer">คำเตือน {{ preview.warnings.length }} รายการ</summary>
          <ul class="mt-1 list-disc pl-5">
            <li v-for="(w, i) in preview.warnings.slice(0, 20)" :key="i">{{ w }}</li>
          </ul>
        </details>
      </div>
    </section>

    <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <!-- Batch list -->
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">รอบ (วันแจ้งงาน)</th>
            <th class="px-4 py-3">สถานะ</th>
            <th class="px-4 py-3 text-right">ตัวแทน</th>
            <th class="px-4 py-3 text-right">รายการ</th>
            <th class="px-4 py-3 text-right">ยอดรวม</th>
            <th class="px-4 py-3">วันที่จ่าย</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="7" class="px-4 py-10 text-center text-slate-400">กำลังโหลด…</td></tr>
          <tr v-else-if="batches.length === 0"><td colspan="7" class="px-4 py-10 text-center text-slate-400">ยังไม่มีรอบจ่ายค่าคอม</td></tr>
          <tr v-for="b in batches" :key="b.id" class="cursor-pointer hover:bg-slate-50" @click="openDetail(b)">
            <td class="px-4 py-3 text-slate-700">{{ fmtDate(b.fromDate) }} – {{ fmtDate(b.toDate) }}</td>
            <td class="px-4 py-3">
              <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[b.status]]">{{ STATUS_LABEL[b.status] }}</span>
            </td>
            <td class="px-4 py-3 text-right text-slate-600">{{ b.totalAgents }}</td>
            <td class="px-4 py-3 text-right text-slate-600">{{ b.totalItems }}</td>
            <td class="px-4 py-3 text-right font-medium text-slate-800">฿{{ money(b.totalAmount) }}</td>
            <td class="px-4 py-3 text-slate-600">{{ b.paymentDate ? fmtDate(b.paymentDate) : '—' }}</td>
            <td class="px-4 py-3 text-right"><i class="pi pi-chevron-right text-slate-300" /></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
