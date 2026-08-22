// C-13 — Duration presets per product kind. Consumed by wizard Step 2's
// DurationChip. Values validated against the 515-row seed distribution
// in docs/audit-2026-08-21/05-live-data.md §5 — see B3-wizard-ia.md §3.

import type { DurationPreset } from '../components/DurationChip.vue'

export type WizardKind = 'motor' | 'ctpl' | 'travel' | 'fire' | 'health' | 'pa' | 'life' | 'misc'

export interface DurationConfig {
  presets: DurationPreset[]
  /** Preset key to auto-select when the wizard loads Step 2. `null` = no
   *  auto-selection (operator picks a date manually). */
  defaultKey: string | null
  /** Renders a `<input type="number">` for a custom-year picker next to
   *  the chips. Life products vary too much to enumerate. */
  allowCustomYears: boolean
}

const YEAR_1: DurationPreset = { key: 'y1', label: '1 ปี', value: 1, unit: 'year' }
const YEAR_3: DurationPreset = { key: 'y3', label: '3 ปี', value: 3, unit: 'year' }
const YEAR_5: DurationPreset = { key: 'y5', label: '5 ปี', value: 5, unit: 'year' }

const DAYS: Record<number, DurationPreset> = {
  3: { key: 'd3', label: '3 วัน', value: 3, unit: 'day' },
  5: { key: 'd5', label: '5 วัน', value: 5, unit: 'day' },
  7: { key: 'd7', label: '7 วัน', value: 7, unit: 'day' },
  14: { key: 'd14', label: '14 วัน', value: 14, unit: 'day' },
  30: { key: 'd30', label: '30 วัน', value: 30, unit: 'day' },
}

const CONFIG_BY_KIND: Record<WizardKind, DurationConfig> = {
  motor: { presets: [YEAR_1], defaultKey: 'y1', allowCustomYears: false },
  ctpl: { presets: [YEAR_1], defaultKey: 'y1', allowCustomYears: false },
  travel: { presets: [DAYS[3], DAYS[5], DAYS[7], DAYS[14], DAYS[30]], defaultKey: 'd7', allowCustomYears: false },
  fire: { presets: [YEAR_1, YEAR_3, YEAR_5], defaultKey: 'y1', allowCustomYears: false },
  health: { presets: [YEAR_1], defaultKey: 'y1', allowCustomYears: false },
  pa: { presets: [YEAR_1], defaultKey: 'y1', allowCustomYears: false },
  life: { presets: [YEAR_1], defaultKey: 'y1', allowCustomYears: true },
  misc: { presets: [], defaultKey: null, allowCustomYears: false },
}

/** Resolve the preset config for a wizard-branch kind. Falls back to the
 *  `misc` config (no chips) when kind is unknown so a mis-authored
 *  product doesn't crash the wizard. */
export function durationConfig(kind: string | null | undefined): DurationConfig {
  if (kind && kind in CONFIG_BY_KIND) return CONFIG_BY_KIND[kind as WizardKind]
  return CONFIG_BY_KIND.misc
}
