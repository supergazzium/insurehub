<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchPayoutBatch, addPayoutAdjustment, markPayoutBatchPaid, cancelPayoutBatch,
  approvePayoutBatch, unapprovePayoutBatch, markAgentPaid,
  reversePayoutBatch, reversePayoutAgent, reissuePayoutBatch, setItemExcluded,
  downloadAgentPdf, generatePayoutPdfs, exportCsvUrl,
  type BatchDetail, type BatchStatus, type BatchAgentRow, type AdjustmentType, type BatchItemRow,
} from '../../api/commissionPayouts'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'
import MoneyInput from '../../components/MoneyInput.vue'

const route = useRoute()
const router = useRouter()
const id = String(route.params.id)

const batch = ref<BatchDetail | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const STATUS_LABEL: Record<BatchStatus, string> = {
  DRAFT: 'ร่าง', GENERATED: 'สร้างเอกสารแล้ว', APPROVED: 'อนุมัติแล้ว', PAID: 'จ่ายแล้ว', CANCELLED: 'ยกเลิก', REVERSED: 'ย้อนกลับ',
}
const STATUS_BADGE: Record<BatchStatus, string> = {
  DRAFT: 'bg-slate-100 text-slate-600',
  GENERATED: 'bg-sky-50 text-sky-700',
  APPROVED: 'bg-violet-50 text-violet-700',
  PAID: 'bg-emerald-50 text-emerald-700',
  CANCELLED: 'bg-rose-50 text-rose-700',
  REVERSED: 'bg-amber-50 text-amber-700',
}
const VAT_LABEL: Record<string, string> = { '1': 'ไม่มี VAT', '2': 'VAT Exclude', '3': 'VAT Include' }

const isEditable = computed(() => batch.value && ['DRAFT', 'GENERATED', 'APPROVED'].includes(batch.value.status))
const canApprove = computed(() => batch.value && ['DRAFT', 'GENERATED'].includes(batch.value.status))
const isApproved = computed(() => batch.value?.status === 'APPROVED')
const isPaid = computed(() => batch.value?.status === 'PAID')
const isReversed = computed(() => batch.value?.status === 'REVERSED')
const netTotal = computed(() =>
  (batch.value?.agents ?? []).reduce((s, a) => s + (a.netTransfer ?? a.net), 0),
)
// Per-agent pay progress (bulk run — each agent is a separate transfer).
const paidAgentCount = computed(() => (batch.value?.agents ?? []).filter((a) => a.paid).length)

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
const adjType = ref<AdjustmentType>('special_deduct')
const adjSaving = ref(false)
const adjError = ref<string | null>(null)
function openAdj(a: BatchAgentRow): void {
  adjFor.value = a; adjAmount.value = 0; adjReason.value = ''; adjType.value = 'special_deduct'; adjError.value = null
}
async function saveAdj(): Promise<void> {
  if (!adjFor.value || !adjReason.value.trim()) { adjError.value = 'กรุณาระบุเหตุผล'; return }
  adjSaving.value = true; adjError.value = null
  try {
    await addPayoutAdjustment(id, {
      agentId: adjFor.value.agentId, agentCode: adjFor.value.agentCode,
      amount: adjAmount.value, reason: adjReason.value.trim(), type: adjType.value,
    })
    adjFor.value = null
    await load()
  } catch (e: unknown) {
    adjError.value = e instanceof ApiError ? e.message : 'บันทึกไม่สำเร็จ'
  } finally {
    adjSaving.value = false
  }
}

// ── Per-agent pay (bulk run — each agent = separate transfer) ────────────
const payAgentFor = ref<BatchAgentRow | null>(null)
const payAgentDate = ref(new Date().toISOString().slice(0, 10))
const payAgentRef = ref('')
const payAgentSaving = ref(false)
const payAgentError = ref<string | null>(null)
function openPayAgent(a: BatchAgentRow): void {
  payAgentFor.value = a
  payAgentDate.value = batch.value?.paymentDate ?? new Date().toISOString().slice(0, 10)
  payAgentRef.value = ''; payAgentError.value = null
}
async function confirmPayAgent(): Promise<void> {
  if (!payAgentFor.value?.agentId || !payAgentDate.value) { payAgentError.value = 'กรุณาระบุวันที่จ่าย'; return }
  payAgentSaving.value = true; payAgentError.value = null
  try {
    await markAgentPaid(id, payAgentFor.value.agentId, payAgentDate.value, payAgentRef.value || undefined)
    payAgentFor.value = null
    await load()
  } catch (e: unknown) {
    payAgentError.value = e instanceof ApiError ? e.message : 'บันทึกจ่ายไม่สำเร็จ'
  } finally {
    payAgentSaving.value = false
  }
}

