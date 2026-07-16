// Typed client for /api/v1/import-failures/* endpoints.
// Populated by the `php artisan insurehub:import` command from Access
// applications that couldn't resolve to a client/agent/product/carrier.

import { api, buildQuery } from './client'
import type { ApiEnvelope, ApiList } from './reports'

export type ImportFailureReason =
  | 'missing_client'
  | 'missing_agent'
  | 'missing_product'
  | 'missing_company'
  | 'other'

export interface ImportFailure {
  id: string
  applicationCode: string
  reason: ImportFailureReason
  detail: string | null
  /** Full source row (staging) — used by the triage UI to show what's missing. */
  raw: Record<string, string | null> | null
  importedAt: string | null
  resolved: boolean
  resolutionNotes: string | null
  createdAt: string | null
  updatedAt: string | null
}

export interface ImportFailureSummaryRow {
  reason: ImportFailureReason
  resolved: boolean
  count: number
}

export interface ImportFailureFilters {
  reason?: ImportFailureReason | ''
  resolved?: boolean
  q?: string
  page?: number
  perPage?: number
}

export function fetchImportFailures(filters: ImportFailureFilters = {}) {
  return api.get<ApiList<ImportFailure>>(
    `import-failures${buildQuery({ ...filters })}`,
  )
}

export function fetchImportFailuresSummary() {
  return api.get<ApiList<ImportFailureSummaryRow>>('import-failures/summary')
}

export function resolveImportFailure(id: string, notes: string | null) {
  return api.patch<ApiEnvelope<{ id: string; resolved: boolean }>>(
    `import-failures/${id}/resolve`,
    { resolutionNotes: notes },
  )
}
