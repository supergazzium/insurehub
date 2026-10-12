import { loadLogo, renderNodeToPdfBlob } from './useQuotationPdf'
import { bahtText } from '../utils/bahtText'
import type { TenantSettingsRow } from '../api/tenant'
import { fmtDate } from '../util/dateFormat'

export interface NoticeFigures {
  annualPremium: number
  netPremium: number | null
  dutyStamp: number | null
  vat: number | null
  discount: number | null
  coverage: number | null
}

export interface RenewalNoticeDoc {
  noticeNo: string
  issuedAt: string
  dueDate: string | null
  validUntil: string | null
  tenant: TenantSettingsRow | null
  customerName: string
  customerPhone: string | null
  policyNo: string | null
  carrierName: string | null
  productName: string | null
  productCategory: string | null
  motorLicenseNo: string | null
  motorVehicle: string | null
  currentExpiry: string | null
  newTermFrom: string | null
  newTermTo: string | null
  premiumModeLabel: string
  current: NoticeFigures
  lastYear: NoticeFigures | null
  agentName: string | null
  note: string | null
}

function esc(s: string | number | null | undefined): string {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function money(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return Number(n).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function thaiDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = iso.length > 10 ? new Date(iso) : new Date(iso + 'T00:00:00')
  if (Number.isNaN(d.getTime())) return fmtDate(iso)
  return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' })
}

function companyLines(t: TenantSettingsRow | null): { name: string; address: string; contact: string; ids: string } {
  const name = t?.name || 'InsureHub'
  const address = [t?.address, t?.district, t?.amphoe, t?.province, t?.postcode].filter(Boolean).join(' ')
  const contact = [t?.phone ? `โทร ${t.phone}` : null, t?.email, t?.website].filter(Boolean).join(' · ')
  const ids = [t?.taxId ? `เลขประจำตัวผู้เสียภาษี ${t.taxId}` : null, t?.oicLicense ? `ใบอนุญาตนายหน้า คปภ. ${t.oicLicense}` : null]
    .filter(Boolean).join(' · ')
  return { name, address, contact, ids }
}

function buildNoticeHtml(doc: RenewalNoticeDoc, logo: string | null): HTMLElement {
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
  const co = companyLines(doc.tenant)
  const c = doc.current
  const l = doc.lastYear
  const total = c.annualPremium - (c.discount ?? 0)
  const paymentChannels = (doc.tenant?.paymentChannels ?? '').trim()

  const sellerLines: string[] = []
  if (co.address) sellerLines.push(esc(co.address))
  if (co.ids) sellerLines.push(esc(co.ids))
  if (co.contact) sellerLines.push(esc(co.contact))

  const metaRow = (k: string, v: string, extraPad = false) =>
    `<tr>
       <td style="padding:${extraPad ? '9px' : '5px'} 8px 5px; font-size:12.5px; color:#1f8893; width:72px; vertical-align:top; border-bottom:1px solid #eef1f4;">${k}</td>
       <td style="padding:${extraPad ? '9px' : '5px'} 8px 5px; font-size:12.5px; color:#1a1a1a; vertical-align:top; border-bottom:1px solid #eef1f4;">${v}</td>
     </tr>`

  const infoRow = (label: string, value: string) =>
    `<tr>
       <td style="padding:5px 12px 5px 0; color:#6b7280; width:170px; vertical-align:top; font-size:12.5px;">${label}</td>
       <td style="padding:5px 0; color:#1a1a1a; font-size:12.5px;">${value}</td>
     </tr>`

  const premiumRow = (label: string, now: number | null, prev: number | null | undefined, strong = false) =>
    `<tr>
       <td style="padding:7px 10px; border-bottom:1px solid #eef1f4; ${strong ? 'font-weight:700;' : ''}">${label}</td>
       ${l ? `<td style="padding:7px 10px; border-bottom:1px solid #eef1f4; text-align:right; color:#6b7280; font-variant-numeric:tabular-nums;">${money(prev ?? null)}</td>` : ''}
       <td style="padding:7px 10px; border-bottom:1px solid #eef1f4; text-align:right; color:#1a1a1a; font-variant-numeric:tabular-nums; ${strong ? 'font-weight:700;' : ''}">${money(now)}</td>
     </tr>`

  const sectionHead = (t: string) =>
    `<h3 style="font-size:13.5px; font-weight:600; color:#1a1a1a; margin:20px 0 6px; border-bottom:1.5px solid #d9dde3; padding-bottom:5px;">${t}</h3>`

  wrapper.innerHTML = `
    <!-- Orange corner fold -->
    <div style="position:absolute; top:0; right:0; width:0; height:0; border-style:solid; border-width:0 64px 64px 0; border-color:transparent #f5a623 transparent transparent;"></div>

    <!-- Header -->
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:24px;">
      <div style="flex:1;">
        ${logo ? `<img src="${logo}" alt="logo" style="height:58px; width:auto; display:block; margin-bottom:14px;" />` : ''}
        <div style="font-weight:700; font-size:14px; color:#1a1a1a;">${esc(co.name)}</div>
        <div style="font-size:12px; line-height:1.7; margin-top:2px;">${sellerLines.join('<br/>')}</div>
      </div>
      <div style="width:320px; flex:none;">
        <div style="text-align:center; font-size:20px; font-weight:700; color:#1f8893; letter-spacing:1px; margin:2px 0 14px;">ใบเตือนต่ออายุ</div>
        <table style="width:100%; border-collapse:collapse; border:1px solid #d9dde3; border-radius:4px; overflow:hidden;">
          ${metaRow('เลขที่', esc(doc.noticeNo))}
          ${metaRow('วันที่ออก', esc(thaiDate(doc.issuedAt)))}
          ${metaRow('ครบกำหนด', `<span style="color:#b91c1c; font-weight:700;">${esc(thaiDate(doc.dueDate))}</span>`)}
          ${doc.validUntil ? metaRow('ใช้ได้ถึง', esc(thaiDate(doc.validUntil))) : ''}
        </table>
      </div>
    </div>

    <!-- Customer -->
    <div style="margin-top:26px;">
      <div style="color:#f5a623; font-weight:600; font-size:12.5px; margin-bottom:3px;">เรียน ลูกค้า</div>
      <div style="font-weight:600;">คุณ${esc(doc.customerName)}${doc.customerPhone ? ` <span style="color:#6b7280; font-weight:400;">· ${esc(doc.customerPhone)}</span>` : ''}</div>
      <div style="color:#4b5563; font-size:12.5px; margin-top:4px;">
        กรมธรรม์ของท่านจะสิ้นสุดความคุ้มครองในวันที่ <b>${esc(thaiDate(doc.currentExpiry))}</b> — ${esc(co.name)} ขอแจ้งรายละเอียดเบี้ยประกันสำหรับการต่ออายุดังนี้
      </div>
    </div>

    <!-- Policy info -->
    ${sectionHead('ข้อมูลกรมธรรม์')}
    <table style="width:100%; border-collapse:collapse;">
      ${infoRow('บริษัทประกัน', esc(doc.carrierName ?? '—'))}
      ${infoRow('ผลิตภัณฑ์', esc(doc.productName ?? '—') + (doc.productCategory ? ` <span style="color:#6b7280;">(${esc(doc.productCategory)})</span>` : ''))}
      ${doc.motorLicenseNo ? infoRow('ทะเบียนรถ', esc(doc.motorLicenseNo) + (doc.motorVehicle ? ` · ${esc(doc.motorVehicle)}` : '')) : ''}
      ${infoRow('เลขกรมธรรม์เดิม', esc(doc.policyNo ?? '—'))}
      ${infoRow('ระยะเวลาคุ้มครองใหม่', `${esc(thaiDate(doc.newTermFrom))} – ${esc(thaiDate(doc.newTermTo))}`)}
      ${infoRow('ทุนประกัน', c.coverage !== null ? money(c.coverage) + ' บาท' : '—')}
      ${infoRow('การชำระเบี้ย', esc(doc.premiumModeLabel))}
    </table>

    <!-- Premium breakdown -->
    ${sectionHead('รายละเอียดเบี้ยประกัน (บาท)')}
    <table style="width:100%; border-collapse:collapse; font-size:12.5px;">
      <thead>
        <tr>
          <th style="padding:7px 10px; text-align:left; font-weight:600; color:#6b7280; border-bottom:1.5px solid #d9dde3;">รายการ</th>
          ${l ? '<th style="padding:7px 10px; text-align:right; font-weight:600; color:#6b7280; border-bottom:1.5px solid #d9dde3;">ปีปัจจุบัน</th>' : ''}
          <th style="padding:7px 10px; text-align:right; font-weight:600; color:#6b7280; border-bottom:1.5px solid #d9dde3;">ปีต่ออายุ</th>
        </tr>
      </thead>
      <tbody>
        ${premiumRow('เบี้ยประกันสุทธิ', c.netPremium, l?.netPremium)}
        ${premiumRow('อากรแสตมป์', c.dutyStamp, l?.dutyStamp)}
        ${premiumRow('ภาษีมูลค่าเพิ่ม', c.vat, l?.vat)}
        ${premiumRow('เบี้ยประกันรวม', c.annualPremium, l?.annualPremium, true)}
        ${c.discount ? premiumRow('ส่วนลด', c.discount, l?.discount) : ''}
        ${c.discount ? premiumRow('ยอดที่ต้องชำระ', total, l ? l.annualPremium - (l.discount ?? 0) : null, true) : ''}
      </tbody>
    </table>

    <!-- Total + baht text -->
    <div style="display:flex; justify-content:flex-end; margin-top:12px;">
      <table style="border-collapse:collapse; min-width:340px;">
        <tr style="border-top:1.5px solid #d9dde3;">
          <td style="padding:9px 10px 6px; text-align:right; color:#1f8893; font-weight:700; font-size:14px;">ยอดที่ต้องชำระ</td>
          <td style="padding:9px 10px 6px; text-align:right; width:150px; font-weight:700; font-size:14px; font-variant-numeric:tabular-nums;">${money(total)} บาท</td>
        </tr>
      </table>
    </div>
    <div style="text-align:right; margin-top:4px; color:#6b7280; font-size:12.5px;">(${esc(bahtText(total))})</div>
    <div style="text-align:right; margin-top:2px; color:#b91c1c; font-size:12px;">กรุณาชำระภายใน ${esc(thaiDate(doc.dueDate))}</div>

    <!-- Payment channels -->
    ${sectionHead('ช่องทางการชำระเงิน')}
    <div style="white-space:pre-line; color:#334155; font-size:12.5px;">${paymentChannels ? esc(paymentChannels) : 'กรุณาติดต่อเจ้าหน้าที่เพื่อรับช่องทางการชำระเงิน'}</div>

    ${doc.note ? `<div style="margin-top:16px; color:#4b5563; font-size:12px; white-space:pre-line;"><b>หมายเหตุ:</b> ${esc(doc.note)}</div>` : ''}
    <div style="margin-top:12px; color:#4b5563; font-size:12px;">
      หากชำระเบี้ยแล้ว กรุณาแจ้งหลักฐานการชำระกับเจ้าหน้าที่${doc.agentName ? ` (ผู้ดูแล: ${esc(doc.agentName)})` : ''} เพื่อดำเนินการต่ออายุให้ทันวันหมดอายุ
    </div>

    <!-- Footer -->
    <div style="margin-top:30px; text-align:right; color:#6b7280; font-size:11px;">
      ออกโดยระบบ <span style="color:#1f8893; font-weight:600;">InsureHub</span> · เอกสารฉบับนี้เป็นใบเตือนต่ออายุ ไม่ใช่กรมธรรม์
    </div>
  `

  wrapper.style.position = 'fixed'
  wrapper.style.left = '-9999px'
  wrapper.style.top = '0'
  document.body.appendChild(wrapper)
  return wrapper
}

export async function renderRenewalNoticePdf(doc: RenewalNoticeDoc): Promise<File> {
  const logo = await loadLogo()
  const blob = await renderNodeToPdfBlob(buildNoticeHtml(doc, logo), 0.25)
  return new File([blob], `${doc.noticeNo}.pdf`, { type: 'application/pdf' })
}
