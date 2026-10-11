// Shared renewal-pipeline action logic, centered on ONE policy row.
//
// Extracted from the per-row modals that used to live in ExpiringSoon.vue so
// the new renewal DETAIL page can own every action inline (no popups), and the
// list can drop the action code entirely. Every mutation optimistically
// updates the passed-in row's stage + timestamp, then callers refetch as they
// see fit.
//
// The composable is created with a Ref<ExpiringPolicy | null>; all actions
// no-op while it's null (page still loading).
import { ref, computed, watch, type Ref } from 'vue'
import {
  requestRenewalQuote, sendRenewalQuote, markRenewalStage, sendRenewalNotice,
  fetchRenewalFollowups, createRenewalFollowup, reopenRenewal,
  fetchRenewalNotices, createRenewalNotice,
  type ExpiringPolicy, type ManualStage, type RenewalFollowup, type FollowupChannel, type FollowupResult,
  type RenewalNotice,
} from '../api/reports'
import {
  fetchCarrierContacts, createCarrierContact, updateCarrierContact, deleteCarrierContact,
  type CarrierContact,
} from '../api/carriers'
import {
  uploadPolicyDocument, fetchPolicyDocuments, downloadPolicyDocument, deletePolicyDocument,
  type PolicyDocumentRow,
} from '../api/policies'
import { fetchTenant } from '../api/tenant'
import { ApiError } from '../api/client'
import type { Policy } from '../stores/policies'
import { renderRenewalNoticePdf } from './useRenewalNoticePdf'
import { decomposeByFormula, type TaxFormula } from '../utils/premiumBreakdown'

export interface RenewalNoticeForm {
  annualPremium: number
  autoSplit: boolean
  formula: TaxFormula
  netPremium: number
  dutyStamp: number
  vat: number
  discount: number
  coverage: number
  premiumMode: 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single'
  dueDate: string
  validUntil: string
  note: string
}

export interface FollowupForm {
  channel: FollowupChannel
  result: FollowupResult
  reasonCode: string
  reasonNote: string
  note: string
  nextFollowupAt: string
}

const PREMIUM_MODE_LABEL: Record<string, string> = {
  annual: 'รายปี', semiannual: 'ราย 6 เดือน', quarterly: 'รายไตรมาส', monthly: 'รายเดือน', single: 'จ่ายครั้งเดียว',
}

