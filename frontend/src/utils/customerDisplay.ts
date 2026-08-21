// Display helpers for the Customer model. Kept here so list, drawer, and
// any future dashboards format identity fields the same way.
//
// The `?` avatar and blank name row on corporate customers was caused by
// the list templates composing name from title+first+last only; corporate
// rows have those empty and rely on `juristicName`. The `IDCARD` column
// missed foreign_individual (passport) and corporate (tax_id) for the same
// reason. Anything reading these fields should go through the helpers here
// so a future new customer type doesn't reintroduce the bug.

import type { CustomerType } from '../stores/customers'

/** Structural shape used by the helpers. Accepts anything with these
 *  fields — the full `Customer` model AND the leaner `CustomerListRow`
 *  both satisfy this without importing either type here. */
interface NameLike {
  customerType: CustomerType | string
  titleTh?: string | null
  firstName?: string | null
  lastName?: string | null
  juristicName?: string | null
}

interface IdLike {
  customerType: CustomerType | string
  idCard?: string | null
  passport?: string | null
  taxId?: string | null
}

/** Canonical display name — falls back to juristicName for corporate. */
export function customerDisplayName(c: NameLike): string {
  if (c.customerType === 'corporate') {
    return (c.juristicName ?? '').trim()
  }
  const parts = [c.titleTh, c.firstName, c.lastName]
    .map((s) => (s ?? '').trim())
    .filter((s) => s !== '')
  return parts.join(' ')
}

/** Initials for the avatar bubble. Corporate uses the first two letters
 *  of the juristic name; person uses first+last initial. Falls back to
 *  `?` only when the customer truly has no name set. */
export function customerInitials(c: NameLike): string {
  if (c.customerType === 'corporate') {
    const name = (c.juristicName ?? '').trim()
    return name.slice(0, 2).toUpperCase() || '?'
  }
  const a = (c.firstName ?? '').trim().charAt(0)
  const b = (c.lastName ?? '').trim().charAt(0)
  return (a + b).toUpperCase() || '?'
}

/** The best "identity number" column value for a customer:
 *  individual → idCard, foreign → passport, corporate → taxId. */
export function customerIdentityNumber(c: IdLike): string {
  if (c.customerType === 'corporate') return (c.taxId ?? '').trim()
  if (c.customerType === 'foreign_individual') return (c.passport ?? '').trim()
  return (c.idCard ?? '').trim()
}

/** Thai display label for the customer_type enum. Unknown values fall
 *  through to the raw enum so bad data stays visible instead of showing
 *  as a dash. */
export function customerTypeLabel(t: CustomerType | string): string {
  switch (t) {
    case 'individual': return 'บุคคลธรรมดา'
    case 'foreign_individual': return 'ชาวต่างชาติ'
    case 'corporate': return 'นิติบุคคล'
    case 'other': return 'อื่นๆ'
    default: return t
  }
}
