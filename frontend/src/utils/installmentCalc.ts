// Installment calculation engine — implements
// Insurehub_Installment_Calculation_Spec_for_IT_FINAL.docx (17 Sep 2026).
//
// Three modes decide who bears the contract fee (2%) and the interest (1%/mo):
//   A — 0% ไม่มีดอก ไม่มี Fee : customer pays neither; AGENT bears fee + interest
//   B — 0% ไม่มีดอก มี Fee    : customer pays fee; AGENT bears interest
//   C — มีดอก มี Fee          : customer pays fee + interest; agent bears nothing
//
// Money rule: every งวด amount is ROUND_UP (ceiling) after summing its parts;
// there is NO final true-up, so the sum of งวด can slightly exceed the base.
// พ.ร.บ., customer fee and customer interest are ALL loaded onto งวด 1 only.

export type InstallmentMode = 'A' | 'B' | 'C'

export interface InstallmentConfig {
  downRate: number            // default 0.25 — only for 5–10 งวด
  contractFeeRate: number     // default 0.02
  interestRatePerMonth: number // default 0.01
}

export const DEFAULT_INSTALLMENT_CONFIG: InstallmentConfig = {
  downRate: 0.25,
  contractFeeRate: 0.02,
  interestRatePerMonth: 0.01,
}

export interface InstallmentLine {
  index: number   // 1-based งวด number
  amount: number  // ceiling-rounded billed amount for this งวด
}

export interface InstallmentResult {
  mode: InstallmentMode
  installmentCount: number
  firstPrincipal: number       // principal assigned to งวด 1 (pre-round)
  compulsoryPremium: number    // พ.ร.บ. billed on งวด 1
  customerFee: number          // fee the customer bears (งวด 1)
  customerInterest: number     // interest the customer bears (งวด 1)
  installments: InstallmentLine[]
  totalCustomerPayment: number // sum of the (rounded) งวด amounts
  agentBearsFee: boolean
  agentBearsInterest: boolean
  agentFeeCost: number         // standard fee the AGENT bears (commission expense)
  agentInterestCost: number    // standard interest the AGENT bears
}

const ceil = (x: number): number => Math.ceil(x - 1e-9) // guard fp noise near integers

/**
 * Compute the installment schedule + agent costs for one policy.
 * @throws Error on out-of-range inputs (count 2–10, main > 0, compulsory >= 0).
 */
export function calcInstallment(
  mainPremium: number,
  compulsoryPremium: number,
  installmentCount: number,
  mode: InstallmentMode,
  config: InstallmentConfig = DEFAULT_INSTALLMENT_CONFIG,
): InstallmentResult {
  const count = Math.floor(installmentCount)
  if (count < 2 || count > 10) throw new Error('installment_count ต้องอยู่ระหว่าง 2–10')
  if (!(mainPremium > 0)) throw new Error('main_premium ต้องมากกว่า 0')
  const compulsory = compulsoryPremium >= 0 ? compulsoryPremium : 0

  // 4.1 first principal — no 25% down for 2–4 งวด (งวด 1 would be too small)
  const firstPrincipal = count <= 4 ? mainPremium / count : mainPremium * config.downRate

  // Standard fee / interest at the configured rates (always computed — the
  // agent-cost side needs them even when the customer pays 0).
  const standardFee = mainPremium * config.contractFeeRate
  const standardInterest = mainPremium * config.interestRatePerMonth * count

  let customerFee = 0
  let customerInterest = 0
  let agentFeeCost = 0
  let agentInterestCost = 0
  switch (mode) {
    case 'A':
      agentFeeCost = standardFee
      agentInterestCost = standardInterest
      break
    case 'B':
      customerFee = standardFee
      agentInterestCost = standardInterest
      break
    case 'C':
      customerFee = standardFee
      customerInterest = standardInterest
      break
  }

  // 5.x — งวด 1 carries principal + พ.ร.บ. + customer fee + customer interest.
  const first = ceil(firstPrincipal + compulsory + customerFee + customerInterest)
  // 4.3 — งวด 2..N are the flat remaining amount, same for each.
  const rest = ceil((mainPremium - firstPrincipal) / (count - 1))

  const installments: InstallmentLine[] = [{ index: 1, amount: first }]
  for (let i = 2; i <= count; i++) installments.push({ index: i, amount: rest })

  const total = installments.reduce((s, l) => s + l.amount, 0)

  return {
    mode,
    installmentCount: count,
    firstPrincipal,
    compulsoryPremium: compulsory,
    customerFee,
    customerInterest,
    installments,
    totalCustomerPayment: total,
    agentBearsFee: mode === 'A',
    agentBearsInterest: mode === 'A' || mode === 'B',
    agentFeeCost,
    agentInterestCost,
  }
}

export const INSTALLMENT_MODES: { value: InstallmentMode; label: string; hint: string }[] = [
  { value: 'A', label: 'A — 0% ไม่มีดอก ไม่มี Fee', hint: 'ลูกค้าไม่จ่ายดอก/Fee · ตัวแทนรับภาระทั้งหมด' },
  { value: 'B', label: 'B — 0% ไม่มีดอก มี Fee', hint: 'ลูกค้าจ่าย Fee 2% · ตัวแทนรับภาระดอกเบี้ย' },
  { value: 'C', label: 'C — มีดอก มี Fee', hint: 'ลูกค้าจ่าย Fee 2% + ดอกเบี้ย 1%/เดือน' },
]

export function installmentModeLabel(mode: string | null | undefined): string {
  return INSTALLMENT_MODES.find((m) => m.value === mode)?.label ?? (mode ?? '')
}
