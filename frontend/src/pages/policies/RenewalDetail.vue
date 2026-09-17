<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchExpiringSoon, type ExpiringPolicy, type RenewalStage,
} from '../../api/reports'
import {
  fetchPolicy, fetchPolicyPayments, fetchPolicyEvents,
  type PolicyPaymentRow, type PolicyEventRow,
} from '../../api/policies'
import { ApiError } from '../../api/client'
import { fmtDate } from '../../util/dateFormat'
import {
  renewalStageMeta, renewalNextAction, RENEWAL_PIPELINE,
} from '../../utils/renewalStage'
import { useRenewalActions } from '../../composables/useRenewalActions'

const route = useRoute()
const router = useRouter()
const policyId = computed(() => String(route.params.id))

// ── Core state ─────────────────────────────────────────────────────────────
const row = ref<ExpiringPolicy | null>(null)
const coverage = ref<number | null>(null)
const payments = ref<PolicyPaymentRow[]>([])
const events = ref<PolicyEventRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const actions = useRenewalActions(row)

// Which inline action panel is expanded. Defaults to the stage's next step.
type PanelKey = 'contact' | 'request_quote' | 'upload_quote' | 'prepare_quote' | 'send_quote' | 'decline' | null
const panel = ref<PanelKey>(null)

const stageMeta = computed(() => renewalStageMeta(row.value?.renewalStage))
const nextAction = computed(() => renewalNextAction(row.value?.renewalStage))

