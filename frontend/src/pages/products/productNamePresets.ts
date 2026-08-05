// Preset product-name templates keyed by (group, category, subCategory).
// When the operator picks a taxonomy leaf, the create-product form auto-
// fills the Name field with the template — `[???]` marks portions the
// operator needs to fill in, `(TOK)` gets replaced with the carrier's
// short-name (nickname or code) at fill time.
//
// Two leaves intentionally have no preset (Rider "อนุสัญญา" and Non-Motor
// "เบ็ดเตล็ด") — those cover "please specify" cases where the operator
// must name the product from scratch.

export interface PresetKey {
  group: string
  category: string
  subCategory: string
}

/** Table of raw templates. `null` = no preset (leave the field empty). */
const TABLE: Array<{ key: PresetKey; template: string | null }> = [
  // ── LIFE ─────────────────────────────────────────────────────────────
  { key: { group: 'Life', category: 'ประเภทสามัญ', subCategory: 'ประกันตลอดชีพ' },
    template: 'ประกันชีวิตตลอดชีพ [???] (TOK)' },
  { key: { group: 'Life', category: 'ประเภทสามัญ', subCategory: 'ประกันสะสมทรัพย์' },
    template: 'ประกันสะสมทรัพย์ [???] (TOK)' },
  { key: { group: 'Life', category: 'ประเภทสามัญ', subCategory: 'ประกันบำนาญ' },
    template: 'ประกันบำนาญ [???] (TOK)' },
  { key: { group: 'Life', category: 'ประเภทสามัญ', subCategory: 'ชั่วระยะเวลา' },
    template: 'ประกันชีวิตชั่วระยะเวลา [???] (TOK)' },

  // ── PA ───────────────────────────────────────────────────────────────
  { key: { group: 'PA', category: 'ประเภทสามัญ', subCategory: 'ประกันอุบัติเหตุ' },
    template: 'ประกันอุบัติเหตุ PA [???] (TOK)' },

  // ── GROUP (Life) ─────────────────────────────────────────────────────
  { key: { group: 'Group-Life', category: 'ประกันกลุ่ม', subCategory: 'ประกันกลุ่มชีวิต' },
    template: 'ประกันชีวิตกลุ่ม [???] (TOK)' },
  { key: { group: 'Group-Life', category: 'ประกันกลุ่ม', subCategory: 'ประกันกลุ่มMRTA' },
    template: 'ประกันคุ้มครองวงเงินสินเชื่อ [???] (TOK)' },
  { key: { group: 'Group-Life', category: 'ประกันกลุ่ม', subCategory: 'ประกันกลุ่มสุขภาพ' },
    template: 'ประกันสุขภาพกลุ่ม [???] (TOK)' },
  { key: { group: 'Group-Life', category: 'ประกันกลุ่ม', subCategory: 'ประกันกลุ่มอุบัติเหตุ' },
    template: 'ประกันอุบัติเหตุกลุ่ม PA Group [???] (TOK)' },

  // ── RIDER — operator must name it themselves ────────────────────────
  { key: { group: 'Rider', category: 'ประเภทสามัญ', subCategory: 'อนุสัญญา' },
    template: null },

  // ── NON-LIFE GROUP ───────────────────────────────────────────────────
  { key: { group: 'Group-NL', category: 'ประกันกลุ่ม', subCategory: 'ประกันกลุ่มMRTA' },
    template: 'ประกันคุ้มครองวงเงินสินเชื่อ [???] (TOK)' },
  { key: { group: 'Group-NL', category: 'ประกันกลุ่ม', subCategory: 'ประกันกลุ่มสุขภาพ' },
    template: 'ประกันสุขภาพกลุ่ม [???] (TOK)' },
  { key: { group: 'Group-NL', category: 'ประกันกลุ่ม', subCategory: 'ประกันกลุ่มอุบัติเหตุ' },
    template: 'ประกันอุบัติเหตุกลุ่ม PA Group [???] (TOK)' },

  // ── MOTOR ────────────────────────────────────────────────────────────
  { key: { group: 'Motor', category: 'การประกันรถโดยความสมัครใจ', subCategory: 'ป1.' },
    template: 'ประกันรถยนต์ชั้น 1 [ซ่อม???] (TOK)' },
  { key: { group: 'Motor', category: 'การประกันรถโดยความสมัครใจ', subCategory: 'ป2.' },
    template: 'ประกันรถยนต์ชั้น 2 (TOK)' },
  { key: { group: 'Motor', category: 'การประกันรถโดยความสมัครใจ', subCategory: 'ป2Plus' },
    template: 'ประกันรถยนต์ชั้น 2+ [ซ่อม???] (TOK)' },
  { key: { group: 'Motor', category: 'การประกันรถโดยความสมัครใจ', subCategory: 'ป3.' },
    template: 'ประกันรถยนต์ชั้น 3 (TOK)' },
  { key: { group: 'Motor', category: 'การประกันรถโดยความสมัครใจ', subCategory: 'ป3Plus' },
    template: 'ประกันรถยนต์ชั้น 3+ (TOK)' },
  { key: { group: 'Motor', category: 'การประกันรถโดยข้อบังคับแห่งกฏหมาย', subCategory: 'พรบ' },
    template: 'พรบ รถยนต์ (TOK)' },

  // ── NON-MOTOR misc ───────────────────────────────────────────────────
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'ขนส่ง' },
    template: 'ประกันขนส่งสินค้า Carrier (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'Marine' },
    template: 'ประกันภัยทางทะเล Marine (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'ประกันการเดินทาง' },
    template: 'ประกันการเดินทาง TA [???ภายใน/ภายนอก] (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'เบ็ดเตล็ด' },
    template: null },
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'ประกันอุบัติเหตุส่วนบุคคล' },
    template: 'ประกันอุบัติเหตุ PA [???] (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'วิชาชีพ' },
    template: 'ประกันวิชาชีพ [???] (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'สัตว์เลี้ยง' },
    template: 'ประกันสัตว์เลี้ยง [???] (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'สุขภาพ' },
    template: 'ประกันสุขภาพ [???] (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'Public Liability' },
    template: 'ความรับผิดตามกฏหมายต่อบุคคลภายนอก PL [???] (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันภัยเบ็ดเตล็ด', subCategory: 'CPM' },
    template: 'ประกันเครื่องจักร CPM [???] (TOK)' },

  // ── NON-MOTOR fire ───────────────────────────────────────────────────
  { key: { group: 'Non-Motor', category: 'การประกันอัคคีภัย', subCategory: 'อัคคีภัยพื้นฐาน' },
    template: 'ประกันอัคคีภัย มาตรฐาน [???] (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันอัคคีภัย', subCategory: 'อัคคีภัยPackage' },
    template: 'ประกันอัคคีภัย Package [???] (TOK)' },
  { key: { group: 'Non-Motor', category: 'การประกันอัคคีภัย', subCategory: 'อัคคีภัย IAR' },
    template: 'ประกันทรัพย์สิน IAR [???] (TOK)' },

  // ── TAX ──────────────────────────────────────────────────────────────
  { key: { group: 'Tax', category: 'ต่อภาษี', subCategory: 'ต่อภาษี' },
    template: 'ต่อภาษี' },
]

function keyOf(k: PresetKey): string {
  return `${k.group}|${k.category}|${k.subCategory}`
}

const INDEX: Map<string, string | null> = new Map(
  TABLE.map(({ key, template }) => [keyOf(key), template] as const),
)

/**
 * Look up the preset template for a taxonomy leaf. Returns:
 *   - the raw template string when found
 *   - null when the leaf is explicitly "operator must name" (Rider, เบ็ดเตล็ด)
 *   - undefined when the leaf isn't in the table (unknown taxonomy)
 */
export function lookupTemplate(k: PresetKey): string | null | undefined {
  const stored = INDEX.get(keyOf(k))
  return stored === undefined ? undefined : stored
}

/**
 * Substitute the carrier's short-name into the `(TOK)` placeholder. Uses
 * the carrier nickname when present (readability), falls back to the
 * carrier code. Returns the template unchanged if no carrier is given.
 */
export function fillCarrier(template: string, carrierShortName: string | null | undefined): string {
  if (!carrierShortName) return template
  return template.replace(/\(TOK\)/g, `(${carrierShortName})`)
}
