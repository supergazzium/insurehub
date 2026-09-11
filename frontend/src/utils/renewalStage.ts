// Centralized renewal-pipeline stage rendering. The stage is derived
// server-side (ReportController::renewalStage) from append-only policy
// events; this file is the single source for its Thai label, badge color,
// pipeline order, and the "next action" a user should take. Both the
// expiring-policies table and the (future) board view read from here so the
// stage vocabulary stays in one place.
//
// Stage order mirrors the backend ladder:
//   not_started → contacted → quote_requested → quote_received
//   → quote_prepared → quote_sent → renewed
// with two off-track terminals: declined, expired.

import type { RenewalStage } from '../api/reports'

export interface RenewalStageMeta {
  /** Thai label shown in the status pill. */
  label: string
  /** Tailwind badge classes (bg + text). */
  badgeClass: string
  /** PrimeIcons class for a small leading icon (without the `pi ` prefix). */
  icon: string
  /** Position on the pipeline (0-based). Terminals off-track share -1. */
  order: number
}

/** The seven on-track stages in pipeline order, for the board view + steppers. */
export const RENEWAL_PIPELINE: RenewalStage[] = [
  'not_started',
  'contacted',
  'quote_requested',
  'quote_received',
  'quote_prepared',
  'quote_sent',
  'renewed',
]

const META: Record<RenewalStage, RenewalStageMeta> = {
  not_started: { label: 'ยังไม่ดำเนินการ', badgeClass: 'bg-slate-100 text-slate-600', icon: 'pi-circle', order: 0 },
  contacted: { label: 'ติดต่อลูกค้าแล้ว', badgeClass: 'bg-sky-50 text-sky-700', icon: 'pi-phone', order: 1 },
  quote_requested: { label: 'ขอใบเสนอราคาแล้ว', badgeClass: 'bg-indigo-50 text-indigo-700', icon: 'pi-send', order: 2 },
  quote_received: { label: 'ได้รับใบเสนอราคา', badgeClass: 'bg-violet-50 text-violet-700', icon: 'pi-inbox', order: 3 },
  quote_prepared: { label: 'จัดทำใบเสนอราคาแล้ว', badgeClass: 'bg-amber-50 text-amber-700', icon: 'pi-file-edit', order: 4 },
  quote_sent: { label: 'ส่งให้ลูกค้าแล้ว', badgeClass: 'bg-teal-50 text-teal-700', icon: 'pi-envelope', order: 5 },
  renewed: { label: 'ต่ออายุแล้ว', badgeClass: 'bg-emerald-50 text-emerald-700', icon: 'pi-check-circle', order: 6 },
  declined: { label: 'ลูกค้าปฏิเสธ', badgeClass: 'bg-rose-50 text-rose-700', icon: 'pi-times-circle', order: -1 },
  expired: { label: 'หมดอายุ (ค้าง)', badgeClass: 'bg-rose-100 text-rose-800', icon: 'pi-exclamation-triangle', order: -1 },
}

const FALLBACK: RenewalStageMeta = META.not_started

/** Meta for a stage; falls back to `not_started` for unknown/missing values. */
export function renewalStageMeta(stage: RenewalStage | null | undefined): RenewalStageMeta {
  return (stage && META[stage]) || FALLBACK
}

/** The next action a user can take from this stage, as a label + a token the
 *  page maps to an action handler. Terminal stages return null. */
export interface RenewalNextAction {
  action: 'contact' | 'request_quote' | 'upload_quote' | 'prepare_quote' | 'send_quote' | 'start_renewal'
  label: string
  icon: string
}

const NEXT_ACTION: Partial<Record<RenewalStage, RenewalNextAction>> = {
  not_started: { action: 'request_quote', label: 'ขอใบเสนอราคา', icon: 'pi-send' },
  contacted: { action: 'request_quote', label: 'ขอใบเสนอราคา', icon: 'pi-send' },
  quote_requested: { action: 'upload_quote', label: 'อัปโหลดใบเสนอราคา', icon: 'pi-upload' },
  quote_received: { action: 'prepare_quote', label: 'สร้างใบเสนอราคา InsureHub', icon: 'pi-file-edit' },
  quote_prepared: { action: 'send_quote', label: 'ส่งให้ลูกค้า', icon: 'pi-envelope' },
  quote_sent: { action: 'start_renewal', label: 'ต่ออายุ', icon: 'pi-arrow-right' },
}

/** The recommended next action for a stage, or null if terminal. */
export function renewalNextAction(stage: RenewalStage | null | undefined): RenewalNextAction | null {
  return (stage && NEXT_ACTION[stage]) || null
}
