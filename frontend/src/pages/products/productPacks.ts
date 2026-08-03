// Field pack definitions — declares which fields are relevant for which
// product type. Used by ProductCreateModal to hide irrelevant fields and
// warn when a type-change would strand data.
//
// A "pack key" is a compact string derived from (carrierInsureType,
// mainRider, productGroup). See `detectPack()` below for the mapping.
//
// The `fields` set is the whitelist of extra fields that pack shows;
// identity fields (code, name, carrier, main/rider, group, category,
// sub-category) are always visible and NOT listed here.

export type PackKey =
  | 'life-main'
  | 'life-group'
  | 'life-rider'
  | 'motor'
  | 'non-motor'
  | 'tax'
  | 'unknown'

/** Every extra field the wizard can render — the union of all pack fields. */
export type PackField =
  | 'minAge' | 'maxAge' | 'gender'
  | 'minSumAssure' | 'maxSumAssure'
  | 'durationYears' | 'payYears' | 'premiumMode'
  | 'minPremium' | 'maxPremium'
  | 'requireMedical' | 'smokerAccepted' | 'preexistingExcluded'
  | 'coverageClass' | 'vehicleAgeMin' | 'vehicleAgeMax'
  | 'summary' | 'notes'

interface Pack {
  key: PackKey
  labelEn: string
  labelTh: string
  fields: readonly PackField[]
}

export const PACKS: Record<PackKey, Pack> = {
  'life-main': {
    key: 'life-main', labelEn: 'Life / PA — Main', labelTh: 'ประกันชีวิต/PA — Main',
    fields: [
      'minAge', 'maxAge', 'gender',
      'minSumAssure', 'maxSumAssure',
      'durationYears', 'payYears', 'premiumMode',
      'minPremium', 'maxPremium',
      'requireMedical', 'smokerAccepted', 'preexistingExcluded',
      'summary', 'notes',
    ],
  },
  'life-group': {
    key: 'life-group', labelEn: 'Group Life / Group PA', labelTh: 'ประกันกลุ่ม',
    fields: [
      'minAge', 'maxAge',
      'minSumAssure', 'maxSumAssure',
      'durationYears', 'premiumMode',
      'minPremium', 'maxPremium',
      'requireMedical',
      'summary', 'notes',
    ],
  },
  'life-rider': {
    key: 'life-rider', labelEn: 'Life — Rider', labelTh: 'ประกันชีวิต — Rider',
    fields: [
      'minAge', 'maxAge',
      'minSumAssure', 'maxSumAssure',
      'durationYears', 'payYears', 'premiumMode',
      'minPremium', 'maxPremium',
      'summary', 'notes',
    ],
  },
  motor: {
    key: 'motor', labelEn: 'Motor', labelTh: 'ประกันภัยรถยนต์',
    fields: [
      'coverageClass',
      'minSumAssure', 'maxSumAssure',
      'vehicleAgeMin', 'vehicleAgeMax',
      'premiumMode',
      'minPremium', 'maxPremium',
      'summary', 'notes',
    ],
  },
  'non-motor': {
    key: 'non-motor', labelEn: 'Non-Motor (Fire / Travel / Accident)', labelTh: 'ประกันภัยทั่วไป (ไม่ใช่รถยนต์)',
    fields: [
      'minSumAssure', 'maxSumAssure',
      'durationYears', 'premiumMode',
      'minPremium', 'maxPremium',
      'summary', 'notes',
    ],
  },
  tax: {
    key: 'tax', labelEn: 'Tax', labelTh: 'ภาษี',
    fields: [
      'durationYears', 'premiumMode',
      'minPremium', 'maxPremium',
      'summary', 'notes',
    ],
  },
  unknown: {
    key: 'unknown', labelEn: 'Unknown', labelTh: '—',
    fields: [], // no extra fields until the user picks a carrier + group
  },
}

/**
 * Map (carrier insureType, mainRider, product group) → pack key.
 * `productGroup` is the value stored in the DB (e.g. "Life", "PA", "Motor").
 */
export function detectPack(
  carrierInsureType: string,
  mainRider: string,
  productGroup: string,
): PackKey {
  if (carrierInsureType === 'tax') return 'tax'
  if (carrierInsureType === 'life') {
    if (mainRider === 'Rider') return 'life-rider'
    if (productGroup === 'Group-Life') return 'life-group'
    if (mainRider === 'Main') return 'life-main'
  }
  if (carrierInsureType === 'non-life') {
    if (productGroup === 'Motor') return 'motor'
    if (productGroup === 'Non-Motor') return 'non-motor'
    if (productGroup === 'Group-NL') return 'non-motor' // treat group-NL same as non-motor for now
  }
  return 'unknown'
}

export function getPack(key: PackKey): Pack {
  return PACKS[key]
}

export function hasField(key: PackKey, field: PackField): boolean {
  return PACKS[key].fields.includes(field)
}

/**
 * Given old + new pack, return the list of fields present in old but not
 * in new. Those are the values that would be "stranded" if the user
 * proceeds with the type change.
 */
export function strandedFields(oldKey: PackKey, newKey: PackKey): PackField[] {
  const newSet = new Set(PACKS[newKey].fields)
  return PACKS[oldKey].fields.filter((f) => !newSet.has(f))
}
