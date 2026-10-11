import { BRAND, loadLogo, renderNodeToPdfBlob } from './useQuotationPdf'
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
    padding: 0;
    font-family: 'IBM Plex Sans Thai', 'Sarabun', system-ui, sans-serif;
    background: #ffffff;
    color: #1e293b;
    font-size: 13px;
    line-height: 1.5;
  `
  const co = companyLines(doc.tenant)
  const c = doc.current
  const l = doc.lastYear
  const total = c.annualPremium - (c.discount ?? 0)
  const row = (label: string, value: string) => `
    <tr>
      <td style="padding:5px 12px 5px 0; color:#64748b; width:170px; vertical-align:top;">${label}</td>
      <td style="padding:5px 0; color:#0f172a;">${value}</td>
    </tr>`
  const premiumRow = (label: string, now: number | null, prev: number | null | undefined, strong = false) => `
    <tr style="${strong ? 'font-weight:700; background:#f8fafc;' : ''}">
      <td style="padding:7px 10px; border-bottom:1px solid ${BRAND.hair};">${label}</td>
      ${l ? `<td style="padding:7px 10px; border-bottom:1px solid ${BRAND.hair}; text-align:right; color:#64748b;">${money(prev ?? null)}</td>` : ''}
      <td style="padding:7px 10px; border-bottom:1px solid ${BRAND.hair}; text-align:right; color:#0f172a;">${money(now)}</td>
    </tr>`
  const paymentChannels = (doc.tenant?.paymentChannels ?? '').trim()

  wrapper.innerHTML = `
    <header style="background:${BRAND.teal}; color:#ffffff; padding:20px 48px; display:flex; justify-content:space-between; align-items:center;">
      <div>
        ${logo
          ? `<img src="${logo}" alt="logo" style="height:50px; width:auto; background:#ffffff; padding:8px 12px; border-radius:8px;" />`
          : `<div style="font-size:22px; font-weight:700;">${esc(co.name)}</div>`}
      </div>
      <div style="text-align:right;">
        <div style="font-size:17px; font-weight:700;">ใบเตือนต่ออายุกรมธรรม์</div>
        <div style="font-size:11px; letter-spacing:1px; opacity:0.75;">RENEWAL NOTICE</div>
        <div style="font-family:'Courier New',monospace; font-size:15px; font-weight:700; margin-top:6px;">${esc(doc.noticeNo)}</div>
      </div>
    </header>

    <div style="padding:12px 48px; background:${BRAND.tealTint}; border-bottom:2px solid ${BRAND.amber}; display:flex; justify-content:space-between; gap:24px; font-size:11px; color:${BRAND.slate};">
      <div>
        <div style="font-weight:700; color:${BRAND.tealDark}; font-size:13px;">${esc(co.name)}</div>
        ${co.address ? `<div>${esc(co.address)}</div>` : ''}
        ${co.contact ? `<div>${esc(co.contact)}</div>` : ''}
        ${co.ids ? `<div>${esc(co.ids)}</div>` : ''}
      </div>
      <div style="text-align:right; white-space:nowrap;">
        <div>วันที่ออก: <span style="color:${BRAND.ink};">${esc(thaiDate(doc.issuedAt))}</span></div>
        <div>ครบกำหนดชำระ: <span style="color:#b91c1c; font-weight:700;">${esc(thaiDate(doc.dueDate))}</span></div>
        ${doc.validUntil ? `<div>ราคานี้ใช้ได้ถึง: <span style="color:${BRAND.ink};">${esc(thaiDate(doc.validUntil))}</span></div>` : ''}
      </div>
    </div>

    <div style="padding:18px 48px 0;">
      <p style="margin:0 0 14px; color:#334155;">
        เรียน คุณ${esc(doc.customerName)}<br />
        กรมธรรม์ของท่านจะสิ้นสุดความคุ้มครองในวันที่ <b>${esc(thaiDate(doc.currentExpiry))}</b>
        ${esc(co.name)} ขอแจ้งรายละเอียดเบี้ยประกันสำหรับการต่ออายุดังนี้
      </p>

      <section>
        <h3 style="font-size:14px; font-weight:600; color:#0f172a; margin:0 0 6px; border-bottom:2px solid ${BRAND.teal}; padding-bottom:4px;">ข้อมูลกรมธรรม์</h3>
        <table style="width:100%; border-collapse:collapse;">
          ${row('ผู้เอาประกัน', esc(doc.customerName) + (doc.customerPhone ? ` <span style="color:#64748b;">· ${esc(doc.customerPhone)}</span>` : ''))}
          ${row('บริษัทประกัน', esc(doc.carrierName ?? '—'))}
          ${row('ผลิตภัณฑ์', esc(doc.productName ?? '—') + (doc.productCategory ? ` <span style="color:#64748b;">(${esc(doc.productCategory)})</span>` : ''))}
          ${doc.motorLicenseNo ? row('ทะเบียนรถ', esc(doc.motorLicenseNo) + (doc.motorVehicle ? ` · ${esc(doc.motorVehicle)}` : '')) : ''}
          ${row('เลขกรมธรรม์เดิม', esc(doc.policyNo ?? '—'))}
          ${row('ระยะเวลาคุ้มครองใหม่', `${esc(thaiDate(doc.newTermFrom))} – ${esc(thaiDate(doc.newTermTo))}`)}
          ${row('ทุนประกัน', c.coverage !== null ? money(c.coverage) + ' บาท' : '—')}
          ${row('การชำระเบี้ย', esc(doc.premiumModeLabel))}
        </table>
      </section>

      <section style="margin-top:14px;">
        <h3 style="font-size:14px; font-weight:600; color:#0f172a; margin:0 0 6px; border-bottom:2px solid ${BRAND.teal}; padding-bottom:4px;">รายละเอียดเบี้ยประกัน (บาท)</h3>
        <table style="width:100%; border-collapse:collapse; font-size:12.5px;">
          <thead>
            <tr style="background:${BRAND.tealTint}; color:${BRAND.tealDark};">
              <th style="padding:7px 10px; text-align:left;">รายการ</th>
              ${l ? '<th style="padding:7px 10px; text-align:right;">ปีปัจจุบัน</th>' : ''}
              <th style="padding:7px 10px; text-align:right;">ปีต่ออายุ</th>
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
      </section>

      <section style="margin-top:14px; display:flex; gap:16px;">
        <div style="flex:1; border:1px solid ${BRAND.tealLine}; border-radius:8px; padding:12px 14px; background:#ffffff;">
          <div style="font-size:12px; font-weight:700; color:${BRAND.tealDark}; margin-bottom:4px;">ช่องทางการชำระเงิน</div>
          <div style="white-space:pre-line; color:#334155; font-size:12px;">${paymentChannels ? esc(paymentChannels) : 'กรุณาติดต่อเจ้าหน้าที่เพื่อรับช่องทางการชำระเงิน'}</div>
        </div>
        <div style="width:220px; border-radius:8px; padding:12px 14px; background:${BRAND.amber}; color:#ffffff;">
          <div style="font-size:11px; opacity:0.9;">ยอดที่ต้องชำระ</div>
          <div style="font-size:22px; font-weight:700;">฿${money(total)}</div>
          <div style="font-size:11px; margin-top:4px;">ภายใน ${esc(thaiDate(doc.dueDate))}</div>
        </div>
      </section>

      ${doc.note ? `<p style="margin:16px 0 0; color:#475569; font-size:12px; white-space:pre-line;"><b>หมายเหตุ:</b> ${esc(doc.note)}</p>` : ''}

      <p style="margin:16px 0 0; color:#475569; font-size:12px;">
        หากชำระเบี้ยแล้ว กรุณาแจ้งหลักฐานการชำระกับเจ้าหน้าที่${doc.agentName ? ` (ผู้ดูแล: ${esc(doc.agentName)})` : ''} เพื่อดำเนินการต่ออายุกรมธรรม์ให้ทันวันหมดอายุ
      </p>
    </div>

    <footer style="margin-top:20px; background:${BRAND.tealDark}; color:#cbd5e1; padding:14px 48px; font-size:10px; text-align:center;">
      <span style="color:#ffffff; font-weight:600;">${esc(co.name)}</span>${co.contact ? ` · ${esc(co.contact)}` : ''}
      <br />เงื่อนไขความคุ้มครองเป็นไปตามที่บริษัทประกันกำหนด · เอกสารฉบับนี้เป็นใบเตือนต่ออายุ ไม่ใช่กรมธรรม์
    </footer>
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
