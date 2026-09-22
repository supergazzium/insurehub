<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchPayoutBatch, addPayoutAdjustment, markPayoutBatchPaid, cancelPayoutBatch,
  agentPdfUrl, generatePayoutPdfs,
  type BatchDetail, type BatchStatus, type BatchAgentRow,
} from '../../api/commissionPayouts'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'

const route = useRoute()
const router = useRouter()
const id = String(route.params.id)

const batch = ref<BatchDetail | null>(null)
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
const VAT_LABEL: Record<string, string> = { '1': 'ไม่มี VAT', '2': 'VAT Exclude', '3': 'VAT Include' }

const isEditable = computed(() => batch.value && ['DRAFT', 'GENERATED', 'APPROVED'].includes(batch.value.status))
const netTotal = computed(() =>
  (batch.value?.agents ?? []).reduce((s, a) => s + a.net, 0),
)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchPayoutBatch(id)
    batch.value = res.data
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

// expanded agent rows
const expanded = ref<Set<string>>(new Set())
function toggle(key: string): void {
  const s = new Set(expanded.value)
  s.has(key) ? s.delete(key) : s.add(key)
  expanded.value = s
}
const rowKey = (a: BatchAgentRow) => a.agentId ?? a.agentCode ?? 'none'

// ── Adjustment dialog ───────────────────────────────────────────────────
const adjFor = ref<BatchAgentRow | null>(null)
const adjAmount = ref<number>(0)
const adjReason = ref('')
const adjSaving = ref(false)
const adjError = ref<string | null>(null)
function openAdj(a: BatchAgentRow): void {
  adjFor.value = a; adjAmount.value = 0; adjReason.value = ''; adjError.value = null
}
async function saveAdj(): Promise<void> {
  if (!adjFor.value || !adjReason.value.trim()) { adjError.value = 'กรุณาระบุเหตุผล'; return }
  adjSaving.value = true; adjError.value = null
  try {
    await addPayoutAdjustment(id, {
      agentId: adjFor.value.agentId, agentCode: adjFor.value.agentCode,
      amount: adjAmount.value, reason: adjReason.value.trim(),
    })
    adjFor.value = null
    await load()
  } catch (e: unknown) {
    adjError.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
  } finally {
    adjSaving.value = false
  }
}

// ── Mark paid dialog ────────────────────────────────────────────────────
const showPay = ref(false)
const payDate = ref(new Date().toISOString().slice(0, 10))
const payRef = ref('')
const paySaving = ref(false)
const payError = ref<string | null>(null)
async function confirmPay(): Promise<void> {
  if (!payDate.value) { payError.value = 'กรุณาระบุวันที่จ่าย'; return }
  paySaving.value = true; payError.value = null
  try {
    await markPayoutBatchPaid(id, payDate.value, payRef.value || undefined)
    showPay.value = false
    await load()
  } catch (e: unknown) {
    payError.value = e instanceof ApiError ? e.message : 'ยืนยันการจ่ายไม่สำเร็จ'
  } finally {
    paySaving.value = false
  }
}

const genBusy = ref(false)
async function generateAll(): Promise<void> {
  genBusy.value = true; error.value = null
  try {
    const blob = await generatePayoutPdfs(id)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `CommissionPayout-${batch.value?.fromDate ?? ''}-${batch.value?.toDate ?? ''}.zip`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'สร้าง PDF ไม่สำเร็จ'
  } finally {
    genBusy.value = false
  }
}
function openAgentPdf(agentCode: string | null): void {
  if (!agentCode) return
  window.open(agentPdfUrl(id, agentCode), '_blank')
}

