<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DateInput from '../../components/DateInput.vue'
import { toIsoDate } from '../../util/dateFormat'
import { useRouter } from 'vue-router'
import { useEmailApi, type DeliveryStatus } from '../../composables/useEmailApi'
import {
  useCarrierContactsStore,
  DEPARTMENT_LABELS,
  isAutoSeeded,
  type CarrierContactGroup,
  type ContactDepartment,
  type InsuranceType,
} from '../../stores/carrierContacts'
import {
  useEmailTemplatesStore,
  renderTemplate,
  TEMPLATE_VARIABLES,
  type EmailTemplate as StoredEmailTemplate,
} from '../../stores/emailTemplates'
import { useQuotation, type Quotation, type QuotationExtraction } from '../../composables/useQuotation'
import { useQuotationPdf } from '../../composables/useQuotationPdf'
import {
  useCaseStatus,
  type CaseStatus,
  type StatusTransition,
} from '../../composables/useCaseStatus'

const router = useRouter()
const emailApi = useEmailApi()
const contactsStore = useCarrierContactsStore()
const templatesStore = useEmailTemplatesStore()

/**
 * Best-effort: derive the insurance type from a case's product name.
 * Groups can then be filtered to only those that serve this type.
 * Empty (unknown) means "no preference" — show every group for the carrier.
 */
function inferInsuranceType(productName: string): InsuranceType | undefined {
  const p = productName.toLowerCase()

  // ── Group covers (must match BEFORE the individual life/health rules) ─
  if (/group health|กลุ่มสุขภาพ|ประกันกลุ่ม.*สุขภาพ/.test(p)) return 'group_health'
  if (/group life|กลุ่ม\s*ชีวิต|ประกันกลุ่ม.*ชีวิต|พนักงาน.*ชีวิต/.test(p)) return 'group_life'

  // ── Health / CI / PA (CI before health so "critical illness" doesn't lose) ─
  if (/critical illness|ci\b|โรคร้าย/.test(p)) return 'ci'
  if (/health|สุขภาพ|เฮลธ์/.test(p)) return 'health'
  if (/\bpa\b|อุบัติเหตุ/.test(p)) return 'pa'

  // ── Property family — CAR / IAR / construction. MUST be checked BEFORE
  // motor so the substring "car" inside CAR doesn't hijack into motor. ────
  if (/\bcar\b|\biar\b|contractor|construction|industrial|งานก่อสร้าง|วิศวกรรม|โรงงาน/.test(p)) return 'fire'

  // ── Marine / cargo (before motor so "cargo car" doesn't misroute) ─────
  if (/marine|cargo|ขนส่ง|สินค้าทางทะเล/.test(p)) return 'marine'

  // ── Motor: voluntary vs compulsory ────────────────────────────────────
  if (/พ\.?ร\.?บ\.?|cmi|compulsory motor|พรบ/.test(p)) return 'cmi'
  if (/motor|รถยนต์|รถยนต|รถ\b/.test(p)) return 'motor'

  // ── Other non-motor non-life ──────────────────────────────────────────
  if (/fire|อัคคีภัย|ทรัพย์สิน/.test(p)) return 'fire'
  if (/travel|เดินทาง/.test(p)) return 'travel'
  if (/professional|liability|วิชาชีพ/.test(p)) return 'liability'
  if (/pet|สัตว์เลี้ยง/.test(p)) return 'pet'

  // ── Life — individual (savings / endowment / annuity / term / whole) ──
  if (/life|ชีวิต|บำนาญ|ตลอดชีพ|ยูนิตลิงก์|สะสมทรัพย์|ชั่วระยะเวลา|term/.test(p)) return 'life'

  return undefined
}
const quotationApi = useQuotation()
const quotationPdf = useQuotationPdf()
const caseStatusApi = useCaseStatus()

// ─────────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────────

interface TimelineEntry {
  status: CaseStatus
  at: string
  byUser: string
  note?: string
}

interface NoteEntry {
  id: string
  byUser: string
  at: string
  body: string
}

interface DocumentEntry {
  id: string
  name: string
  uploadedAt: string
  size: string
}

type ThreadStatus = 'waiting' | 'replied' | 'resolved' | 'overdue'

type Sentiment = 'positive' | 'neutral' | 'needs_info' | 'rejecting'

interface AISummary {
  oneLiner: string
  sentiment: Sentiment
  riskScore: 1 | 2 | 3 | 4 | 5
  actions: { id: string; label: string; done: boolean }[]
  suggestedReplyTemplate: string | null // EmailTemplateKey or null
  suggestedReplyHint: string
  keyEntities: { label: string; value: string }[]
  generatedAt: string
}

interface EmailResponse {
  id: string
  receivedAt: string
  fromAddress: string
  fromName: string
  body: string
  aiSummary: AISummary | null
}

interface EmailThread {
  id: string
  caseId: string  // FK to support case
  carrierCode: string
  to: string
  cc: string
  subject: string         // user-typed subject (without tracking suffix)
  body: string
  sentAt: string
  sentByUser: string
  template: string
  status: ThreadStatus    // workflow status (waiting/replied/resolved/overdue)
  responses: EmailResponse[]
  attachments: { id: string; name: string; size: string }[]
  // ── Backend tracking fields ──────────────────────────────────────────
  messageId: string | null      // RFC-5322 Message-ID assigned by server
  replyAddress: string | null   // plus-addressed reply target
  fromAddress: string | null    // what carriers see in "From"
  trackedSubject: string | null // subject + [#T-xxx]
  deliveryStatus: DeliveryStatus | null // queued/sending/sent/delivered/bounced/failed
  deliveredAt: string | null
  bouncedReason: string | null
}


interface SupportCase {
  id: string
  caseId: string
  agentName: string
  /** Selling agent's broker code (e.g. "IN210253"). Used by templates that
   *  embed the agent code (e.g. "{{agentCode}} >> รหัสตัวแทนที่มาส่งงาน"). */
  agentCode: string
  agentEmail: string | null  // selling agent's contact email
  clientName: string
  clientEmail: string | null // client's email — may not exist for cold leads
  clientPhone: string | null
  carrier: string
  status: CaseStatus
  lastUpdated: string // ISO
  stuckHours: number // hours in current status — used for SLA calc
  premium: number
  productName: string
  rejectionReason: string | null
  timeline: TimelineEntry[]
  notes: NoteEntry[]
  documents: DocumentEntry[]
  threads: EmailThread[]
  statusHistory: StatusTransition[]
  /** Set when AI suggests a different status — drives the suggestion banner in the drawer */
  pendingAITransition: {
    suggestedStatus: CaseStatus
    sourceResponseId: string
    sourceCarrierCode: string
    suggestedAt: string
  } | null
}

// ─────────────────────────────────────────────────────────────────────────────
// Mock data
// ─────────────────────────────────────────────────────────────────────────────

const cases = ref<SupportCase[]>([
  {
    id: 'c1',
    caseId: 'CASE-2026-0412',
    agentName: 'จิราภรณ์ พงษ์ศิริ', agentCode: 'AG-00002',
    agentEmail: 'jirapron@abc-insure.co.th',
    clientEmail: 'pattra.j@gmail.com',
    clientPhone: '081-987-1234',
    clientName: 'ภัทรา จันทร์เพ็ญ',
    carrier: 'AIA',
    status: 'Underwriting',
    lastUpdated: '2026-06-06T14:22:00',
    stuckHours: 18,
    premium: 48_000,
    productName: 'เอไอเอ ตลอดชีพ 100',
    rejectionReason: null,
    timeline: [
      { status: 'Draft', at: '2026-06-04 10:00', byUser: 'จิราภรณ์ พงษ์ศิริ', note: 'สร้างใบเสนอราคา' },
      { status: 'Pending Carrier', at: '2026-06-05 09:30', byUser: 'ระบบ', note: 'ส่งใบสมัครไปยังบริษัทประกัน' },
      { status: 'Underwriting', at: '2026-06-05 16:42', byUser: 'AIA Underwriter', note: 'รับใบสมัครเข้ารับการพิจารณา' },
    ],
    notes: [
      { id: 'n1', byUser: 'ผู้ดูแลระบบ', at: '2026-06-05 16:50', body: 'ติดต่อ underwriter ทาง email แล้ว รอผลภายใน 24 ชม.' },
    ],
    documents: [
      { id: 'd1', name: 'application_signed.pdf', uploadedAt: '2026-06-05', size: '1.2 MB' },
      { id: 'd2', name: 'medical_report.pdf', uploadedAt: '2026-06-05', size: '3.8 MB' },
    ],
    threads: [],
    statusHistory: [],
    pendingAITransition: null,
  },
  {
    id: 'c2',
    caseId: 'CASE-2026-0388',
    agentName: 'สมชาย แก้วประเสริฐ', agentCode: 'AG-00001',
    agentEmail: 'somchai@abc-insure.co.th',
    clientEmail: 'teerayut.s@gmail.com',
    clientPhone: '089-555-2233',
    clientName: 'ธีรยุทธ สามารถ',
    carrier: 'AIA',
    status: 'Action Required',
    lastUpdated: '2026-06-04T11:08:00',
    stuckHours: 70,
    premium: 120_000,
    productName: 'เอไอเอ ตลอดชีพ 100 (VIP)',
    rejectionReason: 'ขาดเอกสารตรวจสุขภาพอายุไม่เกิน 30 วัน',
    timeline: [
      { status: 'Draft', at: '2026-06-01 09:00', byUser: 'สมชาย แก้วประเสริฐ' },
      { status: 'Pending Carrier', at: '2026-06-02 10:00', byUser: 'ระบบ' },
      { status: 'Underwriting', at: '2026-06-03 14:00', byUser: 'AIA' },
      { status: 'Action Required', at: '2026-06-04 11:08', byUser: 'AIA', note: 'ขาดเอกสาร medical report ใหม่' },
    ],
    notes: [
      { id: 'n2', byUser: 'สมชาย แก้วประเสริฐ', at: '2026-06-04 13:30', body: 'แจ้งลูกค้านัดตรวจสุขภาพใหม่ในวันที่ 2026-06-08' },
    ],
    documents: [
      { id: 'd3', name: 'application_v2.pdf', uploadedAt: '2026-06-02', size: '1.1 MB' },
      { id: 'd4', name: 'old_medical.pdf', uploadedAt: '2026-06-02', size: '2.4 MB' },
    ],
    threads: [],
    statusHistory: [],
    pendingAITransition: null,
  },
  {
    id: 'c3',
    caseId: 'CASE-2026-0405',
    agentName: 'อนุชา ใจดี', agentCode: 'AG-00003',
    agentEmail: 'anucha@abc-insure.co.th',
    clientEmail: 'kanlaya.s@hotmail.com',
    clientPhone: '081-222-3344',
    clientName: 'กัลยา ศรีเรือง',
    carrier: 'TLI',
    status: 'Ready to Issue',
    lastUpdated: '2026-06-07T08:15:00',
    stuckHours: 2,
    premium: 65_000,
    productName: 'ไทยประกันชีวิต โรคร้ายแรง พรีเมียม',
    rejectionReason: null,
    timeline: [
      { status: 'Draft', at: '2026-06-03 11:00', byUser: 'อนุชา ใจดี' },
      { status: 'Pending Carrier', at: '2026-06-03 16:00', byUser: 'ระบบ' },
      { status: 'Underwriting', at: '2026-06-04 09:00', byUser: 'TLI' },
      { status: 'Ready to Issue', at: '2026-06-07 08:15', byUser: 'TLI', note: 'อนุมัติแล้ว รอออกเลขกรมธรรม์' },
    ],
    notes: [],
    documents: [
      { id: 'd5', name: 'application_tli.pdf', uploadedAt: '2026-06-03', size: '0.9 MB' },
    ],
    threads: [],
    statusHistory: [],
    pendingAITransition: null,
  },
  {
    id: 'c4',
    caseId: 'CASE-2026-0421',
    agentName: 'พรทิพย์ มั่นคง', agentCode: 'AG-00004',
    agentEmail: 'porntip@abc-insure.co.th',
    clientEmail: 'noppharat.a@gmail.com',
    clientPhone: '081-444-5566',
    clientName: 'นพรัตน์ อภิวงศ์',
    carrier: 'TLI',
    status: 'Draft',
    lastUpdated: '2026-06-06T17:45:00',
    stuckHours: 14,
    premium: 28_500,
    productName: 'บำนาญ มั่นคง 65',
    rejectionReason: null,
    timeline: [
      { status: 'Draft', at: '2026-06-06 17:45', byUser: 'พรทิพย์ มั่นคง' },
    ],
    notes: [],
    documents: [],
    threads: [],
    statusHistory: [],
    pendingAITransition: null,
  },
  {
    id: 'c5',
    caseId: 'CASE-2026-0399',
    agentName: 'จิราภรณ์ พงษ์ศิริ', agentCode: 'AG-00002',
    agentEmail: 'jirapron@abc-insure.co.th',
    clientEmail: 'akkaradej.r@gmail.com',
    clientPhone: '081-888-9900',
    clientName: 'อัครเดช รุ่งโรจน์',
    carrier: 'MTL',
    status: 'Pending Carrier',
    lastUpdated: '2026-06-05T10:30:00',
    stuckHours: 46,
    premium: 250_000,
    productName: 'เมืองไทย ยูนิตลิงก์',
    rejectionReason: null,
    timeline: [
      { status: 'Draft', at: '2026-06-04 14:00', byUser: 'จิราภรณ์ พงษ์ศิริ' },
      { status: 'Pending Carrier', at: '2026-06-05 10:30', byUser: 'ระบบ', note: 'รอ MTL ตอบรับใบสมัคร' },
    ],
    notes: [],
    documents: [
      { id: 'd6', name: 'application_mti.pdf', uploadedAt: '2026-06-04', size: '1.5 MB' },
      { id: 'd7', name: 'income_proof.pdf', uploadedAt: '2026-06-04', size: '2.1 MB' },
    ],
    threads: [],
    statusHistory: [],
    pendingAITransition: null,
  },
  {
    id: 'c6',
    caseId: 'CASE-2026-0376',
    agentName: 'ณัฐวุฒิ รัตนา', agentCode: 'AG-00005',
    agentEmail: 'nattawut@abc-insure.co.th',
    clientEmail: 'manasanan.p@gmail.com',
    clientPhone: '081-999-0011',
    clientName: 'มนัสนันท์ พิทักษ์',
    carrier: 'BLA',
    status: 'Action Required',
    lastUpdated: '2026-06-03T15:20:00',
    stuckHours: 95,
    premium: 4_500,
    productName: 'กรุงเทพ PA สบายใจ',
    rejectionReason: 'ลายเซ็นในใบสมัครไม่ตรงกับบัตรประชาชน',
    timeline: [
      { status: 'Draft', at: '2026-06-01 10:00', byUser: 'ณัฐวุฒิ รัตนา' },
      { status: 'Pending Carrier', at: '2026-06-02 09:00', byUser: 'ระบบ' },
      { status: 'Action Required', at: '2026-06-03 15:20', byUser: 'BLA', note: 'ลายเซ็นไม่ตรง' },
    ],
    notes: [
      { id: 'n3', byUser: 'ณัฐวุฒิ รัตนา', at: '2026-06-04 09:00', body: 'รอลูกค้ากลับจากต่างจังหวัด คาดว่าจะได้ลายเซ็นใหม่ในสัปดาห์หน้า' },
    ],
    documents: [
      { id: 'd8', name: 'application_pa.pdf', uploadedAt: '2026-06-01', size: '0.8 MB' },
    ],
    threads: [],
    statusHistory: [],
    pendingAITransition: null,
  },
  {
    id: 'c7',
    caseId: 'CASE-2026-0418',
    agentName: 'ปิยะ ทองคำ', agentCode: 'AG-00007',
    agentEmail: 'piya@abc-insure.co.th',
    clientEmail: 'sudarat.m@gmail.com',
    clientPhone: '081-555-6677',
    clientName: 'สุดารัตน์ มงคล',
    carrier: 'AIA',
    status: 'Pending Carrier',
    lastUpdated: '2026-06-06T11:00:00',
    stuckHours: 22,
    premium: 32_500,
    productName: 'เอไอเอ เฮลธ์ พลัส',
    rejectionReason: null,
    timeline: [
      { status: 'Draft', at: '2026-06-05 16:00', byUser: 'ปิยะ ทองคำ' },
      { status: 'Pending Carrier', at: '2026-06-06 11:00', byUser: 'ระบบ' },
    ],
    notes: [],
    documents: [
      { id: 'd9', name: 'health_application.pdf', uploadedAt: '2026-06-06', size: '1.4 MB' },
    ],
    threads: [],
    statusHistory: [],
    pendingAITransition: null,
  },
])

// ─────────────────────────────────────────────────────────────────────────────
// Stats (highlight cards)
// ─────────────────────────────────────────────────────────────────────────────

const stats = computed(() => {
  const all = cases.value
  return {
    pendingQuotes: all.filter((c) => c.status === 'Pending Carrier' || c.status === 'Draft').length,
    slaAtRisk: all.filter(
      (c) =>
        c.stuckHours >= 24 &&
        (c.status === 'Pending Carrier' || c.status === 'Underwriting'),
    ).length,
    actionRequired: all.filter((c) => c.status === 'Action Required').length,
    convertedMtd: 47, // mock — would be derived from policies converted this month
  }
})

// ─────────────────────────────────────────────────────────────────────────────
// Filters
// ─────────────────────────────────────────────────────────────────────────────

const search = ref('')
const statusFilter = ref<'All' | CaseStatus>('All')
const statusOptions: ('All' | CaseStatus)[] = [
  'All',
  'Draft',
  'Quote Sent',
  'Quote Accepted',
  'Pending Carrier',
  'Underwriting',
  'Action Required',
  'Ready to Issue',
  'Rejected',
  'Withdrawn',
]

