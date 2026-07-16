// Typed clients for /api/v1/admin/agents/* — admin approval + oversight.
import { api } from './client'
import type { MyAgent } from './portal'

export interface AuditEntry {
  id: string
  occurredAt: string | null
  actor: string
  action: string
  result: string
  ip: string | null
  metadata: Record<string, unknown> | null
}

export interface DownlineNode {
  id: string
  parentAgentId: string | null
  agentCode: string
  firstName: string
  lastName: string
  email: string
  phone: string
  lineId: string
  joinedAt: string | null
  approvalStatus: 'pending' | 'approved' | 'rejected'
  level: number
}

export function fetchPendingAgents() {
  return api.get<{ data: MyAgent[] }>('admin/agents/pending')
}

export function approveAgent(agentId: string) {
  return api.post<{ message: string; data: MyAgent }>(`admin/agents/${agentId}/approve`)
}

export function rejectAgent(agentId: string, note: string) {
  return api.post<{ message: string; data: MyAgent }>(`admin/agents/${agentId}/reject`, { note })
}

export function fetchAgentAudit(agentId: string) {
  return api.get<{ data: AuditEntry[] }>(`admin/agents/${agentId}/audit`)
}

export function fetchDownlineTree(agentId: string) {
  return api.get<{ data: DownlineNode[] }>(`admin/agents/${agentId}/downline-tree`)
}
