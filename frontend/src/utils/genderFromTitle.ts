const FEMALE_PREFIXES = ['นางสาว', 'น.ส.', 'นส.', 'นาง', 'ด.ญ.', 'พญ.', 'ทพญ.', 'mrs', 'ms', 'miss', 'madam']
const MALE_PREFIXES = ['นาย', 'เด็กชาย', 'ด.ช.', 'นพ.', 'ทพ.', 'mr', 'master']
const RANK_PATTERN = /^(ว่าที่\s*)?(พล|พัน|ร้อย|จ่า|สิบ|พ\.|ร\.|จ\.|ส\.|ด\.ต|น\.อ|น\.ท|น\.ต)/u

function startsWith(title: string, prefix: string): boolean {
  if (!title.startsWith(prefix)) return false
  if (!/^[a-z]+$/.test(prefix)) return true
  const next = title.charAt(prefix.length)
  return next === '' || next === '.' || next === ' '
}

export function genderFromTitle(title: string | null | undefined): 'M' | 'F' | null {
  const t = (title ?? '').trim().toLowerCase()
  if (!t) return null
  if (t.includes('หญิง')) return 'F'
  if (FEMALE_PREFIXES.some((p) => startsWith(t, p))) return 'F'
  if (MALE_PREFIXES.some((p) => startsWith(t, p))) return 'M'
  return RANK_PATTERN.test(t) ? 'M' : null
}

export function splitCustomerQuery(query: string): { idCard?: string; firstName?: string; lastName?: string; phone?: string } {
  const q = query.trim()
  if (!q) return {}
  const digits = q.replace(/[\s-]/g, '')
  if (/^\d{13}$/.test(digits)) return { idCard: digits }
  if (/^0\d{8,9}$/.test(digits)) return { phone: digits }
  const parts = q.split(/\s+/)
  return parts.length > 1 ? { firstName: parts[0], lastName: parts.slice(1).join(' ') } : { firstName: q }
}
