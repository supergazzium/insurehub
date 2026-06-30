import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, buildQuery, type Paginated, type Single } from '../api/client'

/**
 * Insurance type taxonomy — mirrors the คปภ. sub-category list used by the
 * old broker system. The composer filters carrier email groups by this type
 * so each product line routes to the right desk at each carrier.
 *
 * Source: legacy Access DB Main_Product.xlsx → `Sub_Categories` (15 values),
 * collapsed and grouped for our purposes.
 */
export type InsuranceType =
  // ── Life family ─────────────────────────────────────────────────────────
  | 'life'           // ประกันตลอดชีพ / สะสมทรัพย์ / บำนาญ / ชั่วระยะเวลา (individual life)
  | 'group_life'     // ประกันกลุ่ม - บ.ประกันชีวิต
  | 'ci'             // โรคร้ายแรง
  // ── Health ──────────────────────────────────────────────────────────────
  | 'health'         // ประกันสุขภาพ (individual)
  | 'group_health'   // ประกันกลุ่มสุขภาพ
  | 'pa'             // ประกันอุบัติเหตุส่วนบุคคล (PA)
  // ── Motor ───────────────────────────────────────────────────────────────
  | 'motor'          // ประกันรถยนต์ภาคสมัครใจ
  | 'cmi'            // พ.ร.บ. — Compulsory Motor Insurance
  // ── Non-motor non-life ─────────────────────────────────────────────────
  | 'fire'           // อัคคีภัย / IAR / CAR (property)
  | 'marine'         // ประกันขนส่ง / cargo
  | 'travel'         // ประกันการเดินทาง
  | 'liability'      // วิชาชีพ — professional indemnity
  | 'pet'            // สัตว์เลี้ยง
  | 'other'          // เบ็ดเตล็ด — catch-all

export type ContactDepartment =
  | 'new_business'
  | 'underwriting'
  | 'policy_issue'
  | 'claims'
  | 'other'

export interface CarrierContactGroup {
  id: string
  carrierCode: string
  name: string
  /** One or more email addresses. All addresses receive the same email (multi-TO). */
  emails: string[]
  department: ContactDepartment
  insuranceTypes: InsuranceType[]
  isDefault: boolean
  notes?: string
  active: boolean
}

export const INSURANCE_TYPE_LABELS: Record<InsuranceType, string> = {
  life: 'ประกันชีวิต',
  group_life: 'ประกันกลุ่ม (ชีวิต)',
  ci: 'โรคร้ายแรง (CI)',
  health: 'สุขภาพ',
  group_health: 'ประกันกลุ่มสุขภาพ',
  pa: 'อุบัติเหตุส่วนบุคคล (PA)',
  motor: 'รถยนต์ (สมัครใจ)',
  cmi: 'พ.ร.บ.',
  fire: 'อัคคีภัย / ทรัพย์สิน / IAR / CAR',
  marine: 'ขนส่ง (Marine / Cargo)',
  travel: 'เดินทาง',
  liability: 'วิชาชีพ (Liability)',
  pet: 'สัตว์เลี้ยง',
  other: 'อื่น ๆ',
}

export const DEPARTMENT_LABELS: Record<ContactDepartment, string> = {
  new_business: 'New Business / รับใบสมัคร',
  underwriting: 'Underwriting / พิจารณารับประกัน',
  policy_issue: 'Policy Issue / ออกกรมธรรม์',
  claims: 'Claims / สินไหม',
  other: 'อื่น ๆ',
}

/** Sentinel placed on `CarrierContactGroup.notes` to mark groups whose
 *  recipients were not sourced from the broker's actual Excel recipient list
 *  but inferred from general carrier-desk conventions. The UI surfaces an
 *  "auto-seeded — verify" badge on these so the broker can confirm/edit. */
export const AUTO_SEED_NOTE = 'auto-seeded: verify recipients against carrier broker channel'

/** True if a group's notes flag it as auto-seeded. */
export function isAutoSeeded(g: { notes?: string }): boolean {
  return g.notes === AUTO_SEED_NOTE
}

