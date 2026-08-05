<script setup lang="ts">
// Phase 7b — Admin payout cycles. Two panes:
//  1. Create batch  — pick period, preview, confirm to materialize N draft payouts
//  2. Existing payouts — list with status filter; per-row actions (issue/pay/void/pdf)
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  previewPayouts, createPayouts, fetchPayoutList, fetchPayout,
  issuePayout, payPayout, voidPayout, downloadPayoutPdf,
  type PayoutPreview, type Payout,
} from '../../api/adminPayouts'
import { ApiError } from '../../api/client'
import DateRangeInput from '../../components/DateRangeInput.vue'

const { t } = useI18n()

// ── Create batch ──────────────────────────────────────────────────────────
const now = new Date()
const firstOfMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
const today = now.toISOString().slice(0, 10)

const range = reactive({ from: firstOfMonth, to: today })
const preview = ref<PayoutPreview | null>(null)
const previewLoading = ref(false)
const previewError = ref<string | null>(null)
const creating = ref(false)

async function doPreview(): Promise<void> {
  previewLoading.value = true
  previewError.value = null
  try {
    preview.value = await previewPayouts(range.from, range.to)
  } catch (e: unknown) {
    previewError.value = e instanceof ApiError ? e.message : 'Preview failed.'
  } finally {
    previewLoading.value = false
  }
}

async function doCreate(): Promise<void> {
  if (!preview.value || !preview.value.groups.length) return
  if (!window.confirm(t('adminPayouts.confirmCreate', { n: preview.value.groups.length }))) return
  creating.value = true
  try {
    await createPayouts(range.from, range.to)
    preview.value = null
    await loadList()
  } catch (e: unknown) {
    previewError.value = e instanceof ApiError ? e.message : 'Create failed.'
  } finally {
    creating.value = false
  }
}

// ── Existing payouts ──────────────────────────────────────────────────────
const payouts = ref<Payout[]>([])
const listLoading = ref(false)
const filterStatus = ref<string>('')
const expanded = ref<string | null>(null)
const detail = ref<Payout | null>(null)
const actionSaving = ref<string | null>(null)

async function loadList(): Promise<void> {
  listLoading.value = true
  try {
    const res = await fetchPayoutList({ status: filterStatus.value || undefined, perPage: 100 })
    payouts.value = res.data
  } finally {
    listLoading.value = false
  }
}

onMounted(loadList)

async function toggleExpand(id: string): Promise<void> {
  if (expanded.value === id) { expanded.value = null; return }
  expanded.value = id
  detail.value = null
  const res = await fetchPayout(id)
  detail.value = res.data
}

async function doIssue(p: Payout): Promise<void> {
  actionSaving.value = p.id
  try {
    const res = await issuePayout(p.id)
    Object.assign(p, res.data)
  } catch (e: unknown) {
    alert(e instanceof ApiError ? e.message : 'Failed.')
  } finally {
    actionSaving.value = null
  }
}

async function doPay(p: Payout): Promise<void> {
  const ref = window.prompt(t('adminPayouts.enterBankRef'))
  if (!ref) return
  actionSaving.value = p.id
  try {
    const res = await payPayout(p.id, ref)
    Object.assign(p, res.data)
  } catch (e: unknown) {
    alert(e instanceof ApiError ? e.message : 'Failed.')
  } finally {
    actionSaving.value = null
  }
}

async function doVoid(p: Payout): Promise<void> {
  const reason = window.prompt(t('adminPayouts.enterVoidReason'))
  if (!reason) return
  actionSaving.value = p.id
  try {
    const res = await voidPayout(p.id, reason)
    Object.assign(p, res.data)
    await loadList()
  } catch (e: unknown) {
    alert(e instanceof ApiError ? e.message : 'Failed.')
  } finally {
    actionSaving.value = null
  }
}

async function doPdf(p: Payout): Promise<void> {
  const filename = `commission-${p.agentCode}-${p.periodFrom}-${p.periodTo}.pdf`
  try {
    await downloadPayoutPdf(p.id, filename)
  } catch (e: unknown) {
    alert(e instanceof Error ? e.message : 'PDF failed.')
  }
}