function addMonthsIso(iso: string, months: number): string {
  const d = new Date(iso.slice(0, 10) + 'T00:00:00')
  d.setMonth(d.getMonth() + months)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function termMonths(from: string | null | undefined, to: string | null | undefined): number {
  if (!from || !to) return 12
  const a = new Date(from.slice(0, 10))
  const b = new Date(to.slice(0, 10))
  const m = (b.getFullYear() - a.getFullYear()) * 12 + (b.getMonth() - a.getMonth())
  return m > 0 ? m : 12
}

export function useRenewalActions(policy: Ref<ExpiringPolicy | null>, detail?: Ref<Policy | null>) {
  // ── Transient feedback + busy flags ──────────────────────────────────────
  const saving = ref(false)
  const msg = ref<{ ok: boolean; text: string } | null>(null)
  function flash(ok: boolean, text: string): void {
    msg.value = { ok, text }
    setTimeout(() => { msg.value = null }, 3500)
  }

  function errText(e: unknown, fallback: string): string {
    return e instanceof ApiError ? e.message : (e instanceof Error ? e.message : fallback)
  }

  const followups = ref<RenewalFollowup[]>([])
  const followupsLoading = ref(false)
  const followupsDeclined = ref(false)
  const declineReasons = ref<Array<{ code: string; label: string }>>([])
  const followupErrors = ref<Record<string, string[]>>({})
  function blankFollowup(result: FollowupResult = 'thinking'): FollowupForm {
    return { channel: 'phone', result, reasonCode: '', reasonNote: '', note: '', nextFollowupAt: '' }
  }
  const followupForm = ref<FollowupForm>(blankFollowup())
  function resetFollowupForm(result: FollowupResult = 'thinking'): void {
    followupForm.value = blankFollowup(result)
    followupErrors.value = {}
  }
  async function loadFollowups(): Promise<void> {
    const r = policy.value; if (!r) return
    followupsLoading.value = true
    try {
      const res = await fetchRenewalFollowups(r.policyId)
      followups.value = res.data
      followupsDeclined.value = res.meta.declined
      declineReasons.value = res.meta.reasons
    } catch { followups.value = [] }
    finally { followupsLoading.value = false }
  }
  async function submitFollowup(): Promise<boolean> {
    const r = policy.value; if (!r) return false
    const f = followupForm.value
    const declining = f.result === 'not_renewing'
    saving.value = true
    followupErrors.value = {}
    try {
      const res = await createRenewalFollowup(r.policyId, {
        channel: f.channel,
        result: f.result,
        reasonCode: declining ? (f.reasonCode || null) : null,
        reasonNote: declining && f.reasonCode === 'other' ? (f.reasonNote.trim() || null) : null,
        note: f.note.trim() || null,
        nextFollowupAt: declining ? null : (f.nextFollowupAt || null),
      })
      const created = res.data
      followups.value = [created, ...followups.value]
      r.lastContactedAt = created.createdAt
      r.lastFollowup = {
        id: created.id, at: created.createdAt, channel: created.channel, result: created.result,
        reasonCode: created.reasonCode, reasonLabel: created.reasonLabel, note: created.note,
        nextAt: created.nextFollowupAt, byName: created.byUserName,
      }
      if (declining) {
        r.renewalDeclinedAt = created.createdAt
        r.renewalStage = 'declined'
        followupsDeclined.value = true
      } else if (r.renewalStage === 'not_started') {
        r.renewalStage = 'contacted'
      }
      resetFollowupForm()
      flash(true, declining ? 'บันทึกว่าลูกค้าไม่ต่ออายุแล้ว' : 'บันทึกการติดตามแล้ว')
      return true
    } catch (e) {
      if (e instanceof ApiError) followupErrors.value = e.body?.errors ?? {}
      flash(false, errText(e, 'บันทึกการติดตามล้มเหลว'))
      return false
    } finally { saving.value = false }
  }
  async function reopenFollowup(note?: string): Promise<boolean> {
    const r = policy.value; if (!r) return false
    saving.value = true
    try {
      await reopenRenewal(r.policyId, note)
      followupsDeclined.value = false
      r.renewalDeclinedAt = null
      r.renewalStage = r.lastContactedAt ? 'contacted' : 'not_started'
      flash(true, 'เปิดติดตามใหม่แล้ว')
      return true
    } catch (e) { flash(false, errText(e, 'ดำเนินการล้มเหลว')); return false }
    finally { saving.value = false }
  }

  // ── Renewal notice email ─────────────────────────────────────────────────
  async function submitNotice(): Promise<boolean> {
    const r = policy.value; if (!r) return false
    saving.value = true
    try {
      const res = await sendRenewalNotice(r.policyId)
      r.lastNoticeSentAt = new Date().toISOString()
      flash(true, res.sentToAgent ? 'ส่งถึงตัวแทน (ลูกค้าไม่มีอีเมล)' : 'ส่งอีเมลแล้ว')
      return true
    } catch (e) { flash(false, errText(e, 'ส่งอีเมลล้มเหลว')); return false }
    finally { saving.value = false }
  }

  // ── Request quote from the carrier (+ editable carrier email list) ────────
  const quoteForm = ref<{ subject: string; message: string; to: string }>({ subject: '', message: '', to: '' })
  const quoteCopied = ref(false)
  const quoteContacts = ref<CarrierContact[]>([])
  const quoteContactsLoading = ref(false)
  const quoteContactEdit = ref<{ id: string | null; email: string; name: string } | null>(null)

  function defaultQuoteSubject(r: ExpiringPolicy): string {
    const ref = r.policyNo || r.applicationNo || '—'
    return `ขอใบเสนอราคาต่ออายุกรมธรรม์ ${ref} — InsureHub`
  }
  function defaultQuoteMessage(r: ExpiringPolicy): string {
    const ref = r.policyNo || r.applicationNo || '—'
    return `เรียน ${r.carrierName || 'บริษัทประกัน'}\n\n`
      + `InsureHub ขอความอนุเคราะห์ใบเสนอราคาต่ออายุกรมธรรม์เลขที่ ${ref} `
      + `ของลูกค้า ${r.customerName || '—'} สำหรับระยะเวลาความคุ้มครองปีถัดไป\n\n`
      + `ขอบคุณครับ/ค่ะ`
  }
  const quoteMailtoHref = computed<string>(() => {
    const m = quoteForm.value
    const params = new URLSearchParams({ subject: m.subject, body: m.message })
    return `mailto:${encodeURIComponent(m.to)}?${params.toString()}`
  })
  /** Prime the request-quote form + load the carrier email list. */
  function initQuoteForm(): void {
    const r = policy.value; if (!r) return
    quoteCopied.value = false
    quoteContactEdit.value = null
    quoteForm.value = {
      subject: defaultQuoteSubject(r),
      message: defaultQuoteMessage(r),
      to: r.carrierEmail ?? '',
    }
    void loadQuoteContacts()
  }
  async function loadQuoteContacts(): Promise<void> {
    const r = policy.value; if (!r?.carrierId) { quoteContacts.value = []; return }
    quoteContactsLoading.value = true
    try {
      const res = await fetchCarrierContacts(r.carrierId)
      quoteContacts.value = res.data.filter((c) => c.email)
      if (!quoteForm.value.to) {
        const primary = quoteContacts.value.find((c) => c.isPrimary) ?? quoteContacts.value[0]
        quoteForm.value.to = primary?.email ?? r.carrierEmail ?? ''
      }
    } catch { /* empty — operator can type/add one */ }
    finally { quoteContactsLoading.value = false }
  }
  function startAddContact(): void { quoteContactEdit.value = { id: null, email: '', name: '' } }
  function startEditContact(c: CarrierContact): void {
    quoteContactEdit.value = { id: c.id, email: c.email, name: [c.firstName, c.lastName].filter(Boolean).join(' ') }
  }
  async function saveContactEdit(): Promise<void> {
    const r = policy.value; const e = quoteContactEdit.value
    if (!r?.carrierId || !e) return
    const email = e.email.trim(); if (!email) return
    const [firstName, ...rest] = e.name.trim().split(/\s+/)
    const payload = { email, firstName: firstName || '', lastName: rest.join(' ') }
    try {
      if (e.id) await updateCarrierContact(r.carrierId, e.id, payload)
      else await createCarrierContact(r.carrierId, payload)
      quoteContactEdit.value = null
      await loadQuoteContacts()
      quoteForm.value.to = email
    } catch (err) { flash(false, errText(err, 'บันทึกอีเมลล้มเหลว')) }
  }
  async function removeContact(c: CarrierContact): Promise<void> {
    const r = policy.value; if (!r?.carrierId) return
    try {
      await deleteCarrierContact(r.carrierId, c.id)
      if (quoteForm.value.to === c.email) quoteForm.value.to = ''
      await loadQuoteContacts()
    } catch (err) { flash(false, errText(err, 'ลบอีเมลล้มเหลว')) }
  }
  async function copyQuoteToClipboard(): Promise<void> {
    const m = quoteForm.value
    const text = `ถึง: ${m.to || '(ยังไม่เลือกอีเมล)'}\nหัวข้อ: ${m.subject}\n\n${m.message}`
    try { await navigator.clipboard.writeText(text); quoteCopied.value = true; setTimeout(() => { quoteCopied.value = false }, 2000) }
    catch { /* clipboard blocked */ }
  }
  /** mode 'system' = app sends; 'manual' = operator sent it, just log. */
  async function submitQuoteRequest(mode: 'system' | 'manual'): Promise<boolean> {
    const r = policy.value; if (!r) return false
    saving.value = true
    try {
      await requestRenewalQuote(r.policyId, {
        recipient: 'carrier', mode,
        subject: quoteForm.value.subject.trim() || undefined,
        message: quoteForm.value.message.trim() || undefined,
      })
      r.quoteRequestedAt = new Date().toISOString()
      r.renewalStage = 'quote_requested'
      flash(true, mode === 'manual' ? 'บันทึกว่าส่งเองแล้ว' : 'ส่งคำขอแล้ว')
      return true
    } catch (e) { flash(false, errText(e, 'ดำเนินการล้มเหลว')); return false }
    finally { saving.value = false }
  }

  // ── Upload the carrier's returned quotation ──────────────────────────────
  async function uploadCarrierQuote(file: File): Promise<boolean> {
    const r = policy.value; if (!r) return false
    saving.value = true
    try {
      await uploadPolicyDocument(r.policyId, 'renewal_quote_carrier', file)
      r.quoteReceivedAt = new Date().toISOString()
      r.renewalStage = 'quote_received'
      flash(true, 'อัปโหลดใบเสนอราคาแล้ว')
      return true
    } catch (e) { flash(false, errText(e, 'อัปโหลดล้มเหลว')); return false }
    finally { saving.value = false }
  }

  // ── Prepare the InsureHub-branded quotation (PDF) ─────────────────────────
  const prepareForm = ref<RenewalNoticeForm | null>(null)
  const preparing = ref(false)
  const prepareErrors = ref<Record<string, string[]>>({})
  const notices = ref<RenewalNotice[]>([])
  const latestNotice = computed<RenewalNotice | null>(() => notices.value[0] ?? null)
  async function loadNotices(): Promise<void> {
    const r = policy.value; if (!r) return
    try { notices.value = (await fetchRenewalNotices(r.policyId)).data } catch { notices.value = [] }
  }
  function splitPremium(form: RenewalNoticeForm): void {
    const parts = decomposeByFormula(Number(form.annualPremium) || 0, form.formula)
    form.netPremium = parts.net
    form.dutyStamp = parts.duty
    form.vat = parts.vat
  }
  function initPrepareForm(): void {
    const r = policy.value; if (!r) return
    const d = detail?.value ?? null
    const base = latestNotice.value
    const form: RenewalNoticeForm = {
      annualPremium: base?.annualPremium ?? Math.round((r.annualPremium ?? 0) * 100) / 100,
      autoSplit: true,
      formula: 1,
      netPremium: 0,
      dutyStamp: 0,
      vat: 0,
      discount: base?.discount ?? 0,
      coverage: base?.coverage ?? d?.coverage ?? 0,
      premiumMode: (base?.premiumMode as RenewalNoticeForm['premiumMode']) ?? d?.premiumMode ?? 'annual',
      dueDate: (r.expiryDate || '').slice(0, 10),
      validUntil: (r.expiryDate || '').slice(0, 10),
      note: '',
    }
    if (base && base.netPremium !== null && base.dutyStamp !== null && base.vat !== null) {
      form.autoSplit = false
      form.netPremium = base.netPremium
      form.dutyStamp = base.dutyStamp
      form.vat = base.vat
    } else {
      splitPremium(form)
    }
    prepareForm.value = form
    prepareErrors.value = {}
  }
  watch(
    () => prepareForm.value && [prepareForm.value.annualPremium, prepareForm.value.autoSplit, prepareForm.value.formula],
    () => { const f = prepareForm.value; if (f?.autoSplit) splitPremium(f) },
  )
  async function submitPrepareQuote(alsoDownload: boolean): Promise<boolean> {
    const r = policy.value; const form = prepareForm.value
    if (!r || !form) return false
    preparing.value = true; saving.value = true
    prepareErrors.value = {}
    try {
      const created = await createRenewalNotice(r.policyId, {
        annualPremium: Number(form.annualPremium),
        netPremium: Number(form.netPremium),
        dutyStamp: Number(form.dutyStamp),
        vat: Number(form.vat),
        discount: Number(form.discount) || null,
        coverage: Number(form.coverage) || null,
        premiumMode: form.premiumMode,
        dueDate: form.dueDate || null,
        validUntil: form.validUntil || null,
        note: form.note.trim() || null,
      })
      const notice = created.data
      const tenant = await fetchTenant().then((x) => x.data).catch(() => null)
      const d = detail?.value ?? null
      const months = termMonths(d?.effectiveDate, d?.expiryDate ?? r.expiryDate)
      const from = (r.expiryDate || '').slice(0, 10) || null
      const file = await renderRenewalNoticePdf({
        noticeNo: notice.noticeNo,
        issuedAt: notice.createdAt ?? new Date().toISOString(),
        dueDate: notice.dueDate,
        validUntil: notice.validUntil,
        tenant,
        customerName: r.customerName,
        customerPhone: r.customerPhone ?? null,
        policyNo: r.policyNo ?? r.applicationNo,
        carrierName: r.carrierName ?? d?.carrierName ?? null,
        productName: r.productName ?? d?.productName ?? null,
        productCategory: r.productSubCategory ?? r.productCategory ?? null,
        motorLicenseNo: r.motorLicenseNo ?? null,
        motorVehicle: r.motorVehicle ?? null,
        currentExpiry: r.expiryDate,
        newTermFrom: from,
        newTermTo: from ? addMonthsIso(from, months) : null,
        premiumModeLabel: PREMIUM_MODE_LABEL[form.premiumMode] ?? form.premiumMode,
        current: {
          annualPremium: notice.annualPremium,
          netPremium: notice.netPremium,
          dutyStamp: notice.dutyStamp,
          vat: notice.vat,
          discount: notice.discount,
          coverage: notice.coverage,
        },
        lastYear: {
          annualPremium: d?.annualPremium ?? r.annualPremium,
          netPremium: d?.premium?.net ?? null,
          dutyStamp: d?.premium?.dutyStamp ?? null,
          vat: d?.premium?.vat ?? null,
          discount: d?.installment?.discountAmount ?? null,
          coverage: d?.coverage ?? null,
        },
        agentName: r.agentName || null,
        note: notice.note,
      })
      if (alsoDownload) {
        const url = URL.createObjectURL(file)
        const a = document.createElement('a')
        a.href = url; a.download = file.name; a.rel = 'noopener'
        document.body.appendChild(a); a.click(); a.remove()
        setTimeout(() => URL.revokeObjectURL(url), 10_000)
      }
      await uploadPolicyDocument(r.policyId, 'renewal_quote_insurehub', file, {
        annualPremium: notice.annualPremium,
        coverage: notice.coverage,
        premiumMode: notice.premiumMode,
        renewalNoticeId: notice.id,
      })
      await loadNotices()
      r.quotePreparedAt = new Date().toISOString()
      r.renewalStage = 'quote_prepared'
      flash(true, `สร้างใบเตือนต่ออายุ ${notice.noticeNo} แล้ว`)
      return true
    } catch (err) {
      if (err instanceof ApiError) prepareErrors.value = err.body?.errors ?? {}
      flash(false, errText(err, 'สร้างใบเตือนล้มเหลว')); return false
    } finally { preparing.value = false; saving.value = false }
  }

  // ── Send the generated quote to the customer ─────────────────────────────
  const sendForm = ref<{ to: string; message: string }>({ to: '', message: '' })
  function initSendForm(): void {
    const r = policy.value; if (!r) return
    sendForm.value = {
      to: r.customerEmail ?? '',
      message: `เรียน คุณ${r.customerName || 'ลูกค้า'}\n\n`
        + `InsureHub ได้จัดทำใบเสนอราคาสำหรับการต่ออายุกรมธรรม์ของท่านเรียบร้อยแล้ว รายละเอียดตามเอกสารแนบ\n\n`
        + `หากมีข้อสงสัยหรือต้องการยืนยันการต่ออายุ กรุณาติดต่อกลับได้ที่อีเมลนี้`,
    }
  }
  async function submitSendQuote(): Promise<boolean> {
    const r = policy.value; if (!r) return false
    saving.value = true
    try {
      await sendRenewalQuote(r.policyId, sendForm.value.message.trim() || undefined, sendForm.value.to.trim() || undefined)
      r.quoteSentAt = new Date().toISOString()
      r.renewalStage = 'quote_sent'
      flash(true, 'ส่งใบเสนอราคาถึงลูกค้าแล้ว')
      return true
    } catch (e) { flash(false, errText(e, 'ส่งใบเสนอราคาล้มเหลว')); return false }
    finally { saving.value = false }
  }

  // ── Manual stage marker (no side-effect, "done outside the system") ───────
  const STAGE_TS_FIELD: Record<ManualStage, keyof ExpiringPolicy> = {
    contacted: 'lastContactedAt',
    quote_requested: 'quoteRequestedAt',
    quote_received: 'quoteReceivedAt',
    quote_prepared: 'quotePreparedAt',
    quote_sent: 'quoteSentAt',
    declined: 'renewalDeclinedAt',
  }
  async function manualMark(stage: ManualStage): Promise<boolean> {
    const r = policy.value; if (!r) return false
    saving.value = true
    try {
      await markRenewalStage(r.policyId, stage)
      ;(r as unknown as Record<string, unknown>)[STAGE_TS_FIELD[stage]] = new Date().toISOString()
      r.renewalStage = stage
      flash(true, 'บันทึกสถานะแล้ว')
      return true
    } catch (e) { flash(false, errText(e, 'บันทึกสถานะล้มเหลว')); return false }
    finally { saving.value = false }
  }

  // ── Documents (list / open / download / delete / regenerate) ─────────────
  const docs = ref<PolicyDocumentRow[]>([])
  const docsLoading = ref(false)
  const docBusy = ref<string | null>(null)
  async function loadDocs(): Promise<void> {
    const r = policy.value; if (!r) return
    docsLoading.value = true
    try {
      const [carrier, insurehub] = await Promise.all([
        fetchPolicyDocuments(r.policyId, 'renewal_quote_carrier'),
        fetchPolicyDocuments(r.policyId, 'renewal_quote_insurehub'),
      ])
      docs.value = [...carrier.data, ...insurehub.data]
    } catch (e) { flash(false, errText(e, 'โหลดเอกสารล้มเหลว')) }
    finally { docsLoading.value = false }
  }
  async function openDoc(d: PolicyDocumentRow, mode: 'open' | 'download'): Promise<void> {
    const r = policy.value; if (!r) return
    docBusy.value = d.id
    try { await downloadPolicyDocument(r.policyId, d.id, d.fileName, mode) }
    catch (e) { flash(false, errText(e, 'ดาวน์โหลดไม่สำเร็จ')) }
    finally { docBusy.value = null }
  }
  async function deleteDoc(d: PolicyDocumentRow): Promise<void> {
    const r = policy.value; if (!r) return
    if (!window.confirm(`ลบไฟล์ "${d.fileName}"?`)) return
    docBusy.value = d.id
    try {
      await deletePolicyDocument(r.policyId, d.id)
      docs.value = docs.value.filter((x) => x.id !== d.id)
      flash(true, 'ลบไฟล์แล้ว')
    } catch (e) { flash(false, errText(e, 'ลบไฟล์ล้มเหลว')) }
    finally { docBusy.value = null }
  }
  /** Regenerate an InsureHub quote: delete the old file then re-open the
   *  prepare form. Returns true if the caller should switch to the prepare panel. */
  async function regenerateInsurehubQuote(d: PolicyDocumentRow): Promise<boolean> {
    const r = policy.value; if (!r) return false
    if (!window.confirm('สร้างใบเตือนต่ออายุ InsureHub ใหม่ และลบไฟล์ฉบับเดิม?')) return false
    docBusy.value = d.id
    try {
      await deletePolicyDocument(r.policyId, d.id)
      docs.value = docs.value.filter((x) => x.id !== d.id)
      flash(true, 'ลบฉบับเดิมแล้ว — กรอกข้อมูลเพื่อสร้างใหม่')
      initPrepareForm()
      return true
    } catch (e) { flash(false, errText(e, 'ดำเนินการล้มเหลว')); return false }
    finally { docBusy.value = null }
  }
  function docTypeLabel(type: string): string {
    if (type === 'renewal_quote_carrier') return 'ใบเสนอราคาจากบริษัทประกัน'
    if (type === 'renewal_quote_insurehub') return 'ใบเตือนต่ออายุ InsureHub'
    return type
  }

  return {
    saving, msg, flash,
    followups, followupsLoading, followupsDeclined, declineReasons, followupForm, followupErrors,
    resetFollowupForm, loadFollowups, submitFollowup, reopenFollowup, submitNotice,
    // request quote
    quoteForm, quoteCopied, quoteContacts, quoteContactsLoading, quoteContactEdit,
    quoteMailtoHref, initQuoteForm, loadQuoteContacts,
    startAddContact, startEditContact, saveContactEdit, removeContact,
    copyQuoteToClipboard, submitQuoteRequest,
    // upload
    uploadCarrierQuote,
    // prepare
    prepareForm, preparing, prepareErrors, initPrepareForm, submitPrepareQuote, splitPremium,
    notices, latestNotice, loadNotices,
    // send
    sendForm, initSendForm, submitSendQuote,
    // manual
    manualMark,
    // docs
    docs, docsLoading, docBusy, loadDocs, openDoc, deleteDoc, regenerateInsurehubQuote, docTypeLabel,
  }
}
