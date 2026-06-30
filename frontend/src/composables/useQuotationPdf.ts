/**
 * useQuotationPdf — render a Quotation into a PDF.
 *
 * Implementation note on Thai fonts:
 * jsPDF's built-in fonts (Helvetica/Times/Courier) don't render Thai glyphs —
 * you'd see placeholder boxes. Embedding a Thai font (Sarabun) requires
 * shipping the font binary as base64, which is ~300 KB.
 *
 * For this MVP we use jsPDF's `.html()` method, which rasterizes a hidden
 * HTML node (rendered with the page's existing Thai fonts via the document
 * stylesheet) into the PDF. Output is a real PDF (vector text for the parts
 * jsPDF can do natively, image for the complex Thai-text blocks). Quality is
 * good for screen and adequate for printing.
 *
 * When you want true vector Thai text, swap to one of:
 *   1. Backend rendering via Laravel + DomPDF (with Sarabun in fonts/)
 *   2. Bundle the Sarabun font as base64 and call jsPDF.addFileToVFS + addFont
 */

import { jsPDF } from 'jspdf'
import type { Quotation } from './useQuotation'

const fmtTHB = (n: number): string => n.toLocaleString('th-TH')

function premiumModeLabel(mode: Quotation['premium_mode']): string {
  return {
    monthly: 'รายเดือน',
    quarterly: 'รายไตรมาส',
    semiannual: 'ราย 6 เดือน',
    annual: 'รายปี',
    single: 'จ่ายครั้งเดียว',
  }[mode]
}

/**
 * Renders the quotation as an HTML node, then passes it to jsPDF.html()
 * which rasterizes the styled output into a PDF. The hidden node uses the
 * already-loaded IBM Plex Sans Thai / Sarabun fonts from index.html so all
 * Thai characters render correctly.
 */
