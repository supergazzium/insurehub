import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { ContactDepartment } from './carrierContacts'
import { api, buildQuery, type Paginated, type Single } from '../api/client'

export interface EmailTemplate {
  /** Stable id. Built-ins keep their seed keys (used by status-based auto-pick). */
  id: string
  label: string
  desc: string
  icon: string
  department: ContactDepartment
  subject: string
  body: string
  /** Built-in templates can be edited but not deleted. User-created can be both. */
  isBuiltIn: boolean
}

/**
 * Variables available inside subject/body. The composer resolves `{{name}}`
 * placeholders against the active case + carrier directory.
 */
export interface TemplateVariableSpec {
  name: string
  label: string
}

export const TEMPLATE_VARIABLES: TemplateVariableSpec[] = [
  { name: 'caseId', label: 'เลขเคส' },
  { name: 'clientName', label: 'ชื่อลูกค้า' },
  { name: 'agentName', label: 'ตัวแทน' },
  { name: 'agentCode', label: 'รหัสตัวแทน' },
  { name: 'carrierName', label: 'ชื่อบริษัทประกัน' },
  { name: 'carrierCode', label: 'รหัสบริษัท' },
  { name: 'productName', label: 'ผลิตภัณฑ์' },
  { name: 'premium', label: 'เบี้ยรายปี (฿)' },
  { name: 'status', label: 'สถานะปัจจุบัน' },
  { name: 'stuckHours', label: 'ค้างกี่ ชม.' },
  { name: 'rejectionReason', label: 'เหตุผลตีกลับ' },
  { name: 'lastUpdatedBE', label: 'อัปเดตล่าสุด (พ.ศ.)' },
]

/**
 * Render a string template by replacing `{{varName}}` placeholders. Unknown
 * tokens are left untouched so users can spot typos.
 */
export function renderTemplate(template: string, vars: Record<string, string>): string {
  return template.replace(/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/g, (full, name: string) => {
    return Object.prototype.hasOwnProperty.call(vars, name) ? vars[name] : full
  })
}

export const useEmailTemplatesStore = defineStore('emailTemplates', () => {
  const templates = ref<EmailTemplate[]>([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)

  function findById(id: string): EmailTemplate | undefined {
    return templates.value.find((t) => t.id === id)
  }

  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const response = await api.get<Paginated<EmailTemplate>>(
        `email-templates${buildQuery({ perPage: 100 })}`,
      )
      templates.value = response.data
      loaded.value = true
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load templates.'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function addTemplate(
    payload: Omit<EmailTemplate, 'id' | 'isBuiltIn'>,
  ): Promise<EmailTemplate> {
    const response = await api.post<Single<EmailTemplate>>('email-templates', payload)
    const created = response.data
    templates.value = [...templates.value, created]
    return created
  }

  async function updateTemplate(
    id: string,
    patch: Partial<Omit<EmailTemplate, 'id' | 'isBuiltIn'>>,
  ): Promise<void> {
    const response = await api.patch<Single<EmailTemplate>>(`email-templates/${id}`, patch)
    const updated = response.data
    templates.value = templates.value.map((t) => (t.id === id ? updated : t))
  }

  /**
   * Returns false (without an HTTP round-trip) for built-in templates, matching
   * the legacy contract. For user templates, returns true after a successful
   * delete. Throws on server errors so the UI can surface them.
   */
  async function removeTemplate(id: string): Promise<boolean> {
    const t = findById(id)
    if (!t || t.isBuiltIn) return false
    await api.delete(`email-templates/${id}`)
    templates.value = templates.value.filter((x) => x.id !== id)
    return true
  }

  return {
    templates,
    loading,
    loaded,
    error,
    findById,
    load,
    addTemplate,
    updateTemplate,
    removeTemplate,
  }
})
