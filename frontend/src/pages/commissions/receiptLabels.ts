import type { AdjustmentKind, DiffReason, ReceivableStatus } from '../../api/commissionReceipts'

export const STATUS_LABEL: Record<ReceivableStatus, string> = {
  Pending: 'รอตรวจ',
  Matched: 'ยอดตรง',
  Mismatch: 'ยอดไม่ตรง',
  Received: 'รับแล้ว',
  'No Commission': 'ไม่มีค่าคอม',
}

export const STATUS_BADGE: Record<ReceivableStatus, string> = {
  Pending: 'bg-slate-100 text-slate-600',
  Matched: 'bg-emerald-50 text-emerald-700',
  Mismatch: 'bg-rose-50 text-rose-700',
  Received: 'bg-emerald-100 text-emerald-800',
  'No Commission': 'bg-slate-200 text-slate-600',
}

export const STATUSES: ReceivableStatus[] = ['Pending', 'Matched', 'Mismatch', 'Received', 'No Commission']

export const REASON_OPTIONS: { value: DiffReason; label: string }[] = [
  { value: 'rate', label: 'อัตราค่าคอมผิด' },
  { value: 'endorsement', label: 'เบี้ยสลักหลัง' },
  { value: 'cancellation', label: 'กรมธรรม์ยกเลิก' },
  { value: 'no_policy_commission', label: 'ยังไม่มียอดค่าคอมในกรมธรรม์' },
  { value: 'other', label: 'อื่นๆ (ระบุ)' },
]

export const KIND_OPTIONS: { value: AdjustmentKind; label: string }[] = [
  { value: 'endorsement', label: 'สลักหลัง' },
  { value: 'cancellation', label: 'ยกเลิก / บริษัทหักคืน' },
  { value: 'other', label: 'อื่นๆ' },
]

export const HINT_STYLE: Record<string, string> = {
  cancellation: 'bg-rose-50 text-rose-700 ring-rose-200',
  endorsement: 'bg-violet-50 text-violet-700 ring-violet-200',
  no_policy_commission: 'bg-amber-50 text-amber-700 ring-amber-200',
  rate: 'bg-sky-50 text-sky-700 ring-sky-200',
}

export function money(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function signedMoney(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return (n > 0 ? '+' : n < 0 ? '−' : '') + money(Math.abs(n))
}

export function differs(a: number | null | undefined, b: number | null | undefined): boolean {
  if (a === null || a === undefined || b === null || b === undefined) return false
  return Math.abs(a - b) > 0.01
}

export function thisMonth(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

export function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function parseAmount(raw: string): number | null {
  let s = raw.trim().replace(/[฿,\s]/g, '')
  if (s === '') return null
  let negative = false
  if (/^\(.*\)$/.test(s)) {
    negative = true
    s = s.slice(1, -1)
  }
  if (s.endsWith('-')) {
    negative = true
    s = s.slice(0, -1)
  }
  if (!/^-?\d+(\.\d+)?$/.test(s)) return null
  const n = Number(s)
  return Number.isFinite(n) ? (negative ? -Math.abs(n) : n) : null
}
