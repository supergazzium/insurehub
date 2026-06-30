/**
 * useCaseStatus — case status state machine.
 *
 * Pure functions. No side effects. Owns all the rules around:
 *  - Which transitions are legal (canTransitionTo)
 *  - What the AI's response implies (nextStatusFromAISummary)
 *  - How to record a transition with audit metadata (recordTransition)
 *
 * In production, these same rules port directly to Laravel (PHP) so the
 * server can enforce them on every API mutation.
 */

export type CaseStatus =
  | 'Draft'
  | 'Pending Carrier'
  | 'Underwriting'
  | 'Action Required'
  | 'Ready to Issue'
  | 'Quote Sent'      // PDF sent to client, waiting for client decision
  | 'Quote Accepted'  // client agreed, ready to submit application
  | 'Rejected'        // carrier declined permanently, no path forward
  | 'Withdrawn'       // client backed out of the case

export type AISentiment = 'positive' | 'neutral' | 'needs_info' | 'rejecting'

export interface StatusTransition {
  id: string
  from: CaseStatus
  to: CaseStatus
  reason: string
  byUser: string
  at: string // 'YYYY-MM-DD HH:MM'
  source: 'manual' | 'ai_suggestion' | 'auto_send' | 'auto_lifecycle'
}

// ──────────────────────────────────────────────────────────────────────────
// Legal transitions — used by both the UI dropdown and server validation
// ──────────────────────────────────────────────────────────────────────────

const TRANSITIONS: Record<CaseStatus, CaseStatus[]> = {
  'Draft': ['Pending Carrier', 'Quote Sent', 'Withdrawn'],
  'Quote Sent': ['Quote Accepted', 'Draft', 'Withdrawn'],
  'Quote Accepted': ['Pending Carrier', 'Withdrawn'],
  'Pending Carrier': ['Underwriting', 'Action Required', 'Ready to Issue', 'Rejected', 'Withdrawn'],
  'Underwriting': ['Action Required', 'Ready to Issue', 'Rejected', 'Withdrawn'],
  'Action Required': ['Pending Carrier', 'Underwriting', 'Rejected', 'Withdrawn'],
  'Ready to Issue': [], // terminal in Support — case moves to /policies module
  'Rejected': ['Draft'], // can re-open with a fresh draft (e.g. different carrier)
  'Withdrawn': ['Draft'], // can be reactivated if client returns
}

export function canTransitionTo(from: CaseStatus, to: CaseStatus): boolean {
  if (from === to) return false
  return TRANSITIONS[from]?.includes(to) ?? false
}

export function allowedTransitions(from: CaseStatus): CaseStatus[] {
  return TRANSITIONS[from] ?? []
}

// ──────────────────────────────────────────────────────────────────────────
// AI-driven inference — what does a carrier's reply mean for status?
// ──────────────────────────────────────────────────────────────────────────

export function nextStatusFromAISummary(
  current: CaseStatus,
  sentiment: AISentiment,
): CaseStatus | null {
  // Map sentiment → preferred target status
  const target: Record<AISentiment, CaseStatus> = {
    positive: 'Ready to Issue',
    needs_info: 'Action Required',
    neutral: 'Underwriting',
    rejecting: 'Rejected',
  }
  const desired = target[sentiment]
  if (desired === current) return null      // no change needed
  if (!canTransitionTo(current, desired)) return null // illegal jump
  return desired
}

// ──────────────────────────────────────────────────────────────────────────
// Time-based helpers
// ──────────────────────────────────────────────────────────────────────────

/** Compute hours since `lastUpdated` (ISO string). Floor to nearest hour. */
export function computeStuckHours(lastUpdatedIso: string): number {
  const t = new Date(lastUpdatedIso).getTime()
  if (isNaN(t)) return 0
  const diffMs = Date.now() - t
  return Math.max(0, Math.floor(diffMs / 3_600_000))
}

/** Should this status be considered "stuck and needing follow-up"? */
export function isStuckStatus(s: CaseStatus): boolean {
  return s === 'Pending Carrier' || s === 'Underwriting' || s === 'Quote Sent'
}

// ──────────────────────────────────────────────────────────────────────────
// Transition recorder
// ──────────────────────────────────────────────────────────────────────────

let _txnCounter = 0
function nextId(): string {
  _txnCounter++
  return 'st-' + Date.now().toString(36) + '-' + _txnCounter
}

function nowStamp(): string {
  return new Date().toISOString().slice(0, 16).replace('T', ' ')
}

export function buildTransition(
  from: CaseStatus,
  to: CaseStatus,
  reason: string,
  byUser: string,
  source: StatusTransition['source'],
): StatusTransition {
  return {
    id: nextId(),
    from,
    to,
    reason,
    byUser,
    at: nowStamp(),
    source,
  }
}

// ──────────────────────────────────────────────────────────────────────────
// Display helpers
// ──────────────────────────────────────────────────────────────────────────

export function statusLabel(s: CaseStatus): string {
  return {
    'Draft': 'ฉบับร่าง',
    'Pending Carrier': 'รอบริษัทประกัน',
    'Underwriting': 'กำลังพิจารณา',
    'Action Required': 'ต้องดำเนินการ',
    'Ready to Issue': 'พร้อมออกกรมธรรม์',
    'Quote Sent': 'ส่งใบเสนอราคาแล้ว',
    'Quote Accepted': 'ลูกค้าตกลงรับ',
    'Rejected': 'ปฏิเสธ',
    'Withdrawn': 'ลูกค้าถอนตัว',
  }[s]
}

export function statusBadgeClass(s: CaseStatus): string {
  return {
    'Draft':            'bg-slate-100 text-slate-600 ring-slate-200',
    'Pending Carrier':  'bg-blue-50 text-blue-700 ring-blue-200',
    'Underwriting':     'bg-purple-50 text-purple-700 ring-purple-200',
    'Action Required':  'bg-rose-50 text-rose-700 ring-rose-200',
    'Ready to Issue':   'bg-emerald-50 text-emerald-700 ring-emerald-200',
    'Quote Sent':       'bg-amber-50 text-amber-700 ring-amber-200',
    'Quote Accepted':   'bg-teal-50 text-teal-700 ring-teal-200',
    'Rejected':         'bg-rose-50 text-rose-700 ring-rose-200',
    'Withdrawn':        'bg-slate-100 text-slate-500 ring-slate-200',
  }[s]
}

export function useCaseStatus() {
  return {
    canTransitionTo,
    allowedTransitions,
    nextStatusFromAISummary,
    computeStuckHours,
    isStuckStatus,
    buildTransition,
    statusLabel,
    statusBadgeClass,
  }
}
