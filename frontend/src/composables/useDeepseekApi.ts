/**
 * useDeepseekApi — frontend client for DeepSeek's chat-completions API.
 *
 * Right now this is MOCKED. Swap to the real API by replacing the body of
 * `chatCompletion()` below with a fetch call. The interface and request/
 * response shape mirror DeepSeek's public spec:
 *
 *   POST https://api.deepseek.com/v1/chat/completions
 *   Authorization: Bearer ${DEEPSEEK_API_KEY}
 *   Content-Type: application/json
 *   { model, messages, response_format?, temperature?, max_tokens? }
 *
 * For structured JSON output (used for quotation extraction), pass:
 *   response_format: { type: 'json_object' }
 * The model will return content as a JSON string that you parse.
 *
 * Docs: https://api-docs.deepseek.com
 */

export type DeepseekModel = 'deepseek-chat' | 'deepseek-reasoner'

export interface ChatMessage {
  role: 'system' | 'user' | 'assistant'
  content: string
}

export interface ChatCompletionRequest {
  model: DeepseekModel
  messages: ChatMessage[]
  temperature?: number
  max_tokens?: number
  response_format?: { type: 'json_object' | 'text' }
}

export interface ChatCompletionResponse {
  id: string
  model: string
  choices: {
    index: number
    message: ChatMessage
    finish_reason: 'stop' | 'length'
  }[]
  usage: {
    prompt_tokens: number
    completion_tokens: number
    total_tokens: number
  }
}

const MOCK_LATENCY_MS = 1200 // simulate realistic API call

// ─── Mock implementation ──────────────────────────────────────────────────
// This generates deterministic but realistic JSON for quotation-extraction
// prompts based on keyword matching against the carrier response body.
// Replace the body with `fetch()` to switch to the real API.

async function chatCompletion(req: ChatCompletionRequest): Promise<ChatCompletionResponse> {
  await new Promise((r) => setTimeout(r, MOCK_LATENCY_MS))

  // For real implementation, paste this in:
  //
  //   const apiKey = import.meta.env.VITE_DEEPSEEK_API_KEY
  //   const res = await fetch('https://api.deepseek.com/v1/chat/completions', {
  //     method: 'POST',
  //     headers: {
  //       'Authorization': `Bearer ${apiKey}`,
  //       'Content-Type': 'application/json',
  //     },
  //     body: JSON.stringify(req),
  //   })
  //   if (!res.ok) throw new Error(`DeepSeek error: ${res.status}`)
  //   return res.json()

  // Pull the user message (the last one is what we react to)
  const userMsg = [...req.messages].reverse().find((m) => m.role === 'user')?.content ?? ''
  const wantJson = req.response_format?.type === 'json_object'

  const content = wantJson ? buildMockJsonResponse(userMsg) : buildMockTextResponse(userMsg)

  return {
    id: 'mock-' + Date.now(),
    model: req.model,
    choices: [{
      index: 0,
      message: { role: 'assistant', content },
      finish_reason: 'stop',
    }],
    usage: {
      prompt_tokens: estimateTokens(req.messages.map((m) => m.content).join(' ')),
      completion_tokens: estimateTokens(content),
      total_tokens: estimateTokens(req.messages.map((m) => m.content).join(' ') + content),
    },
  }
}

function estimateTokens(text: string): number {
  return Math.ceil(text.length / 4)
}

function buildMockTextResponse(_userMsg: string): string {
  return 'ขอบคุณสำหรับข้อมูล ระบบ AI พร้อมให้ความช่วยเหลือ'
}

