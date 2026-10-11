// Shared helpers for the role-specific dashboards (#3).
// Keep formatting + the "data not wired yet" marker in one place so every
// role dashboard looks consistent and placeholders are obvious.

/** Thai-locale compact currency: ฿1.69M / ฿12.0K / ฿840. */
export function formatBaht(n: number | null | undefined): string {
  const v = Number(n) || 0
  if (Math.abs(v) >= 1_000_000) return `฿${(v / 1_000_000).toFixed(2)}M`
  if (Math.abs(v) >= 1_000) return `฿${(v / 1_000).toFixed(1)}K`
  return `฿${Math.round(v).toLocaleString()}`
}

/** Plain integer with thousands separators, em-dash when null. */
export function formatInt(n: number | null | undefined): string {
  return n == null ? '—' : Number(n).toLocaleString('th-TH')
}

export type Tone = 'sky' | 'emerald' | 'violet' | 'amber' | 'rose' | 'slate' | 'indigo'

export const TONE_CLASSES: Record<Tone, string> = {
  sky: 'bg-sky-50 text-sky-700',
  emerald: 'bg-emerald-50 text-emerald-700',
  violet: 'bg-violet-50 text-violet-700',
  amber: 'bg-amber-50 text-amber-700',
  rose: 'bg-rose-50 text-rose-700',
  slate: 'bg-slate-100 text-slate-600',
  indigo: 'bg-indigo-50 text-indigo-700',
}
