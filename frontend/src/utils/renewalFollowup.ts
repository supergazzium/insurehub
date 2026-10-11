import type { FollowupChannel, FollowupResult } from '../api/reports'

export const FOLLOWUP_RESULTS: FollowupResult[] = ['unreachable', 'interested', 'thinking', 'not_renewing']

export const FOLLOWUP_CHANNELS: FollowupChannel[] = ['phone', 'line', 'email', 'inperson', 'other']

const RESULT_META: Record<FollowupResult, { label: string; badgeClass: string; icon: string }> = {
  unreachable: { label: 'ติดต่อไม่ได้', badgeClass: 'bg-slate-100 text-slate-600', icon: 'pi-phone' },
  interested: { label: 'สนใจต่ออายุ', badgeClass: 'bg-emerald-50 text-emerald-700', icon: 'pi-thumbs-up' },
  thinking: { label: 'ขอคิดก่อน', badgeClass: 'bg-amber-50 text-amber-700', icon: 'pi-clock' },
  not_renewing: { label: 'ไม่ต่ออายุ', badgeClass: 'bg-rose-50 text-rose-700', icon: 'pi-times-circle' },
}

const CHANNEL_META: Record<FollowupChannel, { label: string; icon: string }> = {
  phone: { label: 'โทรศัพท์', icon: 'pi-phone' },
  line: { label: 'LINE', icon: 'pi-comments' },
  email: { label: 'อีเมล', icon: 'pi-envelope' },
  inperson: { label: 'พบตัว', icon: 'pi-user' },
  other: { label: 'อื่นๆ', icon: 'pi-ellipsis-h' },
}

export function followupResultMeta(result: string | null | undefined) {
  return RESULT_META[(result ?? 'thinking') as FollowupResult] ?? RESULT_META.thinking
}

export function followupChannelMeta(channel: string | null | undefined) {
  return CHANNEL_META[(channel ?? 'other') as FollowupChannel] ?? CHANNEL_META.other
}

export function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function addDaysIso(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function isFollowupOverdue(nextAt: string | null | undefined): boolean {
  return !!nextAt && nextAt.slice(0, 10) <= todayIso()
}