// Detect quotation-extraction prompts and return structured JSON.
function buildMockJsonResponse(userMsg: string): string {
  const text = userMsg.toLowerCase()

  // Try to detect a policy number, premium, coverage, dates inside the user message
  // (which contains the carrier response body the caller passed in).
  const polMatch = userMsg.match(/POL-[A-Z]+-\d+-\d+/i)
  const policyNumber = polMatch ? polMatch[0] : null

  // Detect baht amounts ("฿48,000" / "48,000 บาท")
  const premiums: number[] = []
  const amountRegex = /(?:฿|บาท)?\s*([\d,]+)(?:\s*บาท)?/g
  let m: RegExpExecArray | null
  while ((m = amountRegex.exec(userMsg)) !== null) {
    const val = parseInt(m[1].replace(/,/g, ''), 10)
    if (val >= 1000 && val <= 100_000_000) premiums.push(val)
  }
  // First reasonable premium, biggest as coverage
  premiums.sort((a, b) => a - b)
  const premium = premiums.find((p) => p <= 500_000) ?? 48_000
  const coverage = premiums.find((p) => p >= 500_000) ?? 2_000_000

  // Detect approval vs needs-info
  const isApproved =
    /อนุมัติ|approved|ออกกรมธรรม์|ผ่าน/.test(text) ||
    /ready to issue|pass/.test(text)
  const needsInfo = /ขอเอกสาร|missing|need|require/.test(text)

  // Detect effective date (Thai format e.g. "15 มิ.ย. 2569")
  const dateMatch = userMsg.match(/(\d{1,2})\s*(ม\.ค\.|ก\.พ\.|มี\.ค\.|เม\.ย\.|พ\.ค\.|มิ\.ย\.|ก\.ค\.|ส\.ค\.|ก\.ย\.|ต\.ค\.|พ\.ย\.|ธ\.ค\.)\s*(\d{4})/i)
  const effectiveDate = dateMatch ? `${dateMatch[1]} ${dateMatch[2]} ${dateMatch[3]}` : null

  // Extract conditions / exclusions / required docs
  const conditions: string[] = []
  if (/ตรวจสุขภาพ|medical/.test(text)) conditions.push('ผ่านการตรวจสุขภาพภายใน 30 วันก่อนคุ้มครอง')
  if (/ลายเซ็น|signature/.test(text)) conditions.push('ลายเซ็นในใบสมัครต้องตรงกับบัตรประชาชน')
  if (/รายได้|income/.test(text)) conditions.push('แสดงหลักฐานรายได้ 3 เดือนล่าสุด')
  if (/วันทำการ|business days|working days/.test(text)) {
    const days = userMsg.match(/(\d+)\s*(?:วันทำการ|business days|working days)/i)
    if (days) conditions.push(`บริษัทประกันใช้เวลาดำเนินการ ${days[1]} วันทำการ`)
  }
  if (!conditions.length && isApproved) {
    conditions.push('คุ้มครองตั้งแต่วันที่ระบุในกรมธรรม์ ตามเงื่อนไขมาตรฐาน')
  }

  const data = {
    proposal_summary: isApproved
      ? `บริษัทประกันอนุมัติเงื่อนไขใบสมัครเรียบร้อย พร้อมออกกรมธรรม์`
      : needsInfo
        ? `ใบสมัครอยู่ระหว่างการพิจารณา — ต้องส่งเอกสารเพิ่ม`
        : `บริษัทประกันแจ้งข้อมูลเงื่อนไขเพิ่มเติม`,
    is_quotation_ready: isApproved,
    policy_number: policyNumber,
    coverage_amount: coverage,
    annual_premium: premium,
    premium_mode: 'annual',
    coverage_period_years: 99,
    payment_period_years: 20,
    effective_date_thai: effectiveDate,
    waiting_period_days: /โรคร้ายแรง|critical/.test(text) ? 90 : /สุขภาพ|health/.test(text) ? 30 : 0,
    riders: [] as string[],
    conditions,
    exclusions: [
      'ไม่คุ้มครองโรคที่เป็นมาก่อนทำประกัน หากไม่ได้แจ้งในใบสมัคร',
      'ไม่คุ้มครองการเสียชีวิตจากการฆ่าตัวตายภายใน 2 ปีแรก',
    ],
    documents_required: needsInfo ? extractDocsRequired(text) : [],
    next_steps: isApproved
      ? 'แจ้งลูกค้าเตรียมชำระเบี้ยงวดแรก และบันทึกเลขกรมธรรม์ในระบบ'
      : 'ส่งเอกสารตามรายการ และตามผลภายใน 7 วันทำการ',
  }

  return JSON.stringify(data, null, 2)
}

function extractDocsRequired(text: string): string[] {
  const docs: string[] = []
  if (/บัตรประชาชน|id card/.test(text)) docs.push('สำเนาบัตรประชาชน')
  if (/ทะเบียนบ้าน/.test(text)) docs.push('สำเนาทะเบียนบ้าน')
  if (/ตรวจสุขภาพ|medical report/.test(text)) docs.push('ผลตรวจสุขภาพไม่เกิน 30 วัน')
  if (/รายได้|income/.test(text)) docs.push('หลักฐานรายได้ 3 เดือนล่าสุด')
  if (/ลายเซ็น|signature/.test(text)) docs.push('ใบสมัครที่ลายเซ็นตรงกับบัตรประชาชน')
  return docs
}

export function useDeepseekApi() {
  return {
    chatCompletion,
    config: {
      defaultModel: 'deepseek-chat' as DeepseekModel,
      // For real use, set this in .env (VITE_DEEPSEEK_API_KEY)
      isMocked: true,
    },
  }
}
