// Client-side CSV export — no backend, no lib. UTF-8 BOM so Excel-th opens
// Thai characters correctly. Used by all Phase 8a reports.

type Cell = string | number | boolean | null | undefined
export interface CsvColumn<T> {
  header: string
  value: (row: T) => Cell
}

function esc(v: Cell): string {
  if (v === null || v === undefined) return ''
  const s = String(v)
  if (s.includes(',') || s.includes('"') || s.includes('\n')) {
    return '"' + s.replace(/"/g, '""') + '"'
  }
  return s
}

/** Build a CSV string from rows + column definitions. */
export function toCsv<T>(rows: T[], columns: CsvColumn<T>[]): string {
  const header = columns.map((c) => esc(c.header)).join(',')
  const body = rows
    .map((r) => columns.map((c) => esc(c.value(r))).join(','))
    .join('\n')
  return header + '\n' + body
}

/** Trigger a browser download of the given CSV text. Prepends UTF-8 BOM. */
export function downloadCsv(csv: string, filename: string): void {
  const bom = '﻿'
  const blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
