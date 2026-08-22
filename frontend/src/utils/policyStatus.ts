// Centralized policy-status rendering. Every list/drawer/wizard reads
// labels + badge colors through this file so the enum and the state
// machine stay in sync with backend B1-state-machine.md.
//
// Legacy codes (`quote`, `application`, `reinstated`) survive here for
// the shim window — a row that missed C-2 backfill still renders a
// stable label + color. C-20 removes them.

import type { PolicyStatus } from '../stores/policies'

// Re-exported so consumers can pull the type + helpers from one module.
export type { PolicyStatus }

/** Backend-canonical group name, mirrors policy_statuses.group_name_th
 *  after C-1. Used for grouping in filter dropdowns. */
export type PolicyStatusGroup =
  | 'Pre-quote'
  | 'Pre-application'
  | 'Pending'
  | 'Post-underwriting'
  | 'Post-issue'
  | 'In-force'
  | 'Ended'

interface StatusEntry {
  code: PolicyStatus
  group: PolicyStatusGroup
  /** Tailwind classes for the badge chip. */
  badgeClass: string
  /** Legacy? — hidden from default filter dropdowns; still rendered when
   *  a row carries the code (shim-era data). */
  legacy?: true
}

const STATUS_TABLE: Record<PolicyStatus, StatusEntry> = {
  draft: { code: 'draft', group: 'Pre-quote', badgeClass: 'bg-slate-100 text-slate-600' },
  quotation: { code: 'quotation', group: 'Pre-application', badgeClass: 'bg-slate-100 text-slate-700' },
  submitted: { code: 'submitted', group: 'Pending', badgeClass: 'bg-amber-50 text-amber-700' },
  approved: { code: 'approved', group: 'Post-underwriting', badgeClass: 'bg-brand-50 text-brand-700' },
  issued: { code: 'issued', group: 'Post-issue', badgeClass: 'bg-sky-50 text-sky-700' },
  active: { code: 'active', group: 'In-force', badgeClass: 'bg-emerald-50 text-emerald-700' },
  expired: { code: 'expired', group: 'Ended', badgeClass: 'bg-slate-100 text-slate-500' },
  cancelled: { code: 'cancelled', group: 'Ended', badgeClass: 'bg-slate-100 text-slate-500' },
  rejected: { code: 'rejected', group: 'Ended', badgeClass: 'bg-rose-50 text-rose-700' },
  lapsed: { code: 'lapsed', group: 'Ended', badgeClass: 'bg-rose-50 text-rose-700' },
  // Legacy — rows that missed C-2 backfill or partial-deploy state.
  quote: { code: 'quote', group: 'Pre-application', badgeClass: 'bg-slate-100 text-slate-700', legacy: true },
  application: { code: 'application', group: 'Pending', badgeClass: 'bg-amber-50 text-amber-700', legacy: true },
  reinstated: { code: 'reinstated', group: 'In-force', badgeClass: 'bg-emerald-50 text-emerald-700', legacy: true },
}

/** Ordered list of the current (non-legacy) 10 codes for filter
 *  dropdowns. Legacy codes still render when present but aren't
 *  offered as filter options. */
export const CURRENT_STATUSES: PolicyStatus[] = [
  'draft', 'quotation', 'submitted', 'approved',
  'issued', 'active', 'expired',
  'cancelled', 'rejected', 'lapsed',
]

/** True if `s` is a terminal state — no further transitions allowed. */
export function isTerminal(s: PolicyStatus): boolean {
  return s === 'expired' || s === 'cancelled' || s === 'rejected' || s === 'lapsed'
}

/** Tailwind badge classes for a status. Unknown/null → slate default. */
export function statusBadgeClass(s: PolicyStatus | null | undefined): string {
  if (!s) return 'bg-slate-100 text-slate-500'
  return STATUS_TABLE[s]?.badgeClass ?? 'bg-slate-100 text-slate-500'
}

/** Display group for filter dropdowns / analytics. */
export function statusGroup(s: PolicyStatus): PolicyStatusGroup | null {
  return STATUS_TABLE[s]?.group ?? null
}

/** Verbs the backend transition matrix allows from a given state. Mirrors
 *  PolicyEventController::TRANSITIONS. Used to gate wizard/drawer action
 *  buttons so we don't offer the user something the server will 409. */
export type PolicyTransitionVerb =
  | 'draftCreated'
  | 'quotationMinted'
  | 'submittedFromDraft'
  | 'convertedToApplication'
  | 'submittedToCarrier'
  | 'underwritingApproved'
  | 'underwritingRejected'
  | 'issued'
  | 'activated'
  | 'expired'
  | 'cancelled'
  | 'lapsed'
  | 'renewed'
  | 'detailsUpdated'

const TRANSITION_MATRIX: Record<PolicyTransitionVerb, PolicyStatus[]> = {
  // source-agnostic — fire on create or as audit stamp; empty list = no
  // "from" constraint. Not offered by allowedNextFromStatus().
  draftCreated: [],
  detailsUpdated: [],
  // active transitions
  quotationMinted: ['draft'],
  submittedFromDraft: ['draft'],
  convertedToApplication: ['quotation', 'quote'],
  submittedToCarrier: ['quotation', 'draft', 'quote'],
  underwritingApproved: ['submitted', 'application'],
  underwritingRejected: ['submitted', 'application'],
  issued: ['approved'],
  activated: ['issued'],
  expired: ['active'],
  cancelled: ['draft', 'quotation', 'submitted', 'approved', 'issued', 'active', 'quote', 'application'],
  lapsed: ['active'],
  renewed: ['active', 'expired', 'issued', 'reinstated'],
}

/** Verbs the operator can legally invoke from a given source status.
 *  Source-agnostic verbs (draftCreated, detailsUpdated) are excluded. */
export function allowedNextFromStatus(status: PolicyStatus): PolicyTransitionVerb[] {
  const out: PolicyTransitionVerb[] = []
  for (const [verb, from] of Object.entries(TRANSITION_MATRIX) as [PolicyTransitionVerb, PolicyStatus[]][]) {
    if (from.length > 0 && from.includes(status)) out.push(verb)
  }
  return out
}

/** True if `verb` is a legal transition from `status`. */
export function canTransition(status: PolicyStatus, verb: PolicyTransitionVerb): boolean {
  const from = TRANSITION_MATRIX[verb]
  return from.length === 0 || from.includes(status)
}

/**
 * i18n-driven label resolver. Callers should prefer the pre-computed
 * `statusLabel` field on API responses (fed by policy_statuses.name_th)
 * because it matches what the DB says. This helper is the fallback for
 * places that don't have a joined label (create-modal form state,
 * client-side dropdowns).
 *
 * Uses vue-i18n's translation function passed in from the calling
 * component so this file stays framework-agnostic.
 */
export function statusLabel(
  s: PolicyStatus | null | undefined,
  t: (key: string) => string,
): string {
  if (!s) return '—'
  return t(`policies.status.${s}`)
}
