<script setup lang="ts">
import { onMounted, ref, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchExpiringSoon, markRenewalContacted, markRenewalStarted, sendRenewalNotice,
  type ExpiringPolicy, type ExpiringSoonMeta,
} from '../../api/reports'
import { ApiError } from '../../api/client'
import { toCsv, downloadCsv } from '../../util/csvExport'

const router = useRouter()

const days = ref<30 | 60 | 90 | 180>(60)
const rows = ref<ExpiringPolicy[]>([])
const meta = ref<ExpiringSoonMeta | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await fetchExpiringSoon(days.value, 200)
    rows.value = res.data
    meta.value = res.meta
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'Unable to load renewal queue.'
    rows.value = []
    meta.value = null
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(days, load)

// ── Phase 8b — renewal actions ──────────────────────────────────────────
const actionSaving = ref<string | null>(null)
const actionMsg = ref<{ id: string; ok: boolean; text: string } | null>(null)

function flash(id: string, ok: boolean, text: string): void {
  actionMsg.value = { id, ok, text }
  setTimeout(() => { actionMsg.value = null }, 3000)
}

async function doContacted(r: ExpiringPolicy): Promise<void> {
  const note = window.prompt('บันทึกการติดต่อ (ไม่จำเป็น):') ?? ''
  actionSaving.value = r.policyId
  try {
    const res = await markRenewalContacted(r.policyId, { channel: 'phone', note: note.trim() || undefined })
    r.lastContactedAt = res.event.occurredAt
    flash(r.policyId, true, 'บันทึกการติดต่อแล้ว')
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'บันทึกล้มเหลว')
  } finally { actionSaving.value = null }
}

async function doSendNotice(r: ExpiringPolicy): Promise<void> {
  if (!window.confirm(`ส่งอีเมลแจ้งเตือนต่ออายุไปยัง ${r.customerEmail || r.agentEmail || '(?)'} ?`)) return
  actionSaving.value = r.policyId
  try {
    const res = await sendRenewalNotice(r.policyId)
    r.lastNoticeSentAt = new Date().toISOString()
    flash(r.policyId, true, res.sentToAgent ? 'ส่งถึงตัวแทน (ลูกค้าไม่มีอีเมล)' : 'ส่งอีเมลแล้ว')
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'ส่งอีเมลล้มเหลว')
  } finally { actionSaving.value = null }
}

async function doStartRenewal(r: ExpiringPolicy): Promise<void> {
  actionSaving.value = r.policyId
  try {
    const res = await markRenewalStarted(r.policyId)
    r.renewalStartedAt = new Date().toISOString()
    const q = res.quoteHint
    // Jump to /quotes/new with pre-fill query params. The quote page reads
    // ?customer= etc. — for Phase 5 the page doesn't consume these yet, but
    // we pass them so a future turn can wire it up. For now this at least
    // opens the new-quote page so the agent proceeds naturally.
    void router.push({
      name: 'quote-new',
      query: {
        customerId: q.customerId ?? '',
        productId: q.productId ?? '',
        carrierId: q.carrierId ?? '',
        writingAgentId: q.writingAgentId ?? '',
        newOrRenew: 'renew',
        refAppToId: q.refAppToId,
      },
    })
  } catch (e: unknown) {
    flash(r.policyId, false, e instanceof ApiError ? e.message : 'ล้มเหลว')
  } finally { actionSaving.value = null }
}

/** "3 days ago" — compact display for last-contacted timestamps. */
function relativeDays(iso: string | null | undefined): string {
  if (!iso) return ''
  const ms = Date.now() - new Date(iso).getTime()
  const days = Math.floor(ms / 86_400_000)
  if (days < 1) return 'วันนี้'
  if (days === 1) return 'เมื่อวาน'
  return days + ' วันก่อน'
}

function exportCsv(): void {
  const csv = toCsv(rows.value, [
    { header: 'Application', value: (r) => r.applicationNo },
    { header: 'Policy', value: (r) => r.policyNo },
    { header: 'Expiry', value: (r) => r.expiryDate },
    { header: 'Days remaining', value: (r) => r.daysRemaining },
    { header: 'Customer', value: (r) => `${r.customerCode ?? ''} ${r.customerName ?? ''}`.trim() },
    { header: 'Customer email', value: (r) => r.customerEmail ?? '' },
    { header: 'Agent', value: (r) => `${r.agentCode ?? ''} ${r.agentName ?? ''}`.trim() },
    { header: 'Carrier', value: (r) => r.carrierCode },
    { header: 'Product', value: (r) => r.productCode },
    { header: 'Annual premium', value: (r) => r.annualPremium.toFixed(2) },
    { header: 'Last contacted', value: (r) => r.lastContactedAt ?? '' },
    { header: 'Notice sent', value: (r) => r.lastNoticeSentAt ?? '' },
    { header: 'Renewal started', value: (r) => r.renewalStartedAt ?? '' },
  ])
  downloadCsv(csv, `renewals-${days.value}d-${new Date().toISOString().slice(0, 10)}.csv`)
}

function badge(dr: number): { cls: string; label: string } {
  if (dr <= 7) return { cls: 'bg-rose-100 text-rose-700', label: 'ด่วน' }
  if (dr <= 30) return { cls: 'bg-amber-100 text-amber-700', label: 'ใกล้ครบ' }
  return { cls: 'bg-slate-100 text-slate-600', label: '' }
}

function fmtBaht(n: number): string {
  return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', maximumFractionDigits: 0 }).format(n)
}

