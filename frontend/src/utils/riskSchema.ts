// C-13 — TypeScript mirror of the risk_schema JSON shape (B4 §1).
// Consumed by RiskFieldRenderer.vue + the wizard payload builder.
//
// Schemas live server-side on product_types.risk_schema (populated by
// C-10 seeder + optional admin edits via C-9). The wizard hydrates one
// per fetched product via the ProductResource.productType.riskSchema
// pass-through (C-3).

export type RiskFieldType =
  | 'string'
  | 'text'
  | 'number'
  | 'date'
  | 'select'
  | 'boolean'
  | 'passport'
  | 'phone'
  | 'array_of_objects'

export interface RiskFieldOption {
  value: string
  label_th: string
  label_en: string
}

export interface RiskFieldValidation {
  min?: number
  max?: number
  pattern?: string
  /** Named client-side validator (e.g. thai_id_checksum, thai_license_plate).
   *  Not implemented yet — the wizard's Submit gate does the shape check
   *  via `pattern` for now. Placeholder for future validator registry. */
  validator?: string
}

export interface RiskFieldDependsOn {
  field: string
  operator: 'gte' | 'lte' | 'eq'
}

export interface RiskField {
  key: string
  label_th: string
  label_en: string
  type: RiskFieldType
  required?: boolean
  /** `column` → writer emits to a top-level policies column
   *  (motor_license_no etc). `risk_data` (default) → writer emits under
   *  policies.risk_data.<section>.<key>. */
  storage?: 'column' | 'risk_data'
  column_name?: string
  placeholder?: string
  help_th?: string
  help_en?: string
  default?: unknown
  options?: RiskFieldOption[]
  validation?: RiskFieldValidation
  depends_on?: RiskFieldDependsOn
  prior_autofill?: boolean
  // array_of_objects only
  min_rows?: number
  max_rows?: number
  row_validation?: { sum_of?: string; equals?: number; empty_ok?: boolean }
  fields?: RiskField[]
}

export interface RiskSection {
  key: string
  label_th: string
  label_en: string
  fields: RiskField[]
  dedupe_keys?: string[]
}

export interface RiskSchema {
  version: number
  kind: string
  sections: RiskSection[]
}

/** Build the writer payload for the whole schema value tree. Splits into
 *  `columns` (flat top-level) and `riskData` (nested by section/field key
 *  under the schema kind). Matches PolicyRiskShim::writerDualWrite on the
 *  backend so PolicyRequest.risk = { kind, data: <riskData[kind]> } +
 *  top-level column keys go together. */
export function splitSchemaPayload(
  schema: RiskSchema,
  values: Record<string, unknown>,
): { columns: Record<string, unknown>; riskData: Record<string, unknown> } {
  const columns: Record<string, unknown> = {}
  const riskData: Record<string, unknown> = {}
  for (const section of schema.sections) {
    const sectionData: Record<string, unknown> = {}
    for (const field of section.fields) {
      const v = values[valueKey(section.key, field.key)]
      if (v === undefined || v === '' || v === null) continue
      if (field.storage === 'column' && field.column_name) {
        columns[field.column_name] = v
      } else {
        sectionData[field.key] = v
      }
    }
    if (Object.keys(sectionData).length > 0) {
      // Flatten single-section-per-kind into the risk_data root — matches
      // PolicyRiskShim's writer contract where risk_data.<kind> is the
      // whole payload, not nested by section. Multi-section schemas
      // (health/life) collapse all sections into the same kind bucket.
      Object.assign(riskData, sectionData)
    }
  }
  return { columns, riskData }
}

/** Composite key for the renderer's internal value bag. Keeps the value
 *  map flat while preserving section grouping so two sections with the
 *  same field key (e.g. `name` in life's insured_person + beneficiaries)
 *  don't collide. */
export function valueKey(sectionKey: string, fieldKey: string): string {
  return `${sectionKey}.${fieldKey}`
}

/** Hydrate the renderer's value bag from a saved risk_data + top-level
 *  column blob. Used when the wizard opens on a resumed draft. */
export function hydrateSchemaValues(
  schema: RiskSchema,
  riskData: Record<string, unknown> | null,
  columnValues: Record<string, unknown>,
): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const section of schema.sections) {
    for (const field of section.fields) {
      const k = valueKey(section.key, field.key)
      if (field.storage === 'column' && field.column_name && columnValues[field.column_name] !== undefined) {
        out[k] = columnValues[field.column_name]
        continue
      }
      if (riskData && field.key in riskData) {
        out[k] = riskData[field.key]
      }
    }
  }
  return out
}

/** Collect (fieldKey, message) pairs for every field that violates its
 *  schema declaration. Called at the wizard's Submit boundary — Draft
 *  saves ignore required, Quotation saves apply Q-gate, Submit applies
 *  full validation. */
export function validateSchemaValues(
  schema: RiskSchema,
  values: Record<string, unknown>,
  level: 'draft' | 'quotation' | 'submit' = 'submit',
): { key: string; message: string }[] {
  const problems: { key: string; message: string }[] = []
  if (level === 'draft') return problems

  for (const section of schema.sections) {
    for (const field of section.fields) {
      const k = valueKey(section.key, field.key)
      const v = values[k]
      const filled = v !== undefined && v !== '' && v !== null

      if (level === 'submit' && field.required && !filled) {
        problems.push({ key: k, message: `${field.label_th} ต้องระบุ` })
        continue
      }
      if (!filled) continue

      const val = field.validation
      if (!val) continue
      if (typeof v === 'string') {
        if (val.min !== undefined && v.length < val.min) problems.push({ key: k, message: `${field.label_th} สั้นเกินไป` })
        if (val.max !== undefined && v.length > val.max) problems.push({ key: k, message: `${field.label_th} ยาวเกินไป` })
        if (val.pattern) {
          try {
            if (!new RegExp(val.pattern).test(v)) problems.push({ key: k, message: `${field.label_th} ไม่ตรงรูปแบบ` })
          } catch { /* ignore malformed patterns — admin edit issue */ }
        }
      }
      if (typeof v === 'number') {
        if (val.min !== undefined && v < val.min) problems.push({ key: k, message: `${field.label_th} ต่ำกว่าค่าต่ำสุด` })
        if (val.max !== undefined && v > val.max) problems.push({ key: k, message: `${field.label_th} เกินค่าสูงสุด` })
      }
    }
  }
  return problems
}