function buildQuotationHtml(q: Quotation): HTMLElement {
  const wrapper = document.createElement('div')
  wrapper.style.cssText = `
    width: 794px;
    padding: 48px;
    font-family: 'IBM Plex Sans Thai', 'Sarabun', system-ui, sans-serif;
    background: #ffffff;
    color: #1e293b;
    font-size: 13px;
    line-height: 1.5;
  `

  const carrier = q.carrierName
  const summaryRow = (label: string, value: string) =>
    `<tr>
      <td style="padding:6px 12px 6px 0; color:#64748b; vertical-align:top; width:160px;">${label}</td>
      <td style="padding:6px 0; color:#0f172a;">${value}</td>
    </tr>`

  const listSection = (title: string, items: string[]) => {
    if (!items.length) return ''
    return `
      <section style="margin-top:24px;">
        <h3 style="font-size:14px; font-weight:600; color:#0f172a; margin:0 0 8px; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">${title}</h3>
        <ol style="margin:0; padding-left:20px; color:#334155;">
          ${items.map((x) => `<li style="margin-bottom:4px;">${escapeHtml(x)}</li>`).join('')}
        </ol>
      </section>`
  }

  wrapper.innerHTML = `
    <!-- Header -->
    <header style="border-bottom:2px solid #26a4b0; padding-bottom:16px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start;">
      <div>
        <div style="font-size:22px; font-weight:700; color:#26a4b0; letter-spacing:-0.5px;">${escapeHtml(q.agencyName)}</div>
        <div style="font-size:11px; color:#64748b; margin-top:4px;">
          ${escapeHtml(q.agencyPhone)} · ${escapeHtml(q.agencyEmail)}
        </div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:11px; color:#94a3b8; text-transform:uppercase; letter-spacing:1px;">ใบเสนอราคา / Quotation</div>
        <div style="font-family: 'Courier New', monospace; font-size:14px; font-weight:600; color:#0f172a; margin-top:4px;">
          ${escapeHtml(q.quotationNumber)}
        </div>
        <div style="font-size:11px; color:#64748b; margin-top:4px;">วันที่ออก: ${escapeHtml(q.generatedAt)}</div>
        <div style="font-size:11px; color:#64748b;">มีผลถึง: ${escapeHtml(q.validUntil)}</div>
      </div>
    </header>

    <!-- Proposal summary callout -->
    <div style="background:#ecfbfc; border:1px solid #a3e7ed; border-radius:8px; padding:12px 16px; margin-bottom:24px;">
      <div style="font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:1px; color:#1f8893;">สรุปข้อเสนอ</div>
      <div style="font-size:14px; color:#0f172a; margin-top:4px;">${escapeHtml(q.proposal_summary)}</div>
    </div>

    <!-- Client section -->
    <section style="margin-top:8px;">
      <h3 style="font-size:14px; font-weight:600; color:#0f172a; margin:0 0 8px; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">ข้อมูลผู้เอาประกัน</h3>
      <table style="width:100%; border-collapse:collapse; font-size:12px;">
        ${summaryRow('ชื่อ-นามสกุล', escapeHtml(q.clientName))}
        ${q.clientIdCard ? summaryRow('เลขบัตรประชาชน', `<span style="font-family:monospace;">${escapeHtml(q.clientIdCard)}</span>`) : ''}
        ${q.clientAge ? summaryRow('อายุ', `${q.clientAge} ปี`) : ''}
        ${q.clientOccupation ? summaryRow('อาชีพ', escapeHtml(q.clientOccupation)) : ''}
        ${summaryRow('เลขเคส', `<span style="font-family:monospace;">${escapeHtml(q.caseId)}</span>`)}
      </table>
    </section>

    <!-- Insurance proposal -->
    <section style="margin-top:24px;">
      <h3 style="font-size:14px; font-weight:600; color:#0f172a; margin:0 0 8px; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">รายละเอียดความคุ้มครอง</h3>
      <table style="width:100%; border-collapse:collapse; font-size:12px;">
        ${summaryRow('บริษัทประกัน', `<strong>${escapeHtml(carrier)}</strong> <span style="color:#94a3b8;">(${escapeHtml(q.carrierCode)})</span>`)}
        ${summaryRow('ผลิตภัณฑ์', escapeHtml(q.productName))}
        ${q.policy_number ? summaryRow('เลขกรมธรรม์', `<span style="font-family:monospace;">${escapeHtml(q.policy_number)}</span>`) : ''}
        ${summaryRow('ทุนประกัน', `<strong style="color:#1f8893;">฿${fmtTHB(q.coverage_amount)}</strong>`)}
        ${summaryRow('เบี้ยประกัน', `<strong>฿${fmtTHB(q.annual_premium)}</strong> ${premiumModeLabel(q.premium_mode)}`)}
        ${summaryRow('ระยะเวลาคุ้มครอง', `${q.coverage_period_years} ปี`)}
        ${summaryRow('ระยะเวลาชำระเบี้ย', `${q.payment_period_years} ปี`)}
        ${q.effective_date_thai ? summaryRow('วันคุ้มครองเริ่ม', escapeHtml(q.effective_date_thai)) : ''}
        ${q.waiting_period_days > 0 ? summaryRow('ระยะเวลารอคอย', `${q.waiting_period_days} วัน`) : ''}
      </table>
    </section>

    ${listSection('สัญญาเพิ่มเติม (Riders)', q.riders)}
    ${listSection('เงื่อนไขความคุ้มครอง', q.conditions)}
    ${listSection('ข้อยกเว้น', q.exclusions)}
    ${listSection('เอกสารที่ต้องเตรียม', q.documents_required)}

    <!-- Next steps -->
    <section style="margin-top:24px; background:#fff8eb; border-left:4px solid #fb9326; padding:12px 16px; border-radius:0 8px 8px 0;">
      <div style="font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:1px; color:#b2440a;">ขั้นถัดไป</div>
      <div style="font-size:13px; color:#0f172a; margin-top:4px;">${escapeHtml(q.next_steps)}</div>
    </section>

    <!-- Signature section -->
    <section style="margin-top:48px; display:flex; gap:48px;">
      <div style="flex:1;">
        <div style="border-top:1px solid #94a3b8; padding-top:6px; font-size:11px; color:#64748b; text-align:center;">
          ผู้เสนอราคา<br /><span style="color:#0f172a;">${escapeHtml(q.agentName)}</span>
        </div>
      </div>
      <div style="flex:1;">
        <div style="border-top:1px solid #94a3b8; padding-top:6px; font-size:11px; color:#64748b; text-align:center;">
          ผู้เอาประกัน<br /><span style="color:#0f172a;">${escapeHtml(q.clientName)}</span>
        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer style="margin-top:40px; padding-top:16px; border-top:1px solid #e2e8f0; font-size:10px; color:#94a3b8; text-align:center;">
      ใบเสนอราคานี้ออกโดย ${escapeHtml(q.agencyName)} · มีผลถึงวันที่ ${escapeHtml(q.validUntil)}
      <br />เงื่อนไขความคุ้มครองเป็นไปตามที่บริษัทประกันกำหนด · เอกสารฉบับนี้ไม่ใช่กรมธรรม์
    </footer>
  `

  // Stage the node off-screen so jsPDF can read its rendered layout
  wrapper.style.position = 'fixed'
  wrapper.style.left = '-9999px'
  wrapper.style.top = '0'
  document.body.appendChild(wrapper)
  return wrapper
}

function escapeHtml(s: string): string {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

export function useQuotationPdf() {
  /**
   * Render the quotation into a PDF Blob.
   * jsPDF.html() rasterizes the HTML node into the PDF page, preserving the
   * Thai fonts from the surrounding document.
   */
  async function renderToBlob(q: Quotation): Promise<Blob> {
    const node = buildQuotationHtml(q)
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' })

    await pdf.html(node, {
      x: 0,
      y: 0,
      width: 595, // A4 width in pt
      windowWidth: 794,
      html2canvas: {
        scale: 595 / 794,
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff',
      },
    })

    document.body.removeChild(node)
    return pdf.output('blob')
  }

  /**
   * Convenience: render + trigger download.
   */
  async function downloadPdf(q: Quotation): Promise<{ blob: Blob; fileName: string; sizeBytes: number }> {
    const blob = await renderToBlob(q)
    const fileName = `${q.quotationNumber}.pdf`
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = fileName
    a.click()
    URL.revokeObjectURL(url)
    return { blob, fileName, sizeBytes: blob.size }
  }

  return {
    renderToBlob,
    downloadPdf,
  }
}
