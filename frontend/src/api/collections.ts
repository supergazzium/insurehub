// การติดตามเงิน (payment collections / dunning) API client.
import { api, buildQuery } from './client'

export type CollectionKind = 'cash' | 'installment' | 'split'

export interface ScheduleLine {
  no: number
  amount: number
  paid: boolean
  dueDate: string | null
  overdue: boolean
}

export interface CollectionRow {
  policyId: string
  policyNo: string | null
  applicationNo: string | null
  status: string
  kind: CollectionKind
  customerCode: string | null
  customerName: string
  customerPhone: string | null
  agentCode: string | null
  agentName: string
  totalDue: number
  paid: number
  outstanding: number
  installmentMode: string | null
  installmentCount: number | null
  paidCount: number
  overdueCount: number
  schedule: ScheduleLine[] | null
  reminderCount: number
  lastReminderAt: string | null
  effectiveDate: string | null
}

export function fetchCollections(params: { kind?: CollectionKind | 'all'; q?: string } = {}) {
  return api.get<{ data: CollectionRow[]; meta: { count: number } }>(
    `collections${buildQuery(params as Record<string, unknown>)}`,
  )
}

export interface ReminderRow {
  id: string
  installmentNo: number | null
  channel: string
  note: string | null
  amountDue: number | null
  createdAt: string | null
}

export function fetchReminders(policyId: string) {
  return api.get<{ data: ReminderRow[] }>(`policies/${policyId}/reminders`)
}

export interface ReminderInput {
  installmentNo?: number | null
  channel?: string
  note?: string
  amountDue?: number | null
}

export function createReminder(policyId: string, payload: ReminderInput) {
  return api.post<{ data: { id: string } }>(`policies/${policyId}/reminders`, payload)
}