// ── Per-item exclude / include ──────────────────────────────────────────
const itemBusy = ref<string | null>(null)
async function toggleItem(it: BatchItemRow): Promise<void> {
  if (!it.id) return
  itemBusy.value = it.id
  error.value = null
  try {
    await setItemExcluded(id, it.id, it.status !== 'excluded')
    await load()
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'แก้ไขรายการไม่สำเร็จ'
  } finally {
    itemBusy.value = null
  }
}

// ── Reversal / re-issue (wrong-payment handling) ────────────────────────
const showReverse = ref(false)
const reverseReason = ref('')
const reverseAgentFor = ref<BatchAgentRow | null>(null)
const reverseSaving = ref(false)
const reverseError = ref<string | null>(null)
function openReverseBatch(): void { showReverse.value = true; reverseAgentFor.value = null; reverseReason.value = ''; reverseError.value = null }
function openReverseAgent(a: BatchAgentRow): void { reverseAgentFor.value = a; showReverse.value = true; reverseReason.value = ''; reverseError.value = null }
async function confirmReverse(): Promise<void> {
  if (!reverseReason.value.trim()) { reverseError.value = 'กรุณาระบุเหตุผล'; return }
  reverseSaving.value = true; reverseError.value = null
  try {
    if (reverseAgentFor.value?.agentId) {
      await reversePayoutAgent(id, reverseAgentFor.value.agentId, reverseReason.value.trim())
    } else {
      await reversePayoutBatch(id, reverseReason.value.trim())
    }
    showReverse.value = false
    await load()
  } catch (e: unknown) {
    reverseError.value = e instanceof ApiError ? e.message : 'ทำรายการย้อนกลับไม่สำเร็จ'
  } finally {
    reverseSaving.value = false
  }
}
const reissueBusy = ref(false)
async function doReissue(): Promise<void> {
  reissueBusy.value = true; error.value = null
  try {
    const res = await reissuePayoutBatch(id)
    router.push({ name: 'commission-payout-detail', params: { id: res.data.id } })
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'ออกใหม่ไม่สำเร็จ'
  } finally {
    reissueBusy.value = false
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
async function openAgentPdf(agentCode: string | null): Promise<void> {
  if (!agentCode) return
  error.value = null
  try {
    // Authed blob download — the PDF route needs a Bearer token, so window.open
    // (which can't attach it) 500s.
    const blob = await downloadAgentPdf(id, agentCode)
    const url = URL.createObjectURL(blob)
    window.open(url, '_blank')
    // Revoke after the new tab has had a chance to load it.
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'สร้าง PDF ไม่สำเร็จ'
  }
}

const csvBusy = ref(false)
async function exportCsv(): Promise<void> {
  csvBusy.value = true; error.value = null
  try {
    const res = await fetch(exportCsvUrl(id), {
      headers: { Authorization: `Bearer ${localStorage.getItem('insurehub.token') ?? ''}`, Accept: 'text/csv' },
    })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `CommissionPayout-${batch.value?.fromDate ?? ''}-${batch.value?.toDate ?? ''}.csv`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'ส่งออก CSV ไม่สำเร็จ'
  } finally {
    csvBusy.value = false
  }
}

async function doApprove(): Promise<void> {
  error.value = null
  try { await approvePayoutBatch(id); await load() }
  catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'อนุมัติไม่สำเร็จ' }
}
async function doUnapprove(): Promise<void> {
  error.value = null
  try { await unapprovePayoutBatch(id); await load() }
  catch (e: unknown) { error.value = e instanceof ApiError ? e.message : 'ยกเลิกอนุมัติไม่สำเร็จ' }
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
            รอบจ่ายค่าคอม<span v-if="batch.periodLabel"> · {{ batch.periodLabel }}</span>
            <span class="ml-2 text-sm font-normal text-slate-400">{{ fmtDate(batch.fromDate) }} – {{ fmtDate(batch.toDate) }}</span>
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
            type="button"
            :disabled="csvBusy"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            @click="exportCsv"
          >
            <i :class="['pi mr-1', csvBusy ? 'pi-spin pi-spinner' : 'pi-file-excel']" /> Export Excel (CSV)
          </button>
          <button
            v-if="canApprove"
            type="button"
            class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700"
            @click="doApprove"
          >
            <i class="pi pi-verified mr-1" /> อนุมัติ
          </button>
          <button
            v-if="isApproved"
            type="button"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50"
            @click="doUnapprove"
          >
            ยกเลิกอนุมัติ
          </button>
          <button
            v-if="isApproved"
            type="button"
            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
            @click="showPay = true"
          >
            <i class="pi pi-check-circle mr-1" /> จ่ายทั้งหมด
          </button>
          <button
            v-if="isPaid"
            type="button"
            class="rounded-lg border border-amber-300 px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50"
            @click="openReverseBatch"
          >
            <i class="pi pi-undo mr-1" /> ทำรายการย้อนกลับ
          </button>
          <button
            v-if="isReversed"
            type="button"
            :disabled="reissueBusy"
            class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            @click="doReissue"
          >
            <i :class="['pi mr-1', reissueBusy ? 'pi-spin pi-spinner' : 'pi-replay']" /> ออกใหม่ (แก้ไข)
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
        จ่ายครบทุกตัวแทนแล้วเมื่อ {{ fmtDate(batch.paymentDate) }}<span v-if="batch.paymentReference"> · อ้างอิง {{ batch.paymentReference }}</span>
      </div>
      <div v-else-if="isApproved" class="mb-4 rounded-lg bg-violet-50 px-4 py-3 text-sm text-violet-800">
        อนุมัติแล้ว — บันทึกจ่ายทีละตัวแทน (แต่ละตัวแทน = โอนแยกกัน) · จ่ายแล้ว {{ paidAgentCount }}/{{ batch.agents.length }} ตัวแทน
      </div>
      <div v-if="isReversed" class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <i class="pi pi-undo mr-1" /> รอบนี้ถูกทำรายการย้อนกลับ<span v-if="batch.reversalReason"> · เหตุผล: {{ batch.reversalReason }}</span>
        <span v-if="batch.reversedAt"> · เมื่อ {{ fmtDate(batch.reversedAt) }}</span>
        — กด "ออกใหม่" เพื่อสร้างรอบจ่ายที่แก้ไขแล้ว
      </div>

      <!-- Agent summary -->
      <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-3">ตัวแทน</th>
              <th class="px-4 py-3">บัญชีธนาคาร</th>
              <th class="px-4 py-3 text-right">รายการ</th>
              <th class="px-4 py-3 text-right">ค่าคอม</th>
              <th class="px-4 py-3 text-right">หัก 3%</th>
              <th class="px-4 py-3 text-right">ยอดโอน</th>
              <th class="px-4 py-3 text-center">สถานะ</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <template v-for="a in batch.agents" :key="rowKey(a)">
              <tr class="hover:bg-slate-50" :class="a.reversed ? 'bg-amber-50/40' : a.paid ? 'bg-emerald-50/40' : ''">
                <td class="px-4 py-3">
                  <button type="button" class="text-left" @click="toggle(rowKey(a))">
                    <span class="font-medium text-slate-800">{{ a.agentName || '—' }}</span>
                    <span class="ml-1 text-xs text-slate-400">{{ a.agentCode }}</span>
                    <i :class="['pi ml-1 text-xs text-slate-400', expanded.has(rowKey(a)) ? 'pi-chevron-down' : 'pi-chevron-right']" />
                  </button>
                  <div class="text-xs text-slate-400">{{ a.vatType ? (VAT_LABEL[a.vatType] ?? a.vatType) : '—' }}</div>
                </td>
                <td class="px-4 py-3 text-xs text-slate-500">
                  <div>{{ a.bankAccountNo || '—' }}</div>
                  <div class="text-slate-400">{{ a.bankAccountName || '' }}</div>
                </td>
                <td class="px-4 py-3 text-right text-slate-600">{{ a.itemCount }}</td>
                <td class="px-4 py-3 text-right text-slate-700">฿{{ money(a.commission) }}</td>
                <td class="px-4 py-3 text-right text-slate-500">{{ (a.whtAmount ?? 0) > 0 ? '-฿' + money(a.whtAmount ?? 0) : '—' }}</td>
                <td class="px-4 py-3 text-right font-semibold text-slate-900">฿{{ money(a.netTransfer ?? a.net) }}</td>
                <td class="px-4 py-3 text-center">
                  <span v-if="a.reversed" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-700">ย้อนกลับ</span>
                  <span v-else-if="a.paid" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">จ่ายแล้ว</span>
                  <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">รอจ่าย</span>
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                  <button type="button" class="mr-2 text-xs text-slate-500 hover:text-brand-700" @click.stop="openAgentPdf(a.agentCode)"><i class="pi pi-file-pdf" /> PDF</button>
                  <button v-if="isEditable && !a.paid" type="button" class="mr-2 text-xs text-brand-600 hover:text-brand-700" @click="openAdj(a)">ปรับปรุง</button>
                  <button v-if="isApproved && !a.paid" type="button" class="text-xs font-medium text-emerald-700 hover:text-emerald-800" @click="openPayAgent(a)"><i class="pi pi-check" /> จ่าย</button>
                  <button v-if="a.paid && !a.reversed" type="button" class="text-xs text-amber-700 hover:text-amber-800" @click="openReverseAgent(a)"><i class="pi pi-undo" /> ย้อนกลับ</button>
                  <span v-if="a.paid && a.paymentReference" class="ml-2 text-xs text-slate-400">({{ a.paymentReference }})</span>
                </td>
              </tr>
              <tr v-if="expanded.has(rowKey(a))" class="bg-slate-50/50">
                <td colspan="8" class="px-6 py-2">
                  <table class="min-w-full text-xs">
                    <thead class="text-left text-slate-400">
                      <tr><th class="py-1 pr-4">เลขกรมธรรม์</th><th class="py-1 pr-4">ใบคำขอ</th><th class="py-1 pr-4 text-right">ยอด</th><th class="py-1 pr-4">ที่มา</th><th class="py-1"></th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="it in a.items" :key="it.id ?? it.policyId" :class="it.status === 'excluded' ? 'opacity-50' : ''">
                        <td class="py-1 pr-4 text-slate-600">{{ it.policyNo || '—' }}<span v-if="it.status === 'excluded'" class="ml-1 text-rose-500">(ตัดออก)</span></td>
                        <td class="py-1 pr-4 text-slate-500">{{ it.applicationNo || '—' }}</td>
                        <td class="py-1 pr-4 text-right text-slate-700">฿{{ money(it.amount) }}</td>
                        <td class="py-1 pr-4 text-slate-400">{{ it.source }}</td>
                        <td class="py-1 text-right">
                          <button
                            v-if="isEditable && it.id && it.status !== 'paid'"
                            type="button" :disabled="itemBusy === it.id"
                            class="text-xs hover:underline disabled:opacity-50"
                            :class="it.status === 'excluded' ? 'text-brand-600' : 'text-rose-500'"
                            @click="toggleItem(it)"
                          >
                            {{ it.status === 'excluded' ? 'นำกลับ' : 'ตัดออก' }}
                          </button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </td>
              </tr>
            </template>
            <tr class="bg-slate-50 font-medium">
              <td class="px-4 py-3" colspan="5">รวมยอดโอนสุทธิ</td>
              <td class="px-4 py-3 text-right text-slate-900">฿{{ money(netTotal) }}</td>
              <td colspan="2"></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Adjustments list -->
      <div v-if="batch.adjustments.length" class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="mb-2 text-sm font-semibold text-slate-700">รายการปรับปรุงยอด</h3>
        <ul class="space-y-1 text-sm">
          <li v-for="adj in batch.adjustments" :key="adj.id" class="flex justify-between">
            <span class="text-slate-600">
              {{ adj.agentCode }}
              <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">
                {{ adj.type === 'bonus' ? 'โบนัส' : adj.type === 'correction' ? 'แก้ไข' : 'หักพิเศษ' }}
              </span>
              — {{ adj.reason }}
            </span>
            <span :class="adj.type === 'bonus' ? 'text-emerald-600' : 'text-rose-600'">
              {{ adj.type === 'bonus' ? '+' : '-' }}฿{{ money(adj.amount) }}
            </span>
          </li>
        </ul>
      </div>
    </template>

    <!-- Adjustment dialog -->
    <div v-if="adjFor" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="adjFor = null">
      <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-1 font-semibold text-slate-800">ปรับปรุงยอด — {{ adjFor.agentName }}</h3>
        <p class="mb-3 text-xs text-slate-500">{{ adjFor.agentCode }} · ค่าคอมปัจจุบัน ฿{{ money(adjFor.commission) }}</p>
        <label class="mb-1 block text-xs text-slate-500">ประเภท</label>
        <select v-model="adjType" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
          <option value="special_deduct">หักพิเศษ (ลดยอด)</option>
          <option value="bonus">โบนัส (เพิ่มยอด)</option>
          <option value="correction">แก้ไข/ปรับลด</option>
        </select>
        <label class="mb-1 block text-xs text-slate-500">จำนวนเงิน (บาท)</label>
        <MoneyInput v-model="adjAmount" :input-class="'mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm'" />
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
            <i v-if="paySaving" class="pi pi-spin pi-spinner mr-1" /> ยืนยันจ่ายทั้งหมด
          </button>
        </div>
      </div>
    </div>

    <!-- Per-agent pay dialog -->
    <div v-if="payAgentFor" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="payAgentFor = null">
      <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-1 font-semibold text-slate-800">บันทึกจ่าย — {{ payAgentFor.agentName }}</h3>
        <p class="mb-3 text-xs text-slate-500">
          {{ payAgentFor.agentCode }} · ยอดโอน <b class="text-emerald-700">฿{{ money(payAgentFor.netTransfer ?? payAgentFor.net) }}</b>
          <span v-if="payAgentFor.bankAccountNo"> · {{ payAgentFor.bankAccountNo }}</span>
        </p>
        <label class="mb-1 block text-xs text-slate-500">วันที่จ่าย *</label>
        <input v-model="payAgentDate" type="date" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <label class="mb-1 block text-xs text-slate-500">เลขอ้างอิงการโอน (ของตัวแทนนี้)</label>
        <input v-model="payAgentRef" type="text" placeholder="เลขที่โอน / เช็ค" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <div v-if="payAgentError" class="mb-2 text-sm text-rose-600">{{ payAgentError }}</div>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600" @click="payAgentFor = null">ยกเลิก</button>
          <button type="button" :disabled="payAgentSaving" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="confirmPayAgent">
            <i v-if="payAgentSaving" class="pi pi-spin pi-spinner mr-1" /> ยืนยันจ่าย
          </button>
        </div>
      </div>
    </div>

    <!-- Reverse (wrong-payment) dialog -->
    <div v-if="showReverse" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" @click.self="showReverse = false">
      <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-1 font-semibold text-slate-800">
          <i class="pi pi-undo mr-1 text-amber-600" />
          ทำรายการย้อนกลับ<span v-if="reverseAgentFor"> — {{ reverseAgentFor.agentName }}</span>
        </h3>
        <p class="mb-3 text-xs text-slate-500">
          <template v-if="reverseAgentFor">ยกเลิกการจ่ายของตัวแทนนี้ และคืนค่าคอมกลับเป็น "ยังไม่จ่าย"</template>
          <template v-else>ยกเลิกการจ่ายทั้งรอบ และคืนค่าคอมทุกรายการกลับเป็น "ยังไม่จ่าย" (ไม่ลบประวัติ)</template>
        </p>
        <label class="mb-1 block text-xs text-slate-500">เหตุผล *</label>
        <textarea v-model="reverseReason" rows="2" placeholder="เช่น โอนผิดบัญชี / ยอดผิด" class="mb-3 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
        <div v-if="reverseError" class="mb-2 text-sm text-rose-600">{{ reverseError }}</div>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600" @click="showReverse = false">ยกเลิก</button>
          <button type="button" :disabled="reverseSaving" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="confirmReverse">
            <i v-if="reverseSaving" class="pi pi-spin pi-spinner mr-1" /> ยืนยันย้อนกลับ
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
