<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  fetchExpiringSoon, type ExpiringPolicy, type RenewalStage, type FollowupResult,
} from '../../api/reports'
import {
  fetchPolicy, fetchPolicyPayments, fetchPolicyEvents, fetchPolicyDocuments, downloadPolicyDocument,
  type PolicyPaymentRow, type PolicyEventRow, type PolicyDocumentRow,
} from '../../api/policies'
import { fetchEndorsements, type Endorsement } from '../../api/endorsements'
import DateInput from '../../components/DateInput.vue'
import {
  FOLLOWUP_CHANNELS, FOLLOWUP_RESULTS, followupResultMeta, followupChannelMeta,
  todayIso, addDaysIso, isFollowupOverdue,
} from '../../utils/renewalFollowup'
import { ApiError } from '../../api/client'
import { fmtDate, fmtDateTime } from '../../util/dateFormat'
import {
  renewalStageMeta, renewalNextAction, RENEWAL_PIPELINE,
} from '../../utils/renewalStage'
import { useRenewalActions } from '../../composables/useRenewalActions'
import PolicyPeriodBadge from '../../components/PolicyPeriodBadge.vue'
import type { Policy } from '../../stores/policies'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const policyId = computed(() => String(route.params.id))

// ── Core state ─────────────────────────────────────────────────────────────
const row = ref<ExpiringPolicy | null>(null)
const coverage = ref<number | null>(null)
const detailPolicy = ref<Policy | null>(null)
const payments = ref<PolicyPaymentRow[]>([])
const events = ref<PolicyEventRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const actions = useRenewalActions(row, detailPolicy)
const endorsements = ref<Endorsement[]>([])
const policyFiles = ref<PolicyDocumentRow[]>([])

// Which inline action panel is expanded. Defaults to the stage's next step.
type PanelKey = 'followup' | 'request_quote' | 'upload_quote' | 'prepare_quote' | 'send_quote' | null
const panel = ref<PanelKey>(null)

const stageMeta = computed(() => renewalStageMeta(row.value?.renewalStage))
const nextAction = computed(() => renewalNextAction(row.value?.renewalStage))

/** Map a next-action token to the inline panel it opens. */
const ACTION_PANEL: Record<string, PanelKey> = {
  contact: 'followup', request_quote: 'request_quote', upload_quote: 'upload_quote',
  prepare_quote: 'prepare_quote', send_quote: 'send_quote',
}

/** Map a PIPELINE STAGE to the action panel that stage owns, so the operator
 *  can click any step in the stepper and jump straight to its actions — not
 *  only the current/next step. `renewed` is the goal (no action); terminals
 *  return null and just show the summary. */
const STAGE_PANEL: Partial<Record<RenewalStage, PanelKey>> = {
  not_started: 'request_quote',
  contacted: 'request_quote',
  quote_requested: 'upload_quote',
  quote_received: 'prepare_quote',
  quote_prepared: 'send_quote',
  quote_sent: 'send_quote',
}
/** Open the panel a stepper stage owns (no-op for stages without one). */
function goToStage(stage: RenewalStage): void {
  const key = STAGE_PANEL[stage] ?? null
  if (key) openPanel(key)
}

// Stepper cells — each on-track stage with its done/current/future state + ts.
const STAGE_TS: Record<RenewalStage, keyof ExpiringPolicy | null> = {
  not_started: null,
  contacted: 'lastContactedAt',
  quote_requested: 'quoteRequestedAt',
  quote_received: 'quoteReceivedAt',
  quote_prepared: 'quotePreparedAt',
  quote_sent: 'quoteSentAt',
  renewed: 'renewalStartedAt',
  declined: 'renewalDeclinedAt',
  expired: null,
}
const stepper = computed(() => {
  const cur = renewalStageMeta(row.value?.renewalStage).order
  return RENEWAL_PIPELINE.map((stage) => {
    const m = renewalStageMeta(stage)
    const tsField = STAGE_TS[stage]
    const ts = tsField ? (row.value?.[tsField] as string | null | undefined) : null
    return {
      stage, meta: m,
      state: m.order < cur ? 'done' : m.order === cur ? 'current' : 'future',
      ts: ts ?? null,
    }
  })
})

const isDeclined = computed(() => row.value?.renewalStage === 'declined')

// ── Load everything ──────────────────────────────────────────────────────
async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    // The expiring-soon row carries the derived stage + all quote* timestamps.
    // Fetch a wide window and pick this policy. (A dedicated by-id endpoint
    // would be cleaner; this reuses the exact same derivation the list uses.)
    const [expiring, detail, pay, ev] = await Promise.all([
      fetchExpiringSoon({ days: 3650, perPage: 500, q: '' }),
      fetchPolicy(policyId.value).catch(() => null),
      fetchPolicyPayments(policyId.value).catch(() => ({ data: [] as PolicyPaymentRow[] })),
      fetchPolicyEvents(policyId.value).catch(() => ({ data: [] as PolicyEventRow[] })),
    ])
    const found = expiring.data.find((r) => r.policyId === policyId.value)
    if (!found) {
      // Not in the expiring window (already renewed / far future). Fall back to
      // a minimal row from the policy detail so the page still renders.
      if (!detail) { error.value = 'ไม่พบกรมธรรม์นี้ในคิวต่ออายุ'; return }
      const d = detail.data as unknown as Record<string, unknown>
      row.value = {
        policyId: policyId.value,
        applicationNo: (d.applicationNo as string) ?? null,
        policyNo: (d.policyNo as string) ?? null,
        expiryDate: (d.expiryDate as string) ?? '',
        daysRemaining: 0,
        annualPremium: (d.annualPremium as number) ?? 0,
        customerCode: null, customerName: (d.customerName as string) ?? '—',
        agentCode: null, agentName: '—',
        carrierCode: null, carrierName: null,
        productCode: null, productName: null,
        renewalStage: 'not_started',
      } as ExpiringPolicy
    } else {
      row.value = found
    }
    coverage.value = detail ? (detail.data as unknown as { coverage?: number }).coverage ?? null : null
    detailPolicy.value = detail ? detail.data : null
    payments.value = pay.data
    events.value = ev.data
    // Default the open panel to the recommended next step.
    void actions.loadDocs()
    void actions.loadFollowups()
    void loadExtras()
    await actions.loadNotices()
    panel.value = nextAction.value ? ACTION_PANEL[nextAction.value.action] ?? null : null
    if (panel.value) primePanel(panel.value)
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

async function loadExtras(): Promise<void> {
  const [en, files] = await Promise.all([
    fetchEndorsements(policyId.value).catch(() => ({ data: [] as Endorsement[] })),
    fetchPolicyDocuments(policyId.value, 'policy').catch(() => ({ data: [] as PolicyDocumentRow[] })),
  ])
  endorsements.value = en.data
  policyFiles.value = files.data
}

/** Prime whichever panel just opened (loads carrier emails / builds forms). */
function primePanel(key: PanelKey): void {
  if (key === 'followup') actions.resetFollowupForm(actions.followupForm.value.result)
  else if (key === 'request_quote') actions.initQuoteForm()
  else if (key === 'prepare_quote') actions.initPrepareForm()
  else if (key === 'send_quote') actions.initSendForm()
}
function openPanel(key: PanelKey): void {
  panel.value = panel.value === key ? null : key
  if (panel.value) primePanel(panel.value)
}