async function doCancel(): Promise<void> {
  if (!confirm('ยกเลิก batch นี้?')) return
  try {
    await cancelPayoutBatch(id)
    await load()
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'ยกเลิกไม่สำเร็จ'
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-6">
    <button type="button" class="mb-3 text-sm text-slate-500 hover:text-slate-700" @click="router.push({ name: 'commission-payouts' })">
      <i class="pi pi-arrow-left mr-1" /> กลับ
    </button>

    <div v-if="loading" class="py-10 text-center text-slate-400">กำลังโหลด…</div>
    <div v-else-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>

    <template v-else-if="batch">
      <header class="mb-5 flex items-start justify-between">
        <div>
          <h1 class="text-xl font-semibold text-slate-800">
            รอบจ่ายค่าคอม {{ fmtDate(batch.fromDate) }} – {{ fmtDate(batch.toDate) }}
          </h1>
          <div class="mt-1 flex items-center gap-3 text-sm text-slate-500">
            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[batch.status]]">{{ STATUS_LABEL[batch.status] }}</span>
            <span>{{ batch.totalAgents }} ตัวแทน · {{ batch.totalItems }} รายการ</span>
            <span v-if="batch.note">· {{ batch.note }}</span>
          </div>
        </div>
        <div class="flex shrink-0 gap-2">
          <button
            type="button"
            :disabled="genBusy"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            @click="generateAll"
          >
            <i :class="['pi mr-1', genBusy ? 'pi-spin pi-spinner' : 'pi-file-pdf']" /> ดาวน์โหลด PDF ทั้งหมด (ZIP)
          </button>
          <button
            v-if="isEditable"
            type="button"
            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
            @click="showPay = true"
          >
            <i class="pi pi-check-circle mr-1" /> ยืนยันการจ่าย
          </button>
          <button
            v-if="isEditable"
            type="button"
            class="rounded-lg border border-rose-200 px-4 py-2 text-sm text-rose-600 hover:bg-rose-50"
            @click="doCancel"
          >
            ยกเลิก
          </button>
        </div>
      </header>

      <div v-if="batch.status === 'PAID'" class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
        จ่ายแล้วเมื่อ {{ fmtDate(batch.paymentDate) }}<span v-if="batch.paymentReference"> · อ้างอิง {{ batch.paymentReference }}</span>
      </div>

      <!-- Agent summary -->
      <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-3">ตัวแทน</th>
              <th class="px-4 py-3">VAT</th>
              <th class="px-4 py-3 text-right">รายการ</th>
              <th class="px-4 py-3 text-right">ค่าคอม</th>
              <th class="px-4 py-3 text-right">หัก</th>
              <th class="px-4 py-3 text-right">สุทธิ</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <template v-for="a in batch.agents" :key="rowKey(a)">
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-3">
                  <button type="button" class="text-left" @click="toggle(rowKey(a))">
                    <span class="font-medium text-slate-800">{{ a.agentName || '—' }}</span>
                    <span class="ml-1 text-xs text-slate-400">{{ a.agentCode }}</span>
                    <i :class="['pi ml-1 text-xs text-slate-400', expanded.has(rowKey(a)) ? 'pi-chevron-down' : 'pi-chevron-right']" />
                  </button>
                </td>
                <td class="px-4 py-3 text-xs text-slate-500">{{ a.vatType ? (VAT_LABEL[a.vatType] ?? a.vatType) : '—' }}</td>
                <td class="px-4 py-3 text-right text-slate-600">{{ a.itemCount }}</td>
                <td class="px-4 py-3 text-right text-slate-700">฿{{ money(a.commission) }}</td>
                <td class="px-4 py-3 text-right" :class="a.deduct > 0 ? 'text-rose-600' : 'text-slate-400'">{{ a.deduct > 0 ? '-฿' + money(a.deduct) : '—' }}</td>
                <td class="px-4 py-3 text-right font-medium text-slate-900">฿{{ money(a.net) }}</td>
                <td class="px-4 py-3 text-right">
                  <button type="button" class="mr-3 text-xs text-slate-500 hover:text-brand-700" @click.stop="openAgentPdf(a.agentCode)"><i class="pi pi-file-pdf" /> PDF</button>
                  <button v-if="isEditable" type="button" class="text-xs text-brand-600 hover:text-brand-700" @click="openAdj(a)">หักพิเศษ</button>
                </td>
              </tr>
              <tr v-if="expanded.has(rowKey(a))" class="bg-slate-50/50">
                <td colspan="7" class="px-6 py-2">
                  <table class="min-w-full text-xs">
                    <thead class="text-left text-slate-400">
                      <tr><th class="py-1 pr-4">เลขกรมธรรม์</th><th class="py-1 pr-4">ใบคำขอ</th><th class="py-1 pr-4 text-right">ยอด</th><th class="py-1">ที่มา</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="it in a.items" :key="it.policyId">
                        <td class="py-1 pr-4 text-slate-600">{{ it.policyNo || '—' }}</td>
                        <td class="py-1 pr-4 text-slate-500">{{ it.applicationNo || '—' }}</td>
                        <td class="py-1 pr-4 text-right text-slate-700">฿{{ money(it.amount) }}</td>
                        <td class="py-1 text-slate-400">{{ it.source }}</td>
                      </tr>
                    </tbody>
                  </table>
                </td>
              </tr>
            </template>
            <tr class="bg-slate-50 font-medium">
              <td class="px-4 py-3" colspan="5">รวมสุทธิ</td>
              <td class="px-4 py-3 text-right text-slate-900">฿{{ money(netTotal) }}</td>
              <td></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Adjustments list -->
      <div v-if="batch.adjustments.length" class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="mb-2 text-sm font-semibold text-slate-700">รายการหักพิเศษ</h3>
        <ul class="space-y-1 text-sm">
          <li v-for="adj in batch.adjustments" :key="adj.id" class="flex justify-between">
            <span class="text-slate-600">{{ adj.agentCode }} — {{ adj.reason }}</span>
            <span class="text-rose-600">-฿{{ money(adj.amount) }}</span>
          </li>
        </ul>
      </div>
    </template>

    <!-- Adjustment dialog -->
    <div v-if="adjFor" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="adjFor = null">
      <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-1 font-semibold text-slate-800">หักพิเศษ — {{ adjFor.agentName }}</h3>
        <p class="mb-3 text-xs text-slate-500">{{ adjFor.agentCode }} · ค่าคอมปัจจุบัน ฿{{ money(adjFor.commission) }}</p>
        <label class="mb-1 block text-xs text-slate-500">ยอดหัก (บาท)</label>
        <input v-model.number="adjAmount" type="number" min="0" step="0.01" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <label class="mb-1 block text-xs text-slate-500">เหตุผล *</label>
        <textarea v-model="adjReason" rows="2" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <div v-if="adjError" class="mb-2 text-sm text-rose-600">{{ adjError }}</div>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600" @click="adjFor = null">ยกเลิก</button>
          <button type="button" :disabled="adjSaving" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="saveAdj">บันทึก</button>
        </div>
      </div>
    </div>

    <!-- Mark paid dialog -->
    <div v-if="showPay" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="showPay = false">
      <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-1 font-semibold text-slate-800">ยืนยันการจ่ายค่าคอม</h3>
        <p class="mb-3 text-xs text-slate-500">ยอดสุทธิรวม ฿{{ money(netTotal) }} · {{ batch?.totalItems }} รายการ</p>
        <label class="mb-1 block text-xs text-slate-500">วันที่จ่าย *</label>
        <input v-model="payDate" type="date" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <label class="mb-1 block text-xs text-slate-500">เลขอ้างอิงการโอน (ถ้ามี)</label>
        <input v-model="payRef" type="text" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <div v-if="payError" class="mb-2 text-sm text-rose-600">{{ payError }}</div>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600" @click="showPay = false">ยกเลิก</button>
          <button type="button" :disabled="paySaving" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="confirmPay">
            <i v-if="paySaving" class="pi pi-spin pi-spinner mr-1" /> ยืนยันจ่าย
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
