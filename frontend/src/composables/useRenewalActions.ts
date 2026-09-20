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
import { ref, computed, type Ref } from 'vue'
import {
  requestRenewalQuote, sendRenewalQuote, declineRenewal, markRenewalStage,
  markRenewalContacted, sendRenewalNotice,
  type ExpiringPolicy, type ManualStage,
} from '../api/reports'
import {
  fetchCarrierContacts, createCarrierContact, updateCarrierContact, deleteCarrierContact,
  type CarrierContact,
} from '../api/carriers'
import {
  uploadPolicyDocument, fetchPolicyDocuments, downloadPolicyDocument, deletePolicyDocument,
  type PolicyDocumentRow,
} from '../api/policies'
import { useRenewalQuote, emptyRenewalQuoteForm, type RenewalQuoteForm } from './useRenewalQuote'
import { ApiError } from '../api/client'

export function useRenewalActions(policy: Ref<ExpiringPolicy | null>) {
  const renewalQuote = useRenewalQuote()

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

  // ── Contact log (channel + note) ─────────────────────────────────────────
  const contactForm = ref<{ channel: string; note: string }>({ channel: 'phone', note: '' })
  async function submitContact(): Promise<boolean> {
    const r = policy.value; if (!r) return false
    saving.value = true
    try {
      const res = await markRenewalContacted(r.policyId, {
        channel: contactForm.value.channel as 'phone' | 'line' | 'email' | 'inperson' | 'other',
        note: contactForm.value.note.trim() || undefined,
      })
      r.lastContactedAt = res.event.occurredAt
      if (r.renewalStage === 'not_started') r.renewalStage = 'contacted'
      contactForm.value = { channel: 'phone', note: '' }
      flash(true, 'บันทึกการติดต่อแล้ว')
      return true
    } catch (e) { flash(false, errText(e, 'บันทึกล้มเหลว')); return false }
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
  const prepareForm = ref<RenewalQuoteForm | null>(null)
  const preparing = ref(false)
  function initPrepareForm(): void {
    const r = policy.value; if (!r) return
    prepareForm.value = emptyRenewalQuoteForm(r)
  }
  async function submitPrepareQuote(alsoDownload: boolean): Promise<boolean> {
    const r = policy.value; const form = prepareForm.value
    if (!r || !form) return false
    preparing.value = true; saving.value = true
    try {
      const quotation = renewalQuote.buildQuotation(r, form)
      const file = await renewalQuote.renderToFile(quotation)
      if (alsoDownload) {
        const url = URL.createObjectURL(file)
        const a = document.createElement('a')
        a.href = url; a.download = file.name; a.rel = 'noopener'
        document.body.appendChild(a); a.click(); a.remove()
        setTimeout(() => URL.revokeObjectURL(url), 10_000)
      }
      await uploadPolicyDocument(r.policyId, 'renewal_quote_insurehub', file)
      r.quotePreparedAt = new Date().toISOString()
      r.renewalStage = 'quote_prepared'
      flash(true, 'สร้างใบเสนอราคา InsureHub แล้ว')
      return true
    } catch (err) {
      console.error('[renewal] prepare-quote failed', err)
      flash(false, errText(err, 'สร้างใบเสนอราคาล้มเหลว')); return false
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

  // ── Decline (customer said no / lost) ────────────────────────────────────
  const declineReason = ref('')
  async function submitDecline(): Promise<boolean> {
    const r = policy.value; if (!r) return false
    saving.value = true
    try {
      await declineRenewal(r.policyId, declineReason.value.trim() || undefined)
      r.renewalDeclinedAt = new Date().toISOString()
      r.renewalStage = 'declined'
      declineReason.value = ''
      flash(true, 'บันทึกว่าลูกค้าปฏิเสธแล้ว')
      return true
    } catch (e) { flash(false, errText(e, 'บันทึกล้มเหลว')); return false }
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
    if (!window.confirm('สร้างใบเสนอราคา InsureHub ใหม่ และลบฉบับเดิม?')) return false
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
    if (type === 'renewal_quote_insurehub') return 'ใบเสนอราคา InsureHub'
    return type
  }

  return {
    saving, msg, flash,
    // contact / notice
    contactForm, submitContact, submitNotice,
    // request quote
    quoteForm, quoteCopied, quoteContacts, quoteContactsLoading, quoteContactEdit,
    quoteMailtoHref, initQuoteForm, loadQuoteContacts,
    startAddContact, startEditContact, saveContactEdit, removeContact,
    copyQuoteToClipboard, submitQuoteRequest,
    // upload
    uploadCarrierQuote,
    // prepare
    prepareForm, preparing, initPrepareForm, submitPrepareQuote,
    // send
    sendForm, initSendForm, submitSendQuote,
    // decline
    declineReason, submitDecline,
    // manual
    manualMark,
    // docs
    docs, docsLoading, docBusy, loadDocs, openDoc, deleteDoc, regenerateInsurehubQuote, docTypeLabel,
  }
}
