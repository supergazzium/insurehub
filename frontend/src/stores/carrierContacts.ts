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