function fmt(n: number): string {
  return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function statusClass(s: string): string {
  return {
    draft: 'bg-slate-100 text-slate-700',
    issued: 'bg-brand-50 text-brand-700',
    paid: 'bg-emerald-50 text-emerald-700',
    void: 'bg-rose-50 text-rose-700',
  }[s] ?? 'bg-slate-100 text-slate-500'
}

const canCreate = computed(() => preview.value && preview.value.groups.length > 0)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">{{ t('adminPayouts.title') }}</h1>
      <p class="text-sm text-slate-500 mt-1">{{ t('adminPayouts.subtitle') }}</p>
    </header>

    <!-- Batch creator -->
    <section class="card p-5 space-y-4">
      <h2 class="text-sm font-semibold text-slate-600">{{ t('adminPayouts.newBatch') }}</h2>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="text-xs text-slate-500 mb-1 block">{{ t('adminPayouts.periodFrom') }} — {{ t('adminPayouts.periodTo') }}</label>
          <DateRangeInput
            :from="range.from"
            :to="range.to"
            @update:from="v => range.from = v"
            @update:to="v => range.to = v"
          />
        </div>
        <div class="md:col-span-2 flex gap-2">
          <button type="button" class="px-3 py-2 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
            :disabled="previewLoading" @click="doPreview">
            <i :class="previewLoading ? 'pi pi-spin pi-spinner' : 'pi pi-eye'" class="text-xs mr-1" />
            {{ t('adminPayouts.previewBtn') }}
          </button>
          <button type="button" class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700 disabled:opacity-50"
            :disabled="!canCreate || creating" @click="doCreate">
            <i v-if="creating" class="pi pi-spin pi-spinner mr-1" />
            {{ t('adminPayouts.createBtn') }}
          </button>
        </div>
      </div>

      <div v-if="previewError" class="text-xs text-rose-700">{{ previewError }}</div>

      <div v-if="preview" class="pt-2 border-t border-slate-100">
        <div class="text-xs text-slate-500 mb-2">
          {{ t('adminPayouts.previewSummary', {
            n: preview.agentCount,
            total: fmt(preview.totalGross),
            from: preview.periodFrom, to: preview.periodTo,
          }) }}
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
            <tr>
              <th class="px-3 py-1.5 text-left">{{ t('adminPayouts.col.agent') }}</th>
              <th class="px-3 py-1.5 text-left w-16">{{ t('adminPayouts.col.vatType') }}</th>
              <th class="px-3 py-1.5 text-right w-24">{{ t('adminPayouts.col.txns') }}</th>
              <th class="px-3 py-1.5 text-right w-32">{{ t('adminPayouts.col.gross') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="!preview.groups.length"><td colspan="4" class="text-center py-4 text-slate-400 text-xs">{{ t('adminPayouts.noAccrued') }}</td></tr>
            <tr v-for="g in preview.groups" :key="g.agentId">
              <td class="px-3 py-1.5">
                <div class="font-mono text-xs">{{ g.agentCode }}</div>
                <div class="text-xs text-slate-500">{{ g.agentName }}</div>
              </td>
              <td class="px-3 py-1.5 text-xs text-slate-500">{{ g.vatType || '—' }}</td>
              <td class="px-3 py-1.5 text-right font-mono text-xs">{{ g.txnCount }}</td>
              <td class="px-3 py-1.5 text-right font-mono">฿ {{ fmt(g.gross) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Existing payouts -->
    <section class="card overflow-hidden">
      <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-slate-600">{{ t('adminPayouts.existing') }}</h2>
        <div class="flex items-center gap-2 text-xs">
          <select v-model="filterStatus" @change="loadList"
            class="border border-slate-200 rounded px-2 py-1 bg-white text-xs">
            <option value="">{{ t('adminPayouts.allStatuses') }}</option>
            <option value="draft">draft</option>
            <option value="issued">issued</option>
            <option value="paid">paid</option>
            <option value="void">void</option>
          </select>
          <button type="button" class="text-slate-500 hover:text-brand-600" @click="loadList">
            <i :class="listLoading ? 'pi pi-spin pi-spinner' : 'pi pi-refresh'" class="text-xs" />
          </button>
        </div>
      </header>
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th class="px-3 py-2 text-left">{{ t('adminPayouts.col.agent') }}</th>
            <th class="px-3 py-2 text-left">{{ t('adminPayouts.col.period') }}</th>
            <th class="px-3 py-2 text-right">{{ t('adminPayouts.col.gross') }}</th>
            <th class="px-3 py-2 text-right">WHT</th>
            <th class="px-3 py-2 text-right">{{ t('adminPayouts.col.net') }}</th>
            <th class="px-3 py-2">{{ t('adminPayouts.col.status') }}</th>
            <th class="px-3 py-2 text-right w-64"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="!payouts.length && !listLoading">
            <td colspan="7" class="text-center py-6 text-slate-400 text-xs">{{ t('adminPayouts.emptyList') }}</td>
          </tr>
          <template v-for="p in payouts" :key="p.id">
            <tr :class="{ 'bg-slate-50/50': expanded === p.id }">
              <td class="px-3 py-2">
                <div class="font-mono text-xs">{{ p.agentCode }}</div>
                <div class="text-xs text-slate-500">{{ p.agentName }}</div>
              </td>
              <td class="px-3 py-2 text-xs font-mono">{{ p.periodFrom }} → {{ p.periodTo }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs">฿ {{ fmt(p.grossAmount) }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs text-slate-500">
                {{ (p.whtRate * 100).toFixed(1) }}% / {{ fmt(p.whtAmount) }}
              </td>
              <td class="px-3 py-2 text-right font-mono font-semibold">฿ {{ fmt(p.netAmount) }}</td>
              <td class="px-3 py-2">
                <span :class="statusClass(p.status)" class="inline-flex px-2 py-0.5 rounded text-xs">{{ p.status }}</span>
              </td>
              <td class="px-3 py-2 text-right">
                <button type="button" class="text-xs text-slate-500 hover:text-brand-600 mr-2" @click="toggleExpand(p.id)">
                  <i :class="expanded === p.id ? 'pi pi-chevron-up' : 'pi pi-chevron-down'" class="text-[10px]" />
                </button>
                <button type="button" class="text-xs text-slate-500 hover:text-brand-600 mr-2" @click="doPdf(p)"
                  :title="t('adminPayouts.pdf')">
                  <i class="pi pi-file-pdf text-xs" />
                </button>
                <template v-if="p.status === 'draft'">
                  <button class="text-xs px-2 py-1 rounded bg-brand-600 text-white hover:bg-brand-700 mr-1 disabled:opacity-50"
                    :disabled="actionSaving === p.id" @click="doIssue(p)">
                    {{ t('adminPayouts.issue') }}
                  </button>
                </template>
                <template v-if="['draft', 'issued'].includes(p.status)">
                  <button class="text-xs px-2 py-1 rounded bg-emerald-600 text-white hover:bg-emerald-700 mr-1 disabled:opacity-50"
                    :disabled="actionSaving === p.id" @click="doPay(p)">
                    {{ t('adminPayouts.markPaid') }}
                  </button>
                  <button class="text-xs px-2 py-1 rounded border border-rose-200 text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                    :disabled="actionSaving === p.id" @click="doVoid(p)">
                    {{ t('adminPayouts.void') }}
                  </button>
                </template>
              </td>
            </tr>
            <tr v-if="expanded === p.id && detail" class="bg-slate-50">
              <td colspan="7" class="px-4 py-3">
                <div class="text-xs text-slate-500 mb-2">
                  {{ t('adminPayouts.txnsHeader') }} ({{ detail.transactions?.length ?? 0 }})
                </div>
                <table class="min-w-full text-xs">
                  <thead class="text-slate-500 uppercase">
                    <tr>
                      <th class="px-2 py-1 text-left">{{ t('adminPayouts.col.type') }}</th>
                      <th class="px-2 py-1 text-left">{{ t('adminPayouts.col.policy') }}</th>
                      <th class="px-2 py-1 text-right">{{ t('adminPayouts.col.base') }}</th>
                      <th class="px-2 py-1 text-right">%</th>
                      <th class="px-2 py-1 text-right">{{ t('adminPayouts.col.amount') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-200">
                    <tr v-for="t in detail.transactions" :key="t.id" :class="{ 'text-rose-700': t.isReversal }">
                      <td class="px-2 py-1">{{ t.type }}<span v-if="t.isReversal"> (reversal)</span></td>
                      <td class="px-2 py-1 font-mono">{{ t.policyNo || t.applicationNo || '#' + t.policyId }}</td>
                      <td class="px-2 py-1 text-right font-mono">{{ fmt(t.basePremium) }}</td>
                      <td class="px-2 py-1 text-right font-mono">{{ (t.diffPct * 100).toFixed(2) }}</td>
                      <td class="px-2 py-1 text-right font-mono">{{ fmt(t.amount) }}</td>
                    </tr>
                  </tbody>
                </table>
                <div v-if="detail.bankRef" class="mt-2 text-xs text-slate-500">
                  {{ t('adminPayouts.bankRef') }}: <span class="font-mono">{{ detail.bankRef }}</span>
                  · {{ t('adminPayouts.paidAt') }}: {{ detail.paidAt?.slice(0, 16).replace('T', ' ') }}
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </section>
  </div>
</template>