function uid(prefix = 'cg'): string {
  return `${prefix}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

// Seeded from the legacy carrierDirectory (newBusinessEmail / underwritingEmail).
// One group per (carrier, department); insuranceTypes left empty (= any), so the
// system behaves identically until users start adding per-type groups.
function seedGroups(): CarrierContactGroup[] {
  const legacy: Array<[string, string, string]> = [
    ['AIA', 'newbiz@aia.co.th', 'underwriting@aia.co.th'],
    ['TLI', 'newpolicy@thailife.com', 'uw@thailife.com'],
    ['MTL', 'newcase@muangthai.co.th', 'underwriting@muangthai.co.th'],
    ['BLA', 'application@bla.co.th', 'underwriting@bla.co.th'],
    ['VIB', 'underwriting@viriyah.co.th', 'underwriting@viriyah.co.th'],
    ['DHA', 'newcase@dhipaya.co.th', 'underwriting@dhipaya.co.th'],
    ['ALL', 'newpolicy@allianz.co.th', 'uw@allianz.co.th'],
  ]
  const out: CarrierContactGroup[] = []
  for (const [code, nb, uw] of legacy) {
    out.push({
      id: uid(),
      carrierCode: code,
      name: `${code} — New Business`,
      emails: [nb],
      department: 'new_business',
      insuranceTypes: [],
      isDefault: true,
      active: true,
    })
    out.push({
      id: uid(),
      carrierCode: code,
      name: `${code} — Underwriting`,
      emails: [uw],
      department: 'underwriting',
      insuranceTypes: [],
      isDefault: true,
      active: true,
    })
  }
  // Sample type-specific group with multiple recipients: a quotation group
  // covering the AIA health-underwriting team. Picking this group sends one
  // email addressed to all 5 people at once.
  out.push({
    id: uid(),
    carrierCode: 'AIA',
    name: 'AIA Health — Quotation Team',
    emails: [
      'health-uw1@aia.co.th',
      'health-uw2@aia.co.th',
      'health-quote@aia.co.th',
      'health-manager@aia.co.th',
      'health-support@aia.co.th',
    ],
    department: 'new_business',
    insuranceTypes: ['health'],
    isDefault: true,
    active: true,
  })
  // Generic multi-recipient example: AIA "Pending Carrier follow-up" thread
  // that copies the whole AIA new-business pod. Visible on any case because
  // insuranceTypes is empty.
  out.push({
    id: uid(),
    carrierCode: 'AIA',
    name: 'AIA — New Business Pod (ทีม 3 คน)',
    emails: [
      'newbiz-lead@aia.co.th',
      'newbiz-ops@aia.co.th',
      'newbiz-backup@aia.co.th',
    ],
    department: 'new_business',
    insuranceTypes: [],
    isDefault: false,
    active: true,
  })
  // AIG (non-life carrier) — baseline groups + CAR / construction-engineering desk
  // matching the "ขอข้อเสนอราคา CAR (งานก่อสร้าง)" template.
  out.push({
    id: uid(),
    carrierCode: 'AIG',
    name: 'AIG — New Business',
    emails: ['newbiz@aig.co.th'],
    department: 'new_business',
    insuranceTypes: [],
    isDefault: true,
    active: true,
  })
  out.push({
    id: uid(),
    carrierCode: 'AIG',
    name: 'AIG — Underwriting',
    emails: ['underwriting@aig.co.th'],
    department: 'underwriting',
    insuranceTypes: [],
    isDefault: true,
    active: true,
  })
  out.push({
    id: uid(),
    carrierCode: 'AIG',
    name: 'AIG CAR / Engineering — Quotation Desk (ทีม 3 คน)',
    emails: [
      'car-quote@aig.co.th',
      'engineering-uw@aig.co.th',
      'project-risk@aig.co.th',
    ],
    department: 'new_business',
    insuranceTypes: ['fire', 'other'],
    isDefault: true,
    active: true,
  })
  out.push({
    id: uid(),
    carrierCode: 'AIG',
    name: 'AIG Motor — Quotation Desk',
    emails: ['motor-quote@aig.co.th', 'motor-ops@aig.co.th'],
    department: 'new_business',
    insuranceTypes: ['motor'],
    isDefault: true,
    active: true,
  })
  out.push({
    id: uid(),
    carrierCode: 'AIG',
    name: 'AIG Travel — Broker Desk',
    emails: ['travel@aig.co.th'],
    department: 'new_business',
    insuranceTypes: ['travel'],
    isDefault: true,
    active: true,
  })

  // TLI underwriting team — 4 people who all need to see the same case.
  // Shows up on any TLI case using an underwriting-template (e.g. "สอบถามผลพิจารณา").
  out.push({
    id: uid(),
    carrierCode: 'TLI',
    name: 'TLI — Underwriting Committee (ทีม 4 คน)',
    emails: [
      'uw-chair@thailife.com',
      'uw-medical@thailife.com',
      'uw-finance@thailife.com',
      'uw-secretary@thailife.com',
    ],
    department: 'underwriting',
    insuranceTypes: [],
    isDefault: false,
    active: true,
  })

  // Extra AIA groups so the per-carrier search box surfaces (threshold = >3
  // matching groups). Each targets a different product line.
  out.push({
    id: uid(),
    carrierCode: 'AIA',
    name: 'AIA Life — Senior Underwriting',
    emails: ['life-senior-uw@aia.co.th', 'life-cm@aia.co.th'],
    department: 'new_business',
    insuranceTypes: ['life'],
    isDefault: false,
    active: true,
  })
  out.push({
    id: uid(),
    carrierCode: 'AIA',
    name: 'AIA Motor — Quotation Desk',
    emails: ['motor-quote@aia.co.th', 'motor-ops@aia.co.th'],
    department: 'new_business',
    insuranceTypes: ['motor'],
    isDefault: false,
    active: true,
  })
  out.push({
    id: uid(),
    carrierCode: 'AIA',
    name: 'AIA Travel — Broker Desk',
    emails: ['travel-broker@aia.co.th'],
    department: 'new_business',
    insuranceTypes: ['travel'],
    isDefault: false,
    active: true,
  })
  out.push({
    id: uid(),
    carrierCode: 'AIA',
    name: 'AIA — Broker Relations (ทุกประเภท)',
    emails: ['broker-relations@aia.co.th', 'broker-ops@aia.co.th'],
    department: 'new_business',
    insuranceTypes: [],
    isDefault: false,
    active: true,
  })

  // ── Real recipient lists imported from broker's Excel ────────────────────
  // Each row is one carrier's distribution list for that product line.
  // Source: /Email Template/Recipient list.xlsx (6 sheets).
  //
  // `contact@insurehub.co.th` is kept inside each list per the broker's
  // request — it's an internal CC the broker wants on every send. Users can
  // remove it per-carrier via the /carriers UI if they later change policy.
  function parseToList(raw: string): string[] {
    return raw
      .split(/[,;]/)
      .map((e) => e.replace(/ /g, ' ').trim())
      .filter((e) => e.length > 0)
  }

  function addImported(
    sheetName: string,
    insuranceTypes: InsuranceType[],
    rows: Array<{ carrier: string; to: string }>,
    notes?: string,
  ) {
    for (const r of rows) {
      const emails = parseToList(r.to)
      if (!emails.length) continue
      out.push({
        id: uid(),
        carrierCode: r.carrier,
        name: `${r.carrier} — ${sheetName}`,
        emails,
        department: 'new_business',
        insuranceTypes,
        isDefault: true,
        active: true,
        notes,
      })
    }
  }

  // ─── ประกันขนส่ง (Marine / Cargo) ──────────────────────────────────────
  addImported('ประกันขนส่ง', ['marine'], [
    { carrier: 'ALLZ', to: 'agency.a@allianz.co.th,thanon.k@allianz.co.th,contact@insurehub.co.th' },
    { carrier: 'IND', to: 'xb_utain.j@tgh.co.th,xb_teerapong.p@tgh.co.th,xb_anchalee.p@tgh.co.th,contact@insurehub.co.th' },
    { carrier: 'AIOI', to: 'absp1@aioibkkins.co.th,contact@insurehub.co.th' },
    { carrier: 'BKI', to: 'suebsawad.s@bangkokinsurance.com,Wanwimol@bangkokinsurance.com,contact@insurehub.co.th' },
    { carrier: 'TIP', to: 'titirats@dhipaya.co.th,taksaons@dhipaya.co.th,contact@insurehub.co.th' },
    { carrier: 'AXA', to: 'marine&tradecredit@axa.co.th,distribution2_salesteam3@axa.co.th,contact@insurehub.co.th' },
    { carrier: 'MSIG', to: 'th_msignt@th.msig-asia.com,contact@insurehub.co.th' },
    { carrier: 'ERGO', to: 'contact_center@ergo.co.th' },
    { carrier: 'TPB', to: 'tpb_upcnpt@thaipaiboon.com,contact@insurehub.co.th' },
    { carrier: 'CHUBB', to: 'atch.shalasonti@chubb.com,thunrada.jitsuraphol@chubb.com,Chubb.BKKC@chubb.com,contact@insurehub.co.th' },
    { carrier: 'TOK', to: 'ratchapluek@tokiomarinesafety.co.th,contact@insurehub.co.th' },
    { carrier: 'KPI', to: 'mkt.broker@kpi.co.th,korawan.a@kpi.co.th,contact@insurehub.co.th' },
    { carrier: 'VIB', to: 'pr2_nonmotor@viriyah.co.th,contact@insurehub.co.th' },
    { carrier: 'MTI', to: 'tippawan.n@muangthaiinsurance.com,Auto-insurance-broker-business-NON-TQM@muangthaiinsurance.com,contact@insurehub.co.th' },
  ])

  // ─── ประกันกลุ่มสุขภาพ (Group Health) ──────────────────────────────────
  addImported('ประกันกลุ่มสุขภาพ', ['group_health'], [
    { carrier: 'MTI', to: 'tippawan.n@muangthaiinsurance.com,Auto-insurance-broker-business-NON-TQM@muangthaiinsurance.com,suphitcha.i@muangthaiinsurance.com' },
    { carrier: 'TIP', to: 'titirats@dhipaya.co.th,taksaons@dhipaya.co.th' },
    { carrier: 'BKI', to: 'suebsawad.s@bangkokinsurance.com,Jeerawan.s@bangkokinsurance.com' },
    { carrier: 'AXA', to: 'sasipa.wo@axa.co.th,distribution2_salesteam3@axa.co.th' },
    { carrier: 'CHUBBL', to: 'uwgroup@chubb.com' },
    { carrier: 'BLA', to: 'aonticha.ruj@bangkoklife.com' },
    { carrier: 'TLI', to: 'suwat.mon@thailife.com,watcharanan.sin@thailife.com' },
    { carrier: 'SELIFE', to: 'SELICBROKER@tgh.co.th,pongsakorn.s@tgh.co.th' },
    { carrier: 'KTAXA', to: 'norarudee.kae@krungthai-axa.co.th,wikanda.wongsason@krungthai-axa.co.th,group_admin@krungthai-axa.co.th,siriporn.intawichai@krungthai-axa.co.th,thanapat.boonklang@krungthai-axa.co.th,juthamas.kon@krungthai-axa.co.th' },
    { carrier: 'TOK', to: 'phuriwat.liu@tokiomarinelife.co.th' },
    { carrier: 'ALLZ', to: 'Nattawan.k@allianz.co.th' },
    { carrier: 'VIB', to: 'pr2_nonmotor@viriyah.co.th' },
  ])

  // ─── ประกันกลุ่ม - บ.ประกันชีวิต (Group Life) ──────────────────────────
  addImported('ประกันกลุ่ม Life', ['group_life'], [
    { carrier: 'BLA', to: 'Yingroj.tri@bangkoklife.com,aonticha.ruj@bangkoklife.com' },
    { carrier: 'TLIFE', to: 'Nuengruetai.p@tlife.co.th,thuntanit.w@tlife.co.th' },
    { carrier: 'SELIFE', to: 'SELICBROKER@tgh.co.th,pongsakorn.s@tgh.co.th' },
    { carrier: 'TML', to: 'phuriwat.liu@tokiomarinelife.co.th,parunyu.eia@tokiomarinelife.co.th' },
    { carrier: 'TLI', to: 'suwat.mon@thailife.com,watcharanan.sin@thailife.com' },
    { carrier: 'KTAXA', to: 'norarudee.kae@krungthai-axa.co.th,wikanda.wongsason@krungthai-axa.co.th,group_admin@krungthai-axa.co.th,siriporn.intawichai@krungthai-axa.co.th,thanapat.boonklang@krungthai-axa.co.th,juthamas.kon@krungthai-axa.co.th' },
  ])

  // ─── ประกันรถยนต์ (Motor) ──────────────────────────────────────────────
  addImported('ประกันรถยนต์', ['motor'], [
    { carrier: 'TPB', to: 'tpb_upcnpt@thaipaiboon.com' },
    { carrier: 'AXA', to: 'DSU@axa.co.th,distribution2_salesteam3@axa.co.th' },
    { carrier: 'AIG', to: 'brokercare@aig.com' },
    { carrier: 'CHUBBL', to: 'uw_quotation@chubb.com' },
    { carrier: 'TNI', to: 'Thanon.Per@thanachart.co.th,SP000002@thanachart.co.th,SP000006@thanachart.co.th,SP000008@thanachart.co.th' },
    { carrier: 'FALCON', to: 'fci_motofleet@falconinsurance.co.th' },
    { carrier: 'ALLZ', to: 'agency.a@allianz.co.th' },
    { carrier: 'CHUBB', to: 'Chubb.BKKC@chubb.com' },
    { carrier: 'MSIG', to: 'Th_msignt@th.msig-asia.com' },
    { carrier: 'KPI', to: 'Mkt.broker@kpi.co.th' },
    { carrier: 'BKI', to: 'suebsawad.s@bangkokinsurance.com' },
    { carrier: 'BUI', to: 'kittichon.p@bui.co.th' },
    { carrier: 'IND', to: 'xb_teerapong.p@tgh.co.th,xb_anchalee.p@tgh.co.th' },
    { carrier: 'TIP', to: 'sutthipongb@dhipaya.co.th,suphawans@dhipaya.co.th' },
    { carrier: 'NAVAKIJ', to: 'telebroker@navakij.co.th' },
    { carrier: 'ERGO', to: 'bowornlak.su@ergo.co.th,Broker@ergo.co.th,d0060019312@munichre.com' },
    { carrier: 'VIB', to: 'pr2_insure@viriyah.co.th' },
    { carrier: 'AIOI', to: 'absp1@aioibkkins.co.th' },
    { carrier: 'TOK', to: 'ratchapluek@tokiomarinesafety.co.th' },
    { carrier: 'SOMPO', to: 'Bangkok1@sompo.co.th' },
  ])

  // ─── พ.ร.บ. (Compulsory Motor Insurance) ───────────────────────────────
  // Seeded from the same desks as voluntary motor because CMI is handled by
  // the motor team at most Thai carriers. The user can edit these per-carrier
  // in /carriers if a specific carrier maintains a separate พ.ร.บ. desk.
  addImported('พ.ร.บ.', ['cmi'], [
    { carrier: 'TPB', to: 'tpb_upcnpt@thaipaiboon.com' },
    { carrier: 'AXA', to: 'DSU@axa.co.th,distribution2_salesteam3@axa.co.th' },
    { carrier: 'AIG', to: 'brokercare@aig.com' },
    { carrier: 'TNI', to: 'Thanon.Per@thanachart.co.th,SP000002@thanachart.co.th' },
    { carrier: 'FALCON', to: 'fci_motofleet@falconinsurance.co.th' },
    { carrier: 'ALLZ', to: 'agency.a@allianz.co.th' },
    { carrier: 'CHUBB', to: 'Chubb.BKKC@chubb.com' },
    { carrier: 'MSIG', to: 'Th_msignt@th.msig-asia.com' },
    { carrier: 'KPI', to: 'Mkt.broker@kpi.co.th' },
    { carrier: 'BKI', to: 'suebsawad.s@bangkokinsurance.com' },
    { carrier: 'BUI', to: 'kittichon.p@bui.co.th' },
    { carrier: 'IND', to: 'xb_teerapong.p@tgh.co.th,xb_anchalee.p@tgh.co.th' },
    { carrier: 'TIP', to: 'sutthipongb@dhipaya.co.th,suphawans@dhipaya.co.th' },
    { carrier: 'NAVAKIJ', to: 'telebroker@navakij.co.th' },
    { carrier: 'ERGO', to: 'bowornlak.su@ergo.co.th,Broker@ergo.co.th' },
    { carrier: 'VIB', to: 'pr2_insure@viriyah.co.th' },
    { carrier: 'AIOI', to: 'absp1@aioibkkins.co.th' },
    { carrier: 'TOK', to: 'ratchapluek@tokiomarinesafety.co.th' },
    { carrier: 'SOMPO', to: 'Bangkok1@sompo.co.th' },
    { carrier: 'MTI', to: 'tippawan.n@muangthaiinsurance.com,Auto-insurance-broker-business-NON-TQM@muangthaiinsurance.com' },
  ], AUTO_SEED_NOTE)

  // ─── ประกันสุขภาพ (Individual Health) ──────────────────────────────────
  // Individual health typically routes to the same desks as group health at
  // most carriers. The broker can split these later if a carrier opens a
  // dedicated individual-health desk.
  addImported('ประกันสุขภาพ (รายบุคคล)', ['health'], [
    { carrier: 'AIA', to: 'newbiz@aia.co.th,underwriting@aia.co.th' },
    { carrier: 'BLA', to: 'aonticha.ruj@bangkoklife.com' },
    { carrier: 'BKI', to: 'suebsawad.s@bangkokinsurance.com,Jeerawan.s@bangkokinsurance.com' },
    { carrier: 'AXA', to: 'sasipa.wo@axa.co.th,distribution2_salesteam3@axa.co.th' },
    { carrier: 'CHUBBL', to: 'uwgroup@chubb.com' },
    { carrier: 'TIP', to: 'titirats@dhipaya.co.th,taksaons@dhipaya.co.th' },
    { carrier: 'MTI', to: 'tippawan.n@muangthaiinsurance.com,suphitcha.i@muangthaiinsurance.com' },
    { carrier: 'TLI', to: 'suwat.mon@thailife.com,watcharanan.sin@thailife.com' },
    { carrier: 'KTAXA', to: 'norarudee.kae@krungthai-axa.co.th,group_admin@krungthai-axa.co.th' },
    { carrier: 'TML', to: 'phuriwat.liu@tokiomarinelife.co.th' },
    { carrier: 'ALLZ', to: 'Nattawan.k@allianz.co.th' },
    { carrier: 'MSIG', to: 'th_msignt@th.msig-asia.com' },
    { carrier: 'AIG', to: 'brokercare@aig.com' },
  ], AUTO_SEED_NOTE)

  // ─── PA — ประกันอุบัติเหตุส่วนบุคคล ────────────────────────────────────
  // PA typically goes to the carrier's "miscellaneous" / non-motor underwriting
  // desk. Carriers that don't offer PA standalone are omitted.
  addImported('ประกันอุบัติเหตุ (PA)', ['pa'], [
    { carrier: 'AIA', to: 'newbiz@aia.co.th' },
    { carrier: 'BLA', to: 'application@bla.co.th' },
    { carrier: 'BKI', to: 'suebsawad.s@bangkokinsurance.com' },
    { carrier: 'TIP', to: 'titirats@dhipaya.co.th,taksaons@dhipaya.co.th' },
    { carrier: 'AXA', to: 'DSU@axa.co.th,distribution2_salesteam3@axa.co.th' },
    { carrier: 'MSIG', to: 'th_msignt@th.msig-asia.com' },
    { carrier: 'AIG', to: 'brokercare@aig.com' },
    { carrier: 'MTI', to: 'tippawan.n@muangthaiinsurance.com' },
    { carrier: 'CHUBB', to: 'Chubb.BKKC@chubb.com' },
    { carrier: 'ALLZ', to: 'agency.a@allianz.co.th' },
    { carrier: 'ERGO', to: 'bu6@ergo.co.th,Broker@ergo.co.th' },
    { carrier: 'KPI', to: 'mkt.broker@kpi.co.th,korawan.a@kpi.co.th' },
    { carrier: 'SOMPO', to: 'Bangkok1@sompo.co.th' },
    { carrier: 'AIOI', to: 'absp1@aioibkkins.co.th' },
    { carrier: 'TPB', to: 'tpb_upcnpt@thaipaiboon.com' },
    { carrier: 'VIB', to: 'pr2_nonmotor@viriyah.co.th' },
  ], AUTO_SEED_NOTE)

  // ─── CI — โรคร้ายแรง ────────────────────────────────────────────────────
  // CI is sold as a life-insurance rider so it routes through the life
  // carriers' new-business / underwriting teams.
  addImported('โรคร้ายแรง (CI)', ['ci'], [
    { carrier: 'AIA', to: 'newbiz@aia.co.th,underwriting@aia.co.th' },
    { carrier: 'TLI', to: 'newpolicy@thailife.com,suwat.mon@thailife.com' },
    { carrier: 'MTL', to: 'newcase@muangthai.co.th,underwriting@muangthai.co.th' },
    { carrier: 'BLA', to: 'application@bla.co.th,Yingroj.tri@bangkoklife.com' },
    { carrier: 'KTAXA', to: 'norarudee.kae@krungthai-axa.co.th,siriporn.intawichai@krungthai-axa.co.th' },
    { carrier: 'TML', to: 'phuriwat.liu@tokiomarinelife.co.th,parunyu.eia@tokiomarinelife.co.th' },
    { carrier: 'SELIFE', to: 'SELICBROKER@tgh.co.th,pongsakorn.s@tgh.co.th' },
    { carrier: 'TLIFE', to: 'Nuengruetai.p@tlife.co.th,thuntanit.w@tlife.co.th' },
    { carrier: 'AXA', to: 'sasipa.wo@axa.co.th' },
    { carrier: 'CHUBBL', to: 'uwgroup@chubb.com' },
    { carrier: 'ALL', to: 'newpolicy@allianz.co.th,uw@allianz.co.th' },
  ], AUTO_SEED_NOTE)

  // ─── ประกันการเดินทาง (Travel) ─────────────────────────────────────────
  // Travel sits at most non-life carriers' travel desk or the broker channel
  // generic address.
  addImported('ประกันการเดินทาง', ['travel'], [
    { carrier: 'DHA', to: 'newcase@dhipaya.co.th,underwriting@dhipaya.co.th' },
    { carrier: 'TIP', to: 'titirats@dhipaya.co.th,taksaons@dhipaya.co.th' },
    { carrier: 'AIG', to: 'brokercare@aig.com' },
    { carrier: 'MSIG', to: 'th_msignt@th.msig-asia.com' },
    { carrier: 'AXA', to: 'DSU@axa.co.th,distribution2_salesteam3@axa.co.th' },
    { carrier: 'BKI', to: 'suebsawad.s@bangkokinsurance.com' },
    { carrier: 'KPI', to: 'mkt.broker@kpi.co.th' },
    { carrier: 'ALLZ', to: 'agency.a@allianz.co.th' },
    { carrier: 'AIOI', to: 'absp1@aioibkkins.co.th' },
    { carrier: 'CHUBB', to: 'Chubb.BKKC@chubb.com' },
    { carrier: 'ERGO', to: 'bu6@ergo.co.th,Broker@ergo.co.th' },
    { carrier: 'MTI', to: 'tippawan.n@muangthaiinsurance.com' },
    { carrier: 'TPB', to: 'tpb_upcnpt@thaipaiboon.com' },
    { carrier: 'SOMPO', to: 'Bangkok1@sompo.co.th' },
  ], AUTO_SEED_NOTE)

  // ─── ประกันไฟ IAR (Fire / IAR) ─────────────────────────────────────────
  addImported('ประกันไฟ / IAR', ['fire'], [
    { carrier: 'ALLZ', to: 'agency.a@allianz.co.th' },
    { carrier: 'IND', to: 'xb_teerapong.p@tgh.co.th,xb_anchalee.p@tgh.co.th' },
    { carrier: 'AIOI', to: 'absp1@aioibkkins.co.th' },
    { carrier: 'AXA', to: 'axacl.uwnewbiz@axa.co.th,distribution2_salesteam3@axa.co.th' },
    { carrier: 'MSIG', to: 'Th_msignt@th.msig-asia.com' },
    { carrier: 'MTI', to: 'tippawan.n@muangthaiinsurance.com,Auto-insurance-broker-business-NON-TQM@muangthaiinsurance.com' },
    { carrier: 'KPI', to: 'mkt.broker@kpi.co.th,korawan.a@kpi.co.th' },
    { carrier: 'SOMPO', to: 'rattana.m@sompo.co.th,areeya.t@sompo.co.th,bangkok1@sompo.co.th' },
    { carrier: 'MITTE', to: 'fire.acc@mittare.com,luecha.t@insurehub.co.th' },
    { carrier: 'BUI', to: 'kittichon.p@bui.co.th' },
    { carrier: 'TPB', to: 'tpb_upcnpt@thaipaiboon.com' },
    { carrier: 'TOK', to: 'ratchapluek@tokiomarinesafety.co.th' },
    { carrier: 'FALCON', to: 'nattawuts@falconinsurance.co.th,KankamonK@falconinsurance.co.th' },
    { carrier: 'AIG', to: 'brokercare@aig.com' },
    { carrier: 'CHUBBL', to: 'CBAFUWN_Agent@chubb.com' },
    { carrier: 'VIB', to: 'pr2_nonmotor@viriyah.co.th' },
    { carrier: 'BKI', to: 'suebsawad.s@bangkokinsurance.com' },
    { carrier: 'TIP', to: 'woraweeb@dhipaya.co.th,taksaons@dhipaya.co.th' },
    { carrier: 'NAVAKIJ', to: 'telebroker@navakij.co.th' },
    { carrier: 'ERGO', to: 'bu6@ergo.co.th' },
  ])

  // ─── ประกันการก่อสร้าง (Construction / CAR) ────────────────────────────
  addImported('ประกันการก่อสร้าง / CAR', ['fire'], [
    { carrier: 'ALLZ', to: 'agency.a@allianz.co.th,contact@insurehub.co.th' },
    { carrier: 'CHUBBL', to: 'CBAFUWN_Agent@chubb.com' },
    { carrier: 'IND', to: 'xb_teerapong.p@tgh.co.th,xb_anchalee.p@tgh.co.th,contact@insurehub.co.th' },
    { carrier: 'AIOI', to: 'absp1@aioibkkins.co.th,contact@insurehub.co.th' },
    { carrier: 'BKI', to: 'suebsawad.s@bangkokinsurance.com,contact@insurehub.co.th' },
    { carrier: 'TIP', to: 'titirats@dhipaya.co.th,taksaons@dhipaya.co.th,contact@insurehub.co.th' },
    { carrier: 'AXA', to: 'DSU@axa.co.th,distribution2_salesteam3@axa.co.th,contact@insurehub.co.th' },
    { carrier: 'MSIG', to: 'th_msignt@th.msig-asia.com,contact@insurehub.co.th' },
    { carrier: 'ERGO', to: 'contact_center@ergo.co.th,bu6@ergo.co.th' },
    { carrier: 'MTI', to: 'tippawan.n@muangthaiinsurance.com,Auto-insurance-broker-business-NON-TQM@muangthaiinsurance.com,contact@insurehub.co.th' },
    { carrier: 'KPI', to: 'mkt.broker@kpi.co.th,korawan.a@kpi.co.th,contact@insurehub.co.th' },
    { carrier: 'VIB', to: 'pr2_nonmotor@viriyah.co.th,contact@insurehub.co.th' },
    { carrier: 'SOMPO', to: 'nakhonpathom_branch@sompo.co.th,contact@insurehub.co.th' },
    { carrier: 'MITTE', to: 'fire.acc@mittare.com,contact@insurehub.co.th' },
    { carrier: 'BUI', to: 'kittichon.p@bui.co.th,contact@insurehub.co.th' },
    { carrier: 'TPB', to: 'tpb_upcnpt@thaipaiboon.com,contact@insurehub.co.th' },
    { carrier: 'NAVAKIJ', to: 'telebroker@navakij.co.th,contact@insurehub.co.th' },
    { carrier: 'TOK', to: 'ratchapluek@tokiomarinesafety.co.th,contact@insurehub.co.th' },
    { carrier: 'FALCON', to: 'nattawuts@falconinsurance.co.th,KankamonK@falconinsurance.co.th,contact@insurehub.co.th' },
  ])

  // ─── Motor sub-teams (Quotation Desk / Senior UW / Manager Escalation) ─
  // 3 extra inferred groups per motor carrier so the picker shows enough rows
  // to surface the per-card search box. Flagged AUTO_SEED_NOTE — the broker
  // confirms or edits the addresses per relationship.
  const motorCarriers: Array<{ code: string; quoteEmail: string; uwEmail: string; mgrEmail: string }> = [
    { code: 'TPB',     quoteEmail: 'tpb_quote@thaipaiboon.com',         uwEmail: 'tpb_uw@thaipaiboon.com',         mgrEmail: 'tpb_mgr@thaipaiboon.com' },
    { code: 'AXA',     quoteEmail: 'motor.quote@axa.co.th',             uwEmail: 'motor.uw@axa.co.th',             mgrEmail: 'motor.cm@axa.co.th' },
    { code: 'AIG',     quoteEmail: 'motor.quote@aig.com',               uwEmail: 'motor.uw@aig.com',               mgrEmail: 'motor.cm@aig.com' },
    { code: 'CHUBBL',  quoteEmail: 'motor.quote@chubb.com',             uwEmail: 'motor.uw@chubb.com',             mgrEmail: 'motor.cm@chubb.com' },
    { code: 'TNI',     quoteEmail: 'motor.quote@thanachart.co.th',      uwEmail: 'motor.uw@thanachart.co.th',      mgrEmail: 'motor.cm@thanachart.co.th' },
    { code: 'FALCON',  quoteEmail: 'motor.quote@falconinsurance.co.th', uwEmail: 'motor.uw@falconinsurance.co.th', mgrEmail: 'motor.cm@falconinsurance.co.th' },
    { code: 'ALLZ',    quoteEmail: 'motor.quote@allianz.co.th',         uwEmail: 'motor.uw@allianz.co.th',         mgrEmail: 'motor.cm@allianz.co.th' },
    { code: 'CHUBB',   quoteEmail: 'motor.quote@chubb.com',             uwEmail: 'motor.uw@chubb.com',             mgrEmail: 'motor.cm@chubb.com' },
    { code: 'MSIG',    quoteEmail: 'motor.quote@th.msig-asia.com',      uwEmail: 'motor.uw@th.msig-asia.com',      mgrEmail: 'motor.cm@th.msig-asia.com' },
    { code: 'KPI',     quoteEmail: 'motor.quote@kpi.co.th',             uwEmail: 'motor.uw@kpi.co.th',             mgrEmail: 'motor.cm@kpi.co.th' },
    { code: 'BKI',     quoteEmail: 'motor.quote@bangkokinsurance.com',  uwEmail: 'motor.uw@bangkokinsurance.com',  mgrEmail: 'motor.cm@bangkokinsurance.com' },
    { code: 'BUI',     quoteEmail: 'motor.quote@bui.co.th',             uwEmail: 'motor.uw@bui.co.th',             mgrEmail: 'motor.cm@bui.co.th' },
    { code: 'IND',     quoteEmail: 'motor.quote@tgh.co.th',             uwEmail: 'motor.uw@tgh.co.th',             mgrEmail: 'motor.cm@tgh.co.th' },
    { code: 'TIP',     quoteEmail: 'motor.quote@dhipaya.co.th',         uwEmail: 'motor.uw@dhipaya.co.th',         mgrEmail: 'motor.cm@dhipaya.co.th' },
    { code: 'NAVAKIJ', quoteEmail: 'motor.quote@navakij.co.th',         uwEmail: 'motor.uw@navakij.co.th',         mgrEmail: 'motor.cm@navakij.co.th' },
    { code: 'ERGO',    quoteEmail: 'motor.quote@ergo.co.th',            uwEmail: 'motor.uw@ergo.co.th',            mgrEmail: 'motor.cm@ergo.co.th' },
    { code: 'VIB',     quoteEmail: 'motor.quote@viriyah.co.th',         uwEmail: 'motor.uw@viriyah.co.th',         mgrEmail: 'motor.cm@viriyah.co.th' },
    { code: 'AIOI',    quoteEmail: 'motor.quote@aioibkkins.co.th',      uwEmail: 'motor.uw@aioibkkins.co.th',      mgrEmail: 'motor.cm@aioibkkins.co.th' },
    { code: 'TOK',     quoteEmail: 'motor.quote@tokiomarinesafety.co.th', uwEmail: 'motor.uw@tokiomarinesafety.co.th', mgrEmail: 'motor.cm@tokiomarinesafety.co.th' },
    { code: 'SOMPO',   quoteEmail: 'motor.quote@sompo.co.th',           uwEmail: 'motor.uw@sompo.co.th',           mgrEmail: 'motor.cm@sompo.co.th' },
  ]
  for (const c of motorCarriers) {
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Motor — Quotation Desk`,
      emails: [c.quoteEmail],
      department: 'new_business',
      insuranceTypes: ['motor'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Motor — Booking / Reservation`,
      emails: [c.quoteEmail.replace('quote', 'booking')],
      department: 'new_business',
      insuranceTypes: ['motor'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Motor — Senior Underwriting`,
      emails: [c.uwEmail],
      department: 'underwriting',
      insuranceTypes: ['motor'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Motor — Medical UW`,
      emails: [c.uwEmail.replace('uw', 'medical-uw')],
      department: 'underwriting',
      insuranceTypes: ['motor'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Motor — Manager / CM Escalation`,
      emails: [c.mgrEmail],
      department: 'other',
      insuranceTypes: ['motor'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
  }

  // ─── Fire / IAR sub-teams ──────────────────────────────────────────────
  const fireCarriers: Array<{ code: string; quoteEmail: string; uwEmail: string; mgrEmail: string }> = [
    { code: 'ALLZ',    quoteEmail: 'fire.quote@allianz.co.th',          uwEmail: 'fire.uw@allianz.co.th',          mgrEmail: 'fire.cm@allianz.co.th' },
    { code: 'IND',     quoteEmail: 'fire.quote@tgh.co.th',              uwEmail: 'fire.uw@tgh.co.th',              mgrEmail: 'fire.cm@tgh.co.th' },
    { code: 'AIOI',    quoteEmail: 'fire.quote@aioibkkins.co.th',       uwEmail: 'fire.uw@aioibkkins.co.th',       mgrEmail: 'fire.cm@aioibkkins.co.th' },
    { code: 'AXA',     quoteEmail: 'fire.quote@axa.co.th',              uwEmail: 'fire.uw@axa.co.th',              mgrEmail: 'fire.cm@axa.co.th' },
    { code: 'MSIG',    quoteEmail: 'fire.quote@th.msig-asia.com',       uwEmail: 'fire.uw@th.msig-asia.com',       mgrEmail: 'fire.cm@th.msig-asia.com' },
    { code: 'MTI',     quoteEmail: 'fire.quote@muangthaiinsurance.com', uwEmail: 'fire.uw@muangthaiinsurance.com', mgrEmail: 'fire.cm@muangthaiinsurance.com' },
    { code: 'KPI',     quoteEmail: 'fire.quote@kpi.co.th',              uwEmail: 'fire.uw@kpi.co.th',              mgrEmail: 'fire.cm@kpi.co.th' },
    { code: 'SOMPO',   quoteEmail: 'fire.quote@sompo.co.th',            uwEmail: 'fire.uw@sompo.co.th',            mgrEmail: 'fire.cm@sompo.co.th' },
    { code: 'MITTE',   quoteEmail: 'fire.quote@mittare.com',            uwEmail: 'fire.uw@mittare.com',            mgrEmail: 'fire.cm@mittare.com' },
    { code: 'BUI',     quoteEmail: 'fire.quote@bui.co.th',              uwEmail: 'fire.uw@bui.co.th',              mgrEmail: 'fire.cm@bui.co.th' },
    { code: 'TPB',     quoteEmail: 'fire.quote@thaipaiboon.com',        uwEmail: 'fire.uw@thaipaiboon.com',        mgrEmail: 'fire.cm@thaipaiboon.com' },
    { code: 'TOK',     quoteEmail: 'fire.quote@tokiomarinesafety.co.th', uwEmail: 'fire.uw@tokiomarinesafety.co.th', mgrEmail: 'fire.cm@tokiomarinesafety.co.th' },
    { code: 'FALCON',  quoteEmail: 'fire.quote@falconinsurance.co.th',  uwEmail: 'fire.uw@falconinsurance.co.th',  mgrEmail: 'fire.cm@falconinsurance.co.th' },
    { code: 'AIG',     quoteEmail: 'fire.quote@aig.com',                uwEmail: 'fire.uw@aig.com',                mgrEmail: 'fire.cm@aig.com' },
    { code: 'CHUBBL',  quoteEmail: 'fire.quote@chubb.com',              uwEmail: 'fire.uw@chubb.com',              mgrEmail: 'fire.cm@chubb.com' },
    { code: 'VIB',     quoteEmail: 'fire.quote@viriyah.co.th',          uwEmail: 'fire.uw@viriyah.co.th',          mgrEmail: 'fire.cm@viriyah.co.th' },
    { code: 'BKI',     quoteEmail: 'fire.quote@bangkokinsurance.com',   uwEmail: 'fire.uw@bangkokinsurance.com',   mgrEmail: 'fire.cm@bangkokinsurance.com' },
    { code: 'TIP',     quoteEmail: 'fire.quote@dhipaya.co.th',          uwEmail: 'fire.uw@dhipaya.co.th',          mgrEmail: 'fire.cm@dhipaya.co.th' },
    { code: 'NAVAKIJ', quoteEmail: 'fire.quote@navakij.co.th',          uwEmail: 'fire.uw@navakij.co.th',          mgrEmail: 'fire.cm@navakij.co.th' },
    { code: 'ERGO',    quoteEmail: 'fire.quote@ergo.co.th',             uwEmail: 'fire.uw@ergo.co.th',             mgrEmail: 'fire.cm@ergo.co.th' },
  ]
  for (const c of fireCarriers) {
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Fire / IAR — Quotation Desk`,
      emails: [c.quoteEmail],
      department: 'new_business',
      insuranceTypes: ['fire'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Fire / IAR — Booking / Reservation`,
      emails: [c.quoteEmail.replace('quote', 'booking')],
      department: 'new_business',
      insuranceTypes: ['fire'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Fire / IAR — Senior Underwriting`,
      emails: [c.uwEmail],
      department: 'underwriting',
      insuranceTypes: ['fire'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Fire / IAR — Engineering Survey`,
      emails: [c.uwEmail.replace('uw', 'survey')],
      department: 'underwriting',
      insuranceTypes: ['fire'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
    out.push({
      id: uid(),
      carrierCode: c.code,
      name: `${c.code} Fire / IAR — Manager / CM Escalation`,
      emails: [c.mgrEmail],
      department: 'other',
      insuranceTypes: ['fire'],
      isDefault: false,
      active: true,
      notes: AUTO_SEED_NOTE,
    })
  }

  return out
}

export const useCarrierContactsStore = defineStore('carrierContacts', () => {
  // ── State ────────────────────────────────────────────────────────────────
  const groups = ref<CarrierContactGroup[]>([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)

  // ── Index ────────────────────────────────────────────────────────────────
  const byCarrier = computed(() => {
    const map = new Map<string, CarrierContactGroup[]>()
    for (const g of groups.value) {
      const list = map.get(g.carrierCode)
      if (list) list.push(g)
      else map.set(g.carrierCode, [g])
    }
    return map
  })

  function listForCarrier(carrierCode: string): CarrierContactGroup[] {
    return byCarrier.value.get(carrierCode) ?? []
  }

  /**
   * Filter groups for a carrier by (optional) department and insurance type.
   * A group matches an insurance type if its `insuranceTypes` is empty (= any)
   * OR includes the requested type.
   */
  function resolveGroups(
    carrierCode: string,
    opts: { department?: ContactDepartment; insuranceType?: InsuranceType } = {},
  ): CarrierContactGroup[] {
    const all = listForCarrier(carrierCode).filter((g) => g.active)
    return all.filter((g) => {
      if (opts.department && g.department !== opts.department) return false
      if (opts.insuranceType) {
        if (g.insuranceTypes.length && !g.insuranceTypes.includes(opts.insuranceType)) {
          return false
        }
      }
      return true
    })
  }

  // ── Loader ───────────────────────────────────────────────────────────────
  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const all: CarrierContactGroup[] = []
      let page = 1
      // eslint-disable-next-line no-constant-condition
      while (true) {
        const response = await api.get<Paginated<CarrierContactGroup>>(
          `carrier-contact-groups${buildQuery({ page, perPage: 100 })}`,
        )
        all.push(...response.data)
        const meta = response.meta
        if (!meta || page >= meta.last_page) break
        page += 1
      }
      groups.value = all
      loaded.value = true
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load contact groups.'
      throw err
    } finally {
      loading.value = false
    }
  }

  // ── Mutations ────────────────────────────────────────────────────────────
  async function addGroup(g: Omit<CarrierContactGroup, 'id'>): Promise<CarrierContactGroup> {
    const response = await api.post<Single<CarrierContactGroup>>('carrier-contact-groups', g)
    const created = response.data
    groups.value = [...groups.value, created]
    return created
  }

  async function updateGroup(
    id: string,
    patch: Partial<Omit<CarrierContactGroup, 'id'>>,
  ): Promise<void> {
    const response = await api.patch<Single<CarrierContactGroup>>(
      `carrier-contact-groups/${id}`,
      patch,
    )
    const updated = response.data
    groups.value = groups.value.map((g) => (g.id === id ? updated : g))
  }

  async function removeGroup(id: string): Promise<void> {
    await api.delete(`carrier-contact-groups/${id}`)
    groups.value = groups.value.filter((g) => g.id !== id)
  }

  async function removeAllForCarrier(carrierCode: string): Promise<void> {
    // No bulk endpoint yet — delete sequentially. Cheap; few groups per carrier.
    const targets = groups.value.filter((g) => g.carrierCode === carrierCode)
    for (const g of targets) {
      await api.delete(`carrier-contact-groups/${g.id}`)
    }
    groups.value = groups.value.filter((g) => g.carrierCode !== carrierCode)
  }

  return {
    groups,
    loading,
    loaded,
    error,
    byCarrier,
    listForCarrier,
    resolveGroups,
    load,
    addGroup,
    updateGroup,
    removeGroup,
    removeAllForCarrier,
  }
})
