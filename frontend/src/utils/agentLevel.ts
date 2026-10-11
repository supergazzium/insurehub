export const AGENT_LEVELS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as const

const BADGE: Record<string, string> = {
  l1: 'bg-slate-100 text-slate-600', l2: 'bg-sky-50 text-sky-700',
  l3: 'bg-violet-50 text-violet-700', l4: 'bg-amber-50 text-amber-700',
  l5: 'bg-rose-50 text-rose-700', l6: 'bg-orange-50 text-orange-700',
  l7: 'bg-emerald-50 text-emerald-700', l8: 'bg-teal-50 text-teal-700',
  l9: 'bg-indigo-50 text-indigo-700', l10: 'bg-fuchsia-50 text-fuchsia-700',
}

const DOT: Record<string, string> = {
  l1: 'bg-slate-400', l2: 'bg-sky-400', l3: 'bg-violet-400', l4: 'bg-amber-400', l5: 'bg-rose-400',
  l6: 'bg-orange-400', l7: 'bg-emerald-400', l8: 'bg-teal-400', l9: 'bg-indigo-400', l10: 'bg-fuchsia-500',
}

export function levelKey(level: number | string | null | undefined): string {
  if (level === null || level === undefined || level === '') return ''
  return typeof level === 'number' ? `l${level}` : level.toLowerCase()
}

export function levelBadgeClass(level: number | string | null | undefined): string {
  return BADGE[levelKey(level)] ?? 'bg-slate-100 text-slate-600'
}

export function levelDotClass(level: number | string | null | undefined): string {
  return DOT[levelKey(level)] ?? 'bg-slate-300'
}

export function levelShort(level: number | string | null | undefined): string {
  const key = levelKey(level)
  return key ? key.toUpperCase() : '—'
}

export const PROMOTION_STATUS: Record<string, { label: string; cls: string }> = {
  pending: { label: 'รออนุมัติ', cls: 'bg-amber-50 text-amber-700' },
  approved: { label: 'อนุมัติแล้ว', cls: 'bg-emerald-50 text-emerald-700' },
  rejected: { label: 'ไม่อนุมัติ', cls: 'bg-rose-50 text-rose-700' },
  superseded: { label: 'ถูกแทนที่', cls: 'bg-slate-100 text-slate-500' },
}

export function promotionTriggerLabel(trigger: string, requestedByName?: string | null): string {
  if (trigger === 'manual') return requestedByName ? `ขอปรับโดย ${requestedByName}` : 'ขอปรับระดับ'
  return 'ระบบเสนอจากยอด'
}
