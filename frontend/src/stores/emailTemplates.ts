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

function uid(): string {
  return 'tpl-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6)
}

/**
 * Seed = the 6 built-ins formerly hard-coded in AgentSupport.vue. Their keys
 * stay the same string ids because other code (status-based auto-pick) maps
 * status → template id directly.
 */
function seedTemplates(): EmailTemplate[] {
  return [
    {
      id: 'follow_up_pending',
      label: 'ติดตามสถานะ (Pending Carrier)',
      desc: 'สำหรับเคสที่ค้างใน Pending Carrier เกิน 24 ชม.',
      icon: 'pi pi-clock',
      department: 'new_business',
      subject: 'ติดตามสถานะใบสมัคร {{caseId}} — {{clientName}}',
      body: `เรียน ฝ่ายรับประกัน {{carrierName}},

ขอติดตามสถานะใบสมัครต่อไปนี้ที่ได้ส่งไปเมื่อ {{lastUpdatedBE}} แต่ยังไม่ได้รับการตอบกลับ:

  • เลขเคส: {{caseId}}
  • ผู้เอาประกัน: {{clientName}}
  • ผลิตภัณฑ์: {{productName}}
  • เบี้ยรายปี: ฿{{premium}}
  • ตัวแทนผู้ขาย: {{agentName}}

ขณะนี้เคสค้างในสถานะ "{{status}}" เป็นเวลา {{stuckHours}} ชั่วโมง รบกวนช่วยตรวจสอบและแจ้งความคืบหน้ากลับมาทาง email นี้

ขอบคุณสำหรับความช่วยเหลือ`,
      isBuiltIn: true,
    },
    {
      id: 'resubmit_corrections',
      label: 'ส่งเอกสารแก้ไข (Action Required)',
      desc: 'สำหรับเคสที่ถูกตีกลับเพื่อแก้ไขข้อมูล',
      icon: 'pi pi-refresh',
      department: 'new_business',
      subject: 'ส่งเอกสารแก้ไข {{caseId}} — {{clientName}}',
      body: `เรียน ฝ่ายรับประกัน {{carrierName}},

ตามที่ได้รับแจ้งเหตุผลการตีกลับใบสมัครดังนี้:

  "{{rejectionReason}}"

ทางทีมได้ดำเนินการแก้ไข/รวบรวมเอกสารใหม่เรียบร้อยแล้ว ขอนำส่งใบสมัครพร้อมเอกสารแนบตามรายละเอียดต่อไปนี้:

  • เลขเคส: {{caseId}}
  • ผู้เอาประกัน: {{clientName}}
  • ผลิตภัณฑ์: {{productName}}
  • ตัวแทนผู้ขาย: {{agentName}}

รบกวนช่วยตรวจสอบและดำเนินการพิจารณาต่อ ขอบคุณครับ/ค่ะ`,
      isBuiltIn: true,
    },
    {
      id: 'underwriting_inquiry',
      label: 'สอบถามผลพิจารณา (Underwriting)',
      desc: 'สอบถามความคืบหน้าจากฝ่ายพิจารณารับประกัน',
      icon: 'pi pi-search',
      department: 'underwriting',
      subject: 'สอบถามผลพิจารณารับประกัน {{caseId}} — {{clientName}}',
      body: `เรียน Underwriter {{carrierName}},

ขอสอบถามความคืบหน้าการพิจารณารับประกันของเคสต่อไปนี้:

  • เลขเคส: {{caseId}}
  • ผู้เอาประกัน: {{clientName}}
  • ผลิตภัณฑ์: {{productName}}
  • ทุนประกัน: ฿{{premium}} (เบี้ยรายปี)
  • อยู่ในขั้นตอน Underwriting เป็นเวลา {{stuckHours}} ชั่วโมง

หากต้องการเอกสารเพิ่มเติมหรือมีข้อสงสัยใดๆ รบกวนแจ้งกลับมาทาง email นี้ เพื่อทางเราจะได้ดำเนินการให้รวดเร็วยิ่งขึ้น

ขอบคุณครับ/ค่ะ`,
      isBuiltIn: true,
    },
    {
      id: 'confirm_approval',
      label: 'ยืนยันการอนุมัติ (Ready to Issue)',
      desc: 'ขอเลขกรมธรรม์หลังจากอนุมัติแล้ว',
      icon: 'pi pi-check-circle',
      department: 'new_business',
      subject: 'ขอเลขกรมธรรม์ {{caseId}} — {{clientName}}',
      body: `เรียน ฝ่ายออกกรมธรรม์ {{carrierName}},

ตามที่ได้รับแจ้งว่าใบสมัครต่อไปนี้ผ่านการพิจารณาแล้ว (สถานะ Ready to Issue):

  • เลขเคส: {{caseId}}
  • ผู้เอาประกัน: {{clientName}}
  • ผลิตภัณฑ์: {{productName}}
  • เบี้ยรายปี: ฿{{premium}}
  • ตัวแทนผู้ขาย: {{agentName}}

รบกวนช่วยดำเนินการออกเลขกรมธรรม์และส่งกลับมาทาง email นี้ เพื่อทางทีมจะได้แจ้งลูกค้าและบันทึกในระบบ

ขอบคุณครับ/ค่ะ`,
      isBuiltIn: true,
    },
    {
      id: 'send_quotation',
      label: 'ส่งใบเสนอราคา (Quotation)',
      desc: 'แนบใบเสนอราคา PDF และเรียนถึงลูกค้า / ตัวแทน',
      icon: 'pi pi-file-pdf',
      department: 'new_business',
      subject: 'ใบเสนอราคา {{caseId}} — {{clientName}}',
      body: `เรียน ท่านผู้รับ,

ตามที่ได้มีการพิจารณาและจัดเตรียมข้อเสนอประกันให้กับ {{clientName}} เคส {{caseId}} แล้วนั้น

ขอแนบไฟล์ใบเสนอราคา (PDF) มาเพื่อพิจารณา รายละเอียดของข้อเสนอประกอบด้วย:

  • ผู้เอาประกัน: {{clientName}}
  • ผลิตภัณฑ์: {{productName}}
  • บริษัทประกัน: {{carrierName}}
  • ตัวแทนผู้ขาย: {{agentName}}

หากมีคำถามหรือต้องการความชัดเจนเพิ่มเติม รบกวนติดต่อกลับมาที่อีเมลนี้

ขอบคุณครับ/ค่ะ`,
      isBuiltIn: true,
    },
    {
      id: 'request_quotation_car',
      label: 'ขอข้อเสนอราคา CAR (งานก่อสร้าง)',
      desc: 'ขอใบเสนอราคาประกัน CAR / Contractor All Risk จากบริษัทประกันวินาศภัย',
      icon: 'pi pi-file-edit',
      department: 'new_business',
      subject: '[{{carrierCode}}] ขอข้อเสนอราคาประกัน CAR ผู้เอาประกันภัย {{clientName}} // Insurehub-{{caseId}}',
      body: `เรียนเจ้าหน้าที่

ขอข้อเสนอราคาประกัน CAR งานซ่อมหลังคาคอนโด มาเมซอง ทำงานแบบโรยตัว
- ผู้เอาประกันภัย {{clientName}}
- สถานที่ก่อสร้าง นิติบุคคลอาคารชุด มาเมซอง ที่อยู่ 359 ลาดพร้าว 94 แขวงพลับพลา เขตวังทองหลาง กทม 10310
- ระยะเวลาก่อสร้าง 150 วัน ทำงานตั้งแต่ชั้น 22 ลงมา

มูลค่างานก่อสร้าง 1,042,367.25 บาท


สอบถามเพิ่มเติม ติดคุณอ๊อด / โทร. 0617079696

ตามเอกสารแนบค่ะ



ขอขอบพระคุณค่ะ
--
Sirinapa (Praew/แพรว)
InsureHub Support Team
โบรกเกอร์ประกันชีวิตและวินาศภัย
Tel: 0617079696`,
      isBuiltIn: true,
    },
    {
      id: 'request_quotation_iar',
      label: 'จองงาน + ขอราคาประกัน IAR (โรงงาน/ทรัพย์สิน)',
      desc: 'ขอใบเสนอราคาประกัน IAR / Industrial All Risks สำหรับโรงงาน หรือทรัพย์สิน',
      icon: 'pi pi-building',
      department: 'new_business',
      subject: '{{carrierCode}} จองงาน + ขอราคาประกัน IAR // {{clientName}} // {{caseId}}',
      body: `เรียนเจ้าหน้าที่

จองงาน+ขอข้อเสนอประกัน IAR

ชื่อบริษัท : {{clientName}} (0735548001608)
ที่ตั้ง 55 หมู่ 8 ถนนพุทธมณฑลสาย 5 ต.บางกระทึก อ.สามพราน จ.นครปฐม 73210

ประกันหมด 25/8/2026

ลักษณะธุรกิจ: (โรงงานผลิตกาว)

ผู้นำด้านการผลิตภัณฑ์กาวคุณภาพมากกว่า 48 ปี
มีภาพลักษณ์ที่่ดี และได้รับการยอมรับในตลาด
พร้อมพัฒนาสูตรกาวหลากหลายชนิดอย่างต่อเนื่อง

รายละเอียดทรัพย์สินเอาประกันภัย
ทรัพย์ 1: อาคารสำนักงาน
-สิ่งปลูกสร้าง+เครื่องใช้ไฟฟ้า อุปกรณ์สำนักงาน 25,000,000 บาท
-โซล่าร์รูฟท็อป 5,000,000 บาท
-สต๊อกสินค้าวัตถุดิบ 30,000,000 บาท
รวม 60,000,000 บาท

ทรัพย์ 2: โรงงานผลิต
-สิ่งปลูกสร้างอาคารโรงงานผลิต + ระบบไฟฟ้า + ตู้ไฟฟ้า + หม้อแปลงไฟฟ้า 15,000,000 บาท
-เครื่องจักร เครื่องมือใช้ในการประกอบกิจการ 13,000,000 บาท
-สต๊อกสินค้า 12,000,000 บาท
รวม 40,000,000 บาท

รวมทุนทั้งหมด 100,000,000 บาท

loss claim ไม่มีเคลม , ไม่มีความเสียหาย

**รบกวนอ้างอิงทุนตามที่พิมให้เนื่องจากมีเพิ่ม โซล่าร์รูฟท็อป เข้าไปเพิ่ม ตามกรมธรรม์เดิมจะไม่มี**

รบกวนขอข้อเสนอที่แข่งขั้นได้ค่ะ

https://maps.app.goo.gl/RiQ1hiu9NqgfNyoRA?g_st=al



ขอขอบพระคุณค่ะ
--
Sirinapa (Praew/แพรว)
InsureHub Support Team
โบรกเกอร์ประกันชีวิตและวินาศภัย
Tel: 062-702-6662
Mailto: operation@insurehub.co.th

พี่เต้`,
      isBuiltIn: true,
    },
    {
      id: 'request_quotation_motor',
      label: 'ขอราคาประกันรถยนต์ ชั้น 1 / 2+',
      desc: 'ขอใบเสนอราคาประกันรถยนต์ภาคสมัครใจ พร้อมเลขทะเบียน / เลขตัวรถ',
      icon: 'pi pi-car',
      department: 'new_business',
      subject: '{{carrierCode}} ขอราคาประกันชั้น 1 , 2+ BYD ATTO 3 เลขทะเบียน 4ขฎ972 ปี 2023 // {{caseId}}',
      body: `เรียนเจ้าหน้าที่

ขอราคาประกันชั้น 1 ซ่อมห้าง และ ชั้น 2+

BYD ATTO 3
เลขทะเบียน 4ขฎ972
ปี 2023
เลขตัวรถ LGXCE4CB3P2050430

รายการจดทะเบียนตามแนบค่ะ


ขอขอบพระคุณค่ะ
--
Sirinapa (Praew/แพรว)
InsureHub Support Team
โบรกเกอร์ประกันชีวิตและวินาศภัย
Tel: 062-702-6662
Mailto: operation@insurehub.co.th


{{agentCode}} >> รหัสตัวแทนที่มาส่งงาน`,
      isBuiltIn: true,
    },
    {
      id: 'custom',
      label: 'เขียนเอง (Custom)',
      desc: 'เริ่มจากแบบเปล่า เขียนเองตั้งแต่ต้น',
      icon: 'pi pi-pencil',
      department: 'new_business',
      subject: '{{caseId}} — {{clientName}}',
      body: `เรียน {{carrierName}},

`,
      isBuiltIn: true,
    },
  ]
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
