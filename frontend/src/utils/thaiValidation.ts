// Thai-language and Thai-identifier validation helpers used by the public
// agent-registration form.

const THAI_NAME_RE = /^[฀-๿][฀-๿\s]*$/

// Thai juristic-person names include the Latin punctuation you'd expect on a
// company card (dots, ampersands, parentheses, hyphens, digits) alongside
// Thai letters. Enforce at least one Thai character so English-only names
// are rejected, while still accepting realistic legal names like
// "บริษัท เอ.บี.ซี. จำกัด (มหาชน)".
const THAI_JURISTIC_ANY_THAI_RE = /[฀-๿]/
const THAI_JURISTIC_ALLOWED_RE = /^[฀-๿0-9A-Za-z\s.,()&\-\/']+$/

export function isThaiName(value: string): boolean {
  const trimmed = value.trim()
  if (trimmed === '') return false
  return THAI_NAME_RE.test(trimmed)
}

export function isThaiJuristicName(value: string): boolean {
  const trimmed = value.trim()
  if (trimmed === '') return false
  if (!THAI_JURISTIC_ANY_THAI_RE.test(trimmed)) return false
  return THAI_JURISTIC_ALLOWED_RE.test(trimmed)
}

// Thai National ID and Thai juristic-registration number share the same
// 13-digit MOD-11 checksum: multiply digits 1..12 by weights 13..2, sum,
// then (11 - sum % 11) % 10 must equal digit 13.
export function isThaiId13(value: string): boolean {
  const digits = value.replace(/\D/g, '')
  if (digits.length !== 13) return false
  let sum = 0
  for (let i = 0; i < 12; i++) {
    sum += Number(digits[i]) * (13 - i)
  }
  const check = (11 - (sum % 11)) % 10
  return check === Number(digits[12])
}

// Thai mobile numbers: 10 digits, "0" + mobile prefix (6/8/9) + 8 digits.
// Accepts spaces/dashes/parentheses as visual separators.
export function isThaiMobile(value: string): boolean {
  const digits = value.replace(/[\s\-()]/g, '')
  return /^0[689]\d{8}$/.test(digits)
}
