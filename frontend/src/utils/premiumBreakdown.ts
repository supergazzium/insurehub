// Decompose an ACTUAL paid amount into เบี้ยสุทธิ / อากรแสตมป์ / ภาษีมูลค่าเพิ่ม.
//
// The premium is paid VAT/duty-inclusive (gross). สูตร 1-4 back-solve the net,
// duty and vat FROM that gross — the same formulas the quote uses. When a งวด is
// paid (ผ่อน / แบ่งชำระ) — and the operator may enter any amount, even one whose
// yearly total exceeds the annual premium — each งวด is decomposed from ITS OWN
// paid amount using the chosen formula. This makes the tax split independent of
// the annual figure, so overpayments (interest / fees) still decompose sensibly.

export type TaxFormula = 1 | 2 | 3 | 4

export interface PremiumParts {
  net: number
  duty: number
  vat: number
}

const round2 = (x: number): number => Math.round(x * 100) / 100
const ceilInt = (x: number): number => Math.ceil(x)

/**
 * Back-solve net/duty/vat from a gross (VAT/duty-inclusive) amount, per formula:
 *   1 — full: duty = ceil(net*0.4%), vat = (duty+net)*7% (iterative)
 *   2 — VAT only: net = gross/1.07, no duty
 *   3 — flat duty 20, no vat
 *   4 — flat duty 150, no vat
 * net + duty + vat always === gross (to 2dp).
 */
export function decomposeByFormula(gross: number, formula: TaxFormula): PremiumParts {
  const g = round2(Number(gross) || 0)
  if (g <= 0) return { net: 0, duty: 0, vat: 0 }

  if (formula === 2) {
    const net = round2(g / 1.07)
    return { net, duty: 0, vat: round2(g - net) }
  }
  if (formula === 3) {
    return { net: round2(g - 20), duty: 20, vat: 0 }
  }
  if (formula === 4) {
    return { net: round2(g - 150), duty: 150, vat: 0 }
  }
  // Formula 1 — iterative back-solve (converges to <0.01 baht).
  let premium = g
  let duty = 0
  let vat = 0
  let prev: number
  let i = 0
  do {
    prev = premium
    duty = ceilInt(premium * 0.004)
    vat = (duty + premium) * 0.07
    premium = g - vat - duty
    i++
  } while (Math.abs(premium - prev) >= 0.01 && i < 100)
  const net = round2(premium)
  duty = round2(duty)
  // Absorb rounding into vat so the three parts sum back to the gross exactly.
  return { net, duty, vat: round2(g - net - duty) }
}
