// Shared date parsing + formatting helpers.
//
// Wire model — everything the backend stores or returns is ISO `YYYY-MM-DD`
// (Gregorian). Display everywhere in the UI is DD/MM/YYYY. These helpers
// are the single conversion boundary so no page has to reinvent the
// parsing rules.

/** ISO `YYYY-MM-DD` → DD/MM/YYYY. Returns '' for null/undefined/empty. */
export function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  // Tolerate datetime values ("2026-08-04 12:34:56" or ISO with T) by
  // taking only the date portion.
  const dateOnly = iso.slice(0, 10)
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateOnly)
  if (!m) return iso
  return `${m[3]}/${m[2]}/${m[1]}`
}

/** Convert a JS Date into ISO `YYYY-MM-DD` in the user's local TZ. */
export function toIsoDate(d: Date | null | undefined): string {
  if (!d) return ''
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

/** Parse an ISO `YYYY-MM-DD` string into a Date at local midnight. */
export function fromIsoDate(iso: string | null | undefined): Date | null {
  if (!iso) return null
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  if (!m) return null
  return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
}
