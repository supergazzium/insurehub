/**
 * แปลงจำนวนเงิน (บาท) เป็นข้อความภาษาไทย เช่น 34235 → "สามหมื่นสี่พันสองร้อยสามสิบห้าบาทถ้วน".
 * รองรับทศนิยม (สตางค์): 1234.50 → "...บาทห้าสิบสตางค์".
 * ใช้บนใบเสนอราคา / ใบเตือน ต่อท้ายยอดรวม.
 */
const DIGITS = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า']
const PLACES = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน']

/** อ่านจำนวนเต็ม ≤ 6 หลัก (หน่วยย่อยในหลักล้าน) เป็นคำไทย. */
function readChunk(n: number): string {
  let out = ''
  const s = String(n)
  const len = s.length
  for (let i = 0; i < len; i++) {
    const d = Number(s[i])
    const place = len - i - 1
    if (d === 0) continue
    // หลักสิบ: "ยี่สิบ" (20) และ "สิบ" (ไม่มี "หนึ่งสิบ")
    if (place === 1 && d === 1) { out += PLACES[1]; continue }
    if (place === 1 && d === 2) { out += 'ยี่' + PLACES[1]; continue }
    // หลักหน่วยที่ลงท้าย ถ้าเป็น 1 และจำนวนมากกว่า 10 → "เอ็ด"
    if (place === 0 && d === 1 && len > 1) { out += 'เอ็ด'; continue }
    out += DIGITS[d] + PLACES[place]
  }
  return out
}

/** อ่านจำนวนเต็มใด ๆ (รองรับหลักล้านซ้ำ) เป็นคำไทย. */
function readInteger(n: number): string {
  if (n === 0) return DIGITS[0]
  let out = ''
  // ตัดเป็นกลุ่มละ 6 หลัก (หลักล้าน) จากขวาไปซ้าย
  const millions: number[] = []
  let rest = n
  while (rest > 0) {
    millions.unshift(rest % 1_000_000)
    rest = Math.floor(rest / 1_000_000)
  }
  millions.forEach((chunk, idx) => {
    if (chunk === 0) return
    out += readChunk(chunk)
    // เติม "ล้าน" ให้ทุกกลุ่มที่ไม่ใช่กลุ่มสุดท้าย
    const trailing = millions.length - idx - 1
    out += 'ล้าน'.repeat(trailing)
  })
  return out
}

export function bahtText(amount: number): string {
  if (!Number.isFinite(amount)) return ''
  const neg = amount < 0
  const abs = Math.abs(amount)
  const baht = Math.floor(abs)
  // ปัดสตางค์เป็น 2 ตำแหน่ง
  const satang = Math.round((abs - baht) * 100)

  let text = ''
  if (satang === 0) {
    text = readInteger(baht) + 'บาทถ้วน'
  } else {
    const bahtPart = baht > 0 ? readInteger(baht) + 'บาท' : ''
    text = bahtPart + readInteger(satang) + 'สตางค์'
  }
  return (neg ? 'ลบ' : '') + text
}
