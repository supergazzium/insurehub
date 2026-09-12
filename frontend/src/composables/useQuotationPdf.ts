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
import html2canvas from 'html2canvas'
import type { Quotation } from './useQuotation'

// ── Brand palette (matches the InsureHub logo + tailwind brand scale) ──────
const BRAND = {
  teal: '#1f8893',      // brand-600 — primary
  tealDark: '#0f3e44',  // brand-900
  tealTint: '#ecfbfc',  // brand-50
  tealLine: '#a3e7ed',  // brand-200
  amber: '#f5a623',     // logo "Hub" + accents
  ink: '#0f172a',
  slate: '#64748b',
  hair: '#e2e8f0',
}
const LOGO_URL = `${import.meta.env.BASE_URL}brand/logo.png`

// Load the company logo once and cache it as a data URL so html2canvas
// captures it deterministically (no CORS/timing races on the <img>).
let logoDataUrl: string | null = null
async function loadLogo(): Promise<string | null> {
  if (logoDataUrl) return logoDataUrl
  try {
    const res = await fetch(LOGO_URL)
    if (!res.ok) return null
    const blob = await res.blob()
    logoDataUrl = await new Promise<string>((resolve, reject) => {
      const fr = new FileReader()
      fr.onload = () => resolve(fr.result as string)
      fr.onerror = reject
      fr.readAsDataURL(blob)
    })
    return logoDataUrl
  } catch { return null }
}

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
function buildQuotationHtml(q: Quotation, logo: string | null): HTMLElement {
  const wrapper = document.createElement('div')
  wrapper.style.cssText = `
    width: 794px;
    padding: 0 0 40px;
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
        <h3 style="font-size:14px; font-weight:600; color:#0f172a; margin:0 0 8px; border-bottom:2px solid #1f8893; padding-bottom:4px;">${title}</h3>
        <ol style="margin:0; padding-left:20px; color:#334155;">
          ${items.map((x) => `<li style="margin-bottom:4px;">${escapeHtml(x)}</li>`).join('')}
        </ol>
      </section>`
  }

  wrapper.innerHTML = `
    <!-- Branded header band -->
    <header style="background:${BRAND.teal}; color:#ffffff; padding:28px 48px; display:flex; justify-content:space-between; align-items:center;">
      <div style="display:flex; align-items:center; gap:16px;">
        ${logo
          ? `<img src="${logo}" alt="InsureHub" style="height:52px; width:auto; background:#ffffff; padding:8px 12px; border-radius:8px;" />`
          : `<div style="font-size:24px; font-weight:700; letter-spacing:-0.5px;">${escapeHtml(q.agencyName)}</div>`}
      </div>
      <div style="text-align:right;">
        <div style="font-size:12px; text-transform:uppercase; letter-spacing:2px; opacity:0.85;">ใบเสนอราคา</div>
        <div style="font-size:11px; letter-spacing:1px; opacity:0.7;">QUOTATION</div>
        <div style="font-family:'Courier New',monospace; font-size:15px; font-weight:700; margin-top:6px;">
          ${escapeHtml(q.quotationNumber)}
        </div>
      </div>
    </header>

    <!-- Company info + dates strip -->
    <div style="padding:14px 48px; background:${BRAND.tealTint}; border-bottom:2px solid ${BRAND.amber}; display:flex; justify-content:space-between; align-items:flex-start; font-size:11px; color:${BRAND.slate};">
      <div>
        <div style="font-weight:700; color:${BRAND.tealDark}; font-size:13px;">${escapeHtml(q.agencyName)}</div>
        <div style="margin-top:2px;">โทร ${escapeHtml(q.agencyPhone)} · ${escapeHtml(q.agencyEmail)}</div>
      </div>
      <div style="text-align:right;">
        <div>วันที่ออก: <span style="color:${BRAND.ink};">${escapeHtml(q.generatedAt)}</span></div>
        <div style="margin-top:2px;">ใบเสนอราคามีผลถึง: <span style="color:${BRAND.ink}; font-weight:600;">${escapeHtml(q.validUntil)}</span></div>
      </div>
    </div>

    <!-- Body -->
    <div style="padding:28px 48px 0;">
    <!-- Proposal summary callout -->
    <div style="background:${BRAND.tealTint}; border:1px solid ${BRAND.tealLine}; border-radius:8px; padding:12px 16px; margin-bottom:24px;">
      <div style="font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:1px; color:${BRAND.teal};">สรุปข้อเสนอ</div>
      <div style="font-size:14px; color:${BRAND.ink}; margin-top:4px;">${escapeHtml(q.proposal_summary || '—')}</div>
    </div>

    <!-- Client section -->
    <section style="margin-top:8px;">
      <h3 style="font-size:14px; font-weight:600; color:#0f172a; margin:0 0 8px; border-bottom:2px solid #1f8893; padding-bottom:4px;">ข้อมูลผู้เอาประกัน</h3>
      <table style="width:100%; border-collapse:collapse; font-size:12px;">
        ${summaryRow('ชื่อ-นามสกุล', escapeHtml(q.clientName))}
        ${q.clientIdCard ? summaryRow('เลขบัตรประชาชน', `<span style="font-family:monospace;">${escapeHtml(q.clientIdCard)}</span>`) : ''}
        ${q.clientAge ? summaryRow('อายุ', `${q.clientAge} ปี`) : ''}
        ${q.clientOccupation ? summaryRow('อาชีพ', escapeHtml(q.clientOccupation)) : ''}
        ${summaryRow('เลขเคส', `<span style="font-family:monospace;">${escapeHtml(q.caseId)}</span>`)}
      </table>
    </section>

    <!-- Premium + coverage highlight -->
    <section style="margin-top:24px; display:flex; gap:12px;">
      <div style="flex:1; background:${BRAND.teal}; color:#ffffff; border-radius:10px; padding:16px 18px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; opacity:0.85;">ทุนประกัน</div>
        <div style="font-size:24px; font-weight:700; margin-top:2px;">฿${fmtTHB(q.coverage_amount)}</div>
      </div>
      <div style="flex:1; background:${BRAND.amber}; color:#ffffff; border-radius:10px; padding:16px 18px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; opacity:0.9;">เบี้ยประกัน (${escapeHtml(premiumModeLabel(q.premium_mode))})</div>
        <div style="font-size:24px; font-weight:700; margin-top:2px;">฿${fmtTHB(q.annual_premium)}</div>
      </div>
    </section>

    <!-- Insurance proposal -->
    <section style="margin-top:24px;">
      <h3 style="font-size:14px; font-weight:600; color:${BRAND.ink}; margin:0 0 8px; border-bottom:2px solid ${BRAND.teal}; padding-bottom:4px;">รายละเอียดความคุ้มครอง</h3>
      <table style="width:100%; border-collapse:collapse; font-size:12px;">
        ${summaryRow('บริษัทประกัน', `<strong>${escapeHtml(carrier)}</strong> <span style="color:#94a3b8;">(${escapeHtml(q.carrierCode)})</span>`)}
        ${summaryRow('ผลิตภัณฑ์', escapeHtml(q.productName))}
        ${q.policy_number ? summaryRow('เลขกรมธรรม์', `<span style="font-family:monospace;">${escapeHtml(q.policy_number)}</span>`) : ''}
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
    ${q.next_steps ? `<section style="margin-top:24px; background:#fff8eb; border-left:4px solid ${BRAND.amber}; padding:12px 16px; border-radius:0 8px 8px 0;">
      <div style="font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:1px; color:#b2440a;">ขั้นถัดไป</div>
      <div style="font-size:13px; color:${BRAND.ink}; margin-top:4px;">${escapeHtml(q.next_steps)}</div>
    </section>` : ''}

    <!-- Signature section -->
    <section style="margin-top:48px; display:flex; gap:48px;">
      <div style="flex:1;">
        <div style="border-top:1px solid #94a3b8; padding-top:6px; font-size:11px; color:${BRAND.slate}; text-align:center;">
          ผู้เสนอราคา<br /><span style="color:${BRAND.ink};">${escapeHtml(q.agentName || q.agencyName)}</span>
        </div>
      </div>
      <div style="flex:1;">
        <div style="border-top:1px solid #94a3b8; padding-top:6px; font-size:11px; color:${BRAND.slate}; text-align:center;">
          ผู้เอาประกัน<br /><span style="color:${BRAND.ink};">${escapeHtml(q.clientName)}</span>
        </div>
      </div>
    </section>
    </div><!-- /body -->

    <!-- Footer band -->
    <footer style="margin-top:40px; background:${BRAND.tealDark}; color:#cbd5e1; padding:16px 48px; font-size:10px; text-align:center;">
      <span style="color:#ffffff; font-weight:600;">${escapeHtml(q.agencyName)}</span> · โทร ${escapeHtml(q.agencyPhone)} · ${escapeHtml(q.agencyEmail)}
      <br style="line-height:1.8;" />เงื่อนไขความคุ้มครองเป็นไปตามที่บริษัทประกันกำหนด · เอกสารฉบับนี้เป็นใบเสนอราคา ไม่ใช่กรมธรรม์
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
    const logo = await loadLogo()
    const node = buildQuotationHtml(q, logo)
    // Give data-URL images (the logo) a tick to be layout-ready before capture.
    await new Promise((r) => setTimeout(r, 50))

    // Rasterize the node ourselves with html2canvas, then place the image into
    // the PDF sized to the page width and paginate manually. This is far more
    // reliable than jsPDF.html()'s internal scaling, which produced blank pages.
    const canvas = await html2canvas(node, {
      scale: 2, // crisp text
      useCORS: true,
      allowTaint: true,
      backgroundColor: '#ffffff',
      windowWidth: 794,
    })
    document.body.removeChild(node)

    const pdf = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' })
    const pageW = pdf.internal.pageSize.getWidth()   // 595pt
    const pageH = pdf.internal.pageSize.getHeight()  // 842pt
    // Scale the full-width canvas to the page width; height follows aspect.
    const imgH = (canvas.height * pageW) / canvas.width
    const imgData = canvas.toDataURL('image/jpeg', 0.92)

    if (imgH <= pageH) {
      pdf.addImage(imgData, 'JPEG', 0, 0, pageW, imgH)
    } else {
      // Taller than one page → slice across pages by shifting the image up.
      let remaining = imgH
      let offset = 0
      while (remaining > 0) {
        pdf.addImage(imgData, 'JPEG', 0, offset, pageW, imgH)
        remaining -= pageH
        if (remaining > 0) {
          pdf.addPage()
          offset -= pageH
        }
      }
    }
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
    a.rel = 'noopener'
    // Append + defer revoke: revoking synchronously right after click()
    // cancels the download in several browsers.
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 10_000)
    return { blob, fileName, sizeBytes: blob.size }
  }

  return {
    renderToBlob,
    downloadPdf,
  }
}