// ── Action wrappers: run, then refresh timeline + close panel on success ────
async function afterSuccess(): Promise<void> {
  const ev = await fetchPolicyEvents(policyId.value).catch(() => null)
  if (ev) events.value = ev.data
  void actions.loadDocs()
}
function openFollowup(result: FollowupResult = 'thinking'): void {
  actions.resetFollowupForm(result)
  panel.value = 'followup'
}
async function doFollowup() { if (await actions.submitFollowup()) { panel.value = null; await afterSuccess() } }
async function doReopen() { if (await actions.reopenFollowup()) await afterSuccess() }
async function doNotice() { if (await actions.submitNotice()) await afterSuccess() }
async function doRequest(mode: 'system' | 'manual') { if (await actions.submitQuoteRequest(mode)) { panel.value = null; await afterSuccess() } }
async function doPrepare(dl: boolean) { if (await actions.submitPrepareQuote(dl)) { panel.value = null; await afterSuccess() } }
async function doSend() { if (await actions.submitSendQuote()) { panel.value = null; await afterSuccess() } }
async function doManual(stage: Parameters<typeof actions.manualMark>[0]) { if (await actions.manualMark(stage)) await afterSuccess() }

// Carrier-quote upload (hidden input).
const uploadInput = ref<HTMLInputElement | null>(null)
function triggerUpload() { uploadInput.value?.click() }
async function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  if (await actions.uploadCarrierQuote(file)) { panel.value = null; await afterSuccess() }
}
async function onRegenerate(d: Parameters<typeof actions.regenerateInsurehubQuote>[0]) {
  if (await actions.regenerateInsurehubQuote(d)) openPanel('prepare_quote')
}

function backToList() { router.push({ name: 'policies-expiring' }) }
/** Final step: start the renewal by opening the new-policy wizard prefilled
 *  from this policy. Fires NO event here — the source policy only flips to
 *  "renewed" once the draft is actually created (backend storeDraft). */
function goRenew() {
  if (detailPolicy.value?.renewedTo?.live) {
    router.push({ name: 'policy-edit-draft', params: { id: detailPolicy.value.renewedTo.id } })
    return
  }
  router.push({ name: 'policy-new', query: { renewFrom: policyId.value } })
}
function openFullEdit() {
  const href = router.resolve({ name: 'policy-edit-draft', params: { id: policyId.value } }).href
  window.open(href, '_blank', 'noopener')
}

// Event type → Thai label for the timeline.
function eventLabel(type: string): string {
  const map: Record<string, string> = {
    created: 'สร้างกรมธรรม์', issued: 'ออกกรมธรรม์', renewalContacted: 'ติดต่อลูกค้า',
    renewalNoticeSent: 'ส่งแจ้งเตือนต่ออายุ', renewalQuoteRequested: 'ขอใบเสนอราคา',
    renewalQuoteReceived: 'ได้รับใบเสนอราคา', renewalQuotePrepared: 'จัดทำใบเสนอราคา InsureHub',
    renewalQuoteSent: 'ส่งใบเสนอราคาให้ลูกค้า', renewalStarted: 'เริ่มต่ออายุ',
    renewalDeclined: 'ลูกค้าปฏิเสธการต่ออายุ', paymentRecorded: 'บันทึกการชำระเงิน',
    renewalReopened: 'เปิดติดตามต่ออายุใหม่', renewalTermsConfirmed: 'ยืนยันเงื่อนไขต่ออายุ',
    documentUploaded: 'อัปโหลดเอกสาร', premiumPaid: 'รับชำระเบี้ย', premiumPaymentVoided: 'ยกเลิกรายการชำระเบี้ย', draftCreated: 'สร้างฉบับร่าง',
    submittedFromDraft: 'ส่งพิจารณา', activated: 'เริ่มคุ้มครอง', expired: 'หมดอายุ', cancelled: 'ยกเลิก',
    approvalUpdated: 'อัปเดตการอนุมัติ', fulfilmentUpdated: 'อัปเดตการจัดส่ง',
  }
  if (type.startsWith('endorsement.')) return `สลักหลัง — ${t(`endorsement.types.${type.replace('endorsement.', '')}`)}`
  return map[type] ?? type
}
function endorsementReason(e: Endorsement): string {
  const p = (e.payload ?? {}) as Record<string, unknown>
  return String(p.reason ?? p.note ?? '') || '—'
}
async function downloadFile(d: PolicyDocumentRow): Promise<void> {
  await downloadPolicyDocument(policyId.value, d.id, d.fileName, 'download').catch(() => undefined)
}

const lastYearPremium = computed(() => {
  const d = detailPolicy.value
  return {
    annual: d?.annualPremium ?? row.value?.annualPremium ?? null,
    net: d?.premium?.net ?? null,
    duty: d?.premium?.dutyStamp ?? null,
    vat: d?.premium?.vat ?? null,
    discount: d?.installment?.discountAmount ?? null,
    coverage: d?.coverage ?? coverage.value ?? null,
  }
})

type CompareKind = 'cost' | 'benefit'
const comparison = computed(() => {
  const n = actions.latestNotice.value
  if (!n) return []
  const l = lastYearPremium.value
  const rows: Array<{ key: string; label: string; last: number | null; now: number | null; kind: CompareKind; strong?: boolean }> = [
    { key: 'annual', label: 'เบี้ยรวม', last: l.annual, now: n.annualPremium, kind: 'cost', strong: true },
    { key: 'net', label: 'เบี้ยสุทธิ', last: l.net, now: n.netPremium, kind: 'cost' },
    { key: 'duty', label: 'อากรแสตมป์', last: l.duty, now: n.dutyStamp, kind: 'cost' },
    { key: 'vat', label: 'ภาษีมูลค่าเพิ่ม', last: l.vat, now: n.vat, kind: 'cost' },
    { key: 'discount', label: 'ส่วนลด', last: l.discount, now: n.discount, kind: 'benefit' },
    { key: 'coverage', label: 'ทุนประกัน', last: l.coverage, now: n.coverage, kind: 'benefit' },
  ]
  return rows.map((r) => {
    const diff = r.last !== null && r.now !== null ? Math.round((r.now - r.last) * 100) / 100 : null
    const pct = diff !== null && r.last ? Math.round((diff / r.last) * 1000) / 10 : null
    const worse = diff !== null && diff !== 0 && ((r.kind === 'cost' && diff > 0) || (r.kind === 'benefit' && diff < 0))
    const better = diff !== null && diff !== 0 && !worse
    return { ...r, diff, pct, cls: worse ? 'text-rose-600' : better ? 'text-emerald-600' : 'text-slate-400' }
  })
})

const TAX_FORMULAS = [
  { value: 1, label: 'สูตร 1 — อากร + VAT' },
  { value: 2, label: 'สูตร 2 — VAT อย่างเดียว' },
  { value: 3, label: 'สูตร 3 — อากร 20 บาท' },
  { value: 4, label: 'สูตร 4 — อากร 150 บาท' },
] as const
const splitMismatch = computed(() => {
  const f = actions.prepareForm.value
  if (!f) return false
  return Math.abs((Number(f.netPremium) || 0) + (Number(f.dutyStamp) || 0) + (Number(f.vat) || 0) - (Number(f.annualPremium) || 0)) > 0.05
})
const money = (n: number | null | undefined) =>
  n == null ? '—' : n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const paidTotal = computed(() => payments.value.reduce((s, p) => s + (p.amount || 0), 0))