/** Map a next-action token to the inline panel it opens. */
const ACTION_PANEL: Record<string, PanelKey> = {
  contact: 'contact', request_quote: 'request_quote', upload_quote: 'upload_quote',
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
    payments.value = pay.data
    events.value = ev.data
    // Default the open panel to the recommended next step.
    panel.value = nextAction.value ? ACTION_PANEL[nextAction.value.action] ?? null : null
    if (panel.value) primePanel(panel.value)
    void actions.loadDocs()
  } catch (e: unknown) {
    error.value = e instanceof ApiError ? e.message : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

/** Prime whichever panel just opened (loads carrier emails / builds forms). */
function primePanel(key: PanelKey): void {
  if (key === 'request_quote') actions.initQuoteForm()
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
async function doContact() { if (await actions.submitContact()) { panel.value = null; await afterSuccess() } }
async function doNotice() { if (await actions.submitNotice()) await afterSuccess() }
async function doRequest(mode: 'system' | 'manual') { if (await actions.submitQuoteRequest(mode)) { panel.value = null; await afterSuccess() } }
async function doPrepare(dl: boolean) { if (await actions.submitPrepareQuote(dl)) { panel.value = null; await afterSuccess() } }
async function doSend() { if (await actions.submitSendQuote()) { panel.value = null; await afterSuccess() } }
async function doDecline() { if (await actions.submitDecline()) { panel.value = null; await afterSuccess() } }
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
function openFullEdit() {
  const href = router.resolve({ name: 'policy-edit', params: { id: policyId.value } }).href
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
  }
  return map[type] ?? type
}
const money = (n: number | null | undefined) =>
  n == null ? '—' : n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const paidTotal = computed(() => payments.value.reduce((s, p) => s + (p.amount || 0), 0))

// ── Prepare-form helpers ────────────────────────────────────────────────────
// The quotation form stores riders/conditions/exclusions as string[]; the UI
// edits them as one-item-per-line textareas. These computed proxies convert
// both ways, guarding against a null form (panel closed).
const PREMIUM_MODES: { value: 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single'; label: string }[] = [
  { value: 'annual', label: 'รายปี' },
  { value: 'semiannual', label: 'ราย 6 เดือน' },
  { value: 'quarterly', label: 'รายไตรมาส' },
  { value: 'monthly', label: 'รายเดือน' },
  { value: 'single', label: 'จ่ายครั้งเดียว' },
]
function listProxy(key: 'riders' | 'conditions' | 'exclusions') {
  return computed<string>({
    get: () => (actions.prepareForm.value?.[key] ?? []).join('\n'),
    set: (v: string) => {
      if (actions.prepareForm.value) {
        actions.prepareForm.value[key] = v.split('\n').map((x) => x.trim()).filter(Boolean)
      }
    },
  })
}
const ridersText = listProxy('riders')
const conditionsText = listProxy('conditions')
const exclusionsText = listProxy('exclusions')

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
        <p class="text-xs text-slate-500">
          กรมธรรม์ {{ row?.policyNo || row?.applicationNo || '—' }}
          <span v-if="row" class="mx-1">·</span>
          <span v-if="row" :class="['inline-flex items-center gap-1 rounded px-1.5 py-0.5', stageMeta.badgeClass]">
            <i :class="['pi', stageMeta.icon, 'text-[10px]']" /> {{ stageMeta.label }}
          </span>
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
        <div v-if="isDeclined" class="mt-3 rounded bg-rose-50 px-2 py-1 text-center text-xs text-rose-700">
          <i class="pi pi-times-circle text-[10px]" /> ลูกค้าปฏิเสธการต่ออายุ
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
          <button type="button"
            :class="['rounded px-2.5 py-1.5 text-xs', panel === 'contact' ? 'bg-slate-200' : 'bg-slate-50 hover:bg-slate-100']"
            @click="openPanel('contact')">
            <i class="pi pi-phone text-[10px]" /> บันทึกการติดต่อ
          </button>
          <button type="button" class="rounded bg-slate-50 px-2.5 py-1.5 text-xs hover:bg-slate-100"
            :disabled="actions.saving.value" @click="doNotice">
            <i class="pi pi-bell text-[10px]" /> ส่งแจ้งเตือน
          </button>
          <button v-if="!isDeclined" type="button"
            :class="['rounded px-2.5 py-1.5 text-xs', panel === 'decline' ? 'bg-rose-100 text-rose-700' : 'bg-rose-50 text-rose-600 hover:bg-rose-100']"
            @click="openPanel('decline')">
            <i class="pi pi-times text-[10px]" /> ลูกค้าปฏิเสธ
          </button>
        </div>

        <!-- Manual advance -->
        <div class="mb-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-2">
          <span class="text-[10px] text-slate-400">ทำนอกระบบแล้ว? ทำเครื่องหมายขั้นตอน:</span>
          <button v-for="st in (['contacted','quote_requested','quote_received','quote_prepared','quote_sent','renewed'] as const)"
            :key="st" type="button"
            class="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-500 hover:bg-slate-50"
            :disabled="actions.saving.value" @click="doManual(st)">
            {{ renewalStageMeta(st).label }}
          </button>
        </div>

        <!-- ─── Inline panels ─────────────────────────────────────────────── -->
        <!-- Contact -->
        <div v-if="panel === 'contact'" class="rounded border border-slate-200 bg-slate-50 p-3">
          <div class="mb-2 flex gap-2">
            <select v-model="actions.contactForm.value.channel"
              class="rounded border border-slate-300 px-2 py-1 text-xs">
              <option value="phone">โทรศัพท์</option>
              <option value="line">LINE</option>
              <option value="email">อีเมล</option>
              <option value="inperson">พบตัว</option>
              <option value="other">อื่นๆ</option>
            </select>
            <input v-model="actions.contactForm.value.note" placeholder="บันทึกย่อ (ไม่บังคับ)"
              class="flex-1 rounded border border-slate-300 px-2 py-1 text-xs" />
          </div>
          <button type="button" class="rounded bg-sky-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
            :disabled="actions.saving.value" @click="doContact">บันทึกการติดต่อ</button>
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
          <p class="mb-2 text-xs text-slate-600">อัปโหลดไฟล์ใบเสนอราคาที่ได้รับจากบริษัทประกัน (PDF / รูปภาพ)</p>
          <button type="button" class="rounded bg-sky-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
            :disabled="actions.saving.value" @click="triggerUpload">
            <i class="pi pi-upload text-[10px]" /> เลือกไฟล์
          </button>
          <input ref="uploadInput" type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp" @change="onFile" />
        </div>

        <!-- Prepare InsureHub quote -->
        <div v-else-if="panel === 'prepare_quote' && actions.prepareForm.value" class="rounded border border-slate-200 bg-slate-50 p-3">
          <p class="mb-3 text-xs font-medium text-slate-600">จัดทำใบเสนอราคา InsureHub</p>

          <!-- Figures -->
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <label class="text-[11px] text-slate-500">ทุนประกัน (บาท)
              <input v-model.number="actions.prepareForm.value.coverageAmount" type="number" min="0"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs" /></label>
            <label class="text-[11px] text-slate-500">เบี้ยประกัน (บาท)
              <input v-model.number="actions.prepareForm.value.annualPremium" type="number" min="0"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs" /></label>
            <label class="text-[11px] text-slate-500">งวดการชำระเบี้ย
              <select v-model="actions.prepareForm.value.premiumMode"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs">
                <option v-for="m in PREMIUM_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select></label>
            <label class="text-[11px] text-slate-500">ระยะเวลาคุ้มครอง (ปี)
              <input v-model.number="actions.prepareForm.value.coveragePeriodYears" type="number" min="0"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs" /></label>
            <label class="text-[11px] text-slate-500">ระยะเวลาชำระเบี้ย (ปี)
              <input v-model.number="actions.prepareForm.value.paymentPeriodYears" type="number" min="0"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs" /></label>
          </div>

          <!-- Summary -->
          <label class="mt-2 block text-[11px] text-slate-500">สรุปข้อเสนอ / รายละเอียดความคุ้มครอง
            <textarea v-model="actions.prepareForm.value.proposalSummary" rows="2"
              placeholder="สรุปแผนความคุ้มครองที่เสนอให้ลูกค้า"
              class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea></label>

          <!-- Lists (one item per line) -->
          <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
            <label class="text-[11px] text-slate-500">ความคุ้มครองเพิ่มเติม (สัญญาเพิ่มเติม)
              <textarea v-model="ridersText" rows="3" placeholder="บรรทัดละ 1 รายการ"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea></label>
            <label class="text-[11px] text-slate-500">เงื่อนไข
              <textarea v-model="conditionsText" rows="3" placeholder="บรรทัดละ 1 รายการ"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea></label>
            <label class="text-[11px] text-slate-500">ข้อยกเว้น
              <textarea v-model="exclusionsText" rows="3" placeholder="บรรทัดละ 1 รายการ"
                class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea></label>
          </div>

          <!-- Next steps -->
          <label class="mt-2 block text-[11px] text-slate-500">ขั้นตอนถัดไป
            <textarea v-model="actions.prepareForm.value.nextSteps" rows="2"
              placeholder="เช่น ยืนยันการต่ออายุภายในวันที่ ... และชำระเบี้ยผ่าน ..."
              class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs"></textarea></label>

          <div class="mt-3 flex gap-2">
            <button type="button" class="rounded bg-sky-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
              :disabled="actions.preparing.value" @click="doPrepare(false)">
              {{ actions.preparing.value ? 'กำลังสร้าง…' : 'สร้าง PDF + บันทึก' }}</button>
            <button type="button" class="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-600 disabled:opacity-50"
              :disabled="actions.preparing.value" @click="doPrepare(true)">สร้าง + ดาวน์โหลด</button>
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

        <!-- Decline -->
        <div v-else-if="panel === 'decline'" class="rounded border border-rose-200 bg-rose-50 p-3">
          <p class="mb-2 text-xs text-rose-700">บันทึกว่าลูกค้าไม่ต่ออายุ</p>
          <input v-model="actions.declineReason.value" placeholder="เหตุผล (ไม่บังคับ)"
            class="mb-2 w-full rounded border border-rose-200 px-2 py-1 text-xs" />
          <button type="button" class="rounded bg-rose-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
            :disabled="actions.saving.value" @click="doDecline">ยืนยันการปฏิเสธ</button>
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
            <dt class="text-slate-400">สินค้า</dt><dd class="text-slate-700">{{ row.productName || '—' }}</dd>
            <dt class="text-slate-400">ทะเบียนรถ</dt><dd class="text-slate-700">{{ row.motorLicenseNo || '—' }}</dd>
            <dt class="text-slate-400">ทุนประกัน</dt><dd class="text-slate-700">{{ money(coverage) }}</dd>
            <dt class="text-slate-400">เบี้ยรายปี</dt><dd class="text-slate-700">{{ money(row.annualPremium) }}</dd>
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
            <h2 class="mb-3 text-sm font-semibold text-slate-700">เอกสารใบเสนอราคา</h2>
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
