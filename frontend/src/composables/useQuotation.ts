/**
 * useQuotation — quotation data model + AI extraction + PDF generation.
 *
 * Combines DeepSeek (for extracting structured offer data from a carrier
 * response email) with jsPDF (for rendering the quotation PDF).
 */

import { useDeepseekApi, type ChatMessage } from './useDeepseekApi'

export interface QuotationExtraction {
  proposal_summary: string
  is_quotation_ready: boolean
  policy_number: string | null
  coverage_amount: number
  annual_premium: number
  premium_mode: 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single'
  coverage_period_years: number
  payment_period_years: number
  effective_date_thai: string | null
  waiting_period_days: number
  riders: string[]
  conditions: string[]
  exclusions: string[]
  documents_required: string[]
  next_steps: string
}

export interface QuotationContext {
  caseId: string
  carrierName: string
  carrierCode: string
  clientName: string
  clientIdCard?: string
  clientAge?: number
  clientOccupation?: string
  productName: string
}

export interface Quotation extends QuotationContext, QuotationExtraction {
  quotationNumber: string
  generatedAt: string
  agentName: string
  agencyName: string
  agencyPhone: string
  agencyEmail: string
  validUntil: string
}

// ── Build the AI prompt ────────────────────────────────────────────────────

function buildExtractionPrompt(responseText: string, ctx: QuotationContext): ChatMessage[] {
  return [
    {
      role: 'system',
      content: `คุณเป็นผู้ช่วยตัวแทนประกันภัยที่เชี่ยวชาญในการสรุปคำตอบจากบริษัทประกันและจัดทำใบเสนอราคา ส่งกลับเป็น JSON เท่านั้น

โครงสร้าง JSON ที่ต้องการ:
{
  "proposal_summary": "string — สรุป 1 ประโยคของข้อเสนอ",
  "is_quotation_ready": boolean — ใบสมัครพร้อมออกใบเสนอราคาหรือไม่,
  "policy_number": "string | null — เลขกรมธรรม์ถ้ามี",
  "coverage_amount": number — ทุนประกัน (บาท),
  "annual_premium": number — เบี้ยรายปี (บาท),
  "premium_mode": "monthly|quarterly|semiannual|annual|single",
  "coverage_period_years": number,
  "payment_period_years": number,
  "effective_date_thai": "string | null — วันคุ้มครอง พ.ศ.",
  "waiting_period_days": number,
  "riders": ["string"],
  "conditions": ["string"],
  "exclusions": ["string"],
  "documents_required": ["string"],
  "next_steps": "string"
}`,
    },
    {
      role: 'user',
      content: `กรณีศึกษา:
- เคส: ${ctx.caseId}
- ลูกค้า: ${ctx.clientName}${ctx.clientIdCard ? ` (บัตร ${ctx.clientIdCard})` : ''}${ctx.clientAge ? ` · อายุ ${ctx.clientAge} ปี` : ''}${ctx.clientOccupation ? ` · อาชีพ ${ctx.clientOccupation}` : ''}
- บริษัทประกัน: ${ctx.carrierName} (${ctx.carrierCode})
- ผลิตภัณฑ์: ${ctx.productName}

คำตอบจากบริษัทประกัน:
"""
${responseText}
"""

ช่วยสรุปข้อมูลเพื่อสร้างใบเสนอราคา ตอบกลับเป็น JSON ตามโครงสร้างที่กำหนด`,
    },
  ]
}

export function useQuotation() {
  const deepseek = useDeepseekApi()

  /**
   * Send the carrier response to DeepSeek and return extracted offer data.
   * In production this hits the real API; in dev it's the mock.
   */
  async function extractQuotationFromResponse(
    responseText: string,
    ctx: QuotationContext,
  ): Promise<QuotationExtraction> {
    const res = await deepseek.chatCompletion({
      model: 'deepseek-chat',
      messages: buildExtractionPrompt(responseText, ctx),
      response_format: { type: 'json_object' },
      temperature: 0.1, // we want structure-faithful, not creative
    })

    const content = res.choices[0]?.message.content ?? '{}'
    try {
      const parsed = JSON.parse(content)
      return parsed as QuotationExtraction
    } catch {
      // If the model returned non-JSON, fall back to sane defaults
      return {
        proposal_summary: 'ไม่สามารถสรุปข้อเสนอจากคำตอบของบริษัทประกันได้',
        is_quotation_ready: false,
        policy_number: null,
        coverage_amount: 0,
        annual_premium: 0,
        premium_mode: 'annual',
        coverage_period_years: 0,
        payment_period_years: 0,
        effective_date_thai: null,
        waiting_period_days: 0,
        riders: [],
        conditions: [],
        exclusions: [],
        documents_required: [],
        next_steps: 'ทบทวนคำตอบของบริษัทประกันด้วยตนเอง',
      }
    }
  }

  function makeQuotationNumber(caseId: string): string {
    const yy = new Date().getFullYear() + 543 // BE year
    const seq = Date.now().toString(36).slice(-5).toUpperCase()
    return `QT-${yy}-${caseId.replace(/[^0-9]/g, '').slice(-4) || '0000'}-${seq}`
  }

  function defaultValidUntil(): string {
    // 30 days from now, in BE
    const d = new Date()
    d.setDate(d.getDate() + 30)
    const day = d.getDate()
    const month = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][d.getMonth()]
    return `${day} ${month} ${d.getFullYear() + 543}`
  }

  return {
    extractQuotationFromResponse,
    makeQuotationNumber,
    defaultValidUntil,
    isMocked: deepseek.config.isMocked,
  }
}