const filteredCases = computed(() =>
  cases.value.filter((c) => {
    if (statusFilter.value !== 'All' && c.status !== statusFilter.value) return false
    if (search.value) {
      const q = search.value.toLowerCase()
      const hay = `${c.caseId} ${c.agentName} ${c.clientName} ${c.carrier}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  }),
)

// ─────────────────────────────────────────────────────────────────────────────
// Badges
// ─────────────────────────────────────────────────────────────────────────────

function statusBadge(s: CaseStatus): string {
  return {
    'Draft': 'bg-slate-100 text-slate-600 ring-slate-200',
    'Pending Carrier': 'bg-blue-50 text-blue-700 ring-blue-200',
    'Underwriting': 'bg-purple-50 text-purple-700 ring-purple-200',
    'Action Required': 'bg-rose-50 text-rose-700 ring-rose-200',
    'Ready to Issue': 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    'Quote Sent': 'bg-amber-50 text-amber-700 ring-amber-200',
    'Quote Accepted': 'bg-teal-50 text-teal-700 ring-teal-200',
    'Rejected': 'bg-rose-50 text-rose-700 ring-rose-200',
    'Withdrawn': 'bg-slate-100 text-slate-500 ring-slate-200',
  }[s]
}

function statusDot(s: CaseStatus): string {
  return {
    'Draft': 'bg-slate-400',
    'Pending Carrier': 'bg-blue-500',
    'Underwriting': 'bg-purple-500',
    'Action Required': 'bg-rose-500',
    'Ready to Issue': 'bg-emerald-500',
    'Quote Sent': 'bg-amber-500',
    'Quote Accepted': 'bg-teal-500',
    'Rejected': 'bg-rose-600',
    'Withdrawn': 'bg-slate-400',
  }[s]
}

/** Live hours since the case was last updated — replaces the static c.stuckHours value. */
function liveStuckHours(c: SupportCase): number {
  // Fall back to stored value if lastUpdated isn't valid
  const live = caseStatusApi.computeStuckHours(c.lastUpdated)
  return live > 0 ? live : c.stuckHours
}

/** Apply a status change to a case, push to history, write a note. */
function autoTransitionCase(
  c: SupportCase,
  to: CaseStatus,
  reason: string,
  source: StatusTransition['source'],
  byUser = 'ระบบ',
  allowIrregular = false,
) {
  if (to === c.status) return
  // Auto + AI sources strictly follow the state machine.
  // Manual source may override with allowIrregular=true.
  if (!allowIrregular && !caseStatusApi.canTransitionTo(c.status, to)) return
  const isIrregular = !caseStatusApi.canTransitionTo(c.status, to)
  const txn = caseStatusApi.buildTransition(c.status, to, reason, byUser, source)
  c.statusHistory = [...c.statusHistory, txn]
  c.status = to
  c.lastUpdated = new Date().toISOString()
  c.pendingAITransition = null // clear any pending suggestion since we just transitioned
  c.notes = [
    ...c.notes,
    {
      id: 'n-stat-' + Date.now(),
      byUser,
      at: txn.at,
      body: `[สถานะ: ${txn.from} → ${txn.to}${isIrregular ? ' · OVERRIDE' : ''}] ${reason}`,
    },
  ]
}

/** When AI summary fires, set a pending suggestion if the sentiment implies a new status. */
function maybeSetAITransition(c: SupportCase, t: EmailThread, resp: EmailResponse) {
  if (!resp.aiSummary) return
  const suggested = caseStatusApi.nextStatusFromAISummary(c.status, resp.aiSummary.sentiment)
  if (!suggested) return
  c.pendingAITransition = {
    suggestedStatus: suggested,
    sourceResponseId: resp.id,
    sourceCarrierCode: t.carrierCode,
    suggestedAt: resp.receivedAt,
  }
}

/** User confirms the AI suggestion → apply it. */
function applyPendingAITransition(c: SupportCase) {
  if (!c.pendingAITransition) return
  const reason = `AI ตรวจพบจากคำตอบของ ${c.pendingAITransition.sourceCarrierCode}`
  autoTransitionCase(c, c.pendingAITransition.suggestedStatus, reason, 'ai_suggestion', 'ผู้ดูแลระบบ (คุณ)')
}

/** User rejects the AI suggestion → just clear it. */
function dismissPendingAITransition(c: SupportCase) {
  c.pendingAITransition = null
}

// Manual status change modal
const showStatusChange = ref(false)
const statusChangeTarget = ref<SupportCase | null>(null)
const statusChangeNewStatus = ref<CaseStatus>('Draft')
const statusChangeReason = ref('')

function openStatusChange(c: SupportCase) {
  statusChangeTarget.value = c
  statusChangeNewStatus.value = caseStatusApi.allowedTransitions(c.status)[0] ?? c.status
  statusChangeReason.value = ''
  showStatusChange.value = true
}

// All 9 statuses, used for the manual override dropdown
const ALL_CASE_STATUSES: CaseStatus[] = [
  'Draft',
  'Quote Sent',
  'Quote Accepted',
  'Pending Carrier',
  'Underwriting',
  'Action Required',
  'Ready to Issue',
  'Rejected',
  'Withdrawn',
]

/** True if the picked target is NOT in the state machine's allowed list. */
const isIrregularJump = computed(() => {
  if (!statusChangeTarget.value) return false
  if (statusChangeNewStatus.value === statusChangeTarget.value.status) return false
  return !caseStatusApi.canTransitionTo(statusChangeTarget.value.status, statusChangeNewStatus.value)
})

function submitStatusChange() {
  if (!statusChangeTarget.value || !statusChangeReason.value.trim()) return
  if (statusChangeNewStatus.value === statusChangeTarget.value.status) return
  autoTransitionCase(
    statusChangeTarget.value,
    statusChangeNewStatus.value,
    statusChangeReason.value.trim(),
    'manual',
    'ผู้ดูแลระบบ (คุณ)',
    true, // allow irregular jumps for manual overrides
  )
  showStatusChange.value = false
  statusChangeTarget.value = null
  statusChangeReason.value = ''
}

function slaPillClass(c: SupportCase): string {
  if (c.status === 'Action Required') return 'bg-rose-50 text-rose-700'
  if (c.stuckHours >= 48) return 'bg-rose-50 text-rose-700'
  if (c.stuckHours >= 24) return 'bg-amber-50 text-amber-700'
  return 'bg-slate-50 text-slate-500'
}

function formatRelative(iso: string): string {
  const now = new Date('2026-06-07T12:00:00').getTime()
  const then = new Date(iso).getTime()
  const diffMin = Math.round((now - then) / 60000)
  if (diffMin < 60) return `${diffMin} นาทีที่แล้ว`
  const diffHr = Math.round(diffMin / 60)
  if (diffHr < 24) return `${diffHr} ชม.ที่แล้ว`
  const diffDay = Math.round(diffHr / 24)
  return `${diffDay} วันที่แล้ว`
}

const fmtTHB = (n: number) => n.toLocaleString('th-TH')

// ─────────────────────────────────────────────────────────────────────────────
// Detail drawer
// ─────────────────────────────────────────────────────────────────────────────

const detailId = ref<string | null>(null)
const detail = computed(() => cases.value.find((c) => c.id === detailId.value) ?? null)

function openDetail(c: SupportCase) {
  detailId.value = c.id
  newNote.value = ''
}

function closeDetail() {
  detailId.value = null
}

// Note composer
const newNote = ref('')
function submitNote() {
  if (!newNote.value.trim() || !detail.value) return
  const note: NoteEntry = {
    id: 'n-' + Date.now(),
    byUser: 'ผู้ดูแลระบบ (คุณ)',
    at: new Date().toISOString().slice(0, 16).replace('T', ' '),
    body: newNote.value.trim(),
  }
  detail.value.notes = [...detail.value.notes, note]
  newNote.value = ''
}

// Document upload (drag-drop placeholder)
const fileInput = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)

function pickFile() {
  fileInput.value?.click()
}

function addFile(file: File) {
  if (!detail.value) return
  const doc: DocumentEntry = {
    id: 'd-' + Date.now(),
    name: file.name,
    uploadedAt: new Date().toISOString().slice(0, 10),
    size: (file.size / 1024 / 1024).toFixed(1) + ' MB',
  }
  detail.value.documents = [...detail.value.documents, doc]
}

function onFileChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) addFile(file)
  ;(e.target as HTMLInputElement).value = ''
}

function onDrop(e: DragEvent) {
  isDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) addFile(file)
}

function removeDoc(id: string) {
  if (!detail.value) return
  detail.value.documents = detail.value.documents.filter((d) => d.id !== id)
}

// ─────────────────────────────────────────────────────────────────────────────
// Stat card → filter the queue
// ─────────────────────────────────────────────────────────────────────────────

type StatKey = 'pending' | 'sla' | 'action' | 'converted' | null
const activeStat = ref<StatKey>(null)

function applyStatFilter(stat: StatKey) {
  // Toggle off if clicking the same card
  if (activeStat.value === stat) {
    activeStat.value = null
    statusFilter.value = 'All'
    search.value = ''
    return
  }
  activeStat.value = stat
  switch (stat) {
    case 'pending':
      statusFilter.value = 'Pending Carrier'
      break
    case 'sla':
      // Custom filter — keep status 'All' but flag SLA filtering
      statusFilter.value = 'All'
      break
    case 'action':
      statusFilter.value = 'Action Required'
      break
    case 'converted':
      statusFilter.value = 'Ready to Issue'
      break
  }
}

// Override filteredCases to also handle SLA filter (re-using existing search/status)
const filteredCasesWithStat = computed(() => {
  let list = filteredCases.value
  if (activeStat.value === 'sla') {
    list = list.filter(
      (c) =>
        c.stuckHours >= 24 &&
        (c.status === 'Pending Carrier' || c.status === 'Underwriting'),
    )
  }
  return list
})

// ─────────────────────────────────────────────────────────────────────────────
// "เคสใหม่" — new case modal
// ─────────────────────────────────────────────────────────────────────────────

const showNewCase = ref(false)

const newCaseForm = ref({
  agentName: '',
  agentCode: '',
  clientName: '',
  carrier: 'AIA',
  productName: '',
  premium: 0,
})

const carrierOptions = ['AIA', 'TLI', 'MTL', 'BLA', 'VIB', 'DHA', 'ALL']

function openNewCase() {
  newCaseForm.value = {
    agentName: '',
    agentCode: '',
    clientName: '',
    carrier: 'AIA',
    productName: '',
    premium: 0,
  }
  showNewCase.value = true
}

function submitNewCase() {
  const f = newCaseForm.value
  if (!f.agentName.trim() || !f.clientName.trim() || !f.productName.trim() || f.premium <= 0) return
  const nextNumber = 500 + cases.value.length
  const id = 'c' + Date.now()
  const nowIso = new Date().toISOString()
  const at = nowIso.slice(0, 10) + ' ' + nowIso.slice(11, 16)
  cases.value = [
    {
      id,
      caseId: `CASE-2026-0${nextNumber}`,
      agentName: f.agentName.trim(),
      agentCode: f.agentCode.trim(),
      agentEmail: null,
      clientName: f.clientName.trim(),
      clientEmail: null,
      clientPhone: null,
      carrier: f.carrier,
      status: 'Draft',
      lastUpdated: nowIso,
      stuckHours: 0,
      premium: f.premium,
      productName: f.productName.trim(),
      rejectionReason: null,
      timeline: [{ status: 'Draft', at, byUser: 'ผู้ดูแลระบบ', note: 'สร้างเคสด้วยตนเอง' }],
      notes: [],
      documents: [],
      threads: [],
      statusHistory: [],
      pendingAITransition: null,
    },
    ...cases.value,
  ]
  showNewCase.value = false
}

// ─────────────────────────────────────────────────────────────────────────────
// Document download (real blob download)
// ─────────────────────────────────────────────────────────────────────────────

function downloadDoc(doc: DocumentEntry) {
  const content = `เอกสารตัวอย่าง — ${doc.name}\nกรณีศึกษา: ${detail.value?.caseId ?? ''}\nลูกค้า: ${detail.value?.clientName ?? ''}\nอัปโหลด: ${doc.uploadedAt}\nขนาด: ${doc.size}\n\n[เนื้อหา PDF จริงจะถูกแทนที่เมื่อเชื่อมต่อ backend]`
  const blob = new Blob([content], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = doc.name.replace(/\.pdf$/, '') + '.txt'
  a.click()
  URL.revokeObjectURL(url)
}

function jumpToPolicies() {
  if (detail.value) {
    router.push({ path: '/policies', query: { case: detail.value.caseId } })
  } else {
    router.push('/policies')
  }
  closeDetail()
}

// ─────────────────────────────────────────────────────────────────────────────
// Email composer
// ─────────────────────────────────────────────────────────────────────────────

interface CarrierDirectoryEntry {
  code: string
  name: string
  newBusinessEmail: string
  underwritingEmail: string
  /** InsureHub broker account / membership code at the carrier (e.g. "004711"
   *  for Allianz). Comes from the broker's relationship with the carrier and is
   *  often required in outbound mail subjects. */
  brokerCode?: string
}

const carrierDirectory: Record<string, CarrierDirectoryEntry> = {
  // ── Life carriers ───────────────────────────────────────────────────────
  AIA: {
    code: 'AIA', name: 'บริษัท เอไอเอ จำกัด',
    newBusinessEmail: 'newbiz@aia.co.th',
    underwritingEmail: 'underwriting@aia.co.th',
  },
  TLI: {
    code: 'TLI', name: 'บริษัท ไทยประกันชีวิต จำกัด (มหาชน)',
    newBusinessEmail: 'newpolicy@thailife.com',
    underwritingEmail: 'uw@thailife.com',
    brokerCode: '00016337',
  },
  MTL: {
    code: 'MTL', name: 'บริษัท เมืองไทยประกันชีวิต จำกัด (มหาชน)',
    newBusinessEmail: 'newcase@muangthai.co.th',
    underwritingEmail: 'underwriting@muangthai.co.th',
  },
  BLA: {
    code: 'BLA', name: 'บริษัท กรุงเทพประกันชีวิต จำกัด (มหาชน)',
    newBusinessEmail: 'application@bla.co.th',
    underwritingEmail: 'underwriting@bla.co.th',
    brokerCode: '01054787',
  },
  TLIFE: {
    code: 'TLIFE', name: 'บริษัท ไทยประกันชีวิตและสุขภาพ จำกัด (T-Life)',
    newBusinessEmail: 'Nuengruetai.p@tlife.co.th',
    underwritingEmail: 'thuntanit.w@tlife.co.th',
  },
  SELIFE: {
    code: 'SELIFE', name: 'บริษัท อาคเนย์ประกันชีวิต จำกัด (มหาชน)',
    newBusinessEmail: 'SELICBROKER@tgh.co.th',
    underwritingEmail: 'pongsakorn.s@tgh.co.th',
    brokerCode: '850-00906',
  },
  TML: {
    code: 'TML', name: 'บริษัท โตเกียวมารีนประกันชีวิต (ประเทศไทย) จำกัด (มหาชน)',
    newBusinessEmail: 'phuriwat.liu@tokiomarinelife.co.th',
    underwritingEmail: 'parunyu.eia@tokiomarinelife.co.th',
    brokerCode: 'U1317',
  },
  KTAXA: {
    code: 'KTAXA', name: 'บริษัท กรุงไทย-แอกซ่า ประกันชีวิต จำกัด (มหาชน)',
    newBusinessEmail: 'norarudee.kae@krungthai-axa.co.th',
    underwritingEmail: 'group_admin@krungthai-axa.co.th',
    brokerCode: '112120',
  },

  // ── Non-life carriers (motor / fire / marine / CAR / health) ────────────
  ALLZ: {
    code: 'ALLZ', name: 'บริษัท อลิอันซ์ อยุธยา ประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'agency.a@allianz.co.th',
    underwritingEmail: 'thanon.k@allianz.co.th',
    brokerCode: '004711',
  },
  ALL: {
    code: 'ALL', name: 'บริษัท อลิอันซ์ อยุธยา ประกันชีวิต จำกัด (มหาชน)',
    newBusinessEmail: 'newpolicy@allianz.co.th',
    underwritingEmail: 'uw@allianz.co.th',
  },
  AIG: {
    code: 'AIG', name: 'บริษัท เอไอจี ประกันภัย (ประเทศไทย) จำกัด (มหาชน)',
    newBusinessEmail: 'brokercare@aig.com',
    underwritingEmail: 'brokercare@aig.com',
    brokerCode: '0030930000',
  },
  VIB: {
    code: 'VIB', name: 'บริษัท วิริยะประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'pr2_nonmotor@viriyah.co.th',
    underwritingEmail: 'pr2_insure@viriyah.co.th',
    brokerCode: '19443',
  },
  DHA: {
    code: 'DHA', name: 'บริษัท ทิพยประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'titirats@dhipaya.co.th',
    underwritingEmail: 'taksaons@dhipaya.co.th',
    brokerCode: '0021630009',
  },
  MTI: {
    code: 'MTI', name: 'บริษัท เมืองไทยประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'tippawan.n@muangthaiinsurance.com',
    underwritingEmail: 'Auto-insurance-broker-business-NON-TQM@muangthaiinsurance.com',
    brokerCode: '74000631',
  },
  IND: {
    code: 'IND', name: 'บริษัท อินทรประกันภัย จำกัด (มหาชน) / TGH',
    newBusinessEmail: 'xb_teerapong.p@tgh.co.th',
    underwritingEmail: 'xb_anchalee.p@tgh.co.th',
    brokerCode: '1110131668',
  },
  AIOI: {
    code: 'AIOI', name: 'บริษัท ไอโออิ กรุงเทพ ประกันภัย จำกัด',
    newBusinessEmail: 'absp1@aioibkkins.co.th',
    underwritingEmail: 'absp1@aioibkkins.co.th',
    brokerCode: '60205-00',
  },
  BKI: {
    code: 'BKI', name: 'บริษัท กรุงเทพประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'suebsawad.s@bangkokinsurance.com',
    underwritingEmail: 'Wanwimol@bangkokinsurance.com',
    brokerCode: '653300',
  },
  TIP: {
    code: 'TIP', name: 'บริษัท ทิพยประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'titirats@dhipaya.co.th',
    underwritingEmail: 'taksaons@dhipaya.co.th',
    brokerCode: '21630009',
  },
  AXA: {
    code: 'AXA', name: 'บริษัท แอกซ่าประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'DSU@axa.co.th',
    underwritingEmail: 'distribution2_salesteam3@axa.co.th',
    brokerCode: 'BB176',
  },
  MSIG: {
    code: 'MSIG', name: 'บริษัท เอ็ม เอส ไอ จี ประกันภัย (ประเทศไทย) จำกัด (มหาชน)',
    newBusinessEmail: 'th_msignt@th.msig-asia.com',
    underwritingEmail: 'th_msignt@th.msig-asia.com',
    brokerCode: 'NTA8476',
  },
  ERGO: {
    code: 'ERGO', name: 'บริษัท เออร์โกประกันภัย (ประเทศไทย) จำกัด (มหาชน)',
    newBusinessEmail: 'contact_center@ergo.co.th',
    underwritingEmail: 'Broker@ergo.co.th',
    brokerCode: '4620',
  },
  TPB: {
    code: 'TPB', name: 'บริษัท ไทยไพบูลย์ประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'tpb_upcnpt@thaipaiboon.com',
    underwritingEmail: 'tpb_upcnpt@thaipaiboon.com',
    brokerCode: '02500160',
  },
  CHUBB: {
    code: 'CHUBB', name: 'บริษัท ชับบ์สามัคคีประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'Chubb.BKKC@chubb.com',
    underwritingEmail: 'atch.shalasonti@chubb.com',
    brokerCode: '000030299',
  },
  CHUBBL: {
    code: 'CHUBBL', name: 'บริษัท ชับบ์ไลฟ์ แอสชัวรันซ์ จำกัด (มหาชน)',
    newBusinessEmail: 'CBAFUWN_Agent@chubb.com',
    underwritingEmail: 'uwgroup@chubb.com',
    brokerCode: 'BR01251',
  },
  TOK: {
    code: 'TOK', name: 'บริษัท โตเกียวมารีนเซฟตี้อินชัวรันซ์ (ประเทศไทย) จำกัด',
    newBusinessEmail: 'ratchapluek@tokiomarinesafety.co.th',
    underwritingEmail: 'ratchapluek@tokiomarinesafety.co.th',
    brokerCode: '10311-002',
  },
  KPI: {
    code: 'KPI', name: 'บริษัท กรุงเทพประกันภัย เคพีไอ จำกัด',
    newBusinessEmail: 'mkt.broker@kpi.co.th',
    underwritingEmail: 'korawan.a@kpi.co.th',
    brokerCode: '0032003988',
  },
  TNI: {
    code: 'TNI', name: 'บริษัท ธนชาตประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'Thanon.Per@thanachart.co.th',
    underwritingEmail: 'SP000002@thanachart.co.th',
  },
  FALCON: {
    code: 'FALCON', name: 'บริษัท ฟอลคอนประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'fci_motofleet@falconinsurance.co.th',
    underwritingEmail: 'nattawuts@falconinsurance.co.th',
  },
  BUI: {
    code: 'BUI', name: 'บริษัท กรุงเทพยูเนียนประกันภัย จำกัด (BUI)',
    newBusinessEmail: 'kittichon.p@bui.co.th',
    underwritingEmail: 'kittichon.p@bui.co.th',
  },
  NAVAKIJ: {
    code: 'NAVAKIJ', name: 'บริษัท นวกิจประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'telebroker@navakij.co.th',
    underwritingEmail: 'telebroker@navakij.co.th',
    brokerCode: 'อินช2829',
  },
  SOMPO: {
    code: 'SOMPO', name: 'บริษัท ซมโปะ ประกันภัย (ประเทศไทย) จำกัด (มหาชน)',
    newBusinessEmail: 'Bangkok1@sompo.co.th',
    underwritingEmail: 'rattana.m@sompo.co.th',
    brokerCode: 'BR01158',
  },
  MITTE: {
    code: 'MITTE', name: 'บริษัท มิตเตอ์ ประกันภัย จำกัด (มหาชน)',
    newBusinessEmail: 'fire.acc@mittare.com',
    underwritingEmail: 'fire.acc@mittare.com',
    brokerCode: 'BK245714',
  },
}

// Pulled from tenant settings → branding. Hard-coded here matching the seed
// default; in production read via useTenantStore or i18n key.
const emailSignature = `ขอแสดงความนับถือ
ทีมงาน บริษัท เอบีซี อินชัวรันส์ จำกัด
โทร 02-555-0100
support@abc-insure.co.th`

// Templates moved to Pinia store (src/stores/emailTemplates.ts) so users can
// add/edit/delete them. EmailTemplateKey stays a string alias for backwards
// compatibility with places that pass template ids around.
type EmailTemplateKey = string

// Template list re-read from the store. Composition use sites refer to this
// computed (treated as an array) instead of the old hard-coded const.
const emailTemplates = computed<StoredEmailTemplate[]>(() => templatesStore.templates)

/**
 * Build the variable map for a case so renderTemplate() can interpolate
 * {{caseId}}, {{clientName}}, etc.
 */
function caseVariables(c: SupportCase): Record<string, string> {
  const carrierName = carrierDirectory[c.carrier]?.name ?? c.carrier
  return {
    caseId: c.caseId,
    clientName: c.clientName,
    agentName: c.agentName,
    agentCode: c.agentCode,
    carrierName,
    carrierCode: c.carrier,
    productName: c.productName,
    premium: c.premium.toLocaleString('th-TH'),
    status: c.status,
    stuckHours: String(c.stuckHours),
    rejectionReason: c.rejectionReason ?? '—',
    lastUpdatedBE: formatDateBE(c.lastUpdated),
  }
}

/** Render a stored template against a case. */
function renderForCase(tpl: StoredEmailTemplate, c: SupportCase): { subject: string; body: string } {
  const vars = caseVariables(c)
  return {
    subject: renderTemplate(tpl.subject, vars),
    body: renderTemplate(tpl.body, vars),
  }
}


function formatDateBE(iso: string): string {
  const d = new Date(iso)
  const day = d.getDate()
  const month = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][d.getMonth()]
  const year = d.getFullYear() + 543
  return `${day} ${month} ${year}`
}

// ─────────────────────────────────────────────────────────────────────────────
// Per-case compose modal — supports sending to 1+ carriers in a single batch
// ─────────────────────────────────────────────────────────────────────────────

type ComposePhase = 'compose' | 'sending'

type RecipientRole = 'carrier' | 'client' | 'agent' | 'other'

interface CarrierRecipient {
  /** Unique row id — for carriers this is the contact-group id; for client/agent/other a synthetic key. */
  rowId: string
  code: string           // carrier code OR a synthetic role-key like 'CLIENT', 'AGENT', 'OTHER'
  role: RecipientRole    // discriminator for rendering & routing
  label: string          // e.g. 'AIA Life — New Business' or 'ลูกค้า'
  /** For carriers: department label shown under the group name. */
  subLabel?: string
  selected: boolean
  to: string             // editable per-row
  threadId: string | null // assigned at send time; updated as delivery transitions
  deliveryStatus: DeliveryStatus | null
}

const showEmail = ref(false)
const emailCase = ref<SupportCase | null>(null)
const emailTemplate = ref<EmailTemplateKey>('follow_up_pending')
const composePhase = ref<ComposePhase>('compose')

const emailForm = ref({
  cc: '',
  subject: '',
  body: '',
})

// ─── Compose state (backing data) ────────────────────────────────────────
// The picker UI mutates this state. `carrierRecipients` below is a derived
// view used by sendEmail / delivery-status display / validity checks.

interface SimpleRowState {
  selected: boolean
  to: string
  threadId: string | null
  deliveryStatus: DeliveryStatus | null
}

interface CarrierBlockState {
  code: string
  // Group ids the user has ticked. Empty = carrier not sending.
  selectedGroupIds: Set<string>
  // Extra ad-hoc addresses the user typed (free-form CSV).
  customExtra: string
  // Mutable send-time state — assigned per send.
  threadId: string | null
  deliveryStatus: DeliveryStatus | null
}

const composeClient = ref<SimpleRowState>({
  selected: false,
  to: '',
  threadId: null,
  deliveryStatus: null,
})
const composeAgent = ref<SimpleRowState>({
  selected: false,
  to: '',
  threadId: null,
  deliveryStatus: null,
})
const composeOther = ref<SimpleRowState>({
  selected: false,
  to: '',
  threadId: null,
  deliveryStatus: null,
})
const composeCarrierBlocks = ref<CarrierBlockState[]>([])

// Per-carrier group search query. Keyed by carrier code so each card has its own box.
const carrierGroupSearch = ref<Record<string, string>>({})

// ─── Template editor modal ─────────────────────────────────────────────
const showTemplateEditor = ref(false)
/** null = creating a brand-new template */
const editorTargetId = ref<string | null>(null)
const editorForm = ref({
  label: '',
  desc: '',
  icon: 'pi pi-envelope',
  department: 'new_business' as ContactDepartment,
  subject: '',
  body: '',
})
const editorBodyRef = ref<HTMLTextAreaElement | null>(null)
const editorSubjectRef = ref<HTMLInputElement | null>(null)
const editorFocusField = ref<'subject' | 'body'>('body')
const deleteTemplateId = ref<string | null>(null)

const editorIsBuiltIn = computed(() => {
  if (!editorTargetId.value) return false
  return templatesStore.findById(editorTargetId.value)?.isBuiltIn ?? false
})

const editorIsCreating = computed(() => editorTargetId.value === null)

function openTemplateEditor(id: string | null) {
  editorTargetId.value = id
  if (id) {
    const t = templatesStore.findById(id)
    if (!t) return
    editorForm.value = {
      label: t.label,
      desc: t.desc,
      icon: t.icon,
      department: t.department,
      subject: t.subject,
      body: t.body,
    }
  } else {
    editorForm.value = {
      label: '',
      desc: '',
      icon: 'pi pi-envelope',
      department: 'new_business',
      subject: '',
      body: '',
    }
  }
  editorFocusField.value = 'body'
  showTemplateEditor.value = true
}

function closeTemplateEditor() {
  showTemplateEditor.value = false
  editorTargetId.value = null
}

const editorValid = computed(
  () =>
    editorForm.value.label.trim().length > 0 &&
    editorForm.value.subject.trim().length > 0 &&
    editorForm.value.body.trim().length > 0,
)

async function saveTemplate() {
  if (!editorValid.value) return
  const payload = {
    label: editorForm.value.label.trim(),
    desc: editorForm.value.desc.trim(),
    icon: editorForm.value.icon.trim() || 'pi pi-envelope',
    department: editorForm.value.department,
    subject: editorForm.value.subject,
    body: editorForm.value.body,
  }
  if (editorTargetId.value) {
    await templatesStore.updateTemplate(editorTargetId.value, payload)
    // If editing the currently-selected template, re-render its preview into the form.
    if (emailCase.value && emailTemplate.value === editorTargetId.value) {
      const tpl = templatesStore.findById(editorTargetId.value)
      if (tpl) {
        const built = renderForCase(tpl, emailCase.value)
        emailForm.value.subject = built.subject
        emailForm.value.body = built.body + '\n\n' + emailSignature
      }
    }
  } else {
    const created = await templatesStore.addTemplate(payload)
    // Auto-select the freshly-created template if a case is open.
    if (emailCase.value) {
      applyTemplate(created.id)
    }
  }
  closeTemplateEditor()
}

function confirmDeleteTemplate(id: string) {
  const t = templatesStore.findById(id)
  if (!t || t.isBuiltIn) return
  deleteTemplateId.value = id
}

async function performDeleteTemplate() {
  const id = deleteTemplateId.value
  if (!id) return
  const ok = await templatesStore.removeTemplate(id)
  deleteTemplateId.value = null
  if (!ok) return
  // If the deleted template was selected, fall back to a sensible default.
  if (emailCase.value && emailTemplate.value === id) {
    applyTemplate('custom')
  }
}

/** Render `{{name}}` as plain text — used inside template UI where literal
 * mustaches would be eaten by the Vue compiler. */
function formatVarToken(name: string): string {
  return '{{' + name + '}}'
}

/** Insert a {{varName}} placeholder at the current cursor of the focused field. */
function insertVariable(name: string) {
  const token = `{{${name}}}`
  const fieldKey = editorFocusField.value
  const el = fieldKey === 'subject' ? editorSubjectRef.value : editorBodyRef.value
  const target = fieldKey === 'subject' ? 'subject' : 'body'
  const current = editorForm.value[target]
  if (!el) {
    editorForm.value[target] = current + token
    return
  }
  const start = el.selectionStart ?? current.length
  const end = el.selectionEnd ?? current.length
  const next = current.slice(0, start) + token + current.slice(end)
  editorForm.value[target] = next
  // Restore cursor after the inserted token on next tick.
  requestAnimationFrame(() => {
    el.focus()
    const pos = start + token.length
    el.setSelectionRange(pos, pos)
  })
}

/** Live preview of the editor's subject/body rendered against the active case. */
const editorPreview = computed(() => {
  if (!emailCase.value) {
    return { subject: editorForm.value.subject, body: editorForm.value.body }
  }
  const vars = caseVariables(emailCase.value)
  return {
    subject: renderTemplate(editorForm.value.subject, vars),
    body: renderTemplate(editorForm.value.body, vars),
  }
})

function filteredGroupsFor(code: string, groups: CarrierContactGroup[]): CarrierContactGroup[] {
  const q = (carrierGroupSearch.value[code] ?? '').trim().toLowerCase()
  if (!q) return groups
  return groups.filter((g) => {
    if (g.name.toLowerCase().includes(q)) return true
    if (g.emails.some((e) => e.toLowerCase().includes(q))) return true
    if (DEPARTMENT_LABELS[g.department].toLowerCase().includes(q)) return true
    return false
  })
}

const attachedDocIds = ref<Set<string>>(new Set())

// Fresh attachments added inside the compose modal (not yet saved to the case).
// They live alongside selectedDocs in the final selection.
interface FreshAttachment {
  id: string
  name: string
  size: string
  sizeBytes: number
}
const freshAttachments = ref<FreshAttachment[]>([])
const saveFreshToCase = ref(true)
const composeFileInput = ref<HTMLInputElement | null>(null)
const composeDragOver = ref(false)

const selectedDocs = computed(() => {
  if (!emailCase.value) return []
  return emailCase.value.documents.filter((d) => attachedDocIds.value.has(d.id))
})

// All attachments going on this email: case docs + fresh files
const allAttachments = computed(() => {
  return [
    ...selectedDocs.value.map((d) => ({ id: d.id, name: d.name, size: d.size, fresh: false })),
    ...freshAttachments.value.map((f) => ({ id: f.id, name: f.name, size: f.size, fresh: true })),
  ]
})

const totalAttachmentSizeBytes = computed(() => {
  let bytes = 0
  for (const d of selectedDocs.value) {
    // parse "1.2 MB" / "850 KB" / "120 KB" — store as bytes
    const m = d.size.match(/([\d.]+)\s*(KB|MB|GB)/i)
    if (m) {
      const val = parseFloat(m[1])
      const unit = m[2].toUpperCase()
      const mul = unit === 'KB' ? 1024 : unit === 'MB' ? 1024 * 1024 : 1024 * 1024 * 1024
      bytes += val * mul
    }
  }
  for (const f of freshAttachments.value) bytes += f.sizeBytes
  return bytes
})

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`
  return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`
}

function toggleAttachment(docId: string) {
  const next = new Set(attachedDocIds.value)
  if (next.has(docId)) next.delete(docId)
  else next.add(docId)
  attachedDocIds.value = next
}

function toggleAllAttachments() {
  if (!emailCase.value) return
  const allIds = emailCase.value.documents.map((d) => d.id)
  if (allIds.every((id) => attachedDocIds.value.has(id))) {
    attachedDocIds.value = new Set()
  } else {
    attachedDocIds.value = new Set(allIds)
  }
}

// Fresh attachments handling
function addFreshFile(file: File) {
  const id = 'fresh-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6)
  freshAttachments.value = [
    ...freshAttachments.value,
    {
      id,
      name: file.name,
      size: formatBytes(file.size),
      sizeBytes: file.size,
    },
  ]
}

function removeFreshFile(id: string) {
  freshAttachments.value = freshAttachments.value.filter((f) => f.id !== id)
}

function triggerComposeFilePicker() {
  composeFileInput.value?.click()
}

function onComposeFileChange(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (!files) return
  for (const f of Array.from(files)) addFreshFile(f)
  ;(e.target as HTMLInputElement).value = ''
}

function onComposeDrop(e: DragEvent) {
  composeDragOver.value = false
  const files = e.dataTransfer?.files
  if (!files) return
  for (const f of Array.from(files)) addFreshFile(f)
}

// ─── Derived carrierRecipients ───────────────────────────────────────────
// One CarrierRecipient per "ready-to-send unit":
//   • client / agent / other (each one row)
//   • per carrier — one row if the user picked ≥1 group OR typed a custom extra
//
// The TO field is the union of the picked groups' emails + customExtra,
// deduped and rendered as a comma-separated address list (valid SMTP).

function parseEmailCsv(s: string): string[] {
  return s
    .split(/[,\n;]/)
    .map((x) => x.trim())
    .filter(Boolean)
}

function carrierToList(block: CarrierBlockState): string[] {
  const out: string[] = []
  for (const gid of block.selectedGroupIds) {
    const g = contactsStore.groups.find((x) => x.id === gid)
    if (g) out.push(...g.emails)
  }
  out.push(...parseEmailCsv(block.customExtra))
  const seen = new Set<string>()
  return out.filter((e) => {
    const key = e.toLowerCase()
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

function carrierGroupSummary(block: CarrierBlockState): string {
  const parts: string[] = []
  for (const gid of block.selectedGroupIds) {
    const g = contactsStore.groups.find((x) => x.id === gid)
    if (g) parts.push(g.name)
  }
  if (block.customExtra.trim()) parts.push('อีเมลเพิ่มเติม')
  return parts.join(' + ')
}

const carrierRecipients = computed<CarrierRecipient[]>(() => {
  const out: CarrierRecipient[] = []

  // Client
  out.push({
    rowId: 'CLIENT',
    code: 'CLIENT',
    role: 'client',
    label: 'ลูกค้า',
    selected: composeClient.value.selected,
    to: composeClient.value.to,
    threadId: composeClient.value.threadId,
    deliveryStatus: composeClient.value.deliveryStatus,
  })

  // Agent
  out.push({
    rowId: 'AGENT',
    code: 'AGENT',
    role: 'agent',
    label: 'ตัวแทนผู้ขาย',
    selected: composeAgent.value.selected,
    to: composeAgent.value.to,
    threadId: composeAgent.value.threadId,
    deliveryStatus: composeAgent.value.deliveryStatus,
  })

  // Carrier blocks → one row each (only if user picked groups OR typed custom)
  for (const b of composeCarrierBlocks.value) {
    const tos = carrierToList(b)
    const hasPick = b.selectedGroupIds.size > 0 || b.customExtra.trim().length > 0
    if (!hasPick) continue
    out.push({
      rowId: `CARRIER-${b.code}`,
      code: b.code,
      role: 'carrier',
      label: carrierDirectory[b.code]?.name ?? b.code,
      subLabel: carrierGroupSummary(b) || undefined,
      selected: true,
      to: tos.join(', '),
      threadId: b.threadId,
      deliveryStatus: b.deliveryStatus,
    })
  }

  // Other
  out.push({
    rowId: 'OTHER',
    code: 'OTHER',
    role: 'other',
    label: 'อีเมลอื่น',
    selected: composeOther.value.selected,
    to: composeOther.value.to,
    threadId: composeOther.value.threadId,
    deliveryStatus: composeOther.value.deliveryStatus,
  })

  return out
})

// ─── Picker UI helpers ───────────────────────────────────────────────────

interface CarrierPickerView {
  code: string
  name: string
  isPrimary: boolean
  groups: CarrierContactGroup[]   // matching groups (filtered by template + type)
  block: CarrierBlockState
  toAddresses: string[]
  totalAddressCount: number
}

const carrierPickerViews = computed<CarrierPickerView[]>(() => {
  if (!emailCase.value) return []
  const tpl = emailTemplates.value.find((t) => t.id === emailTemplate.value)
  const dept = tpl?.department
  const insType = inferInsuranceType(emailCase.value.productName)
  const primary = emailCase.value.carrier

  return composeCarrierBlocks.value.map((b) => {
    const groups = contactsStore.resolveGroups(b.code, {
      department: dept,
      insuranceType: insType,
    })
    const tos = carrierToList(b)
    return {
      code: b.code,
      name: carrierDirectory[b.code]?.name ?? b.code,
      isPrimary: b.code === primary,
      groups,
      block: b,
      toAddresses: tos,
      totalAddressCount: tos.length,
    }
  })
})

function toggleClientRow() {
  composeClient.value.selected = !composeClient.value.selected
}
function toggleAgentRow() {
  composeAgent.value.selected = !composeAgent.value.selected
}
function toggleOtherRow() {
  composeOther.value.selected = !composeOther.value.selected
}

function toggleCarrierGroup(carrierCode: string, groupId: string) {
  const b = composeCarrierBlocks.value.find((x) => x.code === carrierCode)
  if (!b) return
  const next = new Set(b.selectedGroupIds)
  if (next.has(groupId)) next.delete(groupId)
  else next.add(groupId)
  b.selectedGroupIds = next
}

function clearCarrierBlock(carrierCode: string) {
  const b = composeCarrierBlocks.value.find((x) => x.code === carrierCode)
  if (!b) return
  b.selectedGroupIds = new Set()
  b.customExtra = ''
}

const selectedCarrierCount = computed(() => carrierRecipients.value.filter((r) => r.selected).length)

const canSendCompose = computed(
  () =>
    selectedCarrierCount.value > 0 &&
    emailForm.value.subject.trim().length > 0 &&
    emailForm.value.body.trim().length > 0 &&
    carrierRecipients.value.every((r) => !r.selected || r.to.trim().length > 0),
)

const allDelivered = computed(
  () =>
    carrierRecipients.value
      .filter((r) => r.selected)
      .every((r) => r.deliveryStatus === 'delivered'),
)

const anyBouncedOrFailed = computed(
  () =>
    carrierRecipients.value
      .filter((r) => r.selected)
      .some((r) => r.deliveryStatus === 'bounced' || r.deliveryStatus === 'failed'),
)

/**
 * Seed the compose state for a case:
 *  - Client / agent / other rows pre-filled
 *  - One CarrierBlockState per carrier in the directory
 *  - For carriers in `selectedCarrierCodes`, pre-tick all default-matching groups
 *
 * The picker UI mutates this state directly. `carrierRecipients` is derived.
 */
function seedComposeState(
  c: SupportCase,
  tpl: StoredEmailTemplate | undefined,
  selectedRoles: Set<RecipientRole | string>,
): void {
  composeClient.value = {
    selected: selectedRoles.has('client') || selectedRoles.has('CLIENT'),
    to: c.clientEmail ?? '',
    threadId: null,
    deliveryStatus: null,
  }
  composeAgent.value = {
    selected: selectedRoles.has('agent') || selectedRoles.has('AGENT'),
    to: c.agentEmail ?? '',
    threadId: null,
    deliveryStatus: null,
  }
  composeOther.value = {
    selected: selectedRoles.has('other') || selectedRoles.has('OTHER'),
    to: '',
    threadId: null,
    deliveryStatus: null,
  }

  const dept = tpl?.department
  const insType = inferInsuranceType(c.productName)
  const blocks: CarrierBlockState[] = []
  for (const code of Object.keys(carrierDirectory)) {
    const matchingGroups = contactsStore.resolveGroups(code, {
      department: dept,
      insuranceType: insType,
    })
    const isPreSelectedCarrier =
      selectedRoles.has(code) || (selectedRoles.has('carrier') && code === c.carrier)
    const preTicked = new Set<string>()
    if (isPreSelectedCarrier) {
      for (const g of matchingGroups) {
        if (g.isDefault) preTicked.add(g.id)
      }
      // If nothing defaulted but the carrier was requested, tick the first match.
      if (preTicked.size === 0 && matchingGroups.length > 0) {
        preTicked.add(matchingGroups[0].id)
      }
    }
    blocks.push({
      code,
      selectedGroupIds: preTicked,
      customExtra: '',
      threadId: null,
      deliveryStatus: null,
    })
  }
  composeCarrierBlocks.value = blocks
}

/**
 * Replace currently-selected groups when the template's department changes.
 * Keeps the user's manual ticks if they still match; adds defaults for any
 * carrier that was previously sending.
 */
function reseedGroupsForTemplate(c: SupportCase, tpl: StoredEmailTemplate | undefined): void {
  const dept = tpl?.department
  const insType = inferInsuranceType(c.productName)
  for (const b of composeCarrierBlocks.value) {
    const matching = contactsStore.resolveGroups(b.code, {
      department: dept,
      insuranceType: insType,
    })
    const matchingIds = new Set(matching.map((g) => g.id))
    const previouslyHadPicks = b.selectedGroupIds.size > 0 || b.customExtra.trim().length > 0
    const next = new Set<string>()
    for (const id of b.selectedGroupIds) {
      if (matchingIds.has(id)) next.add(id)
    }
    if (previouslyHadPicks && next.size === 0) {
      // No surviving picks — fall back to defaults so the carrier keeps sending.
      for (const g of matching) {
        if (g.isDefault) next.add(g.id)
      }
      if (next.size === 0 && matching.length > 0) next.add(matching[0].id)
    }
    b.selectedGroupIds = next
  }
}

/** Write back send-time state to whichever backing slot a derived row came from. */
function setRowSendState(
  rowId: string,
  patch: { threadId?: string | null; deliveryStatus?: DeliveryStatus | null },
): void {
  if (rowId === 'CLIENT') {
    if (patch.threadId !== undefined) composeClient.value.threadId = patch.threadId
    if (patch.deliveryStatus !== undefined) composeClient.value.deliveryStatus = patch.deliveryStatus
    return
  }
  if (rowId === 'AGENT') {
    if (patch.threadId !== undefined) composeAgent.value.threadId = patch.threadId
    if (patch.deliveryStatus !== undefined) composeAgent.value.deliveryStatus = patch.deliveryStatus
    return
  }
  if (rowId === 'OTHER') {
    if (patch.threadId !== undefined) composeOther.value.threadId = patch.threadId
    if (patch.deliveryStatus !== undefined) composeOther.value.deliveryStatus = patch.deliveryStatus
    return
  }
  if (rowId.startsWith('CARRIER-')) {
    const code = rowId.slice('CARRIER-'.length)
    const b = composeCarrierBlocks.value.find((x) => x.code === code)
    if (!b) return
    if (patch.threadId !== undefined) b.threadId = patch.threadId
    if (patch.deliveryStatus !== undefined) b.deliveryStatus = patch.deliveryStatus
  }
}

function openEmail(c: SupportCase, suggestedTemplate?: EmailTemplateKey) {
  emailCase.value = c
  const auto: EmailTemplateKey =
    suggestedTemplate ??
    (c.status === 'Action Required' ? 'resubmit_corrections' :
     c.status === 'Underwriting' ? 'underwriting_inquiry' :
     c.status === 'Ready to Issue' ? 'confirm_approval' :
     c.status === 'Pending Carrier' ? 'follow_up_pending' :
     'custom')
  emailTemplate.value = auto
  composePhase.value = 'compose'
  applyTemplate(auto, { skipReseed: true })
  // Default: case's primary carrier pre-checked
  const tpl = emailTemplates.value.find((t) => t.id === auto)
  seedComposeState(c, tpl, new Set([c.carrier]))
  // Default: all case documents attached + no fresh files yet
  attachedDocIds.value = new Set(c.documents.map((d) => d.id))
  freshAttachments.value = []
  saveFreshToCase.value = true
  composeDragOver.value = false
  showEmail.value = true
}

/**
 * Open the compose modal pre-loaded with:
 *  - send_quotation template
 *  - client + agent + responding carrier pre-checked
 *  - PDF auto-attached as a fresh file
 */
function openEmailWithQuotation(c: SupportCase, q: Quotation, pdfDoc: DocumentEntry, pdfBlobSize: number) {
  emailCase.value = c
  emailTemplate.value = 'send_quotation'
  composePhase.value = 'compose'
  applyTemplate('send_quotation', { skipReseed: true })

  const tpl = emailTemplates.value.find((t) => t.id === 'send_quotation')
  // Pre-select client + agent + the carrier whose response this quotation came from
  seedComposeState(c, tpl, new Set(['client', 'agent', q.carrierCode]))

  // The PDF is already in case.documents, so just include it in attachedDocIds
  attachedDocIds.value = new Set([pdfDoc.id])

  // Customize subject + body with the actual quotation number
  emailForm.value.subject = `ใบเสนอราคา ${q.quotationNumber} — ${c.clientName} (เคส ${c.caseId})`
  emailForm.value.body = `เรียน ท่านผู้รับ,

ตามที่ได้มีการพิจารณาและจัดเตรียมข้อเสนอประกันให้กับ ${c.clientName} เคส ${c.caseId} แล้วนั้น

ขอแนบใบเสนอราคา (เลขที่ ${q.quotationNumber}) มาเพื่อพิจารณา รายละเอียดของข้อเสนอประกอบด้วย:

  • ผู้เอาประกัน: ${c.clientName}
  • ผลิตภัณฑ์: ${c.productName}
  • บริษัทประกัน: ${q.carrierName}
  • ทุนประกัน: ฿${q.coverage_amount.toLocaleString('th-TH')}
  • เบี้ยประกัน: ฿${q.annual_premium.toLocaleString('th-TH')} (${q.premium_mode === 'annual' ? 'รายปี' : q.premium_mode})
  • ใบเสนอราคามีผลถึง: ${q.validUntil}

หากมีคำถามหรือต้องการความชัดเจนเพิ่มเติม รบกวนติดต่อกลับมาที่อีเมลนี้

ขอบคุณครับ/ค่ะ

${emailSignature}`

  freshAttachments.value = []
  saveFreshToCase.value = false
  composeDragOver.value = false
  void pdfBlobSize // referenced for future use (e.g. size validation)
  showEmail.value = true
}

function applyTemplate(key: EmailTemplateKey, opts: { skipReseed?: boolean } = {}) {
  if (!emailCase.value) return
  const tpl = emailTemplates.value.find((t) => t.id === key)
  if (!tpl) return
  const built = renderForCase(tpl, emailCase.value)
  emailTemplate.value = key
  emailForm.value = {
    cc: '',
    subject: built.subject,
    body: built.body + '\n\n' + emailSignature,
  }
  // Group filtering depends on the template's department + the case's type.
  // Re-tick defaults for any carrier that was previously sending so the user
  // doesn't lose their selection when switching templates.
  if (!opts.skipReseed) {
    reseedGroupsForTemplate(emailCase.value, tpl)
  }
}

function closeEmail() {
  showEmail.value = false
  emailCase.value = null
  composePhase.value = 'compose'
  composeCarrierBlocks.value = []
  composeClient.value = { selected: false, to: '', threadId: null, deliveryStatus: null }
  composeAgent.value = { selected: false, to: '', threadId: null, deliveryStatus: null }
  composeOther.value = { selected: false, to: '', threadId: null, deliveryStatus: null }
  carrierGroupSearch.value = {}
}

// Apply a delivery-status update from the API mock onto a specific thread
function updateThreadDelivery(caseRef: SupportCase, threadId: string, status: DeliveryStatus, when: string) {
  const t = caseRef.threads.find((x) => x.id === threadId)
  if (!t) return
  t.deliveryStatus = status
  if (status === 'delivered') t.deliveredAt = when
  if (status === 'bounced') t.bouncedReason = 'Address rejected by recipient mail server'
  // Mirror to the inline recipient row so the modal UI lights up live
  const row = carrierRecipients.value.find((r) => r.threadId === threadId)
  if (row) setRowSendState(row.rowId, { deliveryStatus: status })
}

// ─── Scheduled sends (in-memory) ──────────────────────────────────────────
// A scheduled send captures a frozen payload + a setTimeout. When the timer
// fires it executes a headless send against the case (case is updated directly,
// no compose modal needed). Refreshing the page loses any scheduled sends.

interface SendRecipientSnapshot {
  rowId: string
  code: string                 // carrier code or CLIENT/AGENT/OTHER
  role: RecipientRole
  to: string
}

interface SendPayloadSnapshot {
  recipients: SendRecipientSnapshot[]
  cc: string
  subject: string
  body: string
  templateLabel: string
  attachments: Array<{ id: string; name: string; size: string }>
  /** Fresh files that should also be appended to caseRef.documents. */
  freshDocs: DocumentEntry[]
}

interface ScheduledSend {
  id: string
  caseId: string           // SupportCase.caseId — used to look up the live case
  scheduledAt: number      // epoch ms
  createdAt: number
  payload: SendPayloadSnapshot
  /** setTimeout handle so we can cancel before it fires. */
  timerId: ReturnType<typeof setTimeout> | null
  status: 'pending' | 'firing' | 'sent' | 'cancelled' | 'failed'
}

const scheduledSends = ref<ScheduledSend[]>([])

function scheduledForCase(caseId: string): ScheduledSend[] {
  return scheduledSends.value
    .filter((s) => s.caseId === caseId && s.status === 'pending')
    .sort((a, b) => a.scheduledAt - b.scheduledAt)
}

/** All pending scheduled sends, soonest first — drives the global panel. */
const pendingScheduledSends = computed<ScheduledSend[]>(() =>
  scheduledSends.value
    .filter((s) => s.status === 'pending')
    .sort((a, b) => a.scheduledAt - b.scheduledAt),
)

/** Bypass the timer and fire immediately. */
function sendScheduledNow(id: string) {
  const entry = scheduledSends.value.find((s) => s.id === id)
  if (!entry || entry.status !== 'pending') return
  if (entry.timerId) clearTimeout(entry.timerId)
  fireScheduledSend(id)
}

/** Open the case-detail drawer for a scheduled-send entry. */
function openCaseFromSchedule(caseId: string) {
  const c = cases.value.find((x) => x.caseId === caseId)
  if (c) openDetail(c)
}

function snapshotCurrentCompose(): SendPayloadSnapshot | null {
  if (!emailCase.value) return null
  const f = emailForm.value
  const recipients: SendRecipientSnapshot[] = carrierRecipients.value
    .filter((r) => r.selected)
    .map((r) => ({ rowId: r.rowId, code: r.code, role: r.role, to: r.to }))
  const tpl = emailTemplates.value.find((t) => t.id === emailTemplate.value)
  const attachments = [
    ...selectedDocs.value.map((d) => ({ id: d.id, name: d.name, size: d.size })),
    ...freshAttachments.value.map((af) => ({ id: af.id, name: af.name, size: af.size })),
  ]
  const today = new Date().toISOString().slice(0, 10)
  const freshDocs: DocumentEntry[] = saveFreshToCase.value
    ? freshAttachments.value.map((af) => ({
        id: af.id,
        name: af.name,
        uploadedAt: today,
        size: af.size,
      }))
    : []
  return {
    recipients,
    cc: f.cc,
    subject: f.subject,
    body: f.body,
    templateLabel: tpl?.label ?? emailTemplate.value,
    attachments,
    freshDocs,
  }
}

/** Headless send used by both the scheduler firing AND by sendEmail's core. */
async function runHeadlessSend(
  caseRef: SupportCase,
  payload: SendPayloadSnapshot,
  onRowStatus?: (rowId: string, threadId: string | null, status: DeliveryStatus | null) => void,
): Promise<void> {
  const sentAt = new Date().toISOString().slice(0, 16).replace('T', ' ')
  const rowThreadIds = new Map<string, string>()
  for (const r of payload.recipients) {
    const localId = 'em-' + Date.now() + '-' + r.rowId + '-' + Math.random().toString(36).slice(2, 5)
    rowThreadIds.set(r.rowId, localId)
    onRowStatus?.(r.rowId, localId, 'queued')
    const thread: EmailThread = {
      id: localId,
      caseId: caseRef.caseId,
      carrierCode: r.code,
      to: r.to,
      cc: payload.cc,
      subject: payload.subject,
      body: payload.body,
      sentAt,
      sentByUser: 'ผู้ดูแลระบบ (คุณ)',
      template: payload.templateLabel,
      status: 'waiting',
      responses: [],
      attachments: payload.attachments.map((a) => ({ id: a.id, name: a.name, size: a.size })),
      messageId: null,
      replyAddress: null,
      fromAddress: null,
      trackedSubject: null,
      deliveryStatus: 'queued',
      deliveredAt: null,
      bouncedReason: null,
    }
    caseRef.threads = [...caseRef.threads, thread]
  }

  if (payload.freshDocs.length) {
    caseRef.documents = [...caseRef.documents, ...payload.freshDocs]
  }

  caseRef.notes = [
    ...caseRef.notes,
    {
      id: 'n-em-' + Date.now(),
      byUser: 'ระบบอีเมล',
      at: sentAt,
      body: `[ส่งอีเมล via API] "${payload.subject}" → ${payload.recipients.length} ปลายทาง (${payload.recipients.map((r) => r.code).join(', ')})`,
    },
  ]

  caseRef.lastUpdated = new Date().toISOString()
  const hasCarrierRecipient = payload.recipients.some((r) => r.role === 'carrier')
  if (caseRef.status === 'Draft' && hasCarrierRecipient) {
    autoTransitionCase(caseRef, 'Pending Carrier', 'ส่งอีเมลถึงบริษัทประกันครั้งแรก', 'auto_send')
  }

  await Promise.all(
    payload.recipients.map(async (r) => {
      const localId = rowThreadIds.get(r.rowId)
      try {
        const res = await emailApi.sendThread(
          {
            caseId: caseRef.caseId,
            carrierCode: r.code,
            to: r.to,
            cc: payload.cc,
            subject: payload.subject,
            body: payload.body,
            template: payload.templateLabel,
          },
          (status, when) => {
            if (localId) {
              updateThreadDelivery(caseRef, localId, status, when)
              onRowStatus?.(r.rowId, localId, status)
            }
          },
        )
        if (localId) {
          const t = caseRef.threads.find((x) => x.id === localId)
          if (t) {
            t.messageId = res.messageId
            t.replyAddress = res.replyAddress
            t.fromAddress = res.fromAddress
            t.trackedSubject = res.trackedSubject
            t.deliveryStatus = res.deliveryStatus
          }
          onRowStatus?.(r.rowId, localId, res.deliveryStatus)
        }
      } catch {
        if (localId) {
          const t = caseRef.threads.find((x) => x.id === localId)
          if (t) t.deliveryStatus = 'failed'
          onRowStatus?.(r.rowId, localId, 'failed')
        }
      }
    }),
  )
}

function scheduleSendAt(whenMs: number): boolean {
  if (!emailCase.value) return false
  if (!canSendCompose.value) return false
  const payload = snapshotCurrentCompose()
  if (!payload) return false
  const caseId = emailCase.value.caseId
  const delay = Math.max(0, whenMs - Date.now())
  const id = 'sch-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6)
  const entry: ScheduledSend = {
    id,
    caseId,
    scheduledAt: whenMs,
    createdAt: Date.now(),
    payload,
    timerId: null,
    status: 'pending',
  }
  entry.timerId = setTimeout(() => fireScheduledSend(id), delay)
  scheduledSends.value = [...scheduledSends.value, entry]
  // Add a note immediately so the case audit trail shows the schedule action.
  const target = cases.value.find((c) => c.caseId === caseId)
  if (target) {
    target.notes = [
      ...target.notes,
      {
        id: 'n-sch-' + Date.now(),
        byUser: 'ระบบอีเมล',
        at: new Date().toISOString().slice(0, 16).replace('T', ' '),
        body: `[ตั้งเวลาส่งอีเมล] "${payload.subject}" → ${payload.recipients.length} ปลายทาง ส่งเวลา ${formatScheduleTime(whenMs)}`,
      },
    ]
  }
  closeEmail()
  return true
}

async function fireScheduledSend(id: string) {
  const entry = scheduledSends.value.find((s) => s.id === id)
  if (!entry || entry.status !== 'pending') return
  const caseRef = cases.value.find((c) => c.caseId === entry.caseId)
  if (!caseRef) {
    entry.status = 'failed'
    return
  }
  entry.status = 'firing'
  try {
    await runHeadlessSend(caseRef, entry.payload)
    entry.status = 'sent'
  } catch {
    entry.status = 'failed'
  }
}

function cancelScheduledSend(id: string) {
  const entry = scheduledSends.value.find((s) => s.id === id)
  if (!entry || entry.status !== 'pending') return
  if (entry.timerId) clearTimeout(entry.timerId)
  entry.status = 'cancelled'
  const target = cases.value.find((c) => c.caseId === entry.caseId)
  if (target) {
    target.notes = [
      ...target.notes,
      {
        id: 'n-sch-cancel-' + Date.now(),
        byUser: 'ระบบอีเมล',
        at: new Date().toISOString().slice(0, 16).replace('T', ' '),
        body: `[ยกเลิกการตั้งเวลา] "${entry.payload.subject}"`,
      },
    ]
  }
  // Prune cancelled entries after a beat so the UI confirms.
  setTimeout(() => {
    scheduledSends.value = scheduledSends.value.filter((s) => s.id !== id)
  }, 500)
}

function formatScheduleTime(ms: number): string {
  const d = new Date(ms)
  const pad = (n: number) => n.toString().padStart(2, '0')
  return `${d.getFullYear() + 543}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function relativeTimeUntil(ms: number): string {
  const diff = ms - Date.now()
  if (diff <= 0) return 'กำลังส่ง...'
  const min = Math.round(diff / 60000)
  if (min < 60) return `อีก ${min} นาที`
  const hr = Math.round(min / 60)
  if (hr < 24) return `อีก ${hr} ชม.`
  const day = Math.round(hr / 24)
  return `อีก ${day} วัน`
}

// ─── Schedule menu UI state ──────────────────────────────────────────────
const showScheduleMenu = ref(false)
const showCustomScheduleDialog = ref(false)
const customScheduleDate = ref('')
const customScheduleTime = ref('')

function toggleScheduleMenu() {
  showScheduleMenu.value = !showScheduleMenu.value
}

function schedulePreset(minutes: number) {
  const when = Date.now() + minutes * 60 * 1000
  showScheduleMenu.value = false
  scheduleSendAt(when)
}

function openCustomSchedule() {
  showScheduleMenu.value = false
  // Default: tomorrow 09:00.
  const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000)
  const pad = (n: number) => n.toString().padStart(2, '0')
  customScheduleDate.value = `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}`
  customScheduleTime.value = '09:00'
  showCustomScheduleDialog.value = true
}

const customScheduleValid = computed(() => {
  if (!customScheduleDate.value || !customScheduleTime.value) return false
  const ms = Date.parse(`${customScheduleDate.value}T${customScheduleTime.value}`)
  return !Number.isNaN(ms) && ms > Date.now()
})

function confirmCustomSchedule() {
  if (!customScheduleValid.value) return
  const ms = Date.parse(`${customScheduleDate.value}T${customScheduleTime.value}`)
  if (scheduleSendAt(ms)) {
    showCustomScheduleDialog.value = false
  }
}

async function sendEmail() {
  if (!emailCase.value || composePhase.value === 'sending') return
  if (!canSendCompose.value) return

  const caseRef = emailCase.value
  const f = emailForm.value
  const sentAt = new Date().toISOString().slice(0, 16).replace('T', ' ')
  const recipients = carrierRecipients.value.filter((r) => r.selected)

  composePhase.value = 'sending'

  // Build threads + batch items. We also remember each row's thread id locally
  // because `recipients` is a derived array — mutating r.threadId wouldn't land
  // on the backing state, so we use setRowSendState() + a local map.
  const items: Parameters<typeof emailApi.sendBatch>[0] = []
  const rowThreadIds = new Map<string, string>()
  for (const r of recipients) {
    const localId = 'em-' + Date.now() + '-' + r.rowId
    rowThreadIds.set(r.rowId, localId)
    setRowSendState(r.rowId, { threadId: localId, deliveryStatus: 'queued' })
    const thread: EmailThread = {
      id: localId,
      caseId: caseRef.caseId,
      carrierCode: r.code,
      to: r.to,
      cc: f.cc,
      subject: f.subject,
      body: f.body,
      sentAt,
      sentByUser: 'ผู้ดูแลระบบ (คุณ)',
      template: emailTemplates.value.find((t) => t.id === emailTemplate.value)?.label ?? emailTemplate.value,
      status: 'waiting',
      responses: [],
      attachments: [
        ...selectedDocs.value.map((d) => ({ id: d.id, name: d.name, size: d.size })),
        ...freshAttachments.value.map((f) => ({ id: f.id, name: f.name, size: f.size })),
      ],
      messageId: null,
      replyAddress: null,
      fromAddress: null,
      trackedSubject: null,
      deliveryStatus: 'queued',
      deliveredAt: null,
      bouncedReason: null,
    }
    caseRef.threads = [...caseRef.threads, thread]
    items.push({
      caseId: caseRef.caseId,
      carrierCode: r.code,
      to: r.to,
      cc: f.cc,
      subject: f.subject,
      body: f.body,
      template: thread.template,
    })
  }

  // Persist fresh files to the case's documents if user opted in
  if (saveFreshToCase.value && freshAttachments.value.length) {
    const newDocs: DocumentEntry[] = freshAttachments.value.map((f) => ({
      id: f.id,
      name: f.name,
      uploadedAt: sentAt.slice(0, 10),
      size: f.size,
    }))
    caseRef.documents = [...caseRef.documents, ...newDocs]
  }

  caseRef.notes = [
    ...caseRef.notes,
    {
      id: 'n-em-' + Date.now(),
      byUser: 'ระบบอีเมล',
      at: sentAt,
      body: `[ส่งอีเมล via API] "${f.subject}" → ${recipients.length} บริษัทประกัน (${recipients.map((r) => r.code).join(', ')})${
        freshAttachments.value.length ? ` · แนบไฟล์ใหม่ ${freshAttachments.value.length} ไฟล์` : ''
      }`,
    },
  ]

  // Auto-bump lastUpdated and Draft→Pending Carrier when sending to a carrier
  caseRef.lastUpdated = new Date().toISOString()
  const hasCarrierRecipient = recipients.some((r) => r.role === 'carrier')
  if (caseRef.status === 'Draft' && hasCarrierRecipient) {
    autoTransitionCase(caseRef, 'Pending Carrier', 'ส่งอีเมลถึงบริษัทประกันครั้งแรก', 'auto_send')
  }

  // Per-recipient sendThread calls in parallel — gives us per-row delivery
  // callbacks. (For one-case-multi-carrier we don't use sendBatch's grouped
  // callback signature because all items share the same caseId.)
  void items
  await Promise.all(
    recipients.map(async (r) => {
      const localId = rowThreadIds.get(r.rowId)
      try {
        const res = await emailApi.sendThread(
          {
            caseId: caseRef.caseId,
            carrierCode: r.code,
            to: r.to,
            cc: f.cc,
            subject: f.subject,
            body: f.body,
            template: emailTemplates.value.find((t) => t.id === emailTemplate.value)?.label ?? emailTemplate.value,
          },
          (status, when) => {
            if (localId) updateThreadDelivery(caseRef, localId, status, when)
          },
        )
        if (localId) {
          const t = caseRef.threads.find((x) => x.id === localId)
          if (t) {
            t.messageId = res.messageId
            t.replyAddress = res.replyAddress
            t.fromAddress = res.fromAddress
            t.trackedSubject = res.trackedSubject
            t.deliveryStatus = res.deliveryStatus
          }
          setRowSendState(r.rowId, { deliveryStatus: res.deliveryStatus })
        }
      } catch {
        if (localId) {
          const t = caseRef.threads.find((x) => x.id === localId)
          if (t) t.deliveryStatus = 'failed'
          setRowSendState(r.rowId, { deliveryStatus: 'failed' })
        }
      }
    }),
  )

  // Auto-close 2s after all delivered, but only if no bounces/failures
  setTimeout(() => {
    if (allDelivered.value && !anyBouncedOrFailed.value && composePhase.value === 'sending') {
      closeEmail()
    }
  }, 2000)
}

// ─────────────────────────────────────────────────────────────────────────────
// AI summary heuristic engine
// Pure function — no side effects. Easy to swap with real Anthropic call later.
// Signature stays: summarizeResponse(text, context) → AISummary
// ─────────────────────────────────────────────────────────────────────────────

interface SummaryContext {
  caseId: string
  carrierName: string
  clientName: string
}

function summarizeResponse(text: string, _ctx: SummaryContext): AISummary {
  const lower = text.toLowerCase()
  const has = (...needles: string[]) => needles.some((n) => text.includes(n) || lower.includes(n.toLowerCase()))

  // Sentiment detection — Thai + English keywords
  let sentiment: Sentiment = 'neutral'
  if (has('อนุมัติ', 'approved', 'approve', 'ผ่าน', 'ออกกรมธรรม์', 'ready to issue', 'pass')) {
    sentiment = 'positive'
  } else if (has('ปฏิเสธ', 'rejected', 'reject', 'decline', 'ไม่อนุมัติ', 'ไม่ผ่าน', 'ไม่รับ')) {
    sentiment = 'rejecting'
  } else if (has('ขอเอกสาร', 'ขอข้อมูล', 'แนบเพิ่ม', 'ส่งเพิ่ม', 'missing', 'require', 'need', 'submit', 'provide', 'clarif', 'ขอ')) {
    sentiment = 'needs_info'
  }

  // Risk score (1=safe, 5=critical)
  let riskScore: 1 | 2 | 3 | 4 | 5 = 2
  if (sentiment === 'positive') riskScore = 1
  if (sentiment === 'neutral') riskScore = 2
  if (sentiment === 'needs_info') riskScore = 3
  if (sentiment === 'rejecting') riskScore = 5
  if (has('urgent', 'ด่วน', '24 ชั่วโมง', 'asap')) riskScore = Math.max(riskScore, 4) as typeof riskScore

  // Action item extraction — look for imperative clauses
  const actions: { id: string; label: string; done: boolean }[] = []
  let actionIdx = 0
  const pushAction = (label: string) =>
    actions.push({ id: 'a-' + (++actionIdx), label, done: false })

  if (has('ตรวจสุขภาพ', 'medical', 'medical report')) {
    pushAction('นัดลูกค้าตรวจสุขภาพใหม่ และส่งผลกลับ')
  }
  if (has('ลายเซ็น', 'signature', 'sign again', 'เซ็นใหม่')) {
    pushAction('ขอให้ลูกค้าเซ็นใหม่ในใบสมัครพร้อมพยาน')
  }
  if (has('สำเนาบัตร', 'id card', 'identification', 'บัตรประชาชน')) {
    pushAction('แนบสำเนาบัตรประชาชนที่ชัดเจนและรับรองสำเนาถูกต้อง')
  }
  if (has('สำเนาทะเบียนบ้าน', 'house registration')) {
    pushAction('แนบสำเนาทะเบียนบ้านพร้อมรับรอง')
  }
  if (has('รายได้', 'income', 'salary slip', 'หลักฐานรายได้')) {
    pushAction('แนบหลักฐานรายได้ 3 เดือนล่าสุด')
  }
  if (has('ใบเสร็จ', 'payment receipt', 'receipt')) {
    pushAction('แนบใบเสร็จการชำระเบี้ยงวดแรก')
  }
  if (has('โทร', 'call', 'phone', 'ติดต่อกลับ')) {
    pushAction('โทรหา underwriter เพื่อยืนยันรายละเอียด')
  }
  if (sentiment === 'positive' && has('เลขกรมธรรม์', 'policy number', 'pol-')) {
    pushAction('บันทึกเลขกรมธรรม์ที่ได้รับและแจ้งลูกค้า')
  }

  // Default fallback if no actions detected
  if (!actions.length) {
    if (sentiment === 'needs_info') pushAction('ตรวจสอบเอกสารที่บริษัทประกันขอเพิ่มและส่งกลับภายใน 3 วัน')
    else if (sentiment === 'positive') pushAction('แจ้งลูกค้าและเตรียมรอเลขกรมธรรม์')
    else if (sentiment === 'rejecting') pushAction('ทบทวนเหตุผลและพิจารณาส่งบริษัทประกันรายอื่น')
    else pushAction('ตอบกลับเพื่อขอความชัดเจน')
  }

  // Suggested next reply template
  let suggestedReplyTemplate: string | null = null
  let suggestedReplyHint = ''
  if (sentiment === 'needs_info') {
    suggestedReplyTemplate = 'resubmit_corrections'
    suggestedReplyHint = 'รวบรวมเอกสารตามรายการแล้วใช้เทมเพลต "ส่งเอกสารแก้ไข"'
  } else if (sentiment === 'positive') {
    suggestedReplyTemplate = 'confirm_approval'
    suggestedReplyHint = 'ขอเลขกรมธรรม์เพื่อนำเข้าระบบโดยใช้เทมเพลต "ยืนยันการอนุมัติ"'
  } else if (sentiment === 'rejecting') {
    suggestedReplyHint = 'ปรึกษาผู้จัดการทีมเรื่องเสนอบริษัทประกันรายอื่น'
  } else {
    suggestedReplyTemplate = 'underwriting_inquiry'
    suggestedReplyHint = 'ขอความชัดเจนเพิ่มเติมโดยใช้เทมเพลต "สอบถามผลพิจารณา"'
  }

  // Key entities — date / policy number / amount
  const entities: { label: string; value: string }[] = []
  const dateMatch = text.match(/(\d{1,2}\s*(?:ม\.ค\.|ก\.พ\.|มี\.ค\.|เม\.ย\.|พ\.ค\.|มิ\.ย\.|ก\.ค\.|ส\.ค\.|ก\.ย\.|ต\.ค\.|พ\.ย\.|ธ\.ค\.)\s*\d{4})/i)
  if (dateMatch) entities.push({ label: 'วันที่ที่ระบุ', value: dateMatch[1] })
  const polMatch = text.match(/POL-[A-Z]+-\d+-\d+/i)
  if (polMatch) entities.push({ label: 'เลขกรมธรรม์', value: polMatch[0] })
  const refMatch = text.match(/REF[-#:]?\s*([A-Z0-9-]+)/i)
  if (refMatch) entities.push({ label: 'เลขอ้างอิง', value: refMatch[1] })
  const daysMatch = text.match(/(\d+)\s*(?:วันทำการ|business days?|working days?)/i)
  if (daysMatch) entities.push({ label: 'ระยะเวลา', value: `${daysMatch[1]} วันทำการ` })

  // One-liner — synthesize from sentiment + first action
  const sentimentLabel: Record<Sentiment, string> = {
    positive: '✅ อนุมัติ',
    neutral: 'ℹ️ ตอบกลับเพื่อให้ข้อมูลเพิ่มเติม',
    needs_info: '⚠️ ขอเอกสาร/ข้อมูลเพิ่ม',
    rejecting: '🚫 ปฏิเสธหรือต้องปรับปรุงข้อมูลสำคัญ',
  }
  const oneLiner = `${sentimentLabel[sentiment]} — ${actions[0].label}`

  return {
    oneLiner,
    sentiment,
    riskScore,
    actions,
    suggestedReplyTemplate,
    suggestedReplyHint,
    keyEntities: entities,
    generatedAt: new Date().toISOString().slice(0, 16).replace('T', ' '),
  }
}

// Simulated carrier replies — used by both the "🎲" debug button and per-thread sim
const SIM_RESPONSES = [
  {
    fromName: 'Underwriter — AIA',
    fromAddress: 'underwriting@aia.co.th',
    body: `เรียน ทีมงาน ABC Insurance,\n\nขอบคุณสำหรับการติดตาม ใบสมัครได้ผ่านการพิจารณาเบื้องต้นแล้ว แต่ทาง underwriter ขอเอกสารเพิ่มเติมดังนี้:\n\n1. สำเนาบัตรประชาชนที่ชัดเจน (รับรองสำเนาถูกต้อง)\n2. ผลตรวจสุขภาพไม่เกิน 30 วัน\n3. หลักฐานรายได้ 3 เดือนล่าสุด\n\nรบกวนส่งกลับภายใน 5 วันทำการ มิเช่นนั้นเคสจะถูกปิดอัตโนมัติ\n\nขอบคุณค่ะ`,
  },
  {
    fromName: 'Khun Somying — Thai Life',
    fromAddress: 'underwriting@thailife.com',
    body: `เรียน คุณ ABC,\n\nยินดีที่จะแจ้งว่าใบสมัครได้รับการอนุมัติแล้ว เลขกรมธรรม์: POL-TLI-2569-00891 มีผลคุ้มครองตั้งแต่ 15 มิ.ย. 2569\n\nกรุณาแจ้งลูกค้าและเตรียมเรียกเก็บเบี้ยงวดแรกได้ทันที\n\nขอบคุณค่ะ`,
  },
  {
    fromName: 'MTL New Business',
    fromAddress: 'newcase@muangthai.co.th',
    body: `เรียน ทีมงาน,\n\nเสียใจที่ต้องแจ้งให้ทราบว่าใบสมัครไม่สามารถอนุมัติได้ในรูปแบบปัจจุบัน เนื่องจากค่า BMI เกินเกณฑ์ที่กำหนด หากลูกค้าประสงค์ดำเนินการต่อ ขอให้ตรวจสุขภาพใหม่ภายใน 60 วัน\n\nหากมีคำถามเพิ่มเติม กรุณาติดต่อกลับ`,
  },
  {
    fromName: 'BLA Underwriting',
    fromAddress: 'underwriting@bla.co.th',
    body: `เรียน ทีมงาน,\n\nได้รับใบสมัครและกำลังพิจารณาอยู่ คาดว่าจะสามารถแจ้งผลได้ภายใน 3 วันทำการ หากต้องการเอกสารเพิ่ม จะติดต่อกลับ\n\nขอบคุณค่ะ`,
  },
  {
    fromName: 'Viriyah Case Manager',
    fromAddress: 'underwriting@viriyah.co.th',
    body: `เรียน คุณตัวแทน,\n\nกรุณาแนบรูปถ่ายรถยนต์ทั้ง 6 ด้าน พร้อมเลขทะเบียนชัดเจน เพื่อพิจารณาเบี้ยให้ถูกต้อง ลายเซ็นในใบสมัครต้องตรงกับบัตรประชาชน รบกวนเซ็นใหม่หากไม่ตรง\n\nReply ภายใน 7 วันทำการ`,
  },
]

function pickSimulatedResponse(): typeof SIM_RESPONSES[number] {
  return SIM_RESPONSES[Math.floor(Math.random() * SIM_RESPONSES.length)]
}

// Mark a thread as overdue if no response after 48h
function computeThreadStatus(t: EmailThread): ThreadStatus {
  if (t.status === 'resolved') return 'resolved'
  if (t.responses.length > 0) return 'replied'
  // Compute hours since sent
  const sent = new Date(t.sentAt.replace(' ', 'T'))
  const now = new Date()
  const hours = (now.getTime() - sent.getTime()) / 3_600_000
  return hours >= 48 ? 'overdue' : 'waiting'
}


// ─────────────────────────────────────────────────────────────────────────────
// Response paste-in + AI summary + status transitions
// ─────────────────────────────────────────────────────────────────────────────

const showResponsePaste = ref(false)
const responseThread = ref<{ thread: EmailThread; caseRef: SupportCase } | null>(null)
const responseText = ref('')
const responseFromName = ref('')
const responseFromAddress = ref('')

function openResponsePaste(c: SupportCase, t: EmailThread) {
  responseThread.value = { thread: t, caseRef: c }
  responseText.value = ''
  responseFromName.value = ''
  responseFromAddress.value = t.to
  showResponsePaste.value = true
}

function submitResponse() {
  if (!responseThread.value || !responseText.value.trim()) return
  const { thread, caseRef } = responseThread.value
  const newResp: EmailResponse = {
    id: 'rsp-' + Date.now(),
    receivedAt: new Date().toISOString().slice(0, 16).replace('T', ' '),
    fromAddress: responseFromAddress.value || thread.to,
    fromName: responseFromName.value || 'Carrier',
    body: responseText.value.trim(),
    aiSummary: null,
  }
  // Auto-fire AI summary
  newResp.aiSummary = summarizeResponse(newResp.body, {
    caseId: caseRef.caseId,
    carrierName: carrierDirectory[thread.carrierCode]?.name ?? thread.carrierCode,
    clientName: caseRef.clientName,
  })
  thread.responses = [...thread.responses, newResp]
  // Hybrid: auto-flip waiting → replied on first response
  if (thread.status === 'waiting' || thread.status === 'overdue') {
    thread.status = 'replied'
  }
  caseRef.notes = [...caseRef.notes, {
    id: 'n-rsp-' + Date.now(),
    byUser: 'ระบบอีเมล',
    at: newResp.receivedAt,
    body: `[ได้รับคำตอบ] จาก ${newResp.fromName}: ${newResp.aiSummary?.oneLiner ?? '(ไม่มีสรุป)'}`,
  }]
  caseRef.lastUpdated = new Date().toISOString()
  maybeSetAITransition(caseRef, thread, newResp)
  showResponsePaste.value = false
  // Auto-open summary
  openAISummary(thread, newResp)
}

// Simulated single response — for demo
function simulateResponseForThread(c: SupportCase, t: EmailThread) {
  const sim = pickSimulatedResponse()
  const resp: EmailResponse = {
    id: 'rsp-sim-' + Date.now(),
    receivedAt: new Date().toISOString().slice(0, 16).replace('T', ' '),
    fromAddress: sim.fromAddress,
    fromName: sim.fromName,
    body: sim.body,
    aiSummary: summarizeResponse(sim.body, {
      caseId: c.caseId,
      carrierName: carrierDirectory[t.carrierCode]?.name ?? t.carrierCode,
      clientName: c.clientName,
    }),
  }
  t.responses = [...t.responses, resp]
  if (t.status === 'waiting' || t.status === 'overdue') t.status = 'replied'
  c.notes = [...c.notes, {
    id: 'n-rsp-sim-' + Date.now(),
    byUser: 'ระบบอีเมล (sim)',
    at: resp.receivedAt,
    body: `[🎲 จำลองคำตอบ] จาก ${resp.fromName}: ${resp.aiSummary?.oneLiner ?? ''}`,
  }]
  c.lastUpdated = new Date().toISOString()
  maybeSetAITransition(c, t, resp)
  openAISummary(t, resp)
}

function markThreadResolved(t: EmailThread, c: SupportCase) {
  t.status = 'resolved'
  c.notes = [...c.notes, {
    id: 'n-resolved-' + Date.now(),
    byUser: 'ผู้ดูแลระบบ (คุณ)',
    at: new Date().toISOString().slice(0, 16).replace('T', ' '),
    body: `[ปิด thread] "${t.subject}"`,
  }]
}

// ─────────────────────────────────────────────────────────────────────────────
// AI summary modal
// ─────────────────────────────────────────────────────────────────────────────

const showAISummary = ref(false)
const aiSummaryContext = ref<{ thread: EmailThread; response: EmailResponse } | null>(null)

function openAISummary(thread: EmailThread, response: EmailResponse) {
  aiSummaryContext.value = { thread, response }
  showAISummary.value = true
}

function toggleAIAction(actionId: string) {
  if (!aiSummaryContext.value?.response.aiSummary) return
  const summary = aiSummaryContext.value.response.aiSummary
  const action = summary.actions.find((a) => a.id === actionId)
  if (action) action.done = !action.done
}

const aiActionsAllDone = computed(() => {
  if (!aiSummaryContext.value?.response.aiSummary) return false
  return aiSummaryContext.value.response.aiSummary.actions.every((a) => a.done)
})

function aiSummaryUseSuggestedReply() {
  if (!aiSummaryContext.value) return
  const { thread } = aiSummaryContext.value
  const summary = aiSummaryContext.value.response.aiSummary
  if (!summary?.suggestedReplyTemplate) return
  // Find the case
  const caseRef = cases.value.find((c) => c.caseId === thread.caseId)
  if (!caseRef) return
  // Hybrid: clicking suggested reply also marks resolved (after they send)
  showAISummary.value = false
  openEmail(caseRef, summary.suggestedReplyTemplate as EmailTemplateKey)
}

// ─────────────────────────────────────────────────────────────────────────────
// Quotation builder — DeepSeek + jsPDF
// ─────────────────────────────────────────────────────────────────────────────

type QuotationMode = 'manual' | 'ai_assisted'

const showQuotation = ref(false)
const quotationMode = ref<QuotationMode>('manual')
const quotationLoading = ref(false)
const quotationGenerating = ref(false)
const quotationGenerated = ref<{ fileName: string; sizeBytes: number; doc: DocumentEntry } | null>(null)
const quotationContext = ref<{
  caseRef: SupportCase
  thread?: EmailThread
  response?: EmailResponse
} | null>(null)
const quotationDraft = ref<Quotation | null>(null)

// Tenant/agency info (eventually from tenant store)
const QUOTATION_AGENCY_NAME = 'บริษัท เอบีซี อินชัวรันส์ จำกัด'
const QUOTATION_AGENCY_PHONE = '02-555-0100'
const QUOTATION_AGENCY_EMAIL = 'support@abc-insure.co.th'

function buildEmptyExtraction(): QuotationExtraction {
  return {
    proposal_summary: '',
    is_quotation_ready: false,
    policy_number: null,
    coverage_amount: 0,
    annual_premium: 0,
    premium_mode: 'annual',
    coverage_period_years: 20,
    payment_period_years: 10,
    effective_date_thai: null,
    waiting_period_days: 0,
    riders: [],
    conditions: [],
    exclusions: [],
    documents_required: [],
    next_steps: '',
  }
}

function buildQuotationFrom(caseRef: SupportCase, carrierCode: string, extraction: QuotationExtraction): Quotation {
  const carrier = carrierDirectory[carrierCode]
  return {
    caseId: caseRef.caseId,
    carrierName: carrier?.name ?? carrierCode,
    carrierCode,
    clientName: caseRef.clientName,
    productName: caseRef.productName,
    ...extraction,
    quotationNumber: quotationApi.makeQuotationNumber(caseRef.caseId),
    generatedAt: new Date().toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }),
    agentName: caseRef.agentName,
    agencyName: QUOTATION_AGENCY_NAME,
    agencyPhone: QUOTATION_AGENCY_PHONE,
    agencyEmail: QUOTATION_AGENCY_EMAIL,
    validUntil: quotationApi.defaultValidUntil(),
  }
}

/** Manual entry — opens builder with blank form pre-filled from case data */
function openQuotationManually(caseRef: SupportCase) {
  quotationContext.value = { caseRef }
  quotationMode.value = 'manual'
  quotationLoading.value = false
  quotationDraft.value = buildQuotationFrom(caseRef, caseRef.carrier, buildEmptyExtraction())
  showQuotation.value = true
}

/** AI-assisted entry — pulls from current AI summary context, calls DeepSeek */
async function openQuotationBuilder() {
  if (!aiSummaryContext.value) return
  const { thread, response } = aiSummaryContext.value
  const caseRef = cases.value.find((c) => c.caseId === thread.caseId)
  if (!caseRef) return
  quotationContext.value = { caseRef, thread, response }
  quotationMode.value = 'ai_assisted'
  showAISummary.value = false
  showQuotation.value = true
  quotationLoading.value = true
  quotationDraft.value = null

  try {
    const carrier = carrierDirectory[thread.carrierCode]
    const extraction: QuotationExtraction = await quotationApi.extractQuotationFromResponse(
      response.body,
      {
        caseId: caseRef.caseId,
        carrierName: carrier?.name ?? thread.carrierCode,
        carrierCode: thread.carrierCode,
        clientName: caseRef.clientName,
        productName: caseRef.productName,
      },
    )
    quotationDraft.value = buildQuotationFrom(caseRef, thread.carrierCode, extraction)
  } finally {
    quotationLoading.value = false
  }
}

/** Recompute carrierName when user changes the carrier dropdown in manual mode */
function onQuotationCarrierChange() {
  if (!quotationDraft.value) return
  const carrier = carrierDirectory[quotationDraft.value.carrierCode]
  quotationDraft.value.carrierName = carrier?.name ?? quotationDraft.value.carrierCode
}

function closeQuotationBuilder() {
  showQuotation.value = false
  quotationContext.value = null
  quotationDraft.value = null
  quotationGenerated.value = null
}

async function generateQuotationPdf() {
  if (!quotationDraft.value || !quotationContext.value) return
  quotationGenerating.value = true
  try {
    const result = await quotationPdf.downloadPdf(quotationDraft.value)
    const { caseRef, thread } = quotationContext.value

    // Attach to case documents
    const doc: DocumentEntry = {
      id: 'doc-quote-' + Date.now(),
      name: result.fileName,
      uploadedAt: new Date().toISOString().slice(0, 10),
      size: formatBytes(result.sizeBytes),
    }
    caseRef.documents = [...caseRef.documents, doc]

    // Attach to thread (AI mode only — manual mode has no thread context)
    if (thread) {
      thread.attachments = [
        ...thread.attachments,
        { id: doc.id, name: doc.name, size: doc.size },
      ]
    }

    // Note
    const modeLabel = quotationMode.value === 'ai_assisted' ? 'AI ช่วยกรอก' : 'กรอกเอง'
    const sourceLabel = thread ? `จาก thread "${thread.subject}"` : '(สร้างเอง)'
    caseRef.notes = [
      ...caseRef.notes,
      {
        id: 'n-quote-' + Date.now(),
        byUser: quotationMode.value === 'ai_assisted' ? 'ระบบ AI (DeepSeek)' : 'ผู้ดูแลระบบ (คุณ)',
        at: new Date().toISOString().slice(0, 16).replace('T', ' '),
        body: `[สร้างใบเสนอราคา · ${modeLabel}] ${quotationDraft.value.quotationNumber} ${sourceLabel}`,
      },
    ]

    // Set success state — modal stays open so the user can send the PDF via email
    quotationGenerated.value = {
      fileName: result.fileName,
      sizeBytes: result.sizeBytes,
      doc,
    }
  } finally {
    quotationGenerating.value = false
  }
}

/** Open the email composer with this quotation's PDF auto-attached + recipients pre-filled */
function sendQuotationViaEmail() {
  if (!quotationContext.value || !quotationDraft.value || !quotationGenerated.value) return
  const { caseRef } = quotationContext.value
  const generated = quotationGenerated.value
  // Close quotation modal then open email modal — single-modal pattern
  showQuotation.value = false
  openEmailWithQuotation(caseRef, quotationDraft.value, generated.doc, generated.sizeBytes)
  // Clear quotation state after handoff
  quotationContext.value = null
  quotationDraft.value = null
  quotationGenerated.value = null
}

// Determine if a response can become a quotation
function canCreateQuotation(response: EmailResponse): boolean {
  if (!response.aiSummary) return false
  return response.aiSummary.sentiment === 'positive' || response.aiSummary.sentiment === 'needs_info'
}

// ─────────────────────────────────────────────────────────────────────────────
// Top-level threads view + filtering
// ─────────────────────────────────────────────────────────────────────────────

type ViewTab = 'cases' | 'threads' | 'scheduled'
const viewTab = ref<ViewTab>('cases')

const allThreads = computed(() => {
  const out: { thread: EmailThread; caseRef: SupportCase }[] = []
  for (const c of cases.value) {
    for (const t of c.threads) {
      // Refresh status based on time
      t.status = computeThreadStatus(t)
      out.push({ thread: t, caseRef: c })
    }
  }
  // Newest first
  return out.sort((a, b) => (a.thread.sentAt < b.thread.sentAt ? 1 : -1))
})

const threadStats = computed(() => {
  const all = allThreads.value
  return {
    total: all.length,
    waiting: all.filter((t) => t.thread.status === 'waiting').length,
    overdue: all.filter((t) => t.thread.status === 'overdue').length,
    replied: all.filter((t) => t.thread.status === 'replied').length,
    resolved: all.filter((t) => t.thread.status === 'resolved').length,
  }
})

const threadStatusFilter = ref<'all' | ThreadStatus>('all')
const threadCaseFilter = ref<string | null>(null)

const filteredThreads = computed(() => {
  let list = allThreads.value
  if (threadCaseFilter.value) list = list.filter((t) => t.thread.caseId === threadCaseFilter.value)
  if (threadStatusFilter.value !== 'all') list = list.filter((t) => t.thread.status === threadStatusFilter.value)
  return list
})

function jumpToThreadsForCase(caseId: string) {
  threadCaseFilter.value = caseId
  threadStatusFilter.value = 'all'
  viewTab.value = 'threads'
}

function clearThreadCaseFilter() {
  threadCaseFilter.value = null
}

function threadStatusBadge(s: ThreadStatus): string {
  return {
    waiting: 'bg-amber-50 text-amber-700 ring-amber-200',
    overdue: 'bg-rose-50 text-rose-700 ring-rose-200',
    replied: 'bg-blue-50 text-blue-700 ring-blue-200',
    resolved: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  }[s]
}

function threadStatusLabel(s: ThreadStatus): string {
  return {
    waiting: 'รอตอบกลับ',
    overdue: 'ค้างนาน',
    replied: 'ตอบกลับแล้ว',
    resolved: 'ปิดแล้ว',
  }[s]
}

function sentimentBadge(s: Sentiment): string {
  return {
    positive: 'bg-emerald-50 text-emerald-700',
    neutral: 'bg-slate-100 text-slate-600',
    needs_info: 'bg-amber-50 text-amber-700',
    rejecting: 'bg-rose-50 text-rose-700',
  }[s]
}

function sentimentLabel(s: Sentiment): string {
  return {
    positive: 'อนุมัติ',
    neutral: 'ข้อมูล',
    needs_info: 'ขอเอกสาร',
    rejecting: 'ปฏิเสธ',
  }[s]
}

// Find case from thread for thread view actions
function caseFor(t: EmailThread): SupportCase | undefined {
  return cases.value.find((c) => c.caseId === t.caseId)
}

// Summary for the case-row chip — picks the most "interesting" thread state.
function caseThreadsSummary(c: SupportCase) {
  if (!c.threads.length) return null
  // Refresh derived workflow status (overdue computed on demand)
  for (const t of c.threads) t.status = computeThreadStatus(t)
  const total = c.threads.length
  const overdue = c.threads.filter((t) => t.status === 'overdue').length
  const waiting = c.threads.filter((t) => t.status === 'waiting').length
  const replied = c.threads.filter((t) => t.status === 'replied').length
  const bounced = c.threads.filter((t) => t.deliveryStatus === 'bounced' || t.deliveryStatus === 'failed').length

  // Pick the worst signal
  if (bounced > 0) return { total, label: `${bounced} ตีกลับ`, cls: 'bg-rose-50 text-rose-700', icon: 'pi pi-exclamation-triangle' }
  if (overdue > 0) return { total, label: `${overdue} ค้างนาน`, cls: 'bg-rose-50 text-rose-700', icon: 'pi pi-clock' }
  if (replied > 0) return { total, label: `${replied} ตอบกลับ`, cls: 'bg-blue-50 text-blue-700', icon: 'pi pi-reply' }
  if (waiting > 0) return { total, label: `${waiting} รอตอบ`, cls: 'bg-amber-50 text-amber-700', icon: 'pi pi-clock' }
  return { total, label: `${total} thread`, cls: 'bg-slate-100 text-slate-600', icon: 'pi pi-envelope' }
}

function deliveryStatusBadge(d: DeliveryStatus | null): string {
  if (!d) return 'bg-slate-100 text-slate-500'
  return {
    queued:    'bg-slate-100 text-slate-600',
    sending:   'bg-sky-50 text-sky-700',
    sent:      'bg-blue-50 text-blue-700',
    delivered: 'bg-emerald-50 text-emerald-700',
    bounced:   'bg-rose-50 text-rose-700',
    failed:    'bg-rose-50 text-rose-700',
    scheduled: 'bg-amber-50 text-amber-700',
  }[d]
}

function deliveryStatusLabel(d: DeliveryStatus | null): string {
  if (!d) return '—'
  return {
    queued:    'รอคิว',
    sending:   'กำลังส่ง',
    sent:      'ส่งแล้ว',
    delivered: 'ปลายทางรับ',
    bounced:   'ตีกลับ',
    failed:    'ล้มเหลว',
    scheduled: 'ตั้งเวลา',
  }[d]
}

function deliveryStatusIcon(d: DeliveryStatus | null): string {
  if (!d) return 'pi pi-minus'
  return {
    queued:    'pi pi-clock',
    sending:   'pi pi-spin pi-spinner',
    sent:      'pi pi-send',
    delivered: 'pi pi-check-circle',
    bounced:   'pi pi-times-circle',
    failed:    'pi pi-exclamation-circle',
    scheduled: 'pi pi-calendar',
  }[d]
}

// ─────────────────────────────────────────────────────────────────────────────
// Seed demo threads (mount hook so AI summaries route through the real engine)
// ─────────────────────────────────────────────────────────────────────────────

onMounted(() => {
  // Kick off store loads — these are idempotent, safe to fire-and-forget.
  void contactsStore.load()
  void templatesStore.load()

  // Already seeded? skip.
  if (cases.value.some((c) => c.threads.length > 0)) return

  // c1: ภัทรา / AIA — has an old "Underwriting inquiry" with a positive carrier response
  const c1 = cases.value.find((c) => c.id === 'c1')
  if (c1) {
    const respText = `เรียน ทีมงาน ABC,\n\nยินดีที่จะแจ้งว่าใบสมัคร ${c1.caseId} ผ่านการพิจารณาเรียบร้อยแล้ว เลขกรมธรรม์: POL-AIA-2569-00921 มีผลคุ้มครองตั้งแต่ 15 มิ.ย. 2569 รบกวนแจ้งลูกค้าเตรียมเบี้ยงวดแรก\n\nขอบคุณค่ะ\nUnderwriter AIA`
    const summary = summarizeResponse(respText, { caseId: c1.caseId, carrierName: 'AIA', clientName: c1.clientName })
    c1.threads = [{
      id: 'em-seed-c1-1',
      caseId: c1.caseId,
      carrierCode: 'AIA',
      to: 'underwriting@aia.co.th',
      cc: '',
      subject: `สอบถามผลพิจารณารับประกัน ${c1.caseId} — ${c1.clientName}`,
      body: 'เรียน Underwriter AIA, ขอสอบถามความคืบหน้า...',
      sentAt: '2026-06-09 10:30',
      sentByUser: 'ผู้ดูแลระบบ',
      template: 'สอบถามผลพิจารณา (Underwriting)',
      status: 'replied',
      responses: [{
        id: 'rsp-seed-c1-1',
        receivedAt: '2026-06-10 14:22',
        fromAddress: 'underwriting@aia.co.th',
        fromName: 'Khun Somying — AIA',
        body: respText,
        aiSummary: summary,
      }],
      attachments: [],
      messageId: '<T-seed-c1.aia@abc-insure.co.th>',
      replyAddress: 'support+T-seed-c1@abc-insure.co.th',
      fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
      trackedSubject: `สอบถามผลพิจารณารับประกัน ${c1.caseId} — ${c1.clientName} [#T-seed-c1]`,
      deliveryStatus: 'delivered',
      deliveredAt: '2026-06-09 10:32',
      bouncedReason: null,
    }]
  }

  // c2: ธีรยุทธ / AIA Action Required — waiting reply (just sent)
  const c2 = cases.value.find((c) => c.id === 'c2')
  if (c2) {
    c2.threads = [{
      id: 'em-seed-c2-1',
      caseId: c2.caseId,
      carrierCode: 'AIA',
      to: 'newbiz@aia.co.th',
      cc: '',
      subject: `ส่งเอกสารแก้ไข ${c2.caseId} — ${c2.clientName}`,
      body: 'เรียน ฝ่ายรับประกัน AIA...',
      sentAt: '2026-06-12 11:00',
      sentByUser: 'ผู้ดูแลระบบ',
      template: 'ส่งเอกสารแก้ไข (Action Required)',
      status: 'waiting',
      responses: [],
      attachments: [],
      messageId: '<T-seed-c2.aia@abc-insure.co.th>',
      replyAddress: 'support+T-seed-c2@abc-insure.co.th',
      fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
      trackedSubject: `ส่งเอกสารแก้ไข ${c2.caseId} — ${c2.clientName} [#T-seed-c2]`,
      deliveryStatus: 'delivered',
      deliveredAt: '2026-06-12 11:02',
      bouncedReason: null,
    }]
  }

  // c6: ณัฐวุฒิ / BLA — overdue (sent 5 days ago, no response)
  const c6 = cases.value.find((c) => c.id === 'c6')
  if (c6) {
    c6.threads = [{
      id: 'em-seed-c6-1',
      caseId: c6.caseId,
      carrierCode: 'BLA',
      to: 'underwriting@bla.co.th',
      cc: '',
      subject: `ติดตามสถานะ ${c6.caseId} — ${c6.clientName}`,
      body: 'เรียน BLA, ขอติดตามสถานะ...',
      sentAt: '2026-06-08 09:00',
      sentByUser: 'ผู้ดูแลระบบ',
      template: 'ติดตามสถานะ (Pending Carrier)',
      status: 'waiting', // will compute to 'overdue' via computeThreadStatus
      responses: [],
      attachments: [],
      messageId: '<T-seed-c6.bla@abc-insure.co.th>',
      replyAddress: 'support+T-seed-c6@abc-insure.co.th',
      fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
      trackedSubject: `ติดตามสถานะ ${c6.caseId} — ${c6.clientName} [#T-seed-c6]`,
      deliveryStatus: 'delivered',
      deliveredAt: '2026-06-08 09:02',
      bouncedReason: null,
    }]
  }

  // c3: กัลยา / TLI Ready to Issue — replied with policy number (positive)
  const c3 = cases.value.find((c) => c.id === 'c3')
  if (c3) {
    const respText = `เรียน ทีมงาน ABC,\n\nยินดีแจ้งว่าใบสมัคร ${c3.caseId} ของคุณ ${c3.clientName} ได้รับการอนุมัติแล้ว ออกกรมธรรม์เลข POL-TLI-2569-00734 เบี้ยรายปี 65,000 บาท ทุนประกัน 5,000,000 บาท คุ้มครองตั้งแต่ 20 มิ.ย. 2569 — กรุณาแจ้งลูกค้าชำระเบี้ยงวดแรกภายใน 7 วันทำการ\n\nKhun Wisanu — Thai Life Underwriting`
    const summary = summarizeResponse(respText, { caseId: c3.caseId, carrierName: 'TLI', clientName: c3.clientName })
    c3.threads = [{
      id: 'em-seed-c3-1',
      caseId: c3.caseId,
      carrierCode: 'TLI',
      to: 'underwriting@thailife.com',
      cc: '',
      subject: `สอบถามผลพิจารณา ${c3.caseId} — ${c3.clientName}`,
      body: 'เรียน Underwriter Thai Life, ขอสอบถามความคืบหน้า...',
      sentAt: '2026-06-10 09:15',
      sentByUser: 'ผู้ดูแลระบบ',
      template: 'สอบถามผลพิจารณา (Underwriting)',
      status: 'replied',
      responses: [{
        id: 'rsp-seed-c3-1',
        receivedAt: '2026-06-11 11:42',
        fromAddress: 'underwriting@thailife.com',
        fromName: 'Khun Wisanu — TLI',
        body: respText,
        aiSummary: summary,
      }],
      attachments: [],
      messageId: '<T-seed-c3.tli@abc-insure.co.th>',
      replyAddress: 'support+T-seed-c3@abc-insure.co.th',
      fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
      trackedSubject: `สอบถามผลพิจารณา ${c3.caseId} — ${c3.clientName} [#T-seed-c3]`,
      deliveryStatus: 'delivered',
      deliveredAt: '2026-06-10 09:17',
      bouncedReason: null,
    }]
  }

  // c4: วิชัย / MTL Draft — bounced thread (typo'd address) so the rose badge shows
  const c4 = cases.value.find((c) => c.id === 'c4')
  if (c4) {
    c4.threads = [{
      id: 'em-seed-c4-1',
      caseId: c4.caseId,
      carrierCode: 'MTL',
      to: 'underwriting@muangthai-typo.co.th',
      cc: '',
      subject: `ส่งใบสมัครใหม่ ${c4.caseId} — ${c4.clientName}`,
      body: 'เรียน ทีมงาน MTL, ขอนำส่งใบสมัครครับ...',
      sentAt: '2026-06-12 14:30',
      sentByUser: 'ผู้ดูแลระบบ',
      template: 'ติดตามสถานะ (Pending Carrier)',
      status: 'waiting',
      responses: [],
      attachments: [],
      messageId: '<T-seed-c4.mti@abc-insure.co.th>',
      replyAddress: 'support+T-seed-c4@abc-insure.co.th',
      fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
      trackedSubject: `ส่งใบสมัครใหม่ ${c4.caseId} — ${c4.clientName} [#T-seed-c4]`,
      deliveryStatus: 'bounced',
      deliveredAt: null,
      bouncedReason: 'Mailbox does not exist at recipient mail server',
    }]
  }

  // c5: นพรัตน์ / VIB — replied with rejection (negative sentiment, exercises rejecting flow)
  const c5 = cases.value.find((c) => c.id === 'c5')
  if (c5) {
    const respText = `เรียน ทีมงาน ABC,\n\nเสียใจที่ต้องแจ้งให้ทราบว่าใบสมัคร ${c5.caseId} ของคุณ ${c5.clientName} ไม่สามารถอนุมัติได้ในรูปแบบปัจจุบัน เนื่องจากค่า BMI เกินเกณฑ์ที่บริษัทกำหนด หากลูกค้าประสงค์ดำเนินการต่อ ขอให้ตรวจสุขภาพใหม่ภายใน 60 วันทำการ พร้อมส่งผลตรวจสุขภาพและหลักฐานรายได้ 3 เดือนล่าสุด\n\nViriyah Underwriting`
    const summary = summarizeResponse(respText, { caseId: c5.caseId, carrierName: 'VIB', clientName: c5.clientName })
    c5.threads = [{
      id: 'em-seed-c5-1',
      caseId: c5.caseId,
      carrierCode: 'VIB',
      to: 'underwriting@viriyah.co.th',
      cc: '',
      subject: `ส่งใบสมัคร ${c5.caseId} — ${c5.clientName}`,
      body: 'เรียน VIB, ขอนำส่งใบสมัครและเอกสารแนบ...',
      sentAt: '2026-06-09 11:00',
      sentByUser: 'ผู้ดูแลระบบ',
      template: 'ติดตามสถานะ (Pending Carrier)',
      status: 'replied',
      responses: [{
        id: 'rsp-seed-c5-1',
        receivedAt: '2026-06-10 16:30',
        fromAddress: 'underwriting@viriyah.co.th',
        fromName: 'Viriyah Underwriting',
        body: respText,
        aiSummary: summary,
      }],
      attachments: [],
      messageId: '<T-seed-c5.vib@abc-insure.co.th>',
      replyAddress: 'support+T-seed-c5@abc-insure.co.th',
      fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
      trackedSubject: `ส่งใบสมัคร ${c5.caseId} — ${c5.clientName} [#T-seed-c5]`,
      deliveryStatus: 'delivered',
      deliveredAt: '2026-06-09 11:02',
      bouncedReason: null,
    }]
  }

  // c7: ธนพล — multi-carrier comparison shopping: 3 carriers, mix of replied + waiting
  const c7 = cases.value.find((c) => c.id === 'c7')
  if (c7) {
    // AIA — replied with needs-info
    const aiaResp = `เรียน ทีมงาน ABC,\n\nใบสมัคร ${c7.caseId} ของคุณ ${c7.clientName} อยู่ระหว่างพิจารณา ทาง underwriter ขอเอกสารเพิ่มเติม:\n\n1. สำเนาบัตรประชาชนที่ชัดเจน\n2. หลักฐานรายได้ 3 เดือนล่าสุด\n\nกรุณาส่งกลับภายใน 7 วันทำการ\n\nKhun Niran — AIA Underwriting`
    const aiaSummary = summarizeResponse(aiaResp, { caseId: c7.caseId, carrierName: 'AIA', clientName: c7.clientName })
    // TLI — positive approval
    const tliResp = `เรียน ทีมงาน ABC,\n\nใบสมัคร ${c7.caseId} ผ่านการพิจารณาแล้ว เลขกรมธรรม์ POL-TLI-2569-00891 เบี้ยรายปี 95,000 บาท ทุนประกัน 5,000,000 บาท คุ้มครองตั้งแต่ 1 ก.ค. 2569 — รบกวนแจ้งลูกค้าเลือกรับข้อเสนอภายใน 14 วัน\n\nThai Life`
    const tliSummary = summarizeResponse(tliResp, { caseId: c7.caseId, carrierName: 'TLI', clientName: c7.clientName })

    c7.threads = [
      // Thread 1 — AIA (replied, needs info)
      {
        id: 'em-seed-c7-aia',
        caseId: c7.caseId,
        carrierCode: 'AIA',
        to: 'underwriting@aia.co.th',
        cc: '',
        subject: `เปรียบเทียบเสนอ ${c7.caseId} — ${c7.clientName}`,
        body: 'เรียน Underwriter AIA, ขอเสนอกรณีนี้เพื่อพิจารณา...',
        sentAt: '2026-06-08 13:00',
        sentByUser: 'ผู้ดูแลระบบ',
        template: 'สอบถามผลพิจารณา (Underwriting)',
        status: 'replied',
        responses: [{
          id: 'rsp-seed-c7-aia',
          receivedAt: '2026-06-09 10:45',
          fromAddress: 'underwriting@aia.co.th',
          fromName: 'Khun Niran — AIA',
          body: aiaResp,
          aiSummary: aiaSummary,
        }],
        attachments: [],
        messageId: '<T-seed-c7-aia.aia@abc-insure.co.th>',
        replyAddress: 'support+T-seed-c7-aia@abc-insure.co.th',
        fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
        trackedSubject: `เปรียบเทียบเสนอ ${c7.caseId} — ${c7.clientName} [#T-seed-c7-aia]`,
        deliveryStatus: 'delivered',
        deliveredAt: '2026-06-08 13:02',
        bouncedReason: null,
      },
      // Thread 2 — TLI (replied, approved)
      {
        id: 'em-seed-c7-tli',
        caseId: c7.caseId,
        carrierCode: 'TLI',
        to: 'underwriting@thailife.com',
        cc: '',
        subject: `เปรียบเทียบเสนอ ${c7.caseId} — ${c7.clientName}`,
        body: 'เรียน Underwriter Thai Life, ขอเสนอกรณีนี้เพื่อพิจารณา...',
        sentAt: '2026-06-08 13:00',
        sentByUser: 'ผู้ดูแลระบบ',
        template: 'สอบถามผลพิจารณา (Underwriting)',
        status: 'replied',
        responses: [{
          id: 'rsp-seed-c7-tli',
          receivedAt: '2026-06-10 09:20',
          fromAddress: 'underwriting@thailife.com',
          fromName: 'Thai Life Underwriting',
          body: tliResp,
          aiSummary: tliSummary,
        }],
        attachments: [],
        messageId: '<T-seed-c7-tli.tli@abc-insure.co.th>',
        replyAddress: 'support+T-seed-c7-tli@abc-insure.co.th',
        fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
        trackedSubject: `เปรียบเทียบเสนอ ${c7.caseId} — ${c7.clientName} [#T-seed-c7-tli]`,
        deliveryStatus: 'delivered',
        deliveredAt: '2026-06-08 13:01',
        bouncedReason: null,
      },
      // Thread 3 — BLA (still waiting, overdue)
      {
        id: 'em-seed-c7-bla',
        caseId: c7.caseId,
        carrierCode: 'BLA',
        to: 'underwriting@bla.co.th',
        cc: '',
        subject: `เปรียบเทียบเสนอ ${c7.caseId} — ${c7.clientName}`,
        body: 'เรียน BLA, ขอเสนอกรณีนี้เพื่อพิจารณา...',
        sentAt: '2026-06-08 13:00',
        sentByUser: 'ผู้ดูแลระบบ',
        template: 'สอบถามผลพิจารณา (Underwriting)',
        status: 'waiting',
        responses: [],
        attachments: [],
        messageId: '<T-seed-c7-bla.bla@abc-insure.co.th>',
        replyAddress: 'support+T-seed-c7-bla@abc-insure.co.th',
        fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
        trackedSubject: `เปรียบเทียบเสนอ ${c7.caseId} — ${c7.clientName} [#T-seed-c7-bla]`,
        deliveryStatus: 'delivered',
        deliveredAt: '2026-06-08 13:03',
        bouncedReason: null,
      },
    ]
  }

  // c1 — add a SECOND thread to ภัทรา so she has multi-carrier comparison + resolved status
  // (her original thread is already 'replied')
  if (c1) {
    c1.threads = [
      ...c1.threads,
      {
        id: 'em-seed-c1-2',
        caseId: c1.caseId,
        carrierCode: 'BLA',
        to: 'newbiz@bla.co.th',
        cc: '',
        subject: `สอบถามอัตราเบี้ย ${c1.caseId} — ${c1.clientName}`,
        body: 'เรียน BLA, ขอสอบถามอัตราเบี้ยสำหรับผลิตภัณฑ์ใกล้เคียง...',
        sentAt: '2026-06-11 09:30',
        sentByUser: 'ผู้ดูแลระบบ',
        template: 'เขียนเอง (Custom)',
        status: 'resolved', // user already closed it
        responses: [{
          id: 'rsp-seed-c1-2',
          receivedAt: '2026-06-11 16:00',
          fromAddress: 'newbiz@bla.co.th',
          fromName: 'BLA New Business',
          body: `เรียน ทีมงาน ABC,\n\nสำหรับผลิตภัณฑ์ใกล้เคียงของ BLA เบี้ยอยู่ที่ประมาณ 52,000 บาทต่อปี ทุนประกัน 2,000,000 บาท แต่เงื่อนไขการพิจารณาเข้มงวดกว่า ขึ้นอยู่กับผลตรวจสุขภาพ\n\nหากต้องการข้อมูลเพิ่มเติม สามารถส่งใบสมัครมาได้\n\nKhun Mali — BLA`,
          aiSummary: summarizeResponse(`สำหรับผลิตภัณฑ์ใกล้เคียงของ BLA เบี้ยอยู่ที่ประมาณ 52,000 บาทต่อปี ทุนประกัน 2,000,000 บาท แต่เงื่อนไขการพิจารณาเข้มงวดกว่า ขึ้นอยู่กับผลตรวจสุขภาพ`, { caseId: c1.caseId, carrierName: 'BLA', clientName: c1.clientName }),
        }],
        attachments: [],
        messageId: '<T-seed-c1-2.bla@abc-insure.co.th>',
        replyAddress: 'support+T-seed-c1-2@abc-insure.co.th',
        fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
        trackedSubject: `สอบถามอัตราเบี้ย ${c1.caseId} — ${c1.clientName} [#T-seed-c1-2]`,
        deliveryStatus: 'delivered',
        deliveredAt: '2026-06-11 09:32',
        bouncedReason: null,
      },
    ]
  }

  // c2 — add a SECOND thread to ธีรยุทธ that FAILED (different from bounce)
  if (c2) {
    c2.threads = [
      ...c2.threads,
      {
        id: 'em-seed-c2-2',
        caseId: c2.caseId,
        carrierCode: 'MTL',
        to: 'newcase@muangthai.co.th',
        cc: '',
        subject: `ปรึกษากรณี ${c2.caseId} — ${c2.clientName}`,
        body: 'เรียน MTL, ขอปรึกษากรณีลูกค้าวีไอพี...',
        sentAt: '2026-06-13 10:00',
        sentByUser: 'ผู้ดูแลระบบ',
        template: 'เขียนเอง (Custom)',
        status: 'waiting',
        responses: [],
        attachments: [],
        messageId: '<T-seed-c2-2.mti@abc-insure.co.th>',
        replyAddress: 'support+T-seed-c2-2@abc-insure.co.th',
        fromAddress: 'ABC Insurance Support <support@abc-insure.co.th>',
        trackedSubject: `ปรึกษากรณี ${c2.caseId} — ${c2.clientName} [#T-seed-c2-2]`,
        deliveryStatus: 'failed',
        deliveredAt: null,
        bouncedReason: 'SMTP timeout — will retry in 1 hour',
      },
    ]
  }
})
</script>

<template>
  <div class="space-y-6">
    <!-- ─── Header ─────────────────────────────────────────────────────── -->
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Agent Support</h1>
        <p class="text-slate-500 text-sm mt-1">ติดตามและช่วยจัดการเคสที่ค้างในทุกขั้นตอนของกรมธรรม์</p>
      </div>
      <button
        type="button"
        @click="openNewCase"
        class="px-4 py-2.5 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition flex items-center gap-2"
      >
        <i class="pi pi-plus" />
        <span class="hidden sm:inline">เคสใหม่</span>
      </button>
    </header>

    <!-- ─── Top-level tabs (Cases / Threads) ───────────────────────────── -->
    <div class="border-b border-slate-200 flex items-center gap-1">
      <button
        @click="viewTab = 'cases'"
        :class="[
          'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition flex items-center gap-2',
          viewTab === 'cases' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
        ]"
      >
        <i class="pi pi-list text-xs" />
        Case Queue
        <span class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded">{{ cases.length }}</span>
      </button>
      <button
        @click="viewTab = 'threads'"
        :class="[
          'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition flex items-center gap-2',
          viewTab === 'threads' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
        ]"
      >
        <i class="pi pi-envelope text-xs" />
        Email Threads
        <span class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded">{{ threadStats.total }}</span>
        <span v-if="threadStats.overdue > 0" class="text-[10px] px-1.5 py-0.5 bg-rose-100 text-rose-700 rounded font-semibold">
          {{ threadStats.overdue }} ค้าง
        </span>
      </button>
      <button
        @click="viewTab = 'scheduled'"
        :class="[
          'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition flex items-center gap-2',
          viewTab === 'scheduled' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900',
        ]"
      >
        <i class="pi pi-clock text-xs" />
        ตั้งเวลาส่ง
        <span
          :class="[
            'text-[10px] px-1.5 py-0.5 rounded font-semibold',
            pendingScheduledSends.length > 0 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500',
          ]"
        >
          {{ pendingScheduledSends.length }}
        </span>
      </button>
    </div>

    <!-- ─── Cases tab content ─────────────────────────────────────────── -->
    <div v-if="viewTab === 'cases'" class="space-y-6">
    <!-- ─── Highlight cards ────────────────────────────────────────────── -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <!-- Pending Quotes -->
      <button
        type="button"
        @click="applyStatFilter('pending')"
        :class="[
          'text-left bg-white rounded-xl border shadow-sm p-5 hover:shadow-md transition',
          activeStat === 'pending' ? 'ring-2 ring-blue-500 border-blue-200' : 'border-slate-200',
        ]"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
            <i class="pi pi-clock" />
          </div>
          <span class="text-xs text-blue-600 font-medium flex items-center gap-1">
            <i v-if="activeStat === 'pending'" class="pi pi-filter-fill text-[10px]" />
            รอดำเนินการ
          </span>
        </div>
        <div class="mt-4">
          <div class="text-3xl font-semibold text-slate-900">{{ stats.pendingQuotes }}</div>
          <div class="text-sm text-slate-500 mt-0.5">Pending Quotes</div>
          <div class="text-xs text-slate-400 mt-1">รอบริษัทประกัน / ตัวแทนตอบกลับ</div>
        </div>
      </button>

      <!-- SLA at Risk -->
      <button
        type="button"
        @click="applyStatFilter('sla')"
        :class="[
          'text-left bg-white rounded-xl border shadow-sm p-5 hover:shadow-md transition',
          activeStat === 'sla' ? 'ring-2 ring-amber-500 border-amber-200' : 'border-slate-200',
        ]"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
            <i class="pi pi-exclamation-triangle" />
          </div>
          <span class="text-xs text-amber-600 font-medium flex items-center gap-1">
            <i v-if="activeStat === 'sla'" class="pi pi-filter-fill text-[10px]" />
            SLA เสี่ยง
          </span>
        </div>
        <div class="mt-4">
          <div class="text-3xl font-semibold text-slate-900">{{ stats.slaAtRisk }}</div>
          <div class="text-sm text-slate-500 mt-0.5">SLA at Risk</div>
          <div class="text-xs text-slate-400 mt-1">ค้างในสถานะเดิม &gt; 24 ชม.</div>
        </div>
      </button>

      <!-- Action Required -->
      <button
        type="button"
        @click="applyStatFilter('action')"
        :class="[
          'text-left bg-white rounded-xl border shadow-sm p-5 hover:shadow-md transition relative overflow-hidden',
          activeStat === 'action' ? 'ring-2 ring-rose-500 border-rose-200' : 'border-slate-200',
        ]"
      >
        <div v-if="stats.actionRequired > 0" class="absolute top-0 left-0 w-1 h-full bg-rose-500" />
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
            <i class="pi pi-flag-fill" />
          </div>
          <span class="text-xs text-rose-600 font-medium flex items-center gap-1">
            <i v-if="activeStat === 'action'" class="pi pi-filter-fill text-[10px]" />
            ต้องดำเนินการ
          </span>
        </div>
        <div class="mt-4">
          <div class="text-3xl font-semibold text-slate-900">{{ stats.actionRequired }}</div>
          <div class="text-sm text-slate-500 mt-0.5">Action Required</div>
          <div class="text-xs text-slate-400 mt-1">ถูกตีกลับ ต้องแก้ไขเอกสาร</div>
        </div>
      </button>

      <!-- Converted MTD -->
      <button
        type="button"
        @click="applyStatFilter('converted')"
        :class="[
          'text-left bg-white rounded-xl border shadow-sm p-5 hover:shadow-md transition',
          activeStat === 'converted' ? 'ring-2 ring-emerald-500 border-emerald-200' : 'border-slate-200',
        ]"
      >
        <div class="flex items-start justify-between">
          <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <i class="pi pi-check-circle" />
          </div>
          <span class="text-xs text-emerald-600 font-medium flex items-center gap-1">
            <i v-if="activeStat === 'converted'" class="pi pi-filter-fill text-[10px]" />
            +12% เทียบเดือนก่อน
          </span>
        </div>
        <div class="mt-4">
          <div class="text-3xl font-semibold text-slate-900">{{ stats.convertedMtd }}</div>
          <div class="text-sm text-slate-500 mt-0.5">Converted Policies MTD</div>
          <div class="text-xs text-slate-400 mt-1">ปิดการขายในเดือนนี้</div>
        </div>
      </button>
    </div>

    <!-- ─── Transaction queue card ─────────────────────────────────────── -->
    <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
      <!-- Utility bar -->
      <div class="px-5 py-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <div>
            <h2 class="font-semibold text-slate-900">Live Transaction Queue</h2>
            <p class="text-xs text-slate-500 mt-0.5">{{ filteredCasesWithStat.length }} เคสจาก {{ cases.length }} ทั้งหมด</p>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-3 flex-1 sm:flex-initial">
          <div class="relative flex-1 min-w-[240px]">
            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
            <input
              v-model="search"
              type="search"
              placeholder="ค้นหาด้วย Case ID, Agent, หรือ Client"
              class="w-full pl-9 pr-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:bg-white focus:border-brand-400 focus:ring-2 focus:ring-brand-100"
            />
          </div>
          <select
            v-model="statusFilter"
            class="px-3 py-2 text-sm border border-slate-200 bg-white rounded-lg focus:outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-100 min-w-[160px]"
          >
            <option v-for="s in statusOptions" :key="s" :value="s">
              {{ s === 'All' ? 'สถานะทั้งหมด' : s }}
            </option>
          </select>
        </div>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50/70 text-slate-500 text-xs uppercase tracking-wider">
            <tr>
              <th class="text-left px-5 py-3 font-medium">Case ID</th>
              <th class="text-left px-5 py-3 font-medium">Agent</th>
              <th class="text-left px-5 py-3 font-medium">Client</th>
              <th class="text-left px-5 py-3 font-medium">Carrier</th>
              <th class="text-left px-5 py-3 font-medium">Status</th>
              <th class="text-left px-5 py-3 font-medium">Last Updated</th>
              <th class="text-right px-5 py-3 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="c in filteredCasesWithStat" :key="c.id" class="hover:bg-slate-50/50 transition cursor-pointer" @click="openDetail(c)">
              <td class="px-5 py-3 font-mono text-xs text-slate-900">{{ c.caseId }}</td>
              <td class="px-5 py-3">
                <div class="flex items-center gap-2.5">
                  <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center text-[10px] font-medium shrink-0">
                    {{ c.agentName.split(' ').map((s) => s.charAt(0)).join('').slice(0, 2) }}
                  </div>
                  <span class="text-slate-800 truncate max-w-[140px]">{{ c.agentName }}</span>
                </div>
              </td>
              <td class="px-5 py-3 text-slate-800 truncate max-w-[180px]">{{ c.clientName }}</td>
              <td class="px-5 py-3">
                <span class="inline-flex px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 text-xs font-mono">{{ c.carrier }}</span>
              </td>
              <td class="px-5 py-3" @click.stop>
                <button
                  type="button"
                  @click="openStatusChange(c)"
                  :class="[
                    'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ring-1 ring-inset hover:ring-2 hover:scale-[1.02] transition-all',
                    statusBadge(c.status),
                  ]"
                  title="คลิกเพื่อเปลี่ยนสถานะ"
                >
                  <span :class="['w-1.5 h-1.5 rounded-full', statusDot(c.status)]" />
                  {{ c.status }}
                  <i class="pi pi-pencil text-[8px] opacity-50 ml-0.5" />
                </button>
              </td>
              <td class="px-5 py-3">
                <div class="text-xs text-slate-700">{{ formatRelative(c.lastUpdated) }}</div>
                <span v-if="liveStuckHours(c) >= 24" :class="['inline-flex items-center gap-1 mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium', slaPillClass(c)]">
                  <i class="pi pi-clock text-[9px]" />
                  ค้าง {{ liveStuckHours(c) }} ชม.
                </span>
              </td>
              <td class="px-5 py-3 text-right" @click.stop>
                <div class="inline-flex items-center gap-1.5">
                  <button
                    v-if="caseThreadsSummary(c)"
                    type="button"
                    @click="jumpToThreadsForCase(c.caseId)"
                    :class="['inline-flex items-center gap-1 px-2 py-1 rounded-md text-[10px] font-medium hover:opacity-90 transition', caseThreadsSummary(c)!.cls]"
                    :title="`เปิดใน Email Threads (${caseThreadsSummary(c)!.total} thread)`"
                  >
                    <i :class="[caseThreadsSummary(c)!.icon, 'text-[9px]']" />
                    {{ caseThreadsSummary(c)!.label }}
                  </button>
                  <button
                    type="button"
                    @click="openEmail(c)"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-white border border-slate-200 text-slate-700 text-xs font-medium rounded-md hover:bg-slate-50 hover:border-slate-300 transition"
                    title="ส่งอีเมล"
                  >
                    <i class="pi pi-envelope text-[10px]" />
                    <span class="hidden lg:inline">ส่งอีเมล</span>
                  </button>
                  <button
                    type="button"
                    @click="openQuotationManually(c)"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-white border border-brand-200 text-brand-700 text-xs font-medium rounded-md hover:bg-brand-50 hover:border-brand-300 transition"
                    title="สร้างใบเสนอราคา (กรอกเอง)"
                  >
                    <i class="pi pi-file-pdf text-[10px]" />
                    <span class="hidden lg:inline">ใบเสนอราคา</span>
                  </button>
                  <button
                    type="button"
                    @click="openDetail(c)"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-900 text-white text-xs font-medium rounded-md hover:bg-slate-700 transition"
                  >
                    <i class="pi pi-eye text-[10px]" />
                    View Details
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredCasesWithStat.length">
              <td colspan="7" class="px-5 py-12 text-center text-slate-400 text-sm">
                <i class="pi pi-inbox text-2xl block mb-2" />
                ไม่พบเคสที่ตรงกับเงื่อนไข
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
    </div>
    <!-- /Cases tab content -->

    <!-- ─── Threads tab content ───────────────────────────────────────── -->
    <div v-if="viewTab === 'threads'" class="space-y-6">
      <!-- Thread stats -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <button
          @click="threadStatusFilter = threadStatusFilter === 'waiting' ? 'all' : 'waiting'"
          :class="['text-left bg-white rounded-xl border shadow-sm p-4 hover:shadow-md transition', threadStatusFilter === 'waiting' ? 'ring-2 ring-amber-500 border-amber-200' : 'border-slate-200']"
        >
          <div class="text-xs text-amber-700 font-medium flex items-center gap-1">
            <i class="pi pi-clock text-[10px]" />
            รอตอบกลับ
          </div>
          <div class="text-2xl font-semibold text-slate-900 mt-1">{{ threadStats.waiting }}</div>
        </button>
        <button
          @click="threadStatusFilter = threadStatusFilter === 'overdue' ? 'all' : 'overdue'"
          :class="['text-left bg-white rounded-xl border shadow-sm p-4 hover:shadow-md transition relative overflow-hidden', threadStatusFilter === 'overdue' ? 'ring-2 ring-rose-500 border-rose-200' : 'border-slate-200']"
        >
          <div v-if="threadStats.overdue > 0" class="absolute top-0 left-0 w-1 h-full bg-rose-500" />
          <div class="text-xs text-rose-700 font-medium flex items-center gap-1">
            <i class="pi pi-exclamation-triangle text-[10px]" />
            ค้างนาน &gt; 48h
          </div>
          <div class="text-2xl font-semibold text-slate-900 mt-1">{{ threadStats.overdue }}</div>
        </button>
        <button
          @click="threadStatusFilter = threadStatusFilter === 'replied' ? 'all' : 'replied'"
          :class="['text-left bg-white rounded-xl border shadow-sm p-4 hover:shadow-md transition', threadStatusFilter === 'replied' ? 'ring-2 ring-blue-500 border-blue-200' : 'border-slate-200']"
        >
          <div class="text-xs text-blue-700 font-medium flex items-center gap-1">
            <i class="pi pi-reply text-[10px]" />
            ตอบกลับแล้ว
          </div>
          <div class="text-2xl font-semibold text-slate-900 mt-1">{{ threadStats.replied }}</div>
        </button>
        <button
          @click="threadStatusFilter = threadStatusFilter === 'resolved' ? 'all' : 'resolved'"
          :class="['text-left bg-white rounded-xl border shadow-sm p-4 hover:shadow-md transition', threadStatusFilter === 'resolved' ? 'ring-2 ring-emerald-500 border-emerald-200' : 'border-slate-200']"
        >
          <div class="text-xs text-emerald-700 font-medium flex items-center gap-1">
            <i class="pi pi-check-circle text-[10px]" />
            ปิดแล้ว
          </div>
          <div class="text-2xl font-semibold text-slate-900 mt-1">{{ threadStats.resolved }}</div>
        </button>
      </div>

      <!-- Threads list -->
      <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between gap-3 flex-wrap">
          <div class="flex items-center gap-2 flex-wrap">
            <h2 class="font-semibold text-slate-900">Email Threads</h2>
            <span
              v-if="threadCaseFilter"
              class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-brand-50 text-brand-700 text-xs font-medium"
            >
              <i class="pi pi-filter-fill text-[10px]" />
              เคส {{ threadCaseFilter }}
              <button @click="clearThreadCaseFilter" class="text-brand-500 hover:text-brand-800 ml-1">
                <i class="pi pi-times text-[10px]" />
              </button>
            </span>
          </div>
          <p class="text-xs text-slate-500">{{ filteredThreads.length }} thread จาก {{ threadStats.total }}</p>
        </div>

        <div v-if="!filteredThreads.length" class="p-12 text-center text-slate-400">
          <i class="pi pi-envelope text-3xl block mb-2" />
          <p class="text-sm">ยังไม่มี thread อีเมล — ลองส่งอีเมลจาก Case Queue</p>
        </div>

        <ul v-else class="divide-y divide-slate-100">
          <li v-for="{ thread, caseRef } in filteredThreads" :key="thread.id" class="px-5 py-4 hover:bg-slate-50/50 transition">
            <div class="flex items-start gap-3 mb-2">
              <div :class="[
                'w-9 h-9 rounded-lg flex items-center justify-center shrink-0',
                thread.status === 'overdue' ? 'bg-rose-50 text-rose-600' :
                thread.status === 'waiting' ? 'bg-amber-50 text-amber-600' :
                thread.status === 'replied' ? 'bg-blue-50 text-blue-600' :
                'bg-emerald-50 text-emerald-600',
              ]">
                <i class="pi pi-envelope" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-sm font-medium text-slate-900 truncate">{{ thread.subject }}</span>
                  <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ring-1 ring-inset', threadStatusBadge(thread.status)]">
                    {{ threadStatusLabel(thread.status) }}
                  </span>
                  <span
                    v-if="thread.deliveryStatus"
                    :class="['inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium', deliveryStatusBadge(thread.deliveryStatus)]"
                    :title="thread.bouncedReason || ''"
                  >
                    <i :class="[deliveryStatusIcon(thread.deliveryStatus), 'text-[9px]']" />
                    {{ deliveryStatusLabel(thread.deliveryStatus) }}
                  </span>
                  <span class="font-mono text-[10px] text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded">{{ thread.carrierCode }}</span>
                  <span class="font-mono text-[10px] text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded">{{ thread.caseId }}</span>
                </div>
                <div class="text-xs text-slate-500 mt-1">
                  ลูกค้า: <span class="text-slate-700">{{ caseRef.clientName }}</span>
                  <span class="mx-1.5 text-slate-300">·</span>
                  ส่ง <span class="font-mono">{{ thread.sentAt }}</span> ถึง <span class="font-mono">{{ thread.to }}</span>
                  <span v-if="thread.messageId" class="mx-1.5 text-slate-300">·</span>
                  <span v-if="thread.messageId" class="font-mono text-[10px] text-slate-400" :title="thread.messageId">
                    Msg-ID: {{ thread.messageId.slice(0, 16) }}…
                  </span>
                </div>

                <!-- Latest AI summary preview -->
                <div v-if="thread.responses.length && thread.responses[thread.responses.length - 1].aiSummary" class="mt-2 border border-slate-200 rounded-lg p-2.5 bg-slate-50/50">
                  <div class="flex items-center gap-2 mb-1">
                    <i class="pi pi-sparkles text-violet-600 text-xs" />
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">AI สรุป</span>
                    <span
                      :class="['inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium', sentimentBadge(thread.responses[thread.responses.length - 1].aiSummary!.sentiment)]"
                    >
                      {{ sentimentLabel(thread.responses[thread.responses.length - 1].aiSummary!.sentiment) }}
                    </span>
                  </div>
                  <div class="text-xs text-slate-700">{{ thread.responses[thread.responses.length - 1].aiSummary!.oneLiner }}</div>
                </div>
              </div>
              <div class="flex flex-col items-end gap-1.5 shrink-0">
                <button
                  v-if="thread.status === 'waiting' || thread.status === 'overdue'"
                  @click="openResponsePaste(caseRef, thread)"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-md hover:bg-blue-700 transition"
                  title="บันทึกคำตอบจากบริษัทประกัน"
                >
                  <i class="pi pi-pencil text-[10px]" />
                  บันทึกคำตอบ
                </button>
                <button
                  v-if="thread.status === 'waiting' || thread.status === 'overdue'"
                  @click="simulateResponseForThread(caseRef, thread)"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-slate-300 text-slate-600 text-xs font-medium rounded-md hover:bg-slate-50 transition"
                  title="จำลองคำตอบ (เพื่อการสาธิต)"
                >
                  🎲 จำลอง
                </button>
                <button
                  v-if="thread.responses.length && thread.responses[thread.responses.length - 1].aiSummary"
                  @click="openAISummary(thread, thread.responses[thread.responses.length - 1])"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-violet-100 text-violet-700 text-xs font-medium rounded-md hover:bg-violet-200 transition"
                >
                  <i class="pi pi-sparkles text-[10px]" />
                  ดู AI สรุป
                </button>
                <button
                  v-if="thread.status === 'replied'"
                  @click="markThreadResolved(thread, caseRef)"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-md hover:bg-emerald-700 transition"
                  title="ปิด thread"
                >
                  <i class="pi pi-check text-[10px]" />
                  ปิด
                </button>
              </div>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- ─── Scheduled tab content ─────────────────────────────────────── -->
    <div v-if="viewTab === 'scheduled'" class="space-y-4">
      <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <div>
            <h2 class="font-semibold text-slate-900 flex items-center gap-2">
              <i class="pi pi-clock text-amber-500" />
              อีเมลที่ตั้งเวลาส่ง
            </h2>
            <p class="text-xs text-slate-500 mt-0.5">
              อีเมลที่รอส่งอัตโนมัติทุกเคส — ยกเลิกหรือกดส่งทันทีได้
            </p>
          </div>
          <span
            :class="[
              'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium',
              pendingScheduledSends.length > 0 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500',
            ]"
          >
            <span :class="['w-1.5 h-1.5 rounded-full', pendingScheduledSends.length > 0 ? 'bg-amber-500' : 'bg-slate-400']" />
            {{ pendingScheduledSends.length }} รออยู่
          </span>
        </header>

        <!-- Empty state -->
        <div v-if="!pendingScheduledSends.length" class="px-5 py-16 text-center">
          <div class="w-16 h-16 mx-auto rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
            <i class="pi pi-inbox text-2xl" />
          </div>
          <p class="text-sm font-medium text-slate-700">ยังไม่มีอีเมลในคิวตั้งเวลา</p>
          <p class="text-xs text-slate-500 mt-1 max-w-md mx-auto">
            ตอนเขียนอีเมล กดปุ่ม
            <i class="pi pi-chevron-down text-[10px] inline-block px-1.5 py-0.5 bg-blue-50 text-blue-700 rounded mx-1" />
            ข้างปุ่ม "ส่ง" เพื่อตั้งเวลาส่งล่วงหน้า
          </p>
        </div>

        <!-- List -->
        <ul v-else class="divide-y divide-slate-100">
          <li
            v-for="s in pendingScheduledSends"
            :key="s.id"
            class="px-5 py-4 hover:bg-slate-50/40 transition"
          >
            <div class="flex items-start gap-4">
              <!-- Time block -->
              <div class="w-32 shrink-0 text-center">
                <div class="text-[10px] uppercase tracking-wider text-amber-600 font-semibold">
                  {{ relativeTimeUntil(s.scheduledAt) }}
                </div>
                <div class="text-xs font-mono text-slate-700 mt-1">{{ formatScheduleTime(s.scheduledAt) }}</div>
              </div>

              <!-- Subject / case / recipients -->
              <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-slate-900 truncate">{{ s.payload.subject }}</div>
                <div class="text-xs text-slate-500 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                  <button
                    type="button"
                    @click="openCaseFromSchedule(s.caseId)"
                    class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-700 hover:underline"
                  >
                    <i class="pi pi-external-link text-[10px]" />
                    <span class="font-mono">{{ s.caseId }}</span>
                  </button>
                  <span class="text-slate-300">·</span>
                  <span>{{ s.payload.recipients.length }} ปลายทาง</span>
                  <span v-if="s.payload.recipients.length > 0" class="text-slate-300">·</span>
                  <span v-if="s.payload.recipients.length > 0" class="flex flex-wrap gap-1">
                    <span
                      v-for="r in s.payload.recipients.slice(0, 4)"
                      :key="r.rowId"
                      class="inline-flex items-center px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px] font-mono"
                    >
                      {{ r.code }}
                    </span>
                    <span v-if="s.payload.recipients.length > 4" class="text-[10px] text-slate-400">
                      +{{ s.payload.recipients.length - 4 }}
                    </span>
                  </span>
                </div>
                <div class="text-[10px] text-slate-400 mt-1">
                  เทมเพลต: {{ s.payload.templateLabel }}
                  <template v-if="s.payload.attachments.length">
                    <span class="text-slate-300 mx-1">·</span>
                    <i class="pi pi-paperclip text-[9px]" />
                    {{ s.payload.attachments.length }} ไฟล์
                  </template>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex items-center gap-1.5 shrink-0">
                <button
                  type="button"
                  @click="sendScheduledNow(s.id)"
                  class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 transition"
                  title="ส่งทันที"
                >
                  <i class="pi pi-send text-[10px]" />
                  ส่งทันที
                </button>
                <button
                  type="button"
                  @click="cancelScheduledSend(s.id)"
                  class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50 rounded-md transition"
                  title="ยกเลิก"
                >
                  <i class="pi pi-times text-[10px]" />
                  ยกเลิก
                </button>
              </div>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- ─── Detail side drawer ─────────────────────────────────────────── -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-if="detail" class="fixed inset-0 z-40 flex" @click.self="closeDetail">
        <div class="flex-1 bg-slate-900/40" @click="closeDetail" />
        <Transition
          enter-active-class="transition duration-300 ease-out"
          enter-from-class="translate-x-full"
          enter-to-class="translate-x-0"
          leave-active-class="transition duration-200 ease-in"
          leave-from-class="translate-x-0"
          leave-to-class="translate-x-full"
        >
          <aside
            v-if="detail"
            class="w-full max-w-2xl bg-white shadow-2xl flex flex-col overflow-hidden"
          >
            <!-- Header -->
            <header class="px-5 py-4 border-b border-slate-100 flex items-start justify-between shrink-0">
              <div class="min-w-0">
                <div class="text-xs text-slate-400 font-mono">{{ detail.caseId }}</div>
                <h3 class="text-base font-semibold text-slate-900 mt-0.5 truncate">{{ detail.clientName }}</h3>
                <div class="text-xs text-slate-500 mt-0.5 truncate">
                  {{ detail.productName }} · {{ detail.carrier }}
                </div>
                <div class="mt-2 flex items-center gap-2">
                  <button
                    type="button"
                    @click="openStatusChange(detail)"
                    :class="[
                      'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ring-1 ring-inset hover:ring-2 transition',
                      statusBadge(detail.status),
                    ]"
                    title="คลิกเพื่อเปลี่ยนสถานะ"
                  >
                    <span :class="['w-1.5 h-1.5 rounded-full', statusDot(detail.status)]" />
                    {{ detail.status }}
                    <i class="pi pi-pencil text-[8px] opacity-50 ml-0.5" />
                  </button>
                  <span class="text-xs text-slate-500">เบี้ย ฿{{ fmtTHB(detail.premium) }}/ปี</span>
                </div>
              </div>
              <button @click="closeDetail" class="text-slate-400 hover:text-slate-700 ml-3 shrink-0">
                <i class="pi pi-times" />
              </button>
            </header>

            <!-- Action banner if needed -->
            <div v-if="detail.status === 'Action Required'" class="px-5 py-3 bg-rose-50 border-b border-rose-100 flex items-start gap-2.5">
              <i class="pi pi-exclamation-circle text-rose-600 mt-0.5" />
              <div class="text-sm text-rose-800">
                <strong class="font-semibold">ต้องดำเนินการ:</strong> {{ detail.rejectionReason }}
              </div>
            </div>

            <!-- AI suggestion banner — set when AI inferred a different status from carrier reply -->
            <div v-if="detail.pendingAITransition" class="px-5 py-3 bg-violet-50 border-b border-violet-100 flex items-start gap-3">
              <div class="w-8 h-8 rounded-lg bg-violet-100 text-violet-700 flex items-center justify-center shrink-0">
                <i class="pi pi-sparkles text-xs" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-xs font-semibold text-violet-700 uppercase tracking-wider">AI แนะนำให้เปลี่ยนสถานะ</div>
                <div class="text-sm text-slate-800 mt-0.5">
                  จากคำตอบของ
                  <span class="font-mono text-xs bg-violet-100 text-violet-700 px-1.5 py-0.5 rounded">{{ detail.pendingAITransition.sourceCarrierCode }}</span>
                  → เสนอเปลี่ยนเป็น
                  <strong>{{ caseStatusApi.statusLabel(detail.pendingAITransition.suggestedStatus) }}</strong>
                </div>
              </div>
              <div class="flex gap-1.5 shrink-0">
                <button
                  @click="applyPendingAITransition(detail)"
                  class="px-3 py-1.5 bg-violet-600 text-white text-xs font-medium rounded-md hover:bg-violet-700 transition flex items-center gap-1"
                >
                  <i class="pi pi-check text-[10px]" />
                  ยืนยัน
                </button>
                <button
                  @click="dismissPendingAITransition(detail)"
                  class="px-2.5 py-1.5 border border-slate-300 text-slate-600 text-xs rounded-md hover:bg-white transition"
                >
                  ไม่ต้องการ
                </button>
              </div>
            </div>

            <!-- Scroll area -->
            <div class="overflow-y-auto flex-1 px-5 py-5 space-y-6">
              <!-- Quick info grid -->
              <section class="grid grid-cols-2 gap-3">
                <div class="border border-slate-200 rounded-lg p-3">
                  <div class="text-xs text-slate-400 uppercase tracking-wider">Agent</div>
                  <div class="text-sm font-medium text-slate-900 mt-1 truncate">{{ detail.agentName }}</div>
                </div>
                <div class="border border-slate-200 rounded-lg p-3">
                  <div class="text-xs text-slate-400 uppercase tracking-wider">Carrier</div>
                  <div class="text-sm font-medium text-slate-900 mt-1">{{ detail.carrier }}</div>
                </div>
                <div class="border border-slate-200 rounded-lg p-3">
                  <div class="text-xs text-slate-400 uppercase tracking-wider">เบี้ยรายปี</div>
                  <div class="text-sm font-medium text-slate-900 mt-1">฿{{ fmtTHB(detail.premium) }}</div>
                </div>
                <div class="border border-slate-200 rounded-lg p-3">
                  <div class="text-xs text-slate-400 uppercase tracking-wider">ค้างในสถานะ</div>
                  <div class="text-sm font-medium mt-1" :class="detail.stuckHours >= 24 ? 'text-rose-700' : 'text-slate-900'">
                    {{ detail.stuckHours }} ชั่วโมง
                  </div>
                </div>
              </section>

              <!-- Lifecycle timeline -->
              <section>
                <div class="flex items-center justify-between mb-3">
                  <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Lifecycle Timeline</h4>
                  <span class="text-xs text-slate-400">{{ detail.timeline.length }} เหตุการณ์</span>
                </div>
                <ol class="relative border-l-2 border-slate-200 ml-3 space-y-4">
                  <li v-for="(ev, idx) in [...detail.timeline].reverse()" :key="idx" class="ml-6">
                    <span :class="['absolute -left-[9px] w-4 h-4 rounded-full border-4 border-white', statusDot(ev.status)]" />
                    <div class="bg-white border border-slate-200 rounded-lg p-3">
                      <div class="flex items-center justify-between gap-2">
                        <span :class="['inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium', statusBadge(ev.status)]">
                          {{ ev.status }}
                        </span>
                        <span class="text-xs text-slate-400 font-mono">{{ ev.at }}</span>
                      </div>
                      <div class="text-xs text-slate-500 mt-1.5">โดย {{ ev.byUser }}</div>
                      <div v-if="ev.note" class="text-sm text-slate-700 mt-1.5">{{ ev.note }}</div>
                    </div>
                  </li>
                </ol>
              </section>

              <!-- Status change history (audit) -->
              <section v-if="detail.statusHistory.length">
                <div class="flex items-center justify-between mb-3">
                  <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">ประวัติการเปลี่ยนสถานะ</h4>
                  <span class="text-xs text-slate-400">{{ detail.statusHistory.length }} ครั้ง</span>
                </div>
                <ol class="space-y-2">
                  <li
                    v-for="txn in [...detail.statusHistory].reverse()"
                    :key="txn.id"
                    class="bg-white border border-slate-200 rounded-lg p-3"
                  >
                    <div class="flex items-center gap-2 flex-wrap">
                      <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium', caseStatusApi.statusBadgeClass(txn.from)]">
                        {{ caseStatusApi.statusLabel(txn.from) }}
                      </span>
                      <i class="pi pi-arrow-right text-slate-400 text-[10px]" />
                      <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium', caseStatusApi.statusBadgeClass(txn.to)]">
                        {{ caseStatusApi.statusLabel(txn.to) }}
                      </span>
                      <span
                        class="ml-auto inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
                        :class="{
                          'bg-slate-100 text-slate-600': txn.source === 'manual',
                          'bg-violet-50 text-violet-700': txn.source === 'ai_suggestion',
                          'bg-blue-50 text-blue-700': txn.source === 'auto_send',
                          'bg-amber-50 text-amber-700': txn.source === 'auto_lifecycle',
                        }"
                      >
                        <i :class="{
                          'pi pi-pencil': txn.source === 'manual',
                          'pi pi-sparkles': txn.source === 'ai_suggestion',
                          'pi pi-send': txn.source === 'auto_send',
                          'pi pi-clock': txn.source === 'auto_lifecycle',
                        }" class="text-[9px]" />
                        {{
                          txn.source === 'manual' ? 'ผู้ใช้' :
                          txn.source === 'ai_suggestion' ? 'AI' :
                          txn.source === 'auto_send' ? 'อัตโนมัติ (ส่งอีเมล)' :
                          'อัตโนมัติ'
                        }}
                      </span>
                    </div>
                    <div class="text-xs text-slate-700 mt-1.5">{{ txn.reason }}</div>
                    <div class="text-[10px] text-slate-400 mt-1 font-mono">{{ txn.byUser }} · {{ txn.at }}</div>
                  </li>
                </ol>
              </section>

              <!-- Document upload -->
              <section>
                <div class="flex items-center justify-between mb-3">
                  <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Documents</h4>
                  <span class="text-xs text-slate-400">{{ detail.documents.length }} ไฟล์</span>
                </div>

                <!-- Drag-drop zone -->
                <div
                  @dragenter.prevent="isDragging = true"
                  @dragover.prevent="isDragging = true"
                  @dragleave.prevent="isDragging = false"
                  @drop.prevent="onDrop"
                  @click="pickFile"
                  :class="[
                    'border-2 border-dashed rounded-lg p-5 text-center cursor-pointer transition',
                    isDragging ? 'border-brand-500 bg-brand-50/50' : 'border-slate-300 hover:border-slate-400 hover:bg-slate-50',
                  ]"
                >
                  <input ref="fileInput" type="file" class="hidden" @change="onFileChange" />
                  <i class="pi pi-cloud-upload text-2xl text-slate-400 block mb-2" />
                  <p class="text-sm font-medium text-slate-700">ลากไฟล์มาวาง หรือคลิกเพื่อเลือก</p>
                  <p class="text-xs text-slate-400 mt-1">รองรับ PDF, JPG, PNG · สูงสุด 10 MB</p>
                </div>

                <!-- Document list -->
                <ul v-if="detail.documents.length" class="mt-3 space-y-2">
                  <li v-for="d in detail.documents" :key="d.id" class="border border-slate-200 rounded-lg p-3 flex items-center gap-3 hover:bg-slate-50/50 transition">
                    <div class="w-9 h-9 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center shrink-0">
                      <i class="pi pi-file-pdf" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <div class="text-sm font-medium text-slate-900 truncate">{{ d.name }}</div>
                      <div class="text-xs text-slate-400">{{ d.size }} · อัปโหลด {{ d.uploadedAt }}</div>
                    </div>
                    <button @click="downloadDoc(d)" class="px-2 py-1 text-slate-400 hover:text-slate-600 rounded transition" title="ดาวน์โหลด">
                      <i class="pi pi-download text-xs" />
                    </button>
                    <button @click="removeDoc(d.id)" class="px-2 py-1 text-rose-500 hover:bg-rose-50 rounded transition">
                      <i class="pi pi-trash text-xs" />
                    </button>
                  </li>
                </ul>
              </section>

              <!-- Emails -->
              <section>
                <div class="flex items-center justify-between mb-3">
                  <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Emails</h4>
                  <button
                    @click="openEmail(detail)"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-md hover:bg-blue-700 transition"
                  >
                    <i class="pi pi-envelope text-[10px]" />
                    ส่งอีเมลใหม่
                  </button>
                </div>

                <!-- Scheduled (pending) sends -->
                <div v-if="scheduledForCase(detail.caseId).length" class="mb-3 space-y-2">
                  <p class="text-[10px] uppercase tracking-wider font-medium text-amber-600 flex items-center gap-1">
                    <i class="pi pi-clock text-[10px]" />
                    รออยู่ในคิว ({{ scheduledForCase(detail.caseId).length }})
                  </p>
                  <div
                    v-for="s in scheduledForCase(detail.caseId)"
                    :key="s.id"
                    class="border border-amber-200 bg-amber-50/40 rounded-lg p-3"
                  >
                    <div class="flex items-start justify-between gap-2">
                      <div class="min-w-0 flex-1">
                        <div class="text-xs font-medium text-slate-900 truncate">{{ s.payload.subject }}</div>
                        <div class="text-[10px] text-slate-500 mt-0.5 flex items-center gap-2 flex-wrap">
                          <span>{{ s.payload.recipients.length }} ปลายทาง</span>
                          <span>·</span>
                          <span class="font-mono">{{ formatScheduleTime(s.scheduledAt) }}</span>
                          <span class="px-1.5 py-0 rounded bg-amber-100 text-amber-700 font-medium">
                            {{ relativeTimeUntil(s.scheduledAt) }}
                          </span>
                        </div>
                      </div>
                      <button
                        type="button"
                        @click="cancelScheduledSend(s.id)"
                        class="text-[10px] text-rose-600 hover:bg-rose-50 px-2 py-1 rounded transition flex items-center gap-1 shrink-0"
                      >
                        <i class="pi pi-times text-[9px]" />
                        ยกเลิก
                      </button>
                    </div>
                  </div>
                </div>

                <div v-if="!detail.threads.length && !scheduledForCase(detail.caseId).length" class="text-center py-4 text-xs text-slate-400 italic border border-dashed border-slate-200 rounded-lg">
                  ยังไม่มีการส่งอีเมล — คลิก "ส่งอีเมลใหม่" เพื่อเริ่มต้น
                </div>
                <template v-if="detail.threads.length">
                  <button
                    type="button"
                    @click="closeDetail(); jumpToThreadsForCase(detail.caseId)"
                    class="w-full mb-2 px-3 py-2 bg-brand-50 hover:bg-brand-100 text-brand-700 rounded-lg text-xs font-medium flex items-center justify-center gap-1.5 transition"
                  >
                    <i class="pi pi-external-link text-[10px]" />
                    ดูทั้งหมดใน Email Threads ({{ detail.threads.length }})
                  </button>
                  <ul class="space-y-2">
                    <li
                      v-for="em in [...detail.threads].reverse()"
                      :key="em.id"
                      class="border border-slate-200 rounded-lg p-3 hover:border-brand-300 hover:bg-brand-50/20 cursor-pointer transition"
                      @click="closeDetail(); jumpToThreadsForCase(detail.caseId)"
                    >
                      <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                          <i class="pi pi-envelope text-xs" />
                        </div>
                        <div class="flex-1 min-w-0">
                          <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-medium text-slate-900 truncate">{{ em.subject }}</span>
                            <span class="font-mono text-[10px] text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded shrink-0">{{ em.carrierCode }}</span>
                            <span
                              v-if="em.deliveryStatus"
                              :class="['inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium', deliveryStatusBadge(em.deliveryStatus)]"
                              :title="em.bouncedReason || ''"
                            >
                              <i :class="[deliveryStatusIcon(em.deliveryStatus), 'text-[9px]']" />
                              {{ deliveryStatusLabel(em.deliveryStatus) }}
                            </span>
                            <span
                              v-if="em.responses.length"
                              class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-violet-50 text-violet-700"
                            >
                              <i class="pi pi-reply text-[9px]" />
                              {{ em.responses.length }} ตอบกลับ
                            </span>
                          </div>
                          <div class="text-xs text-slate-500 mt-0.5 truncate">
                            ถึง: <span class="font-mono">{{ em.to }}</span>
                            <template v-if="em.attachments && em.attachments.length"> · <i class="pi pi-paperclip text-[9px]" /> {{ em.attachments.length }} ไฟล์</template>
                          </div>
                          <div class="text-[10px] text-slate-400 mt-1">
                            {{ em.sentByUser }} · {{ em.sentAt }} · <span class="italic">{{ em.template }}</span>
                          </div>
                        </div>
                      </div>
                    </li>
                  </ul>
                </template>
              </section>

              <!-- Notes log -->
              <section>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Notes Log</h4>

                <!-- Composer -->
                <div class="border border-slate-200 rounded-lg p-3 mb-3 focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-100 transition">
                  <textarea
                    v-model="newNote"
                    rows="2"
                    placeholder="เขียนบันทึก... (Ctrl+Enter เพื่อบันทึก)"
                    class="w-full text-sm bg-transparent resize-none focus:outline-none placeholder:text-slate-400"
                    @keydown.ctrl.enter.exact="submitNote"
                    @keydown.meta.enter.exact="submitNote"
                  />
                  <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-100">
                    <div class="text-xs text-slate-400">เห็นเฉพาะทีมสนับสนุน</div>
                    <button
                      @click="submitNote"
                      :disabled="!newNote.trim()"
                      class="px-3 py-1 text-xs bg-brand-600 text-white rounded font-medium hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1"
                    >
                      <i class="pi pi-send text-[10px]" />
                      เพิ่มบันทึก
                    </button>
                  </div>
                </div>

                <!-- Note list -->
                <ul v-if="detail.notes.length" class="space-y-2.5">
                  <li v-for="n in [...detail.notes].reverse()" :key="n.id" class="flex gap-3">
                    <div class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-[10px] font-medium shrink-0">
                      {{ n.byUser.split(' ').map((s) => s.charAt(0)).join('').slice(0, 2) }}
                    </div>
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2 text-xs">
                        <span class="font-medium text-slate-900">{{ n.byUser }}</span>
                        <span class="text-slate-400 font-mono">{{ n.at }}</span>
                      </div>
                      <div class="text-sm text-slate-700 mt-0.5 leading-relaxed">{{ n.body }}</div>
                    </div>
                  </li>
                </ul>
                <div v-else class="text-center py-4 text-xs text-slate-400 italic">ยังไม่มีบันทึก</div>
              </section>
            </div>

            <!-- Footer actions -->
            <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 shrink-0 flex-wrap">
              <button @click="closeDetail" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white transition">
                ปิด
              </button>
              <button
                @click="openStatusChange(detail)"
                class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white transition flex items-center gap-1.5"
              >
                <i class="pi pi-refresh text-xs" />
                เปลี่ยนสถานะ
              </button>
              <button
                @click="openQuotationManually(detail); closeDetail()"
                class="px-4 py-2 text-sm rounded-lg border border-brand-300 text-brand-700 hover:bg-brand-50 transition flex items-center gap-1.5"
              >
                <i class="pi pi-file-pdf text-xs" />
                สร้างใบเสนอราคา
              </button>
              <button @click="jumpToPolicies" class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition flex items-center gap-1.5">
                <i class="pi pi-external-link text-xs" />
                เปิดในหน้ากรมธรรม์
              </button>
            </footer>
          </aside>
        </Transition>
      </div>
    </Transition>

    <!-- ─── New case modal ─────────────────────────────────────────────── -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="showNewCase"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40"
      >
        <div class="bg-white rounded-xl shadow-xl w-full max-w-lg">
          <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
              <h3 class="font-semibold text-slate-900">สร้างเคสใหม่</h3>
              <p class="text-xs text-slate-500 mt-0.5">บันทึกเคสที่ยังไม่ได้เริ่มในระบบกรมธรรม์</p>
            </div>
            <button @click="showNewCase = false" class="text-slate-400 hover:text-slate-700">
              <i class="pi pi-times" />
            </button>
          </header>

          <div class="px-5 py-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">ตัวแทน <span class="text-rose-500">*</span></label>
                <input
                  v-model="newCaseForm.agentName"
                  type="text"
                  placeholder="ชื่อ-นามสกุล ตัวแทน"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">
                  รหัสตัวแทน
                  <span class="ml-1 font-normal text-slate-400 text-xs">(เช่น IN210253)</span>
                </label>
                <input
                  v-model="newCaseForm.agentCode"
                  type="text"
                  placeholder="IN210xxx"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">ลูกค้า <span class="text-rose-500">*</span></label>
              <input
                v-model="newCaseForm.clientName"
                type="text"
                placeholder="ชื่อ-นามสกุล ลูกค้า"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">บริษัทประกัน</label>
                <select
                  v-model="newCaseForm.carrier"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                >
                  <option v-for="c in carrierOptions" :key="c" :value="c">{{ c }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">เบี้ยรายปี (บาท)</label>
                <input
                  v-model.number="newCaseForm.premium"
                  type="number"
                  min="0"
                  step="1000"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1.5">ผลิตภัณฑ์ <span class="text-rose-500">*</span></label>
              <input
                v-model="newCaseForm.productName"
                type="text"
                placeholder="เช่น เอไอเอ ตลอดชีพ 100"
                class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>

            <div class="bg-blue-50 border border-blue-200 text-blue-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              <span>เคสจะถูกสร้างในสถานะ Draft และเพิ่มเข้าคิวสด — ดูได้ในตารางด้านล่าง</span>
            </div>
          </div>

          <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
            <button @click="showNewCase = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
              ยกเลิก
            </button>
            <button
              @click="submitNewCase"
              :disabled="!newCaseForm.agentName.trim() || !newCaseForm.clientName.trim() || !newCaseForm.productName.trim() || newCaseForm.premium <= 0"
              class="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1.5"
            >
              <i class="pi pi-plus text-xs" />
              สร้างเคส
            </button>
          </footer>
        </div>
      </div>
    </Transition>

    <!-- ─── Email compose modal ────────────────────────────────────────── -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="showEmail && emailCase"
        class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/50"
      >
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
          <!-- Header -->
          <header class="px-5 py-4 border-b border-slate-100 flex items-start justify-between shrink-0">
            <div class="min-w-0">
              <h3 class="font-semibold text-slate-900 flex items-center gap-2">
                <i class="pi pi-envelope text-blue-600" />
                เขียนอีเมลถึงบริษัทประกัน
              </h3>
              <p class="text-xs text-slate-500 mt-0.5">
                เคส <span class="font-mono">{{ emailCase.caseId }}</span> · {{ emailCase.clientName }} · {{ emailCase.carrier }}
              </p>
            </div>
            <button @click="closeEmail" class="text-slate-400 hover:text-slate-700 shrink-0">
              <i class="pi pi-times" />
            </button>
          </header>

          <!-- Body (scrollable) -->
          <div v-if="composePhase === 'compose'" class="overflow-y-auto flex-1 px-5 py-5">
            <!-- Sender preview — confirms the From line carriers will see -->
            <div class="mb-4 flex items-center gap-3 px-3 py-2.5 rounded-lg bg-blue-50/40 border border-blue-100">
              <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center shrink-0">
                <i class="pi pi-send text-xs" />
              </div>
              <div class="min-w-0 flex-1">
                <div class="text-[10px] uppercase tracking-wider font-semibold text-blue-700">ส่งจาก (From)</div>
                <div class="text-sm font-medium text-slate-900 truncate">
                  {{ emailApi.config.fromName }}
                  <span class="text-slate-400 font-normal">&lt;{{ emailApi.config.fromAddress }}&gt;</span>
                </div>
                <div class="text-[10px] text-slate-500 truncate font-mono">
                  Reply-To: no-reply+T-xxxxx@{{ emailApi.config.tenantDomain }}
                  <span class="text-slate-400">(plus-addressed per thread)</span>
                </div>
              </div>
              <span
                v-if="emailApi.mode === 'mock'"
                class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 font-medium shrink-0"
                title="ระบบยังเป็น mock — ยังไม่ส่งจริงผ่าน Zoho"
              >
                mock mode
              </span>
            </div>

            <!-- Template picker -->
            <div class="mb-5">
              <div class="flex items-center justify-between mb-2">
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">เลือกเทมเพลต</label>
                <button
                  type="button"
                  @click="openTemplateEditor(null)"
                  class="text-[10px] text-blue-600 hover:text-blue-700 hover:underline flex items-center gap-1"
                >
                  <i class="pi pi-plus text-[9px]" />
                  เพิ่มเทมเพลตใหม่
                </button>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <div
                  v-for="tpl in emailTemplates"
                  :key="tpl.id"
                  :class="[
                    'group relative text-left p-3 border rounded-lg transition flex items-start gap-2.5 cursor-pointer',
                    emailTemplate === tpl.id
                      ? 'ring-2 ring-blue-500 border-blue-200 bg-blue-50/30'
                      : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50',
                  ]"
                  @click="applyTemplate(tpl.id)"
                >
                  <div :class="[
                    'w-7 h-7 rounded-md flex items-center justify-center shrink-0',
                    emailTemplate === tpl.id ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500',
                  ]">
                    <i :class="tpl.icon + ' text-xs'" />
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-slate-900 flex items-center gap-1.5">
                      {{ tpl.label }}
                      <span v-if="tpl.isBuiltIn" class="text-[9px] px-1 py-0 rounded bg-slate-100 text-slate-500 font-normal">ในระบบ</span>
                    </div>
                    <div class="text-[11px] text-slate-500 mt-0.5 leading-snug">{{ tpl.desc }}</div>
                  </div>
                  <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition shrink-0">
                    <button
                      type="button"
                      @click.stop="openTemplateEditor(tpl.id)"
                      class="p-1 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded transition"
                      title="แก้ไขเทมเพลต"
                    >
                      <i class="pi pi-pencil text-[10px]" />
                    </button>
                    <button
                      v-if="!tpl.isBuiltIn"
                      type="button"
                      @click.stop="confirmDeleteTemplate(tpl.id)"
                      class="p-1 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded transition"
                      title="ลบเทมเพลต"
                    >
                      <i class="pi pi-trash text-[10px]" />
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Recipient picker -->
            <div class="mb-4">
              <div class="flex items-center justify-between mb-2">
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">
                  เลือกปลายทาง ({{ selectedCarrierCount }} อีเมล)
                </label>
                <span class="text-[10px] text-slate-400 italic">หนึ่ง thread ต่อบริษัท — ติดตามคำตอบแยกกัน</span>
              </div>

              <!-- Client / Agent simple rows -->
              <div class="border border-slate-200 rounded-lg divide-y divide-slate-100 mb-3">
                <label
                  :class="[
                    'flex items-center gap-3 px-3 py-2 cursor-pointer transition',
                    composeClient.selected ? 'bg-emerald-50/40' : 'hover:bg-slate-50',
                  ]"
                >
                  <input
                    type="checkbox"
                    :checked="composeClient.selected"
                    @change="toggleClientRow"
                    class="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                  />
                  <i class="pi pi-user text-emerald-600 text-xs w-4 shrink-0" />
                  <div class="min-w-0 w-40 shrink-0 hidden md:block">
                    <div class="text-xs font-medium text-slate-800">ลูกค้า</div>
                    <div class="text-[10px] text-slate-400 truncate">{{ emailCase.clientName }}</div>
                  </div>
                  <input
                    v-model="composeClient.to"
                    :disabled="!composeClient.selected"
                    type="email"
                    placeholder="client@example.com"
                    class="flex-1 min-w-0 px-2 py-1 text-xs font-mono border border-slate-200 rounded focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-100 disabled:bg-slate-50 disabled:text-slate-400"
                  />
                </label>
                <label
                  :class="[
                    'flex items-center gap-3 px-3 py-2 cursor-pointer transition',
                    composeAgent.selected ? 'bg-violet-50/40' : 'hover:bg-slate-50',
                  ]"
                >
                  <input
                    type="checkbox"
                    :checked="composeAgent.selected"
                    @change="toggleAgentRow"
                    class="w-4 h-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                  />
                  <i class="pi pi-id-card text-violet-600 text-xs w-4 shrink-0" />
                  <div class="min-w-0 w-40 shrink-0 hidden md:block">
                    <div class="text-xs font-medium text-slate-800">ตัวแทนผู้ขาย</div>
                    <div class="text-[10px] text-slate-400 truncate">{{ emailCase.agentName }}</div>
                  </div>
                  <input
                    v-model="composeAgent.to"
                    :disabled="!composeAgent.selected"
                    type="email"
                    placeholder="agent@example.com"
                    class="flex-1 min-w-0 px-2 py-1 text-xs font-mono border border-slate-200 rounded focus:outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-100 disabled:bg-slate-50 disabled:text-slate-400"
                  />
                </label>
              </div>

              <!-- Carrier blocks: pick groups, system unions emails -->
              <div class="space-y-2.5">
                <div
                  v-for="cv in carrierPickerViews"
                  :key="cv.code"
                  :class="[
                    'border rounded-lg overflow-hidden transition',
                    cv.totalAddressCount > 0
                      ? 'border-blue-200 ring-1 ring-blue-100 bg-blue-50/20'
                      : 'border-slate-200',
                  ]"
                >
                  <header class="px-3 py-2 flex items-center justify-between gap-2 bg-slate-50/60 border-b border-slate-100">
                    <div class="flex items-center gap-2 min-w-0">
                      <i class="pi pi-building text-slate-500 text-xs" />
                      <span class="font-mono text-[10px] font-medium text-slate-600 bg-slate-100 px-1.5 py-0.5 rounded shrink-0">{{ cv.code }}</span>
                      <span class="text-xs font-medium text-slate-800 truncate">{{ cv.name }}</span>
                      <span v-if="cv.isPrimary" class="text-[10px] px-1.5 py-0.5 bg-emerald-50 text-emerald-700 rounded shrink-0">
                        หลัก
                      </span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                      <span
                        v-if="cv.totalAddressCount > 0"
                        class="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 font-medium"
                      >
                        ส่ง {{ cv.totalAddressCount }} อีเมล
                      </span>
                      <button
                        v-if="cv.totalAddressCount > 0"
                        type="button"
                        @click="clearCarrierBlock(cv.code)"
                        class="text-[10px] text-slate-400 hover:text-rose-600 hover:underline"
                      >
                        ล้างการเลือก
                      </button>
                    </div>
                  </header>

                  <!-- Groups checklist -->
                  <div v-if="cv.groups.length" class="px-3 py-2 space-y-1.5">
                    <div class="flex items-center justify-between gap-2 mb-1">
                      <p class="text-[10px] uppercase tracking-wider font-medium text-slate-500">
                        กลุ่มอีเมล ({{ filteredGroupsFor(cv.code, cv.groups).length }}/{{ cv.groups.length }})
                      </p>
                    </div>
                    <div v-if="cv.groups.length > 2" class="relative mb-1">
                      <i class="pi pi-search absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]" />
                      <input
                        :value="carrierGroupSearch[cv.code] ?? ''"
                        @input="(e) => carrierGroupSearch[cv.code] = (e.target as HTMLInputElement).value"
                        type="search"
                        placeholder="ค้นหากลุ่ม / อีเมล / แผนก..."
                        class="w-full pl-6 pr-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100 bg-white"
                      />
                    </div>
                    <p
                      v-if="!filteredGroupsFor(cv.code, cv.groups).length"
                      class="text-[10px] text-slate-400 italic px-2 py-2"
                    >
                      ไม่พบกลุ่มที่ตรงกับ "{{ carrierGroupSearch[cv.code] }}"
                    </p>
                    <label
                      v-for="g in filteredGroupsFor(cv.code, cv.groups)"
                      :key="g.id"
                      :class="[
                        'flex items-start gap-2 px-2 py-1.5 rounded cursor-pointer transition',
                        cv.block.selectedGroupIds.has(g.id) ? 'bg-white border border-blue-200' : 'hover:bg-white/60',
                      ]"
                    >
                      <input
                        type="checkbox"
                        :checked="cv.block.selectedGroupIds.has(g.id)"
                        @change="toggleCarrierGroup(cv.code, g.id)"
                        class="w-3.5 h-3.5 mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500 shrink-0"
                      />
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5 flex-wrap">
                          <span class="text-xs font-medium text-slate-800">{{ g.name }}</span>
                          <span class="text-[10px] px-1 py-0 rounded bg-slate-100 text-slate-500">
                            {{ DEPARTMENT_LABELS[g.department] }}
                          </span>
                          <span
                            v-if="g.emails.length > 1"
                            class="text-[10px] px-1 py-0 rounded bg-amber-50 text-amber-700 font-medium"
                          >
                            {{ g.emails.length }} อีเมล
                          </span>
                          <span
                            v-if="isAutoSeeded(g)"
                            class="text-[10px] px-1 py-0 rounded bg-amber-100 text-amber-800 font-medium inline-flex items-center gap-0.5"
                            title="กลุ่มนี้ถูกใส่ไว้ให้อัตโนมัติ — โปรดตรวจสอบรายชื่อผู้รับให้ถูกต้องก่อนส่ง"
                          >
                            <i class="pi pi-exclamation-triangle text-[8px]" />
                            ตรวจสอบ
                          </span>
                        </div>
                        <div class="flex flex-wrap gap-1 mt-1">
                          <span
                            v-for="addr in g.emails"
                            :key="addr"
                            class="inline-flex items-center px-1.5 py-0.5 text-[10px] font-mono bg-slate-50 border border-slate-200 rounded text-slate-600"
                          >
                            {{ addr }}
                          </span>
                        </div>
                      </div>
                    </label>
                  </div>
                  <div v-else class="px-3 py-3 text-center">
                    <p class="text-[11px] text-slate-500">
                      ยังไม่มีกลุ่มอีเมลที่ตรงกับเทมเพลตนี้
                    </p>
                    <p class="text-[10px] text-slate-400 mt-0.5">
                      เพิ่มกลุ่มที่หน้า <RouterLink to="/carriers" class="text-blue-600 hover:underline">Carriers</RouterLink>
                      หรือพิมพ์อีเมลในช่อง "เพิ่มเติม" ด้านล่าง
                    </p>
                  </div>

                  <!-- Custom extra addresses -->
                  <div class="px-3 py-2 border-t border-slate-100 bg-white/50">
                    <label class="block text-[10px] uppercase tracking-wider font-medium text-slate-500 mb-1">
                      อีเมลเพิ่มเติม (คั่นด้วย ,)
                    </label>
                    <input
                      v-model="cv.block.customExtra"
                      type="text"
                      placeholder="extra1@aia.co.th, extra2@aia.co.th"
                      class="w-full px-2 py-1 text-xs font-mono border border-slate-200 rounded focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100"
                    />
                  </div>

                  <!-- Resolved TO preview -->
                  <div v-if="cv.totalAddressCount > 0" class="px-3 py-2 border-t border-slate-100 bg-blue-50/30">
                    <p class="text-[10px] uppercase tracking-wider font-medium text-slate-500 mb-1">
                      ส่งถึง (TO รวม)
                    </p>
                    <div class="flex flex-wrap gap-1">
                      <span
                        v-for="addr in cv.toAddresses"
                        :key="addr"
                        class="inline-flex items-center px-1.5 py-0.5 text-[10px] font-mono bg-white border border-blue-200 rounded text-slate-700"
                      >
                        {{ addr }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Other free-form row -->
              <label
                :class="[
                  'mt-3 flex items-center gap-3 px-3 py-2 cursor-pointer transition border rounded-lg',
                  composeOther.selected ? 'bg-slate-50 border-slate-300' : 'border-slate-200 hover:bg-slate-50',
                ]"
              >
                <input
                  type="checkbox"
                  :checked="composeOther.selected"
                  @change="toggleOtherRow"
                  class="w-4 h-4 rounded border-slate-300 text-slate-600 focus:ring-slate-500"
                />
                <i class="pi pi-envelope text-slate-500 text-xs w-4 shrink-0" />
                <div class="min-w-0 w-40 shrink-0 hidden md:block">
                  <div class="text-xs font-medium text-slate-800">อีเมลอื่น</div>
                  <div class="text-[10px] text-slate-400">ส่งเป็น thread แยก</div>
                </div>
                <input
                  v-model="composeOther.to"
                  :disabled="!composeOther.selected"
                  type="email"
                  placeholder="someone@example.com"
                  class="flex-1 min-w-0 px-2 py-1 text-xs font-mono border border-slate-200 rounded focus:outline-none focus:border-slate-500 focus:ring-1 focus:ring-slate-100 disabled:bg-slate-50 disabled:text-slate-400"
                />
              </label>

              <p class="text-[10px] text-slate-400 mt-2">
                ตั้งค่ากลุ่มอีเมลของแต่ละบริษัทได้ที่หน้า
                <RouterLink to="/carriers" class="text-blue-600 hover:underline">Carriers</RouterLink>
              </p>
            </div>

            <!-- Cc -->
            <div class="mb-3">
              <label class="block text-xs font-medium text-slate-600 mb-1.5">Cc (ไม่บังคับ) — ส่งสำเนาถึงทุก thread</label>
              <input
                v-model="emailForm.cc"
                type="email"
                placeholder="cc@example.com"
                class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>

            <!-- Subject -->
            <div class="mb-3">
              <label class="block text-xs font-medium text-slate-600 mb-1.5">หัวข้อ <span class="text-rose-500">*</span></label>
              <input
                v-model="emailForm.subject"
                type="text"
                class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
              />
            </div>

            <!-- Body -->
            <div class="mb-3">
              <label class="block text-xs font-medium text-slate-600 mb-1.5">เนื้อหา</label>
              <textarea
                v-model="emailForm.body"
                rows="14"
                class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 resize-none font-sans leading-relaxed"
              />
            </div>

            <!-- Attachments — always visible with picker + drag-drop -->
            <div
              :class="[
                'border rounded-lg overflow-hidden mb-4 transition',
                composeDragOver ? 'border-blue-400 ring-2 ring-blue-200 bg-blue-50/30' : 'border-slate-200',
              ]"
              @dragenter.prevent="composeDragOver = true"
              @dragover.prevent="composeDragOver = true"
              @dragleave.prevent="composeDragOver = false"
              @drop.prevent="onComposeDrop"
            >
              <div class="px-3 py-2 bg-slate-50 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
                <div class="flex items-center gap-2 flex-wrap">
                  <i class="pi pi-paperclip text-slate-500 text-xs" />
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-600">ไฟล์แนบ</span>
                  <span
                    :class="[
                      'inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium',
                      allAttachments.length > 0 ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500',
                    ]"
                  >
                    {{ allAttachments.length }} ไฟล์
                  </span>
                  <span
                    v-if="allAttachments.length"
                    class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] bg-slate-100 text-slate-500 font-mono"
                  >
                    {{ formatBytes(totalAttachmentSizeBytes) }}
                  </span>
                </div>
                <div class="flex items-center gap-2">
                  <button
                    v-if="emailCase.documents.length > 1"
                    type="button"
                    @click="toggleAllAttachments"
                    class="text-[10px] text-blue-600 hover:underline"
                  >
                    {{ selectedDocs.length === emailCase.documents.length ? 'ยกเลิกทั้งหมด' : 'เลือกเอกสารเคสทั้งหมด' }}
                  </button>
                  <input
                    ref="composeFileInput"
                    type="file"
                    multiple
                    class="hidden"
                    @change="onComposeFileChange"
                  />
                  <button
                    type="button"
                    @click="triggerComposeFilePicker"
                    class="inline-flex items-center gap-1 px-2 py-1 text-[10px] font-medium bg-blue-600 text-white rounded hover:bg-blue-700 transition"
                  >
                    <i class="pi pi-plus text-[9px]" />
                    เพิ่มไฟล์
                  </button>
                </div>
              </div>

              <!-- Drag-drop hint when dragging -->
              <div v-if="composeDragOver" class="px-3 py-6 text-center bg-blue-50/50">
                <i class="pi pi-cloud-upload text-blue-500 text-2xl block mb-1" />
                <p class="text-sm text-blue-700 font-medium">วางไฟล์ที่นี่เพื่อแนบ</p>
              </div>

              <template v-else>
                <!-- Empty state when no docs AND no fresh files -->
                <div v-if="!emailCase.documents.length && !freshAttachments.length" class="px-3 py-5 text-center">
                  <i class="pi pi-file text-slate-300 text-xl block mb-1" />
                  <p class="text-xs text-slate-500">ยังไม่มีไฟล์แนบ</p>
                  <p class="text-[10px] text-slate-400 mt-0.5">ลากวางไฟล์ที่นี่ หรือคลิก "เพิ่มไฟล์" ด้านบน</p>
                </div>

                <!-- Unified list: case docs (with checkbox) + fresh files (with remove) -->
                <ul v-else class="divide-y divide-slate-100 max-h-48 overflow-y-auto">
                  <!-- Case documents -->
                  <li
                    v-for="d in emailCase.documents"
                    :key="d.id"
                    class="px-3 py-2 flex items-center gap-2.5 hover:bg-slate-50 cursor-pointer transition"
                    @click="toggleAttachment(d.id)"
                  >
                    <input
                      type="checkbox"
                      :checked="attachedDocIds.has(d.id)"
                      @click.stop
                      @change="toggleAttachment(d.id)"
                      class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 shrink-0"
                    />
                    <div class="w-7 h-7 rounded bg-slate-100 text-slate-500 flex items-center justify-center shrink-0">
                      <i class="pi pi-file-pdf text-xs" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <div class="text-sm text-slate-900 truncate">{{ d.name }}</div>
                      <div class="text-[10px] text-slate-400">
                        <span class="inline-flex items-center px-1 py-0 rounded bg-slate-100 text-slate-500 mr-1">เอกสารเคส</span>
                        {{ d.size }} · อัปโหลด {{ d.uploadedAt }}
                      </div>
                    </div>
                  </li>

                  <!-- Fresh files -->
                  <li
                    v-for="f in freshAttachments"
                    :key="f.id"
                    class="px-3 py-2 flex items-center gap-2.5 bg-blue-50/30"
                  >
                    <i class="pi pi-check-circle text-blue-600 text-xs shrink-0" />
                    <div class="w-7 h-7 rounded bg-blue-100 text-blue-700 flex items-center justify-center shrink-0">
                      <i class="pi pi-file text-xs" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <div class="text-sm text-slate-900 truncate">{{ f.name }}</div>
                      <div class="text-[10px] text-slate-400">
                        <span class="inline-flex items-center px-1 py-0 rounded bg-blue-100 text-blue-700 font-medium mr-1">ไฟล์ใหม่</span>
                        {{ f.size }}
                      </div>
                    </div>
                    <button
                      type="button"
                      @click.stop="removeFreshFile(f.id)"
                      class="px-1.5 py-1 text-rose-500 hover:bg-rose-50 rounded transition"
                      title="ลบไฟล์"
                    >
                      <i class="pi pi-times text-[10px]" />
                    </button>
                  </li>
                </ul>

                <!-- Footer: save-to-case toggle + hint -->
                <div class="px-3 py-2 bg-slate-50/50 border-t border-slate-100 space-y-1.5">
                  <label v-if="freshAttachments.length" class="flex items-center gap-2 cursor-pointer">
                    <input
                      v-model="saveFreshToCase"
                      type="checkbox"
                      class="w-3.5 h-3.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                    />
                    <span class="text-[11px] text-slate-700">
                      บันทึกไฟล์ใหม่เข้าเป็นเอกสารของเคสนี้หลังส่ง ({{ freshAttachments.length }} ไฟล์)
                    </span>
                  </label>
                  <p class="text-[10px] text-slate-500 italic">
                    ไฟล์ที่แนบจะถูกส่งไปกับทุก thread (ทุกบริษัทประกันที่เลือก)
                  </p>
                </div>
              </template>
            </div>

            <!-- API delivery info banner -->
            <div class="border-t border-slate-100 pt-4">
              <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs text-slate-600 flex items-start gap-2">
                <i class="pi pi-info-circle text-slate-400 mt-0.5" />
                <div class="flex-1">
                  <div>ส่งผ่าน <strong class="text-slate-800">{{ emailApi.config.fromName }} ({{ emailApi.config.fromAddress }})</strong></div>
                  <div class="mt-1 text-[11px] text-slate-500">คำตอบจะถูกจับคู่กับเคสนี้อัตโนมัติผ่าน plus-addressing — ติดตามสถานะการส่งได้ในประวัติของเคสและในหน้า Email Threads</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Sending phase — live results strip -->
          <div v-else class="overflow-y-auto flex-1 px-5 py-5 space-y-4">
            <div class="text-center mb-4">
              <div v-if="!allDelivered" class="inline-flex items-center gap-2 text-blue-700 text-sm font-medium">
                <i class="pi pi-spin pi-spinner" />
                กำลังส่งถึงบริษัทประกัน {{ selectedCarrierCount }} ราย...
              </div>
              <div v-else-if="!anyBouncedOrFailed" class="inline-flex items-center gap-2 text-emerald-700 text-sm font-medium">
                <i class="pi pi-check-circle" />
                ส่งถึงบริษัทประกันทั้งหมดเรียบร้อย — หน้าต่างจะปิดอัตโนมัติ
              </div>
              <div v-else class="inline-flex items-center gap-2 text-rose-700 text-sm font-medium">
                <i class="pi pi-exclamation-triangle" />
                ส่งเสร็จแล้ว — มีบางรายการตีกลับ ตรวจสอบด้านล่าง
              </div>
            </div>

            <ul class="space-y-2">
              <li
                v-for="r in carrierRecipients.filter((x) => x.selected)"
                :key="r.rowId"
                class="border border-slate-200 rounded-lg p-3 flex items-center gap-3"
              >
                <div
                  :class="[
                    'w-10 h-10 rounded-lg flex items-center justify-center shrink-0',
                    r.role === 'client' ? 'bg-emerald-50 text-emerald-600' :
                    r.role === 'agent' ? 'bg-violet-50 text-violet-600' :
                    r.role === 'other' ? 'bg-slate-100 text-slate-500' :
                    'bg-slate-50 text-slate-600',
                  ]"
                >
                  <i :class="
                    r.role === 'client' ? 'pi pi-user' :
                    r.role === 'agent' ? 'pi pi-id-card' :
                    r.role === 'other' ? 'pi pi-envelope' :
                    'pi pi-building'
                  " />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium text-slate-900">
                    {{ r.label }}
                    <span v-if="r.role === 'carrier'" class="text-slate-400 font-normal text-xs">· {{ r.code }}</span>
                  </div>
                  <div class="text-[11px] text-slate-500 truncate">
                    <template v-if="r.subLabel">{{ r.subLabel }} · </template>
                    <template v-else-if="r.role === 'client'">{{ emailCase?.clientName }} · </template>
                    <template v-else-if="r.role === 'agent'">{{ emailCase?.agentName }} · </template>
                    <span class="font-mono">{{ r.to }}</span>
                  </div>
                </div>
                <span
                  :class="['inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium', deliveryStatusBadge(r.deliveryStatus)]"
                >
                  <i :class="[deliveryStatusIcon(r.deliveryStatus), 'text-[10px]']" />
                  {{ deliveryStatusLabel(r.deliveryStatus) }}
                </span>
              </li>
            </ul>

            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs text-slate-600 flex items-start gap-2">
              <i class="pi pi-info-circle text-slate-400 mt-0.5" />
              <span>คำตอบจากแต่ละบริษัทจะถูกจับคู่กับ thread ของตน</span>
            </div>

            <button
              v-if="emailCase"
              type="button"
              @click="const cid = emailCase.caseId; closeEmail(); jumpToThreadsForCase(cid)"
              class="w-full px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium flex items-center justify-center gap-2 transition"
            >
              <i class="pi pi-external-link" />
              ติดตามทุก thread ใน Email Threads
            </button>
          </div>

          <!-- Footer -->
          <footer class="px-5 py-4 border-t border-slate-100 flex justify-between gap-2 bg-slate-50/50 rounded-b-xl shrink-0">
            <div v-if="composePhase === 'compose'" class="text-xs text-slate-400 self-center">
              ลายเซ็นแนบอัตโนมัติจาก Tenant Settings
            </div>
            <div v-else class="text-xs text-slate-500 self-center font-medium">
              {{ carrierRecipients.filter((r) => r.selected && r.deliveryStatus === 'delivered').length }}/{{ selectedCarrierCount }} ส่งถึงปลายทางแล้ว
            </div>

            <div v-if="composePhase === 'compose'" class="flex gap-2">
              <button @click="closeEmail" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
                ยกเลิก
              </button>
              <!-- Split send button -->
              <div class="relative inline-flex">
                <button
                  @click="sendEmail"
                  :disabled="!canSendCompose"
                  class="px-4 py-2 text-sm rounded-l-lg bg-blue-600 text-white font-medium hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5 transition border-r border-blue-700/40"
                >
                  <i class="pi pi-send text-xs" />
                  ส่งถึง {{ selectedCarrierCount }} ปลายทาง
                </button>
                <button
                  @click="toggleScheduleMenu"
                  :disabled="!canSendCompose"
                  class="px-2.5 py-2 text-sm rounded-r-lg bg-blue-600 text-white font-medium hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center transition"
                  title="ตั้งเวลาส่ง"
                >
                  <i class="pi pi-chevron-down text-xs" />
                </button>
                <Transition
                  enter-active-class="transition duration-150 ease-out"
                  enter-from-class="opacity-0 translate-y-1"
                  enter-to-class="opacity-100 translate-y-0"
                >
                  <div
                    v-if="showScheduleMenu"
                    class="absolute right-0 bottom-full mb-1 w-56 bg-white border border-slate-200 rounded-lg shadow-lg z-50 overflow-hidden"
                  >
                    <div class="px-3 py-2 bg-slate-50 border-b border-slate-100">
                      <p class="text-[10px] uppercase tracking-wider font-medium text-slate-500">ตั้งเวลาส่ง</p>
                    </div>
                    <button
                      type="button"
                      @click="schedulePreset(15)"
                      class="w-full text-left px-3 py-2 text-xs hover:bg-blue-50 hover:text-blue-700 transition flex items-center justify-between"
                    >
                      <span>ใน 15 นาที</span>
                      <i class="pi pi-clock text-[10px] text-slate-400" />
                    </button>
                    <button
                      type="button"
                      @click="schedulePreset(60)"
                      class="w-full text-left px-3 py-2 text-xs hover:bg-blue-50 hover:text-blue-700 transition flex items-center justify-between"
                    >
                      <span>ใน 1 ชม.</span>
                      <i class="pi pi-clock text-[10px] text-slate-400" />
                    </button>
                    <button
                      type="button"
                      @click="schedulePreset(4 * 60)"
                      class="w-full text-left px-3 py-2 text-xs hover:bg-blue-50 hover:text-blue-700 transition flex items-center justify-between"
                    >
                      <span>ใน 4 ชม.</span>
                      <i class="pi pi-clock text-[10px] text-slate-400" />
                    </button>
                    <button
                      type="button"
                      @click="schedulePreset(24 * 60)"
                      class="w-full text-left px-3 py-2 text-xs hover:bg-blue-50 hover:text-blue-700 transition flex items-center justify-between border-b border-slate-100"
                    >
                      <span>พรุ่งนี้เวลานี้</span>
                      <i class="pi pi-clock text-[10px] text-slate-400" />
                    </button>
                    <button
                      type="button"
                      @click="openCustomSchedule"
                      class="w-full text-left px-3 py-2 text-xs hover:bg-blue-50 hover:text-blue-700 transition flex items-center justify-between font-medium text-blue-700"
                    >
                      <span>กำหนดเอง...</span>
                      <i class="pi pi-calendar text-[10px]" />
                    </button>
                  </div>
                </Transition>
              </div>
            </div>
            <div v-else class="flex gap-2">
              <button
                @click="closeEmail"
                class="px-4 py-2 text-sm rounded-lg bg-slate-900 text-white font-medium hover:bg-slate-800 flex items-center gap-1.5"
              >
                <i class="pi pi-check text-xs" />
                เสร็จสิ้น
              </button>
            </div>
          </footer>
        </div>
      </div>
    </Transition>

    <!-- ─── Response paste-in modal ────────────────────────────────────── -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="showResponsePaste && responseThread"
        class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/50"
        @click.self="showResponsePaste = false"
      >
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col">
          <header class="px-5 py-4 border-b border-slate-100 flex items-start justify-between shrink-0">
            <div>
              <h3 class="font-semibold text-slate-900 flex items-center gap-2">
                <i class="pi pi-reply text-blue-600" />
                บันทึกคำตอบจากบริษัทประกัน
              </h3>
              <p class="text-xs text-slate-500 mt-0.5">วางเนื้อหาอีเมลที่ได้รับ — AI จะสรุปและแนะนำการตอบกลับให้</p>
            </div>
            <button @click="showResponsePaste = false" class="text-slate-400 hover:text-slate-700">
              <i class="pi pi-times" />
            </button>
          </header>
          <div class="px-5 py-5 space-y-3 overflow-y-auto">
            <div class="bg-slate-50 border border-slate-100 rounded-lg p-3 text-xs text-slate-600">
              <div><strong>เคส:</strong> <span class="font-mono">{{ responseThread.caseRef.caseId }}</span> — {{ responseThread.caseRef.clientName }}</div>
              <div><strong>Thread:</strong> {{ responseThread.thread.subject }}</div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">ชื่อผู้ตอบกลับ</label>
                <input v-model="responseFromName" type="text" placeholder="เช่น คุณส้มยิง — AIA" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">อีเมลผู้ตอบ</label>
                <input v-model="responseFromAddress" type="email" class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-600 mb-1.5">เนื้อหาอีเมลตอบกลับ <span class="text-rose-500">*</span></label>
              <textarea
                v-model="responseText"
                rows="10"
                placeholder="วางเนื้อหาอีเมลจากบริษัทประกันที่นี่..."
                class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 resize-none font-sans"
              />
            </div>
            <div class="bg-violet-50 border border-violet-200 text-violet-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-sparkles mt-0.5" />
              <span>AI จะสรุปคำตอบและแนะนำขั้นถัดไปอัตโนมัติเมื่อบันทึก</span>
            </div>
          </div>
          <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
            <button @click="showResponsePaste = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">ยกเลิก</button>
            <button @click="submitResponse" :disabled="!responseText.trim()" class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5">
              <i class="pi pi-sparkles text-xs" />
              บันทึกและสรุปด้วย AI
            </button>
          </footer>
        </div>
      </div>
    </Transition>

    <!-- ─── AI summary modal ───────────────────────────────────────────── -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="showAISummary && aiSummaryContext"
        class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/50"
        @click.self="showAISummary = false"
      >
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col">
          <header class="px-5 py-4 border-b border-slate-100 flex items-start justify-between shrink-0 bg-gradient-to-br from-violet-50 to-white">
            <div class="flex items-start gap-3">
              <div class="w-10 h-10 rounded-lg bg-violet-100 text-violet-700 flex items-center justify-center">
                <i class="pi pi-sparkles" />
              </div>
              <div>
                <h3 class="font-semibold text-slate-900">AI สรุปคำตอบ</h3>
                <p class="text-xs text-slate-500 mt-0.5">{{ aiSummaryContext.thread.subject }}</p>
              </div>
            </div>
            <button @click="showAISummary = false" class="text-slate-400 hover:text-slate-700">
              <i class="pi pi-times" />
            </button>
          </header>

          <div class="px-5 py-5 overflow-y-auto flex-1 space-y-5">
            <!-- One-liner + sentiment + risk -->
            <div class="border border-slate-200 rounded-lg p-4">
              <div class="flex items-center gap-2 flex-wrap mb-2">
                <span :class="['inline-flex items-center px-2 py-1 rounded-md text-xs font-medium', sentimentBadge(aiSummaryContext.response.aiSummary!.sentiment)]">
                  Sentiment: {{ sentimentLabel(aiSummaryContext.response.aiSummary!.sentiment) }}
                </span>
                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-slate-100 text-slate-700 text-xs font-medium">
                  Risk
                  <span class="flex gap-0.5">
                    <span
                      v-for="i in 5"
                      :key="i"
                      :class="[
                        'w-1.5 h-3 rounded-sm',
                        i <= aiSummaryContext.response.aiSummary!.riskScore ?
                          (aiSummaryContext.response.aiSummary!.riskScore >= 4 ? 'bg-rose-500' :
                           aiSummaryContext.response.aiSummary!.riskScore === 3 ? 'bg-amber-500' :
                           'bg-emerald-500') : 'bg-slate-200',
                      ]"
                    />
                  </span>
                </span>
              </div>
              <p class="text-sm text-slate-900 font-medium leading-relaxed">{{ aiSummaryContext.response.aiSummary!.oneLiner }}</p>
            </div>

            <!-- Action items checklist -->
            <div>
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-2">
                <i class="pi pi-check-square text-xs" />
                สิ่งที่ต้องดำเนินการ ({{ aiSummaryContext.response.aiSummary!.actions.filter(a => a.done).length }}/{{ aiSummaryContext.response.aiSummary!.actions.length }})
              </h4>
              <ul class="space-y-2">
                <li
                  v-for="a in aiSummaryContext.response.aiSummary!.actions"
                  :key="a.id"
                  class="border border-slate-200 rounded-lg p-3 flex items-start gap-2.5 cursor-pointer hover:bg-slate-50"
                  @click="toggleAIAction(a.id)"
                >
                  <input
                    type="checkbox"
                    :checked="a.done"
                    @click.stop
                    @change="toggleAIAction(a.id)"
                    class="mt-0.5 w-4 h-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                  />
                  <span :class="['text-sm flex-1', a.done ? 'text-slate-400 line-through' : 'text-slate-700']">
                    {{ a.label }}
                  </span>
                </li>
              </ul>
            </div>

            <!-- Key entities -->
            <div v-if="aiSummaryContext.response.aiSummary!.keyEntities.length">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-2">
                <i class="pi pi-tag text-xs" />
                ข้อมูลสำคัญที่พบ
              </h4>
              <div class="grid grid-cols-2 gap-2">
                <div v-for="(ent, i) in aiSummaryContext.response.aiSummary!.keyEntities" :key="i" class="border border-slate-200 rounded-lg p-2.5">
                  <div class="text-[10px] uppercase tracking-wider text-slate-400">{{ ent.label }}</div>
                  <div class="text-sm font-mono text-slate-900 mt-0.5 truncate">{{ ent.value }}</div>
                </div>
              </div>
            </div>

            <!-- Suggested next step -->
            <div class="border border-violet-200 bg-violet-50/40 rounded-lg p-4">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-violet-700 mb-1 flex items-center gap-2">
                <i class="pi pi-arrow-right text-xs" />
                ขั้นถัดไปที่แนะนำ
              </h4>
              <p class="text-sm text-slate-800 leading-relaxed">{{ aiSummaryContext.response.aiSummary!.suggestedReplyHint }}</p>
            </div>

            <!-- Original response collapse -->
            <details class="border border-slate-200 rounded-lg">
              <summary class="px-3 py-2 cursor-pointer text-xs font-medium text-slate-600 hover:bg-slate-50">
                <i class="pi pi-envelope text-[10px] mr-1" />
                ดูเนื้อหาอีเมลต้นฉบับ
              </summary>
              <div class="px-3 py-2 border-t border-slate-200 text-xs text-slate-700 whitespace-pre-wrap font-sans leading-relaxed">{{ aiSummaryContext.response.body }}</div>
            </details>
          </div>

          <footer class="px-5 py-4 border-t border-slate-100 flex justify-between gap-2 bg-slate-50/50 rounded-b-xl shrink-0">
            <button @click="showAISummary = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
              ปิด
            </button>
            <div class="flex gap-2 flex-wrap">
              <button
                v-if="canCreateQuotation(aiSummaryContext.response)"
                @click="openQuotationBuilder"
                class="px-4 py-2 text-sm rounded-lg bg-gradient-to-r from-violet-600 to-violet-700 text-white font-medium hover:from-violet-700 hover:to-violet-800 flex items-center gap-1.5 shadow-sm"
              >
                <i class="pi pi-sparkles text-xs" />
                สร้างใบเสนอราคา (AI ช่วย)
              </button>
              <button
                v-if="aiSummaryContext.response.aiSummary!.suggestedReplyTemplate"
                @click="aiSummaryUseSuggestedReply"
                class="px-4 py-2 text-sm rounded-lg bg-violet-600 text-white font-medium hover:bg-violet-700 flex items-center gap-1.5"
              >
                <i class="pi pi-pencil text-xs" />
                ใช้เทมเพลตแนะนำ
              </button>
              <button
                v-if="aiActionsAllDone"
                @click="markThreadResolved(aiSummaryContext.thread, caseFor(aiSummaryContext.thread)!); showAISummary = false"
                class="px-4 py-2 text-sm rounded-lg bg-emerald-600 text-white font-medium hover:bg-emerald-700 flex items-center gap-1.5"
              >
                <i class="pi pi-check text-xs" />
                ปิด thread
              </button>
            </div>
          </footer>
        </div>
      </div>
    </Transition>

    <!-- ─── Quotation Builder modal ────────────────────────────────────── -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="showQuotation"
        class="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-slate-900/50"
      >
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
          <!-- Header -->
          <header
            :class="[
              'px-5 py-4 border-b border-slate-100 flex items-start justify-between shrink-0',
              quotationMode === 'ai_assisted'
                ? 'bg-gradient-to-br from-violet-50 to-white'
                : 'bg-gradient-to-br from-brand-50 to-white',
            ]"
          >
            <div class="flex items-start gap-3">
              <div
                :class="[
                  'w-10 h-10 rounded-lg flex items-center justify-center',
                  quotationMode === 'ai_assisted'
                    ? 'bg-violet-100 text-violet-700'
                    : 'bg-brand-100 text-brand-700',
                ]"
              >
                <i :class="quotationMode === 'ai_assisted' ? 'pi pi-sparkles' : 'pi pi-file-pdf'" />
              </div>
              <div>
                <h3 class="font-semibold text-slate-900 flex items-center gap-2 flex-wrap">
                  สร้างใบเสนอราคา
                  <span
                    v-if="quotationMode === 'ai_assisted'"
                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-violet-100 text-violet-700"
                  >
                    <i class="pi pi-sparkles text-[9px]" />
                    AI ช่วยกรอก
                  </span>
                  <span
                    v-else
                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-200 text-slate-700"
                  >
                    <i class="pi pi-pencil text-[9px]" />
                    กรอกเอง
                  </span>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5" v-if="quotationContext">
                  <template v-if="quotationMode === 'ai_assisted' && quotationContext.thread">
                    จากคำตอบของ {{ quotationContext.thread.carrierCode }} ·
                  </template>
                  เคส
                  <span class="font-mono">{{ quotationContext.caseRef.caseId }}</span>
                  · {{ quotationContext.caseRef.clientName }}
                </p>
              </div>
            </div>
            <button @click="closeQuotationBuilder" class="text-slate-400 hover:text-slate-700">
              <i class="pi pi-times" />
            </button>
          </header>

          <!-- Loading state -->
          <div v-if="quotationLoading" class="flex-1 flex items-center justify-center py-16">
            <div class="text-center">
              <div class="inline-flex items-center gap-2 mb-3 text-brand-700">
                <i class="pi pi-spin pi-spinner text-2xl" />
              </div>
              <p class="text-sm font-medium text-slate-700">DeepSeek กำลังวิเคราะห์คำตอบ...</p>
              <p class="text-xs text-slate-500 mt-1">ดึงข้อมูลทุน เบี้ย เงื่อนไข จากเนื้อหาอีเมล</p>
              <p class="text-[10px] text-slate-400 mt-3 italic" v-if="quotationApi.isMocked">โหมด mock — สลับเป็น real API ได้ที่ useDeepseekApi.ts</p>
            </div>
          </div>

          <!-- Success screen — PDF generated, choose what to do next -->
          <div v-else-if="quotationGenerated" class="overflow-y-auto flex-1 px-5 py-8">
            <div class="max-w-md mx-auto text-center">
              <div class="w-16 h-16 mx-auto rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mb-4">
                <i class="pi pi-check text-2xl" />
              </div>
              <h3 class="text-lg font-semibold text-slate-900">สร้างใบเสนอราคาเรียบร้อย</h3>
              <p class="text-sm text-slate-500 mt-1">ไฟล์ถูกดาวน์โหลดและบันทึกในเอกสารของเคสแล้ว</p>

              <div class="mt-6 border border-slate-200 rounded-lg p-4 bg-slate-50 text-left flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-white border border-slate-200 text-rose-600 flex items-center justify-center shrink-0">
                  <i class="pi pi-file-pdf" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium text-slate-900 truncate">{{ quotationGenerated.fileName }}</div>
                  <div class="text-xs text-slate-500">{{ formatBytes(quotationGenerated.sizeBytes) }} · บันทึกในเอกสารเคส {{ quotationContext?.caseRef.caseId }}</div>
                </div>
              </div>

              <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-3 text-left text-xs text-blue-800 flex items-start gap-2">
                <i class="pi pi-info-circle mt-0.5" />
                <span>
                  ส่งใบเสนอราคาให้ลูกค้า / ตัวแทน / บริษัทประกันได้ทันที — ระบบจะแนบ PDF ให้อัตโนมัติพร้อมพรีฟิลผู้รับ
                </span>
              </div>

              <div class="mt-6 flex flex-col gap-2">
                <button
                  @click="sendQuotationViaEmail"
                  class="w-full px-4 py-3 rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 transition flex items-center justify-center gap-2"
                >
                  <i class="pi pi-send" />
                  ส่งอีเมลพร้อมใบเสนอราคา
                </button>
                <button
                  @click="closeQuotationBuilder"
                  class="w-full px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 transition text-sm"
                >
                  ปิด (จะส่งทีหลัง)
                </button>
              </div>
            </div>
          </div>

          <!-- Review form -->
          <div v-else-if="quotationDraft" class="overflow-y-auto flex-1 px-5 py-5 space-y-5">
            <!-- AI-extracted summary callout (AI mode only) -->
            <div v-if="quotationMode === 'ai_assisted' && quotationDraft.proposal_summary" class="border border-violet-200 bg-violet-50/40 rounded-lg p-3">
              <div class="flex items-center gap-2 mb-1">
                <i class="pi pi-sparkles text-violet-600 text-xs" />
                <span class="text-[10px] font-semibold uppercase tracking-wider text-violet-700">AI สรุปข้อเสนอ</span>
              </div>
              <p class="text-sm text-slate-800">{{ quotationDraft.proposal_summary }}</p>
            </div>

            <!-- Manual mode info callout -->
            <div v-if="quotationMode === 'manual'" class="border border-slate-200 bg-slate-50 rounded-lg p-3 flex items-start gap-2">
              <i class="pi pi-pencil text-slate-500 text-xs mt-0.5" />
              <div class="text-xs text-slate-600">
                <strong class="text-slate-700">โหมดกรอกเอง</strong> — ข้อมูลพื้นฐานของเคสถูกเติมไว้แล้ว
                กรุณากรอกตัวเลข/เงื่อนไขจากใบเสนอราคาที่ได้รับ
                <span class="text-slate-400 italic"> · สามารถปรับเปลี่ยนบริษัทประกันได้ในส่วนรายละเอียดด้านล่าง</span>
              </div>
            </div>

            <!-- Carrier picker (manual mode only — AI mode locks it to the responding carrier) -->
            <section v-if="quotationMode === 'manual'">
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">บริษัทประกันและผลิตภัณฑ์</h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs text-slate-600 mb-1">บริษัทประกัน</label>
                  <select
                    v-model="quotationDraft.carrierCode"
                    @change="onQuotationCarrierChange"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500"
                  >
                    <option v-for="code in Object.keys(carrierDirectory)" :key="code" :value="code">
                      {{ code }} · {{ carrierDirectory[code].name }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">ผลิตภัณฑ์</label>
                  <input
                    v-model="quotationDraft.productName"
                    type="text"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500"
                  />
                </div>
                <div class="md:col-span-2">
                  <label class="block text-xs text-slate-600 mb-1">สรุปข้อเสนอ (ไม่บังคับ)</label>
                  <input
                    v-model="quotationDraft.proposal_summary"
                    type="text"
                    placeholder="เช่น ข้อเสนอประกันชีวิตตลอดชีพ ทุน 2 ล้านบาท"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500"
                  />
                </div>
              </div>
            </section>

            <!-- Header fields -->
            <section>
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">ข้อมูลใบเสนอราคา</h4>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                  <label class="block text-xs text-slate-600 mb-1">เลขที่ใบเสนอราคา</label>
                  <input v-model="quotationDraft.quotationNumber" type="text" class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">วันที่ออก</label>
                  <input v-model="quotationDraft.generatedAt" type="text" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">มีผลถึง</label>
                  <input v-model="quotationDraft.validUntil" type="text" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
              </div>
            </section>

            <!-- Coverage details -->
            <section>
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">รายละเอียดความคุ้มครอง</h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs text-slate-600 mb-1">ทุนประกัน (บาท)</label>
                  <input v-model.number="quotationDraft.coverage_amount" type="number" min="0" step="100000" class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">เบี้ยประกัน (บาท)</label>
                  <input v-model.number="quotationDraft.annual_premium" type="number" min="0" step="1000" class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">งวดการชำระ</label>
                  <select v-model="quotationDraft.premium_mode" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500">
                    <option value="monthly">รายเดือน</option>
                    <option value="quarterly">รายไตรมาส</option>
                    <option value="semiannual">ราย 6 เดือน</option>
                    <option value="annual">รายปี</option>
                    <option value="single">จ่ายครั้งเดียว</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">เลขกรมธรรม์ (ถ้ามี)</label>
                  <input v-model="quotationDraft.policy_number" type="text" class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">ระยะคุ้มครอง (ปี)</label>
                  <input v-model.number="quotationDraft.coverage_period_years" type="number" min="0" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">ระยะชำระเบี้ย (ปี)</label>
                  <input v-model.number="quotationDraft.payment_period_years" type="number" min="0" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">วันคุ้มครองเริ่ม</label>
                  <input v-model="quotationDraft.effective_date_thai" type="text" placeholder="15 มิ.ย. 2569" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">ระยะเวลารอคอย (วัน)</label>
                  <input v-model.number="quotationDraft.waiting_period_days" type="number" min="0" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500" />
                </div>
              </div>
            </section>

            <!-- Conditions / exclusions / required docs (textareas as bulleted lists, one per line) -->
            <section>
              <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">เงื่อนไข & รายละเอียดเพิ่ม</h4>
              <div class="space-y-3">
                <div>
                  <label class="block text-xs text-slate-600 mb-1">เงื่อนไขความคุ้มครอง (บรรทัดละหนึ่งข้อ)</label>
                  <textarea
                    :value="quotationDraft.conditions.join('\n')"
                    @input="quotationDraft.conditions = ($event.target as HTMLTextAreaElement).value.split('\n').filter(Boolean)"
                    rows="3"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 resize-none font-sans"
                  />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">ข้อยกเว้น</label>
                  <textarea
                    :value="quotationDraft.exclusions.join('\n')"
                    @input="quotationDraft.exclusions = ($event.target as HTMLTextAreaElement).value.split('\n').filter(Boolean)"
                    rows="3"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 resize-none font-sans"
                  />
                </div>
                <div v-if="quotationDraft.documents_required.length > 0 || true">
                  <label class="block text-xs text-slate-600 mb-1">เอกสารที่ต้องเตรียม</label>
                  <textarea
                    :value="quotationDraft.documents_required.join('\n')"
                    @input="quotationDraft.documents_required = ($event.target as HTMLTextAreaElement).value.split('\n').filter(Boolean)"
                    rows="2"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 resize-none font-sans"
                  />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">ขั้นถัดไป</label>
                  <textarea
                    v-model="quotationDraft.next_steps"
                    rows="2"
                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 resize-none font-sans"
                  />
                </div>
              </div>
            </section>

            <!-- Output info banner -->
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs text-slate-600 flex items-start gap-2">
              <i class="pi pi-info-circle text-slate-400 mt-0.5" />
              <span>
                เมื่อสร้าง PDF แล้ว ไฟล์จะถูกดาวน์โหลดและบันทึกเข้าเป็นเอกสารของเคส
                <template v-if="quotationMode === 'ai_assisted' && quotationContext?.thread">
                  (และผูกกับ thread ของ {{ quotationContext.thread.carrierCode }})
                </template>
              </span>
            </div>
          </div>

          <!-- Footer — only on the review-form phase -->
          <footer v-if="!quotationGenerated" class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl shrink-0">
            <button @click="closeQuotationBuilder" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
              ยกเลิก
            </button>
            <button
              @click="generateQuotationPdf"
              :disabled="quotationLoading || quotationGenerating || !quotationDraft"
              class="px-5 py-2 text-sm rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5 transition"
            >
              <i :class="quotationGenerating ? 'pi pi-spin pi-spinner' : 'pi pi-file-pdf'" class="text-xs" />
              {{ quotationGenerating ? 'กำลังสร้าง PDF...' : 'สร้างและดาวน์โหลด PDF' }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>

    <!-- ─── Manual status change modal ─────────────────────────────────── -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="showStatusChange && statusChangeTarget"
        class="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-slate-900/50"
        @click.self="showStatusChange = false"
      >
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
          <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
              <h3 class="font-semibold text-slate-900 flex items-center gap-2">
                <i class="pi pi-refresh text-slate-500" />
                เปลี่ยนสถานะเคส
              </h3>
              <p class="text-xs text-slate-500 mt-0.5">
                <span class="font-mono">{{ statusChangeTarget.caseId }}</span> · {{ statusChangeTarget.clientName }}
              </p>
            </div>
            <button @click="showStatusChange = false" class="text-slate-400 hover:text-slate-700">
              <i class="pi pi-times" />
            </button>
          </header>

          <div class="px-5 py-5 space-y-4">
            <div>
              <label class="block text-xs font-medium text-slate-600 mb-1.5">สถานะปัจจุบัน</label>
              <div :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium', caseStatusApi.statusBadgeClass(statusChangeTarget.status)]">
                {{ caseStatusApi.statusLabel(statusChangeTarget.status) }}
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-600 mb-1.5">
                เปลี่ยนเป็น <span class="text-rose-500">*</span>
                <span class="text-slate-400 font-normal italic ml-1">— ✓ คือสถานะที่ระบบแนะนำ</span>
              </label>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                <button
                  v-for="s in ALL_CASE_STATUSES"
                  :key="s"
                  type="button"
                  :disabled="s === statusChangeTarget.status"
                  @click="statusChangeNewStatus = s"
                  :class="[
                    'flex items-center gap-2 px-2.5 py-1.5 rounded-md text-xs font-medium border transition text-left',
                    s === statusChangeTarget.status
                      ? 'border-slate-200 bg-slate-50 text-slate-300 cursor-not-allowed'
                      : statusChangeNewStatus === s
                      ? 'border-brand-500 ring-2 ring-brand-100 ' + caseStatusApi.statusBadgeClass(s)
                      : 'border-slate-200 hover:border-slate-300 ' + caseStatusApi.statusBadgeClass(s).replace('ring-', 'hover:ring-'),
                  ]"
                >
                  <i
                    v-if="caseStatusApi.canTransitionTo(statusChangeTarget.status, s)"
                    class="pi pi-check text-emerald-600 text-[10px] shrink-0"
                    title="ระบบแนะนำ"
                  />
                  <i
                    v-else-if="s !== statusChangeTarget.status"
                    class="pi pi-exclamation-triangle text-amber-500 text-[10px] shrink-0"
                    title="ข้ามขั้นตอน — ต้องระบุเหตุผล"
                  />
                  <span v-else class="w-3 shrink-0" />
                  <span class="flex-1 min-w-0 truncate">{{ caseStatusApi.statusLabel(s) }}</span>
                  <span v-if="s === statusChangeTarget.status" class="text-[9px] uppercase tracking-wider">ปัจจุบัน</span>
                </button>
              </div>
            </div>

            <!-- Irregular-jump warning -->
            <div
              v-if="isIrregularJump"
              class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2"
            >
              <i class="pi pi-exclamation-triangle mt-0.5 text-amber-600" />
              <div>
                <strong>นี่คือการเปลี่ยนสถานะที่ข้ามขั้นตอน</strong>
                <div class="mt-0.5">
                  ปกติแล้ว <strong>{{ caseStatusApi.statusLabel(statusChangeTarget.status) }}</strong>
                  ไม่ควรเปลี่ยนเป็น <strong>{{ caseStatusApi.statusLabel(statusChangeNewStatus) }}</strong>
                  โดยตรง รบกวนระบุเหตุผลอย่างละเอียดเพื่อบันทึก
                </div>
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-600 mb-1.5">
                เหตุผล <span class="text-rose-500">*</span>
                <span class="text-slate-400 font-normal italic">— บันทึกใน audit log</span>
              </label>
              <textarea
                v-model="statusChangeReason"
                rows="3"
                placeholder="เช่น ลูกค้าเปลี่ยนใจกลับมา / ปรับสถานะให้ตรงกับ workflow จริง"
                class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-brand-500 resize-none"
              />
            </div>

            <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-3 py-2 flex items-start gap-2">
              <i class="pi pi-info-circle mt-0.5" />
              <span>การเปลี่ยนสถานะจะถูกบันทึกในประวัติพร้อมเหตุผล + ผู้เปลี่ยน</span>
            </div>
          </div>

          <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
            <button @click="showStatusChange = false" class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white">
              ยกเลิก
            </button>
            <button
              @click="submitStatusChange"
              :disabled="!statusChangeReason.trim() || statusChangeNewStatus === statusChangeTarget.status"
              :class="[
                'px-4 py-2 text-sm rounded-lg text-white font-medium flex items-center gap-1.5 transition disabled:opacity-40 disabled:cursor-not-allowed',
                isIrregularJump ? 'bg-amber-600 hover:bg-amber-700' : 'bg-slate-900 hover:bg-slate-700',
              ]"
            >
              <i class="pi pi-check text-xs" />
              {{ isIrregularJump ? 'ยืนยัน (ข้ามขั้นตอน)' : 'ยืนยันการเปลี่ยน' }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>

    <!-- Custom-schedule date/time modal -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="showCustomScheduleDialog"
        class="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-slate-900/50"
        @click.self="showCustomScheduleDialog = false"
      >
        <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
          <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
              <i class="pi pi-calendar text-blue-600" />
              <h3 class="font-semibold text-slate-900">ตั้งเวลาส่งเอง</h3>
            </div>
            <button @click="showCustomScheduleDialog = false" class="text-slate-400 hover:text-slate-700">
              <i class="pi pi-times" />
            </button>
          </header>
          <div class="px-5 py-4 space-y-3">
            <div>
              <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">วันที่</label>
              <DateInput v-model="customScheduleDate" :min="toIsoDate(new Date())" />
            </div>
            <div>
              <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">เวลา</label>
              <input
                v-model="customScheduleTime"
                type="time"
                class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
              />
            </div>
            <p v-if="customScheduleDate && customScheduleTime && !customScheduleValid" class="text-[10px] text-rose-600">
              เวลาต้องเป็นอนาคต
            </p>
          </div>
          <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
            <button
              type="button"
              @click="showCustomScheduleDialog = false"
              class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white"
            >
              ยกเลิก
            </button>
            <button
              type="button"
              @click="confirmCustomSchedule"
              :disabled="!customScheduleValid"
              class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5"
            >
              <i class="pi pi-clock text-xs" />
              ตั้งเวลา
            </button>
          </footer>
        </div>
      </div>
    </Transition>

    <!-- Template editor modal -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="showTemplateEditor"
        class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/50"
        @click.self="closeTemplateEditor"
      >
        <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[92vh] flex flex-col">
          <header class="px-5 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <div>
              <h3 class="font-semibold text-slate-900 flex items-center gap-2">
                {{ editorIsCreating ? 'เพิ่มเทมเพลตใหม่' : 'แก้ไขเทมเพลต' }}
                <span
                  v-if="editorIsBuiltIn"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 font-normal"
                >
                  เทมเพลตในระบบ — แก้ไขได้ ลบไม่ได้
                </span>
              </h3>
              <p class="text-xs text-slate-500 mt-0.5">
                <span v-pre>ใช้ <code class="font-mono bg-slate-100 px-1 rounded">{{ชื่อตัวแปร}}</code> เพื่อแทรกข้อมูลของเคสในตอนส่ง</span>
              </p>
            </div>
            <button @click="closeTemplateEditor" class="text-slate-400 hover:text-slate-700 shrink-0">
              <i class="pi pi-times" />
            </button>
          </header>

          <div class="overflow-y-auto flex-1 px-5 py-4 space-y-4">
            <!-- Meta -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div class="md:col-span-2">
                <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">ชื่อเทมเพลต *</label>
                <input
                  v-model="editorForm.label"
                  type="text"
                  placeholder="เช่น ขอใบเสนอราคาประกันสุขภาพ"
                  class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                />
              </div>
              <div>
                <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">แผนกปลายทาง</label>
                <select
                  v-model="editorForm.department"
                  class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 bg-white"
                >
                  <option
                    v-for="d in (['new_business','underwriting','policy_issue','claims','other'] as ContactDepartment[])"
                    :key="d"
                    :value="d"
                  >
                    {{ DEPARTMENT_LABELS[d] }}
                  </option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div class="md:col-span-2">
                <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">คำอธิบายสั้น</label>
                <input
                  v-model="editorForm.desc"
                  type="text"
                  placeholder="ตัวอย่าง: ใช้ตอนขอใบเสนอราคาจาก AIA เคสประกันสุขภาพ"
                  class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                />
              </div>
              <div>
                <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">ไอคอน (PrimeIcons)</label>
                <input
                  v-model="editorForm.icon"
                  type="text"
                  placeholder="pi pi-envelope"
                  class="w-full px-3 py-2 text-xs font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                />
              </div>
            </div>

            <!-- Variable palette -->
            <div class="border border-slate-200 rounded-lg p-3 bg-slate-50/40">
              <p class="text-[10px] uppercase tracking-wider font-medium text-slate-500 mb-1.5">
                ตัวแปรที่ใช้ได้ — คลิกเพื่อแทรกในช่อง {{ editorFocusField === 'subject' ? 'หัวข้อ' : 'เนื้อหา' }}
              </p>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="v in TEMPLATE_VARIABLES"
                  :key="v.name"
                  type="button"
                  @click="insertVariable(v.name)"
                  class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-mono bg-white border border-slate-200 rounded hover:border-blue-300 hover:bg-blue-50 transition"
                  :title="v.label"
                >
                  <span class="text-slate-500 font-mono">{{ formatVarToken(v.name) }}</span>
                  <span class="text-slate-400">· {{ v.label }}</span>
                </button>
              </div>
            </div>

            <!-- Subject -->
            <div>
              <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">หัวข้อ *</label>
              <input
                ref="editorSubjectRef"
                v-model="editorForm.subject"
                @focus="editorFocusField = 'subject'"
                type="text"
                class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
              />
              <p v-if="emailCase" class="text-[10px] text-slate-400 mt-1">
                ตัวอย่างหลังแทนค่า: <span class="font-mono text-slate-600">{{ editorPreview.subject }}</span>
              </p>
            </div>

            <!-- Body -->
            <div>
              <label class="block text-[10px] font-medium uppercase tracking-wider text-slate-500 mb-1">เนื้อหา *</label>
              <textarea
                ref="editorBodyRef"
                v-model="editorForm.body"
                @focus="editorFocusField = 'body'"
                rows="14"
                class="w-full px-3 py-2 text-sm font-mono border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 resize-y leading-relaxed"
              />
            </div>

            <!-- Preview -->
            <div v-if="emailCase" class="border border-slate-200 rounded-lg overflow-hidden">
              <div class="px-3 py-2 bg-slate-50 border-b border-slate-100 flex items-center gap-2">
                <i class="pi pi-eye text-slate-400 text-xs" />
                <span class="text-[10px] uppercase tracking-wider font-medium text-slate-500">
                  ตัวอย่างเมื่อแทนค่าจากเคส {{ emailCase.caseId }}
                </span>
              </div>
              <div class="px-3 py-2 text-xs whitespace-pre-wrap leading-relaxed text-slate-700 max-h-48 overflow-y-auto bg-white">{{ editorPreview.body }}</div>
            </div>
          </div>

          <footer class="px-5 py-4 border-t border-slate-100 flex items-center justify-between gap-2 bg-slate-50/50 rounded-b-xl shrink-0">
            <button
              v-if="!editorIsCreating && !editorIsBuiltIn"
              type="button"
              @click="confirmDeleteTemplate(editorTargetId!); closeTemplateEditor()"
              class="px-4 py-2 text-sm rounded-lg border border-rose-300 text-rose-700 hover:bg-rose-50 transition flex items-center gap-1.5"
            >
              <i class="pi pi-trash text-xs" />
              ลบเทมเพลตนี้
            </button>
            <span v-else />
            <div class="flex items-center gap-2">
              <button
                type="button"
                @click="closeTemplateEditor"
                class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white"
              >
                ยกเลิก
              </button>
              <button
                type="button"
                @click="saveTemplate"
                :disabled="!editorValid"
                class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5"
              >
                <i class="pi pi-check text-xs" />
                {{ editorIsCreating ? 'สร้างเทมเพลต' : 'บันทึก' }}
              </button>
            </div>
          </footer>
        </div>
      </div>
    </Transition>

    <!-- Delete confirmation modal -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
    >
      <div
        v-if="deleteTemplateId"
        class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/50"
        @click.self="deleteTemplateId = null"
      >
        <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
          <div class="px-5 py-5">
            <div class="w-10 h-10 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mb-3">
              <i class="pi pi-trash" />
            </div>
            <h3 class="font-semibold text-slate-900">ลบเทมเพลตนี้?</h3>
            <p class="text-sm text-slate-500 mt-1.5">
              ลบเทมเพลต <strong class="text-slate-900">{{ templatesStore.findById(deleteTemplateId)?.label }}</strong>
              ออกจากระบบ การกระทำนี้ย้อนกลับไม่ได้
            </p>
          </div>
          <footer class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2 bg-slate-50/50 rounded-b-xl">
            <button
              type="button"
              @click="deleteTemplateId = null"
              class="px-4 py-2 text-sm rounded-lg border border-slate-300 text-slate-700 hover:bg-white"
            >
              ยกเลิก
            </button>
            <button
              type="button"
              @click="performDeleteTemplate"
              class="px-4 py-2 text-sm rounded-lg bg-rose-600 text-white font-medium hover:bg-rose-700"
            >
              ลบ
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </div>
</template>
