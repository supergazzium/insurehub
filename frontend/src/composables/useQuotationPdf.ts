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
import type { Quotation, QuotationLineItem } from './useQuotation'
import { bahtText } from '../utils/bahtText'

// ── Brand palette (matches the InsureHub logo + tailwind brand scale) ──────
export const BRAND = {
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
export async function loadLogo(): Promise<string | null> {
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

const money2 = (n: number): string => Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

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
    min-height: 1123px;
    padding: 46px 52px 40px;
    position: relative;
    font-family: 'Sarabun', 'IBM Plex Sans Thai', system-ui, sans-serif;
    background: #ffffff;
    color: #1a1a1a;
    font-size: 13px;
    line-height: 1.5;
    box-sizing: border-box;
  `

  // ── รายการในตาราง: ถ้าไม่มี lineItems → สังเคราะห์ 1 รายการจาก product/coverage ──
  const items: QuotationLineItem[] = (q.lineItems && q.lineItems.length)
    ? q.lineItems
    : [{
        description: `${escapeHtml(q.carrierName)} ${escapeHtml(q.productName)}`.trim() || escapeHtml(q.productName),
        quantity: 1,
        unitPrice: q.annual_premium,
        amount: q.annual_premium,
      }]

  const subtotal = items.reduce((s2, it) => s2 + (Number(it.amount) || 0), 0)
  const vatRate = q.vatRate ?? 0
  const vat = Math.round(subtotal * (vatRate / 100) * 100) / 100
  const grand = subtotal + vat

  // ที่อยู่ผู้ขาย: หลายบรรทัดด้วย \n
  const agencyAddrHtml = (q.agencyAddress || '')
    .split('\n').map((l) => escapeHtml(l.trim())).filter(Boolean).join('<br/>')

  const sellerLines: string[] = []
  if (agencyAddrHtml) sellerLines.push(agencyAddrHtml)
  if (q.agencyTaxId) sellerLines.push(`เลขประจำตัวผู้เสียภาษี ${escapeHtml(q.agencyTaxId)}`)
  if (q.agencyPhone) sellerLines.push(`โทร. ${escapeHtml(q.agencyPhone)}`)
  if (q.agencyMobile) sellerLines.push(`เบอร์มือถือ ${escapeHtml(q.agencyMobile)}`)
  if (q.agencyWebsite) sellerLines.push(escapeHtml(q.agencyWebsite))

  const metaRow = (k: string, v: string, extraPad = false) =>
    `<tr${extraPad ? ' style="border-top:1px solid #d9dde3;"' : ''}>
       <td style="padding:${extraPad ? '9px' : '5px'} 8px 5px; font-size:12.5px; color:#1f8893; width:64px; vertical-align:top; border-bottom:1px solid #eef1f4;">${k}</td>
       <td style="padding:${extraPad ? '9px' : '5px'} 8px 5px; font-size:12.5px; color:#1a1a1a; vertical-align:top; border-bottom:1px solid #eef1f4;">${v}</td>
     </tr>`

  const itemRow = (it: QuotationLineItem, i: number) => {
    const subs = (it.subItems && it.subItems.length)
      ? `<ul style="margin:4px 0 0; padding:0; list-style:none;">
           ${it.subItems.map((x) => `<li style="font-size:12.5px; padding:1px 0 1px 6px; color:#1a1a1a;">${escapeHtml(x)}</li>`).join('')}
         </ul>`
      : ''
    return `<tr>
      <td style="padding:9px 10px; font-size:13px; vertical-align:top; color:#6b7280;">${i + 1}</td>
      <td style="padding:9px 10px; font-size:13px; vertical-align:top;">
        <div style="font-weight:500;">${escapeHtml(it.description)}</div>${subs}
      </td>
      <td style="padding:9px 10px; font-size:13px; vertical-align:top; text-align:right; font-variant-numeric:tabular-nums;">${Number(it.quantity).toLocaleString('th-TH')}</td>
      <td style="padding:9px 10px; font-size:13px; vertical-align:top; text-align:right; font-variant-numeric:tabular-nums;">${money2(it.unitPrice)}</td>
      <td style="padding:9px 10px; font-size:13px; vertical-align:top; text-align:right; font-variant-numeric:tabular-nums;">${money2(it.amount)}</td>
    </tr>`
  }

  const totalRow = (k: string, v: string, grandTotal = false) =>
    `<tr${grandTotal ? ' style="border-top:1.5px solid #d9dde3;"' : ''}>
       <td style="padding:${grandTotal ? '9px' : '6px'} 10px 6px; text-align:right; color:#1f8893; font-size:13px; ${grandTotal ? 'font-weight:700;' : ''}">${k}</td>
       <td style="padding:${grandTotal ? '9px' : '6px'} 10px 6px; text-align:right; width:150px; font-size:13px; font-variant-numeric:tabular-nums; ${grandTotal ? 'font-weight:700;' : ''}">${v}</td>
     </tr>`

  wrapper.innerHTML = `
    <!-- Orange corner fold -->
    <div style="position:absolute; top:0; right:0; width:0; height:0; border-style:solid; border-width:0 64px 64px 0; border-color:transparent #f5a623 transparent transparent;"></div>

    <!-- Header -->
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:24px;">
      <div style="flex:1;">
        ${logo ? `<img src="${logo}" alt="logo" style="height:58px; width:auto; display:block; margin-bottom:14px;" />` : ''}
        <div style="font-weight:700; font-size:14px; color:#1a1a1a;">${escapeHtml(q.agencyName)}</div>
        <div style="font-size:12px; line-height:1.7; margin-top:2px;">${sellerLines.join('<br/>')}</div>
      </div>
      <div style="width:320px; flex:none;">
        <div style="text-align:center; font-size:22px; font-weight:700; color:#1f8893; letter-spacing:1px; margin:2px 0 14px;">ใบเสนอราคา</div>
        <table style="width:100%; border-collapse:collapse; border:1px solid #d9dde3; border-radius:4px; overflow:hidden;">
          ${metaRow('เลขที่', escapeHtml(q.quotationNumber))}
          ${metaRow('วันที่', escapeHtml(q.generatedAt))}
          ${metaRow('ผู้ขาย', escapeHtml(q.sellerName || q.agentName || '—'))}
          ${q.jobTitle ? metaRow('ชื่องาน', escapeHtml(q.jobTitle), true) : ''}
        </table>
      </div>
    </div>

    <!-- Customer -->
    <div style="margin-top:26px;">
      <div style="color:#f5a623; font-weight:600; font-size:12.5px; margin-bottom:3px;">ลูกค้า</div>
      <div style="font-weight:600;">${escapeHtml(q.clientName)}</div>
      ${q.clientAddress ? `<div>${escapeHtml(q.clientAddress)}</div>` : ''}
      ${q.clientTaxId ? `<div>เลขประจำตัวผู้เสียภาษี ${escapeHtml(q.clientTaxId)}</div>`
        : (q.clientIdCard ? `<div>เลขบัตรประชาชน ${escapeHtml(q.clientIdCard)}</div>` : '')}
    </div>

    <!-- Items -->
    <table style="width:100%; border-collapse:collapse; margin-top:22px;">
      <thead>
        <tr>
          <th style="font-weight:600; font-size:12.5px; color:#6b7280; border-bottom:1.5px solid #d9dde3; padding:8px 10px; text-align:left; width:34px;">#</th>
          <th style="font-weight:600; font-size:12.5px; color:#6b7280; border-bottom:1.5px solid #d9dde3; padding:8px 10px; text-align:left;">รายละเอียด</th>
          <th style="font-weight:600; font-size:12.5px; color:#6b7280; border-bottom:1.5px solid #d9dde3; padding:8px 10px; text-align:right; width:70px;">จำนวน</th>
          <th style="font-weight:600; font-size:12.5px; color:#6b7280; border-bottom:1.5px solid #d9dde3; padding:8px 10px; text-align:right; width:120px;">ราคาต่อหน่วย</th>
          <th style="font-weight:600; font-size:12.5px; color:#6b7280; border-bottom:1.5px solid #d9dde3; padding:8px 10px; text-align:right; width:120px;">ยอดรวม</th>
        </tr>
      </thead>
      <tbody>
        ${items.map(itemRow).join('')}
        <tr><td colspan="5" style="border-bottom:1px solid #d9dde3; padding:0; height:0;"></td></tr>
      </tbody>
    </table>

    <!-- Totals -->
    <div style="display:flex; justify-content:flex-end; margin-top:10px;">
      <table style="border-collapse:collapse; min-width:340px;">
        ${totalRow('รวมเป็นเงิน', `${money2(subtotal)} บาท`)}
        ${totalRow(`ภาษีมูลค่าเพิ่ม ${vatRate}%`, `${money2(vat)} บาท`)}
        ${totalRow('จำนวนเงินรวมทั้งสิ้น', `${money2(grand)} บาท`, true)}
      </table>
    </div>
    <div style="text-align:right; margin-top:6px; color:#6b7280; font-size:12.5px;">(${escapeHtml(bahtText(grand))})</div>

    ${q.next_steps ? `<div style="margin-top:20px; color:#6b7280; font-size:12px;">หมายเหตุ: ${escapeHtml(q.next_steps)}</div>` : ''}

    <!-- Signatures -->
    <div style="display:flex; gap:48px; margin-top:110px;">
      <div style="flex:1; text-align:center;">
        <div style="font-size:12px; margin-bottom:56px;">ในนาม ${escapeHtml(q.clientName)}</div>
        <div style="display:flex; gap:16px; justify-content:center;">
          <div style="flex:1;"><div style="border-top:1px solid #1a1a1a; padding-top:5px; font-size:11.5px; color:#6b7280;">ผู้สั่งซื้อสินค้า</div></div>
          <div style="flex:1; max-width:90px;"><div style="border-top:1px solid #1a1a1a; padding-top:5px; font-size:11.5px; color:#6b7280;">วันที่</div></div>
        </div>
      </div>
      <div style="flex:1; text-align:center;">
        <div style="font-size:12px; margin-bottom:56px;">ในนาม ${escapeHtml(q.agencyName)}</div>
        <div style="display:flex; gap:16px; justify-content:center;">
          <div style="flex:1;"><div style="border-top:1px solid #1a1a1a; padding-top:5px; font-size:11.5px; color:#6b7280;">ผู้อนุมัติ</div></div>
          <div style="flex:1; max-width:90px;"><div style="border-top:1px solid #1a1a1a; padding-top:5px; font-size:11.5px; color:#6b7280;">วันที่</div></div>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div style="margin-top:34px; text-align:right; color:#6b7280; font-size:11px;">
      ออกโดยระบบ <span style="color:#1f8893; font-weight:600;">InsureHub</span> · ระบบจัดการตัวแทนประกันภัย
    </div>
  `

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

export async function renderNodeToPdfBlob(node: HTMLElement, fitTolerance = 0): Promise<Blob> {
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
  } else if (imgH <= pageH * (1 + fitTolerance)) {
    const w = (pageW * pageH) / imgH
    pdf.addImage(imgData, 'JPEG', (pageW - w) / 2, 0, w, pageH)
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

export function useQuotationPdf() {
  /**
   * Render the quotation into a PDF Blob.
   * jsPDF.html() rasterizes the HTML node into the PDF page, preserving the
   * Thai fonts from the surrounding document.
   */
  async function renderToBlob(q: Quotation): Promise<Blob> {
    const logo = await loadLogo()
    const node = buildQuotationHtml(q, logo)
    return renderNodeToPdfBlob(node)
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