// Carrier-supplied quote files only (for the upload panel's inline list).
const carrierDocs = computed(() => actions.docs.value.filter((d) => d.type === 'renewal_quote_carrier'))
const latestFollowup = computed(() => actions.followups.value[0] ?? null)

// ── Prepare-form helpers ────────────────────────────────────────────────────
const PREMIUM_MODES: { value: 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single'; label: string }[] = [
  { value: 'annual', label: 'รายปี' },
  { value: 'semiannual', label: 'ราย 6 เดือน' },
  { value: 'quarterly', label: 'รายไตรมาส' },
  { value: 'monthly', label: 'รายเดือน' },
  { value: 'single', label: 'จ่ายครั้งเดียว' },
]
onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-5xl px-4 py-5">
    <!-- Header ------------------------------------------------------------- -->
    <div class="mb-4 flex items-start justify-between gap-3">
      <div>
        <button type="button" class="mb-1 text-xs text-slate-500 hover:text-slate-700"
          @click="backToList">
          <i class="pi pi-arrow-left text-[10px]" /> กลับไปคิวต่ออายุ
        </button>
        <h1 class="text-lg font-semibold text-slate-800">
          {{ row?.customerName || '—' }}
        </h1>
        <p class="text-xs text-slate-500 flex flex-wrap items-center gap-1">
          กรมธรรม์ {{ row?.policyNo || row?.applicationNo || '—' }}
          <PolicyPeriodBadge v-if="detailPolicy?.periodLabel" :label="detailPolicy.periodLabel" />
          <span v-if="row" class="mx-1">·</span>
          <span v-if="row" :class="['inline-flex items-center gap-1 rounded px-1.5 py-0.5', stageMeta.badgeClass]">
            <i :class="['pi', stageMeta.icon, 'text-[10px]']" /> {{ stageMeta.label }}
          </span>
          <button v-if="detailPolicy?.renewedTo" type="button" data-testid="renewal-detail-renewed-to"
            :class="['inline-flex items-center gap-1 rounded border px-1.5 py-0.5',
              detailPolicy.renewedTo.live ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-400 line-through']"
            @click="router.push({ name: 'policy-edit-draft', params: { id: detailPolicy.renewedTo.id } })">
            ต่ออายุเป็น {{ detailPolicy.renewedTo.applicationNo || detailPolicy.renewedTo.policyNo }}
            <i class="pi pi-arrow-right text-[10px]" />
          </button>
        </p>
      </div>
      <button type="button"
        class="shrink-0 rounded border border-slate-300 px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
        @click="openFullEdit">
        <i class="pi pi-external-link text-[10px]" /> แก้ไขกรมธรรม์แบบเต็ม
      </button>
    </div>

    <div v-if="loading" class="py-16 text-center text-sm text-slate-400">
      <i class="pi pi-spin pi-spinner" /> กำลังโหลด…
    </div>
    <div v-else-if="error" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
      {{ error }}
    </div>

    <template v-else-if="row">
      <!-- Flash -------------------------------------------------------------- -->
      <transition name="fade">
        <div v-if="actions.msg.value"
          :class="['mb-3 rounded px-3 py-2 text-sm',
            actions.msg.value.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']">
          {{ actions.msg.value.text }}
        </div>
      </transition>

      <!-- Pipeline stepper --------------------------------------------------- -->
      <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4">
        <p class="mb-2 text-[10px] text-slate-400">
          <i class="pi pi-info-circle text-[9px]" /> คลิกที่ขั้นตอนใดก็ได้เพื่อไปยังการดำเนินการของขั้นตอนนั้น
        </p>
        <div class="flex items-center overflow-x-auto">
          <template v-for="(s, i) in stepper" :key="s.stage">
            <button type="button"
              :class="['group flex min-w-[92px] flex-col items-center rounded p-1 text-center transition',
                STAGE_PANEL[s.stage] ? 'cursor-pointer hover:bg-slate-50' : 'cursor-default',
                panel && STAGE_PANEL[s.stage] === panel ? 'bg-sky-50 ring-1 ring-sky-300' : '']"
              :disabled="!STAGE_PANEL[s.stage]"
              :title="STAGE_PANEL[s.stage] ? 'ไปยังขั้นตอนนี้' : ''"
              @click="goToStage(s.stage)">
              <div :class="['flex h-7 w-7 items-center justify-center rounded-full border text-[11px]',
                s.state === 'done' ? 'border-emerald-500 bg-emerald-500 text-white'
                : s.state === 'current' ? 'border-sky-500 bg-sky-50 text-sky-600'
                : 'border-slate-300 bg-white text-slate-400',
                STAGE_PANEL[s.stage] ? 'group-hover:border-sky-400' : '']">
                <i v-if="s.state === 'done'" class="pi pi-check text-[10px]" />
                <i v-else :class="['pi', s.meta.icon, 'text-[10px]']" />
              </div>
              <span :class="['mt-1 text-[10px] leading-tight',
                s.state === 'future' ? 'text-slate-400' : 'text-slate-700']">{{ s.meta.label }}</span>
              <span class="text-[9px] text-slate-400">{{ s.ts ? fmtDate(s.ts) : '' }}</span>
            </button>
            <div v-if="i < stepper.length - 1"
              :class="['mx-1 h-0.5 min-w-[16px] flex-1',
                stepper[i + 1].state !== 'future' ? 'bg-emerald-400' : 'bg-slate-200']" />
          </template>
        </div>
        <div v-if="isDeclined" class="mt-3 flex items-center justify-center gap-2 rounded bg-rose-50 px-2 py-1 text-xs text-rose-700">
          <i class="pi pi-times-circle text-[10px]" /> ลูกค้าปฏิเสธการต่ออายุ
          <span v-if="latestFollowup?.reasonLabel" class="text-rose-600">— {{ latestFollowup.reasonLabel }}</span>
          <button type="button" class="ml-2 rounded border border-rose-300 bg-white px-2 py-0.5 text-[11px] text-rose-700 hover:bg-rose-100"
            data-testid="reopen-followup" :disabled="actions.saving.value" @click="doReopen">
            <i class="pi pi-replay text-[10px]" /> เปิดติดตามใหม่
          </button>
        </div>
      </div>

      <!-- Action bar --------------------------------------------------------- -->
      <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4">
        <div class="mb-3 flex flex-wrap items-center gap-2">
          <span class="text-xs font-medium text-slate-500">การดำเนินการ:</span>
          <button v-if="nextAction && ACTION_PANEL[nextAction.action]" type="button"
            :class="['rounded px-2.5 py-1.5 text-xs font-medium',
              panel === ACTION_PANEL[nextAction.action]
                ? 'bg-sky-600 text-white' : 'bg-sky-50 text-sky-700 hover:bg-sky-100']"
            @click="openPanel(ACTION_PANEL[nextAction.action])">
            <i :class="['pi', nextAction.icon, 'text-[10px]']" /> {{ nextAction.label }}
          </button>
          <!-- Final step: ต่ออายุ — navigates to the new-policy wizard prefilled
               from this policy. Not a panel: it's the handoff to renewal. -->
          <button v-if="nextAction && nextAction.action === 'start_renewal'" type="button"
            class="rounded bg-emerald-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-emerald-700"
            @click="goRenew">
            <i class="pi pi-arrow-right text-[10px]" /> {{ nextAction.label }}
          </button>
          <button type="button" data-testid="open-followup"
            :class="['rounded px-2.5 py-1.5 text-xs', panel === 'followup' ? 'bg-slate-200' : 'bg-slate-50 hover:bg-slate-100']"
            @click="panel === 'followup' ? (panel = null) : openFollowup()">
            <i class="pi pi-phone text-[10px]" /> บันทึกการติดตาม
          </button>
          <button type="button"
            :class="['rounded px-2.5 py-1.5 text-xs', panel === 'upload_quote' ? 'bg-violet-100 text-violet-700' : 'bg-violet-50 text-violet-600 hover:bg-violet-100']"
            @click="openPanel('upload_quote')">
            <i class="pi pi-upload text-[10px]" /> อัปโหลดใบเสนอราคาบริษัท
          </button>
          <button type="button" class="rounded bg-slate-50 px-2.5 py-1.5 text-xs hover:bg-slate-100"
            :disabled="actions.saving.value" @click="doNotice">
            <i class="pi pi-bell text-[10px]" /> ส่งแจ้งเตือน
          </button>
          <button v-if="!isDeclined" type="button" data-testid="open-decline"
            :class="['rounded px-2.5 py-1.5 text-xs', panel === 'followup' && actions.followupForm.value.result === 'not_renewing' ? 'bg-rose-100 text-rose-700' : 'bg-rose-50 text-rose-600 hover:bg-rose-100']"
            @click="openFollowup('not_renewing')">
            <i class="pi pi-times text-[10px]" /> ลูกค้าปฏิเสธ
          </button>
        </div>

        <!-- Manual advance -->
        <div class="mb-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-2">
          <span class="text-[10px] text-slate-400">ทำนอกระบบแล้ว? ทำเครื่องหมายขั้นตอน:</span>
          <button v-for="st in (['contacted','quote_requested','quote_received','quote_prepared','quote_sent'] as const)"
            :key="st" type="button"
            class="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-500 hover:bg-slate-50"
            :disabled="actions.saving.value" @click="doManual(st)">
            {{ renewalStageMeta(st).label }}
          </button>
        </div>

        <!-- ─── Inline panels ─────────────────────────────────────────────── -->
        <div v-if="panel === 'followup'" class="rounded border border-slate-200 bg-slate-50 p-3" data-testid="followup-panel">
          <p class="mb-2 text-xs font-medium text-slate-600">บันทึกผลการติดตามลูกค้า</p>
          <div class="grid gap-3 sm:grid-cols-[170px_1fr]">
            <label class="text-[11px] text-slate-500">ช่องทาง
              <select v-model="actions.followupForm.value.channel" data-testid="followup-channel"
                class="mt-0.5 w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs">
                <option v-for="c in FOLLOWUP_CHANNELS" :key="c" :value="c">{{ followupChannelMeta(c).label }}</option>
              </select>
            </label>
            <div>
              <div class="mb-0.5 text-[11px] text-slate-500">ผลการติดตาม</div>
              <div class="flex flex-wrap gap-1.5">
                <button v-for="res in FOLLOWUP_RESULTS" :key="res" type="button" :data-testid="`followup-result-${res}`"
                  :class="['inline-flex items-center gap-1 rounded border px-2.5 py-1 text-xs transition',
                    actions.followupForm.value.result === res
                      ? [followupResultMeta(res).badgeClass, 'border-current font-medium ring-1 ring-current']
                      : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50']"
                  @click="actions.followupForm.value.result = res">
                  <i :class="['pi', followupResultMeta(res).icon, 'text-[10px]']" /> {{ followupResultMeta(res).label }}
                </button>
              </div>
            </div>
          </div>
          <div v-if="actions.followupForm.value.result === 'not_renewing'" class="mt-3 grid gap-2 sm:grid-cols-2">
            <label class="text-[11px] text-rose-700">เหตุผลที่ไม่ต่ออายุ *
              <select v-model="actions.followupForm.value.reasonCode" data-testid="followup-reason"
                class="mt-0.5 w-full rounded border border-rose-200 bg-white px-2 py-1 text-xs">
                <option value="">— เลือกเหตุผล —</option>
                <option v-for="r in actions.declineReasons.value" :key="r.code" :value="r.code">{{ r.label }}</option>
              </select>
              <span v-if="actions.followupErrors.value.reasonCode" class="text-[10px] text-rose-600">{{ actions.followupErrors.value.reasonCode[0] }}</span>
            </label>
            <label v-if="actions.followupForm.value.reasonCode === 'other'" class="text-[11px] text-rose-700">ระบุเหตุผล *
              <input v-model="actions.followupForm.value.reasonNote" maxlength="500"
                class="mt-0.5 w-full rounded border border-rose-200 bg-white px-2 py-1 text-xs" />
              <span v-if="actions.followupErrors.value.reasonNote" class="text-[10px] text-rose-600">{{ actions.followupErrors.value.reasonNote[0] }}</span>
            </label>
          </div>
          <label class="mt-3 block text-[11px] text-slate-500">บันทึกการคุย
            <textarea v-model="actions.followupForm.value.note" rows="3" maxlength="2000" data-testid="followup-note"
              placeholder="เช่น ลูกค้าขอเทียบราคากับบริษัทอื่น จะแจ้งกลับวันศุกร์"
              class="mt-0.5 w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs"></textarea>
          </label>
          <div v-if="actions.followupForm.value.result !== 'not_renewing'" class="mt-2">
            <div class="mb-0.5 text-[11px] text-slate-500">นัดติดตามครั้งถัดไป</div>
            <div class="flex flex-wrap items-center gap-2">
              <div class="w-44"><DateInput v-model="actions.followupForm.value.nextFollowupAt" :min="todayIso()" /></div>
              <button v-for="d in [1, 3, 7]" :key="d" type="button" :data-testid="`followup-next-${d}`"
                class="rounded border border-slate-200 bg-white px-2 py-0.5 text-[11px] text-slate-600 hover:bg-slate-50"
                @click="actions.followupForm.value.nextFollowupAt = addDaysIso(d)">+{{ d }} วัน</button>
              <button v-if="actions.followupForm.value.nextFollowupAt" type="button"
                class="px-1 text-[11px] text-slate-400 hover:text-slate-600"
                @click="actions.followupForm.value.nextFollowupAt = ''">ไม่นัด</button>
            </div>
            <span v-if="actions.followupErrors.value.nextFollowupAt" class="text-[10px] text-rose-600">{{ actions.followupErrors.value.nextFollowupAt[0] }}</span>
          </div>
          <div class="mt-3 flex gap-2">
            <button type="button" data-testid="followup-submit"
              :class="['rounded px-3 py-1.5 text-xs text-white disabled:opacity-50',
                actions.followupForm.value.result === 'not_renewing' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-sky-600 hover:bg-sky-700']"
              :disabled="actions.saving.value" @click="doFollowup">
              {{ actions.followupForm.value.result === 'not_renewing' ? 'บันทึก — ลูกค้าไม่ต่ออายุ' : 'บันทึกการติดตาม' }}
            </button>
            <button type="button" class="rounded px-3 py-1.5 text-xs text-slate-500 hover:bg-white" @click="panel = null">ยกเลิก</button>
          </div>
        </div>

        <!-- Request quote -->
        <div v-else-if="panel === 'request_quote'" class="rounded border border-slate-200 bg-slate-50 p-3">
          <p class="mb-2 text-xs font-medium text-slate-600">ขอใบเสนอราคาต่ออายุจากบริษัทประกัน</p>
          <!-- Carrier email list -->
          <div class="mb-2">
            <label class="text-[10px] text-slate-500">ส่งถึง (อีเมลบริษัทประกัน)</label>
            <div v-if="actions.quoteContactsLoading.value" class="text-[11px] text-slate-400">กำลังโหลด…</div>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="c in actions.quoteContacts.value" :key="c.id" type="button"
                :class="['group inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px]',
                  actions.quoteForm.value.to === c.email ? 'border-sky-500 bg-sky-50 text-sky-700' : 'border-slate-200 bg-white text-slate-600']"
                @click="actions.quoteForm.value.to = c.email">
                {{ c.email }}
                <i class="pi pi-pencil text-[9px] opacity-0 group-hover:opacity-60" @click.stop="actions.startEditContact(c)" />
                <i class="pi pi-times text-[9px] opacity-0 group-hover:opacity-60" @click.stop="actions.removeContact(c)" />
              </button>
              <button type="button" class="rounded border border-dashed border-slate-300 px-1.5 py-0.5 text-[11px] text-slate-500"
                @click="actions.startAddContact()"><i class="pi pi-plus text-[9px]" /> เพิ่มอีเมล</button>
            </div>
            <!-- inline add/edit -->
            <div v-if="actions.quoteContactEdit.value" class="mt-1.5 flex gap-1.5">
              <input v-model="actions.quoteContactEdit.value.email" placeholder="อีเมล"
                class="rounded border border-slate-300 px-2 py-1 text-[11px]" />
              <input v-model="actions.quoteContactEdit.value.name" placeholder="ชื่อผู้ติดต่อ (ไม่บังคับ)"
                class="rounded border border-slate-300 px-2 py-1 text-[11px]" />
              <button type="button" class="rounded bg-sky-600 px-2 py-1 text-[11px] text-white" @click="actions.saveContactEdit()">บันทึก</button>
              <button type="button" class="rounded px-2 py-1 text-[11px] text-slate-500" @click="actions.quoteContactEdit.value = null">ยกเลิก</button>
            </div>
          </div>
          <input v-model="actions.quoteForm.value.to" placeholder="หรือพิมพ์อีเมลเอง"
            class="mb-2 w-full rounded border border-slate-300 px-2 py-1 text-xs" />
          <input v-model="actions.quoteForm.value.subject"
            class="mb-2 w-full rounded border border-slate-300 px-2 py-1 text-xs" />
          <textarea v-model="actions.quoteForm.value.message" rows="5"
            class="mb-2 w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="rounded bg-sky-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
              :disabled="actions.saving.value" @click="doRequest('system')">ส่งอีเมลจากระบบ</button>
            <a :href="actions.quoteMailtoHref.value"
              class="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-600 hover:bg-white">เปิดในแอปเมล</a>
            <button type="button" class="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-600 hover:bg-white"
              @click="actions.copyQuoteToClipboard()">{{ actions.quoteCopied.value ? 'คัดลอกแล้ว ✓' : 'คัดลอกข้อความ' }}</button>
            <button type="button" class="rounded bg-emerald-50 px-3 py-1.5 text-xs text-emerald-700 disabled:opacity-50"
              :disabled="actions.saving.value" @click="doRequest('manual')">ฉันส่งเองแล้ว — บันทึก</button>
          </div>
        </div>

        <!-- Upload carrier quote -->
        <div v-else-if="panel === 'upload_quote'" class="rounded border border-slate-200 bg-slate-50 p-3">
          <p class="mb-1 text-xs font-medium text-slate-600">อัปโหลดใบเสนอราคาที่ได้รับจากบริษัทประกัน</p>
          <p class="mb-2 text-[11px] text-slate-500">
            เลือกไฟล์ PDF หรือรูปภาพที่บริษัทประกันส่งกลับมา — เมื่ออัปโหลดแล้ว สถานะจะเปลี่ยนเป็น “ได้รับใบเสนอราคา”
          </p>
          <button type="button" class="rounded bg-sky-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
            :disabled="actions.saving.value" @click="triggerUpload">
            <i class="pi pi-upload text-[10px]" /> {{ actions.saving.value ? 'กำลังอัปโหลด…' : 'เลือกไฟล์' }}
          </button>
          <input ref="uploadInput" type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp" @change="onFile" />

          <!-- Already-uploaded carrier files -->
          <div v-if="carrierDocs.length" class="mt-3 border-t border-slate-200 pt-2">
            <p class="mb-1 text-[10px] text-slate-400">ไฟล์ที่อัปโหลดแล้ว</p>
            <ul class="space-y-1">
              <li v-for="d in carrierDocs" :key="d.id"
                class="flex items-center justify-between gap-2 rounded border border-slate-100 bg-white px-2 py-1 text-[11px]">
                <span class="min-w-0 truncate text-slate-700">{{ d.fileName }}</span>
                <span class="flex shrink-0 gap-1">
                  <button type="button" class="rounded p-0.5 text-slate-400 hover:text-sky-600" title="เปิด"
                    :disabled="actions.docBusy.value === d.id" @click="actions.openDoc(d, 'open')"><i class="pi pi-eye text-[11px]" /></button>
                  <button type="button" class="rounded p-0.5 text-slate-400 hover:text-sky-600" title="ดาวน์โหลด"
                    :disabled="actions.docBusy.value === d.id" @click="actions.openDoc(d, 'download')"><i class="pi pi-download text-[11px]" /></button>
                  <button type="button" class="rounded p-0.5 text-slate-400 hover:text-rose-600" title="ลบ"
                    :disabled="actions.docBusy.value === d.id" @click="actions.deleteDoc(d)"><i class="pi pi-trash text-[11px]" /></button>
                </span>
              </li>
            </ul>
          </div>
        </div>

        <div v-else-if="panel === 'prepare_quote' && actions.prepareForm.value" class="rounded border border-slate-200 bg-slate-50 p-3" data-testid="notice-form">
          <p class="mb-1 text-xs font-medium text-slate-600">จัดทำใบเตือนต่ออายุ InsureHub</p>
          <p class="mb-3 text-[11px] text-slate-500">
            กรอกราคาตามใบเตือนที่ได้จากบริษัทประกัน
            <span v-if="carrierDocs.length">(อ้างอิง: {{ carrierDocs[0].fileName }})</span>
            — ระบบออกเลขที่ใบเตือนให้อัตโนมัติ และใช้ข้อมูลบริษัท/ช่องทางชำระเงินจากตั้งค่าหน่วยงาน
          </p>
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <label class="text-[11px] text-slate-500">เบี้ยรวม (บาท) *
              <input v-model.number="actions.prepareForm.value.annualPremium" type="number" min="0" step="0.01" data-testid="notice-annual"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs" /></label>
            <label class="text-[11px] text-slate-500">ส่วนลด (บาท)
              <input v-model.number="actions.prepareForm.value.discount" type="number" min="0" step="0.01"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs" /></label>
            <label class="text-[11px] text-slate-500">ทุนประกัน (บาท)
              <input v-model.number="actions.prepareForm.value.coverage" type="number" min="0" data-testid="notice-coverage"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs" /></label>
          </div>
          <div class="mt-3 rounded border border-slate-200 bg-white p-2">
            <div class="mb-2 flex flex-wrap items-center gap-3 text-[11px] text-slate-600">
              <label class="inline-flex items-center gap-1">
                <input v-model="actions.prepareForm.value.autoSplit" type="checkbox" class="accent-sky-600" /> แยกเบี้ยสุทธิ/อากร/VAT อัตโนมัติ
              </label>
              <select v-if="actions.prepareForm.value.autoSplit" v-model.number="actions.prepareForm.value.formula"
                class="rounded border border-slate-300 px-2 py-0.5 text-[11px]">
                <option v-for="f in TAX_FORMULAS" :key="f.value" :value="f.value">{{ f.label }}</option>
              </select>
            </div>
            <div class="grid grid-cols-3 gap-2">
              <label class="text-[11px] text-slate-500">เบี้ยสุทธิ
                <input v-model.number="actions.prepareForm.value.netPremium" type="number" step="0.01" :disabled="actions.prepareForm.value.autoSplit"
                  data-testid="notice-net" class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs disabled:bg-slate-50" /></label>
              <label class="text-[11px] text-slate-500">อากรแสตมป์
                <input v-model.number="actions.prepareForm.value.dutyStamp" type="number" step="0.01" :disabled="actions.prepareForm.value.autoSplit"
                  class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs disabled:bg-slate-50" /></label>
              <label class="text-[11px] text-slate-500">ภาษีมูลค่าเพิ่ม
                <input v-model.number="actions.prepareForm.value.vat" type="number" step="0.01" :disabled="actions.prepareForm.value.autoSplit"
                  class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs disabled:bg-slate-50" /></label>
            </div>
            <p v-if="splitMismatch" class="mt-1 text-[10px] text-rose-600">เบี้ยสุทธิ + อากร + VAT ต้องเท่ากับเบี้ยรวม</p>
          </div>
          <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
            <label class="text-[11px] text-slate-500">งวดการชำระเบี้ย
              <select v-model="actions.prepareForm.value.premiumMode"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs">
                <option v-for="m in PREMIUM_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select></label>
            <div class="text-[11px] text-slate-500">วันครบกำหนดชำระ
              <DateInput v-model="actions.prepareForm.value.dueDate" class="mt-0.5" /></div>
            <div class="text-[11px] text-slate-500">ราคานี้ใช้ได้ถึง
              <DateInput v-model="actions.prepareForm.value.validUntil" class="mt-0.5" /></div>
          </div>
          <label class="mt-2 block text-[11px] text-slate-500">หมายเหตุบนใบเตือน
            <textarea v-model="actions.prepareForm.value.note" rows="2" maxlength="2000"
              placeholder="เช่น เบี้ยปรับขึ้นตามอัตราใหม่ของบริษัท / มีส่วนลดประวัติดี"
              class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea></label>
          <div v-if="Object.keys(actions.prepareErrors.value).length" class="mt-2 rounded bg-rose-50 px-2 py-1 text-[11px] text-rose-700">
            <div v-for="(m, k) in actions.prepareErrors.value" :key="k">{{ m[0] }}</div>
          </div>
          <div class="mt-3 flex gap-2">
            <button type="button" class="rounded bg-sky-600 px-3 py-1.5 text-xs text-white disabled:opacity-50" data-testid="notice-submit"
              :disabled="actions.preparing.value || splitMismatch" @click="doPrepare(false)">
              {{ actions.preparing.value ? 'กำลังสร้าง…' : 'สร้างใบเตือน PDF + บันทึก' }}</button>
            <button type="button" class="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-600 disabled:opacity-50"
              :disabled="actions.preparing.value || splitMismatch" @click="doPrepare(true)">สร้าง + ดาวน์โหลด</button>
          </div>
        </div>

        <!-- Send quote to customer -->
        <div v-else-if="panel === 'send_quote'" class="rounded border border-slate-200 bg-slate-50 p-3">
          <p class="mb-2 text-xs font-medium text-slate-600">ส่งใบเสนอราคาให้ลูกค้า</p>
          <input v-model="actions.sendForm.value.to" placeholder="อีเมลลูกค้า"
            class="mb-2 w-full rounded border border-slate-300 px-2 py-1 text-xs" />
          <textarea v-model="actions.sendForm.value.message" rows="4"
            class="mb-2 w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea>
          <div class="flex gap-2">
            <button type="button" class="rounded bg-sky-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
              :disabled="actions.saving.value" @click="doSend">ส่งอีเมลให้ลูกค้า</button>
            <button type="button" class="rounded bg-emerald-50 px-3 py-1.5 text-xs text-emerald-700"
              :disabled="actions.saving.value" @click="doManual('quote_sent')">ส่งเองแล้ว — บันทึก</button>
          </div>
        </div>

      </div>

      <div class="mb-4 grid gap-4 md:grid-cols-5">
        <div class="rounded-lg border border-slate-200 bg-white p-4 md:col-span-3" data-testid="last-year-policy">
          <div class="mb-3 flex items-start justify-between gap-2">
            <div>
              <h2 class="text-sm font-semibold text-slate-700">กรมธรรม์เดิม (ปีที่กำลังต่ออายุ)</h2>
              <p class="text-[11px] text-slate-400">ใช้อธิบายลูกค้าว่าปีนี้มีอะไรบ้าง และเทียบกับใบเตือนปีหน้า</p>
            </div>
            <div class="flex shrink-0 gap-1.5">
              <button v-if="detailPolicy?.renewedFrom" type="button"
                class="rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50"
                @click="router.push({ name: 'policy-renewal', params: { id: detailPolicy.renewedFrom.id } })">
                <i class="pi pi-history text-[10px]" /> ปีก่อนหน้า
              </button>
              <button type="button" class="rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50" @click="openFullEdit">
                <i class="pi pi-external-link text-[10px]" /> เปิดกรมธรรม์
              </button>
            </div>
          </div>
          <dl class="grid grid-cols-[110px_1fr] gap-y-1.5 text-xs sm:grid-cols-[110px_1fr_110px_1fr]">
            <dt class="text-slate-400">บริษัทประกัน</dt><dd class="text-slate-700">{{ row.carrierName || detailPolicy?.carrierName || '—' }}</dd>
            <dt class="text-slate-400">ผลิตภัณฑ์</dt>
            <dd class="text-slate-700">{{ row.productName || detailPolicy?.productName || '—' }}
              <div v-if="row.productSubCategory || row.productCategory" class="text-[10px] text-slate-400">{{ [row.productCategory, row.productSubCategory].filter(Boolean).join(' › ') }}</div></dd>
            <dt class="text-slate-400">ระยะเวลาคุ้มครอง</dt><dd class="text-slate-700">{{ fmtDate(detailPolicy?.effectiveDate) || '—' }} – {{ fmtDate(detailPolicy?.expiryDate ?? row.expiryDate) }}</dd>
            <dt class="text-slate-400">ทะเบียน / รถ</dt><dd class="text-slate-700">{{ row.motorLicenseNo || '—' }}<span v-if="row.motorVehicle" class="text-slate-400"> · {{ row.motorVehicle }}</span></dd>
            <dt class="text-slate-400">ทุนประกัน</dt><dd class="text-slate-700">{{ money(lastYearPremium.coverage) }}</dd>
            <dt class="text-slate-400">การชำระเบี้ย</dt><dd class="text-slate-700">{{ PREMIUM_MODES.find((m) => m.value === detailPolicy?.premiumMode)?.label ?? '—' }}</dd>
          </dl>
          <table class="mt-3 w-full text-xs">
            <tbody class="divide-y divide-slate-100">
              <tr><td class="py-1 text-slate-500">เบี้ยสุทธิ</td><td class="py-1 text-right text-slate-700">{{ money(lastYearPremium.net) }}</td></tr>
              <tr><td class="py-1 text-slate-500">อากรแสตมป์</td><td class="py-1 text-right text-slate-700">{{ money(lastYearPremium.duty) }}</td></tr>
              <tr><td class="py-1 text-slate-500">ภาษีมูลค่าเพิ่ม</td><td class="py-1 text-right text-slate-700">{{ money(lastYearPremium.vat) }}</td></tr>
              <tr class="font-semibold"><td class="py-1 text-slate-700">เบี้ยรวม</td><td class="py-1 text-right text-slate-900">{{ money(lastYearPremium.annual) }}</td></tr>
              <tr v-if="lastYearPremium.discount"><td class="py-1 text-slate-500">ส่วนลด</td><td class="py-1 text-right text-emerald-700">{{ money(lastYearPremium.discount) }}</td></tr>
            </tbody>
          </table>
          <div v-if="detailPolicy?.riders?.length" class="mt-3">
            <div class="mb-1 text-[11px] font-medium text-slate-500">สัญญาเพิ่มเติม</div>
            <ul class="space-y-0.5 text-xs">
              <li v-for="(r, i) in detailPolicy.riders" :key="r.id ?? i" class="flex justify-between gap-2">
                <span class="text-slate-700">{{ r.name || '—' }}</span><span class="text-slate-500">{{ money(r.premium) }}</span>
              </li>
            </ul>
          </div>
          <div class="mt-3">
            <div class="mb-1 text-[11px] font-medium text-slate-500">ประวัติสลักหลัง ({{ endorsements.length }})</div>
            <ul v-if="endorsements.length" class="space-y-1 text-xs" data-testid="last-year-endorsements">
              <li v-for="e in endorsements" :key="e.id" :class="['flex items-center gap-2', e.voidedAt ? 'text-slate-400 line-through' : 'text-slate-700']">
                <span :class="['rounded px-1 text-[10px] font-semibold', e.voidedAt ? 'bg-slate-100' : 'bg-amber-50 text-amber-700']">${{ e.seq ?? '?' }}</span>
                <span class="whitespace-nowrap">{{ fmtDate(String((e.payload ?? {}).effectiveDate ?? '')) || fmtDate(e.occurredAt) }}</span>
                <span class="whitespace-nowrap">{{ t(`endorsement.types.${e.type.replace('endorsement.', '')}`) }}</span>
                <span class="min-w-0 truncate">{{ endorsementReason(e) }}</span>
              </li>
            </ul>
            <p v-else class="text-[11px] text-slate-400">ไม่มีสลักหลัง</p>
          </div>
          <div class="mt-3">
            <div class="mb-1 text-[11px] font-medium text-slate-500">ไฟล์กรมธรรม์</div>
            <ul v-if="policyFiles.length" class="space-y-1">
              <li v-for="f in policyFiles" :key="f.id" class="flex items-center justify-between gap-2 rounded border border-slate-100 px-2 py-1 text-[11px]">
                <span class="min-w-0 truncate text-slate-700">{{ f.fileName }}</span>
                <button type="button" class="text-slate-400 hover:text-sky-600" title="ดาวน์โหลด" @click="downloadFile(f)"><i class="pi pi-download text-[11px]" /></button>
              </li>
            </ul>
            <p v-else class="text-[11px] text-slate-400">ยังไม่มีไฟล์กรมธรรม์</p>
          </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 md:col-span-2" data-testid="notice-comparison">
          <h2 class="text-sm font-semibold text-slate-700">เทียบกับใบเตือนต่ออายุ</h2>
          <template v-if="actions.latestNotice.value">
            <p class="mb-2 text-[11px] text-slate-400">
              {{ actions.latestNotice.value.noticeNo }} · {{ fmtDateTime(actions.latestNotice.value.createdAt) }}
              <span v-if="actions.latestNotice.value.createdByName">· {{ actions.latestNotice.value.createdByName }}</span>
            </p>
            <table class="w-full text-xs">
              <thead class="text-[10px] text-slate-400">
                <tr><th class="py-1 text-left font-normal"></th><th class="py-1 text-right font-normal">ปีนี้</th><th class="py-1 text-right font-normal">ใบเตือน</th><th class="py-1 text-right font-normal">ส่วนต่าง</th></tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="c in comparison" :key="c.key" :class="c.strong ? 'font-semibold' : ''" :data-testid="`compare-${c.key}`">
                  <td class="py-1.5 text-slate-600">{{ c.label }}</td>
                  <td class="py-1.5 text-right text-slate-500">{{ money(c.last) }}</td>
                  <td class="py-1.5 text-right text-slate-900">{{ money(c.now) }}</td>
                  <td :class="['py-1.5 text-right', c.cls]">
                    <template v-if="c.diff !== null && c.diff !== 0">{{ c.diff > 0 ? '+' : '' }}{{ money(c.diff) }}<div v-if="c.pct !== null" class="text-[10px]">{{ c.pct > 0 ? '+' : '' }}{{ c.pct }}%</div></template>
                    <template v-else>—</template>
                  </td>
                </tr>
              </tbody>
            </table>
            <p v-if="actions.latestNotice.value.note" class="mt-2 rounded bg-slate-50 px-2 py-1 text-[11px] text-slate-600 whitespace-pre-line">{{ actions.latestNotice.value.note }}</p>
          </template>
          <div v-else class="mt-2 rounded border border-dashed border-slate-200 p-3 text-center text-[11px] text-slate-400">
            ยังไม่มีใบเตือนต่ออายุ
            <div class="mt-2">
              <button type="button" class="rounded bg-sky-50 px-2.5 py-1 text-[11px] text-sky-700 hover:bg-sky-100" @click="openPanel('prepare_quote')">
                <i class="pi pi-file-edit text-[10px]" /> จัดทำใบเตือน
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Summary + timeline grid ------------------------------------------- -->
      <div class="grid gap-4 md:grid-cols-2">
        <!-- Read-only summary -->
        <div class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="mb-3 text-sm font-semibold text-slate-700">ข้อมูลกรมธรรม์</h2>
          <dl class="grid grid-cols-2 gap-y-2 text-xs">
            <dt class="text-slate-400">ลูกค้า</dt><dd class="text-slate-700">{{ row.customerName || '—' }}</dd>
            <dt class="text-slate-400">โทรศัพท์</dt><dd class="text-slate-700">{{ row.customerPhone || '—' }}</dd>
            <dt class="text-slate-400">อีเมล</dt><dd class="break-all text-slate-700">{{ row.customerEmail || '—' }}</dd>
            <dt class="text-slate-400">ตัวแทน</dt><dd class="text-slate-700">{{ row.agentName || '—' }}</dd>
            <dt class="text-slate-400">บริษัทประกัน</dt><dd class="text-slate-700">{{ row.carrierName || '—' }}</dd>
            <dt class="text-slate-400">สินค้า</dt><dd class="text-slate-700">{{ row.productName || detailPolicy?.productName || '—' }}</dd>
            <dt class="text-slate-400">ทะเบียนรถ</dt><dd class="text-slate-700">{{ row.motorLicenseNo || '—' }}</dd>
            <dt class="text-slate-400">ทุนประกัน</dt><dd class="text-slate-700">{{ money(coverage) }}</dd>
            <dt class="text-slate-400">เบี้ยรายปี</dt><dd class="text-slate-700">{{ money(row.annualPremium) }}</dd>
            <dt class="text-slate-400">ปี/งวด</dt><dd class="text-slate-700"><PolicyPeriodBadge :label="detailPolicy?.periodLabel ?? row.periodLabel" /></dd>
            <dt class="text-slate-400">วันหมดอายุ</dt>
            <dd class="text-slate-700">{{ fmtDate(row.expiryDate) }}
              <span :class="row.daysRemaining < 30 ? 'text-rose-600' : 'text-slate-400'">
                ({{ row.daysRemaining }} วัน)</span></dd>
          </dl>
          <div class="mt-3 border-t border-slate-100 pt-2 text-xs">
            <div class="flex justify-between">
              <span class="text-slate-400">ชำระแล้ว</span>
              <span class="text-slate-700">{{ money(paidTotal) }} ({{ payments.length }} งวด)</span>
            </div>
          </div>
        </div>

        <!-- Timeline + documents -->
        <div class="space-y-4">
          <div class="rounded-lg border border-slate-200 bg-white p-4" data-testid="followup-thread">
            <div class="mb-3 flex items-center justify-between">
              <h2 class="text-sm font-semibold text-slate-700">ประวัติการติดตาม <span class="font-normal text-slate-400">({{ actions.followups.value.length }})</span></h2>
              <button type="button" class="rounded bg-sky-50 px-2 py-0.5 text-[11px] text-sky-700 hover:bg-sky-100" @click="openFollowup()">
                <i class="pi pi-plus text-[9px]" /> บันทึก
              </button>
            </div>
            <ol v-if="actions.followups.value.length" class="space-y-3">
              <li v-for="(f, i) in actions.followups.value" :key="f.id"
                :class="['border-l-2 pl-3', f.result === 'not_renewing' ? 'border-rose-300' : f.result === 'interested' ? 'border-emerald-300' : 'border-slate-200']">
                <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                  <span :class="['inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-medium', followupResultMeta(f.result).badgeClass]">
                    <i :class="['pi', followupResultMeta(f.result).icon, 'text-[9px]']" /> {{ followupResultMeta(f.result).label }}
                  </span>
                  <span class="text-slate-500"><i :class="['pi', followupChannelMeta(f.channel).icon, 'text-[9px]']" /> {{ followupChannelMeta(f.channel).label }}</span>
                  <span class="text-slate-400">· {{ fmtDateTime(f.createdAt) }}<span v-if="f.byUserName"> · {{ f.byUserName }}</span></span>
                </div>
                <div v-if="f.reasonLabel" class="mt-0.5 text-xs text-rose-700">เหตุผล: {{ f.reasonLabel }}<span v-if="f.reasonNote"> — {{ f.reasonNote }}</span></div>
                <div v-if="f.note" class="mt-0.5 whitespace-pre-line text-xs text-slate-700">{{ f.note }}</div>
                <div v-if="f.nextFollowupAt" :class="['mt-0.5 text-[11px]', i === 0 && isFollowupOverdue(f.nextFollowupAt) ? 'font-medium text-rose-600' : 'text-slate-500']">
                  <i class="pi pi-calendar text-[9px]" /> นัดครั้งถัดไป {{ fmtDate(f.nextFollowupAt) }}
                  <span v-if="i === 0 && isFollowupOverdue(f.nextFollowupAt)">(ถึงกำหนดแล้ว)</span>
                </div>
              </li>
            </ol>
            <p v-else class="text-xs text-slate-400">{{ actions.followupsLoading.value ? 'กำลังโหลด…' : 'ยังไม่มีการติดตาม' }}</p>
          </div>

          <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">ประวัติการดำเนินการ</h2>
            <ul v-if="events.length" class="space-y-2">
              <li v-for="e in events" :key="e.id" class="flex gap-2 text-xs">
                <span class="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full bg-sky-400" />
                <div>
                  <div class="text-slate-700">{{ eventLabel(e.type) }}</div>
                  <div class="text-[10px] text-slate-400">{{ fmtDate(e.at) }}</div>
                </div>
              </li>
            </ul>
            <p v-else class="text-xs text-slate-400">ยังไม่มีประวัติ</p>
          </div>

          <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">เอกสารใบเตือน / ใบเสนอราคา</h2>
            <div v-if="actions.docsLoading.value" class="text-xs text-slate-400">กำลังโหลด…</div>
            <ul v-else-if="actions.docs.value.length" class="space-y-1.5">
              <li v-for="d in actions.docs.value" :key="d.id"
                class="flex items-center justify-between gap-2 rounded border border-slate-100 px-2 py-1.5 text-xs">
                <div class="min-w-0">
                  <div class="truncate text-slate-700">{{ d.fileName }}</div>
                  <div class="text-[10px] text-slate-400">{{ actions.docTypeLabel(d.type) }}</div>
                </div>
                <div class="flex shrink-0 gap-1">
                  <button type="button" class="rounded p-1 text-slate-400 hover:text-sky-600" title="เปิด"
                    :disabled="actions.docBusy.value === d.id" @click="actions.openDoc(d, 'open')"><i class="pi pi-eye text-[11px]" /></button>
                  <button type="button" class="rounded p-1 text-slate-400 hover:text-sky-600" title="ดาวน์โหลด"
                    :disabled="actions.docBusy.value === d.id" @click="actions.openDoc(d, 'download')"><i class="pi pi-download text-[11px]" /></button>
                  <button v-if="d.type === 'renewal_quote_insurehub'" type="button" class="rounded p-1 text-slate-400 hover:text-amber-600" title="สร้างใหม่"
                    :disabled="actions.docBusy.value === d.id" @click="onRegenerate(d)"><i class="pi pi-refresh text-[11px]" /></button>
                  <button type="button" class="rounded p-1 text-slate-400 hover:text-rose-600" title="ลบ"
                    :disabled="actions.docBusy.value === d.id" @click="actions.deleteDoc(d)"><i class="pi pi-trash text-[11px]" /></button>
                </div>
              </li>
            </ul>
            <p v-else class="text-xs text-slate-400">ยังไม่มีเอกสาร</p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
