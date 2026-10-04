<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  createReceiptBatch, fetchReceiptBatch, fetchReceiptBatches, receiptFilePath, uploadReceiptFile,
  type ReceiptBatch, type ReceiptFile,
} from '../../api/commissionReceipts'
import type { CarrierListRow } from '../../api/carriers'
import { ApiError } from '../../api/client'
import { useAuthStore } from '../../stores/auth'
import { openPdf } from '../../util/fileDownload'
import { fmtDate } from '../../util/dateFormat'
import { money, parseAmount, signedMoney, thisMonth } from './receiptLabels'

const props = defineProps<{ carriers: CarrierListRow[] }>()

const auth = useAuthStore()
const canRecord = computed(() => auth.hasPermission('receipts.record'))

const batches = ref<ReceiptBatch[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const showCreate = ref(false)
const form = ref({ insurerId: null as number | null, statementMonth: thisMonth(), statementTotal: '', statementDate: '', receivedFileDate: '', remark: '' })
const creating = ref(false)

const selected = ref<(ReceiptBatch & { files: ReceiptFile[] }) | null>(null)
const uploading = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    batches.value = (await fetchReceiptBatches()).data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

async function doCreate(): Promise<void> {
  if (!form.value.insurerId) { error.value = 'กรุณาเลือกบริษัทประกัน'; return }
  creating.value = true
  error.value = null
  try {
    await createReceiptBatch({
      insurerId: form.value.insurerId,
      statementMonth: form.value.statementMonth || undefined,
      statementTotal: parseAmount(form.value.statementTotal) ?? undefined,
      statementDate: form.value.statementDate || undefined,
      receivedFileDate: form.value.receivedFileDate || undefined,
      remark: form.value.remark || undefined,
    })
    showCreate.value = false
    form.value = { insurerId: null, statementMonth: thisMonth(), statementTotal: '', statementDate: '', receivedFileDate: '', remark: '' }
    await load()
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'สร้างไม่สำเร็จ'
  } finally {
    creating.value = false
  }
}

async function openBatch(b: ReceiptBatch): Promise<void> {
  selected.value = (await fetchReceiptBatch(b.id)).data
}

async function onFilePicked(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !selected.value) return
  uploading.value = true
  error.value = null
  try {
    await uploadReceiptFile(selected.value.id, file)
    selected.value = (await fetchReceiptBatch(selected.value.id)).data
    await load()
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'อัปโหลดไม่สำเร็จ'
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function download(f: ReceiptFile): Promise<void> {
  try {
    await openPdf(receiptFilePath(f.id), 'download')
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'ดาวน์โหลดไม่สำเร็จ'
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm text-slate-500">รอบ statement ที่ได้รับจากบริษัทประกัน · เทียบยอดตาม statement กับยอดที่ยืนยันรับในระบบ และแนบไฟล์ต้นฉบับ</p>
      <button v-if="canRecord" type="button" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showCreate = !showCreate">
        <i class="pi pi-plus mr-1" /> สร้างรอบใหม่
      </button>
    </div>

    <div v-if="error" class="mb-3 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700">{{ error }}</div>

    <section v-if="showCreate" class="mb-5 rounded-xl border border-slate-200 bg-white p-5">
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div>
          <label class="mb-1 block text-xs text-slate-500">บริษัทประกัน *</label>
          <select v-model.number="form.insurerId" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
            <option :value="null">— เลือก —</option>
            <option v-for="c in props.carriers" :key="c.id" :value="Number(c.id)">{{ c.name }}</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">statement เดือน</label>
          <input v-model="form.statementMonth" type="month" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">ยอดรวมตาม statement</label>
          <input v-model="form.statementTotal" type="text" inputmode="decimal" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-right text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">วันที่ใน statement</label>
          <input v-model="form.statementDate" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">วันที่ได้รับไฟล์</label>
          <input v-model="form.receivedFileDate" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-xs text-slate-500">หมายเหตุ</label>
          <input v-model="form.remark" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        </div>
      </div>
      <div class="mt-3">
        <button type="button" :disabled="creating" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="doCreate">สร้าง</button>
      </div>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
      <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white lg:col-span-3">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs text-slate-500">
            <tr>
              <th class="px-3 py-3">เลขรอบ</th><th class="px-3 py-3">บริษัท / เดือน</th>
              <th class="px-3 py-3 text-right">ยอด statement</th><th class="px-3 py-3 text-right">รับในระบบ</th>
              <th class="px-3 py-3 text-right">ส่วนต่าง</th><th class="px-3 py-3 text-center">ไฟล์</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loading"><td colspan="6" class="px-4 py-8 text-center text-slate-400">กำลังโหลด…</td></tr>
            <tr v-else-if="batches.length === 0"><td colspan="6" class="px-4 py-8 text-center text-slate-400">ยังไม่มีรอบ statement</td></tr>
            <tr v-for="b in batches" :key="b.id" class="cursor-pointer hover:bg-slate-50" :class="selected?.id === b.id ? 'bg-sky-50' : ''" @click="openBatch(b)">
              <td class="px-3 py-3 font-mono text-xs text-slate-700">{{ b.batchNo }}</td>
              <td class="px-3 py-3">
                <div class="max-w-[220px] truncate text-slate-700" :title="b.insurerName || ''">{{ b.insurerName }}</div>
                <div class="text-xs text-slate-400">{{ b.statementMonth || '—' }}</div>
              </td>
              <td class="px-3 py-3 text-right text-slate-600">{{ b.statementTotal !== null ? money(b.statementTotal) : '—' }}</td>
              <td class="px-3 py-3 text-right text-slate-800">{{ money(b.receivedTotal) }}<div class="text-[10px] text-slate-400">{{ b.receivedCount }} รายการ</div></td>
              <td class="px-3 py-3 text-right" :class="b.difference !== null && Math.abs(b.difference) > 0.01 ? 'text-rose-600' : 'text-slate-400'">{{ b.difference !== null ? signedMoney(b.difference) : '—' }}</td>
              <td class="px-3 py-3 text-center text-slate-500">{{ b.fileCount }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="selected" class="rounded-xl border border-slate-200 bg-white p-4 lg:col-span-2">
        <h3 class="mb-1 font-semibold text-slate-800">{{ selected.batchNo }}</h3>
        <p class="mb-3 text-xs text-slate-500">
          {{ selected.insurerName }} · statement {{ selected.statementMonth || '—' }}
          · รับไฟล์ {{ selected.receivedFileDate ? fmtDate(selected.receivedFileDate) : '—' }}
        </p>
        <label v-if="canRecord" class="mb-3 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
          <i class="pi pi-upload" /> {{ uploading ? 'กำลังอัปโหลด…' : 'แนบไฟล์ statement (xlsx / csv / pdf)' }}
          <input type="file" accept=".xlsx,.xls,.csv,.pdf" class="hidden" :disabled="uploading" @change="onFilePicked" />
        </label>
        <ul class="space-y-1 text-sm">
          <li v-for="f in selected.files" :key="f.id" class="flex items-center justify-between rounded px-2 py-1 hover:bg-slate-50">
            <span class="truncate text-slate-700">{{ f.originalFilename }}</span>
            <button type="button" class="text-brand-600 hover:text-brand-700" @click="download(f)"><i class="pi pi-download" /></button>
          </li>
          <li v-if="selected.files.length === 0" class="text-xs text-slate-400">ยังไม่มีไฟล์แนบ</li>
        </ul>
      </div>
    </div>
  </div>
</template>