const summary = computed(() => ({
  total: meta.value?.total ?? rows.value.length,
  urgent: rows.value.filter((r) => r.daysRemaining <= 7).length,
  window: meta.value ? `${meta.value.from} → ${meta.value.to}` : '',
}))
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Renewal Pipeline</h1>
        <p class="text-slate-500 mt-1 text-sm">
          กรมธรรม์ที่ยังคุ้มครองอยู่และจะครบกำหนดในช่วงเวลาที่เลือก
        </p>
      </div>
      <div class="flex items-center gap-2">
        <label class="text-sm text-slate-500">ระยะเวลา</label>
        <select v-model.number="days" class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option :value="30">30 วัน</option>
          <option :value="60">60 วัน</option>
          <option :value="90">90 วัน</option>
          <option :value="180">180 วัน</option>
        </select>
        <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50 flex items-center gap-1"
          :disabled="!rows.length" @click="exportCsv">
          <i class="pi pi-download text-xs" /> Export CSV
        </button>
      </div>
    </header>

    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Total in window</div>
        <div class="text-2xl font-semibold text-slate-900 mt-1">{{ summary.total.toLocaleString() }}</div>
        <div class="text-xs text-slate-500 mt-1">{{ summary.window }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">ด่วน (≤7 วัน)</div>
        <div class="text-2xl font-semibold text-rose-600 mt-1">{{ summary.urgent.toLocaleString() }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase tracking-wider text-slate-400">Status</div>
        <div class="text-sm text-slate-700 mt-2">
          <span v-if="loading" class="text-slate-500">Loading…</span>
          <span v-else-if="error" class="text-rose-600">{{ error }}</span>
          <span v-else>เรียลไทม์จาก Laravel</span>
        </div>
      </div>
    </section>

    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="px-4 py-2 text-left">Application</th>
              <th class="px-4 py-2 text-left">Policy no</th>
              <th class="px-4 py-2 text-left">ลูกค้า</th>
              <th class="px-4 py-2 text-left">ตัวแทน</th>
              <th class="px-4 py-2 text-right">Premium</th>
              <th class="px-4 py-2 text-left">Expiry</th>
              <th class="px-4 py-2 text-right">วัน</th>
              <th class="px-4 py-2 text-left">สถานะติดตาม</th>
              <th class="px-4 py-2 text-right w-72">การดำเนินการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in rows" :key="r.policyId" class="hover:bg-slate-50">
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.applicationNo ?? '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-slate-700">{{ r.policyNo ?? '—' }}</td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ r.customerName || r.customerCode }}</div>
                <div class="text-xs text-slate-500">
                  <span>{{ r.customerCode }}</span>
                  <span v-if="r.customerEmail" class="ml-1">· {{ r.customerEmail }}</span>
                </div>
              </td>
              <td class="px-4 py-2">
                <div class="text-slate-900">{{ r.agentName || r.agentCode }}</div>
                <div class="text-xs text-slate-500">{{ r.agentCode }}</div>
              </td>
              <td class="px-4 py-2 text-right font-medium text-slate-900">{{ fmtBaht(r.annualPremium) }}</td>
              <td class="px-4 py-2">{{ r.expiryDate }}</td>
              <td class="px-4 py-2 text-right">
                <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium', badge(r.daysRemaining).cls]">
                  {{ r.daysRemaining }} วัน
                  <span v-if="badge(r.daysRemaining).label" class="text-[10px]">{{ badge(r.daysRemaining).label }}</span>
                </span>
              </td>
              <td class="px-4 py-2 text-xs text-slate-500">
                <div v-if="r.renewalStartedAt" class="text-brand-700">
                  <i class="pi pi-arrow-right text-[10px] mr-0.5" /> เริ่มต่ออายุ · {{ relativeDays(r.renewalStartedAt) }}
                </div>
                <div v-if="r.lastNoticeSentAt" class="text-emerald-700">
                  <i class="pi pi-envelope text-[10px] mr-0.5" /> ส่งอีเมล · {{ relativeDays(r.lastNoticeSentAt) }}
                </div>
                <div v-if="r.lastContactedAt" class="text-slate-600">
                  <i class="pi pi-phone text-[10px] mr-0.5" /> ติดต่อ · {{ relativeDays(r.lastContactedAt) }}
                </div>
                <div v-if="!r.lastContactedAt && !r.lastNoticeSentAt && !r.renewalStartedAt" class="text-slate-300">—</div>
                <div v-if="actionMsg?.id === r.policyId"
                  :class="actionMsg.ok ? 'text-emerald-700' : 'text-rose-700'"
                  class="text-[10px] mt-1">{{ actionMsg.text }}</div>
              </td>
              <td class="px-4 py-2 text-right">
                <div class="inline-flex items-center gap-1">
                  <button type="button" title="บันทึกการติดต่อ"
                    class="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-brand-600 disabled:opacity-50"
                    :disabled="actionSaving === r.policyId" @click="doContacted(r)">
                    <i class="pi pi-phone text-xs" />
                  </button>
                  <button type="button" title="ส่งอีเมลแจ้งต่ออายุ"
                    class="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-brand-600 disabled:opacity-50"
                    :disabled="actionSaving === r.policyId" @click="doSendNotice(r)">
                    <i class="pi pi-envelope text-xs" />
                  </button>
                  <button type="button"
                    class="ml-1 px-2 py-1 rounded bg-brand-600 text-white text-xs hover:bg-brand-700 disabled:opacity-50"
                    :disabled="actionSaving === r.policyId" @click="doStartRenewal(r)">
                    <i class="pi pi-arrow-right text-[10px] mr-1" /> ต่ออายุ
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td colspan="8" class="px-4 py-6 text-center text-slate-500">ไม่พบกรมธรรม์ที่จะครบกำหนดในช่วงเวลานี้</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
