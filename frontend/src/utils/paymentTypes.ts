// Payment-type vocabulary shared by the policy edit form (typeOfPaid) and the
// payment-recording modal, so the two never drift apart.
//
// Business distinction that matters (per ops):
//   split       (แบ่งชำระ) — customer pays the CARRIER in installments directly.
//                InsureHub does NOT front any money.
//   installment (ผ่อน)     — InsureHub fronts the premium to the carrier, then
//                the customer repays InsureHub. This carries credit risk for
//                InsureHub, so it's tracked separately from แบ่งชำระ.

export interface PaymentTypeOption {
  value: string
  label: string
  /** True when this type is paid across multiple งวด (installment-style UI). */
  multiInstallment?: boolean
}

export const PAYMENT_TYPE_OPTIONS: PaymentTypeOption[] = [
  { value: 'cash', label: 'เงินสด' },
  { value: 'transfer', label: 'โอนเงิน' },
  { value: 'credit_card', label: 'บัตรเครดิต' },
  { value: 'split', label: 'แบ่งชำระ (กับบริษัทประกันโดยตรง)', multiInstallment: true },
  { value: 'installment', label: 'ผ่อน (InsureHub สำรองจ่าย)', multiInstallment: true },
  { value: 'other', label: 'อื่นๆ' },
]

/** Human label for a stored value; falls back to the raw value (e.g. legacy
 *  numeric codes like "4") so existing data still renders. */
export function paymentTypeLabel(value: string | null | undefined): string {
  if (!value) return ''
  return PAYMENT_TYPE_OPTIONS.find((o) => o.value === value)?.label ?? value
}
