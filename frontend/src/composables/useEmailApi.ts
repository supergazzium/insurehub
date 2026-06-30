import { getToken } from '../api/client'

/**
 * useEmailApi — frontend client for outbound mail + reply tracking.
 *
 * ─── DEPLOYMENT MODES ──────────────────────────────────────────────────────
 *
 *   MODE = 'mock'  (current default)
 *     Pure in-browser simulation. Used during UI development. Fakes
 *     queue → sending → sent → delivered with realistic-ish latency. ~2%
 *     bounce rate for demo. No network calls.
 *
 *   MODE = 'zoho-proxy'  (Phase 2 target)
 *     Proxies through a backend service of yours (Laravel / Node / Worker)
 *     that holds the Zoho OAuth refresh token and calls Zoho Mail REST API
 *     on the frontend's behalf. See ZOHO_PROXY_CONTRACT below.
 *
 * Swap modes by flipping the MODE constant. NO other file should need to
 * change — the public surface (sendThread, sendBatch, simulateIncomingReply,
 * uploadAttachment, cancelScheduled) stays identical.
 *
 *
 * ─── ZOHO_PROXY_CONTRACT ───────────────────────────────────────────────────
 *
 * The proxy backend MUST expose these endpoints. The frontend never talks to
 * Zoho directly because (a) client_secret can't ship to browser and (b) Zoho
 * doesn't enable CORS for outbound /messages.
 *
 *   POST  /api/mail/attachments
 *     Multipart upload. Backend forwards to Zoho
 *     POST /accounts/{accountId}/messages/attachments
 *     Returns { storeName, attachmentName, attachmentPath } verbatim from Zoho.
 *
 *   POST  /api/mail/send
 *     Body: ZohoSendRequest (see type below).
 *     Backend translates to Zoho POST /accounts/{accountId}/messages,
 *     returns { messageId, threadId, status: 'sent' | 'queued' | 'scheduled' }.
 *
 *   POST  /api/mail/schedule
 *     Body: ZohoSendRequest with scheduleTime set.
 *     Backend sets scheduleType=6 + scheduleTime, calls /messages,
 *     returns { scheduledMailId, scheduledAt }.
 *
 *   DELETE  /api/mail/schedule/:scheduledMailId
 *     Backend calls Zoho DELETE /scheduledMails/{id}.
 *
 *   GET   /api/mail/incoming?since=<iso8601>
 *     Backend polls Zoho /accounts/{id}/messages/search for messages whose
 *     subject or inReplyTo matches a tracked thread tag, returns matches.
 *     Frontend polls this every 30-60s when the threads tab is open.
 *
 *
 * ─── PLUS-ADDRESSING ───────────────────────────────────────────────────────
 *
 * Outbound: From = "no-reply@insurehub.co.th". ReplyTo = "no-reply+T-<threadId>@insurehub.co.th".
 * Zoho preserves both headers on the wire. When the carrier replies-all, the
 * reply lands in our inbox addressed to the plus-aliased address. The proxy's
 * polling endpoint greps inbound To/CC for `no-reply+T-xxx@…` to associate
 * replies with our threads.
 *
 * Fallback (when carrier strips the plus-address): the subject is stamped
 * with [#T-xxx] so polling can still group by subject suffix.
 */

// ─────────────────────────────────────────────────────────────────────────────
// MODE
// ─────────────────────────────────────────────────────────────────────────────

type ApiMode = 'mock' | 'zoho-proxy'

/** Flipped to 'zoho-proxy' now that the Laravel backend mediates Zoho.
 *  Flip back to 'mock' for offline / demo work. */
const MODE: ApiMode = 'zoho-proxy'

/** Where the proxy backend lives. Derived from VITE_API_BASE_URL so the same
 *  build talks to whatever API the env points at. */
const PROXY_BASE_URL = `${(import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000/api/v1').replace(/\/+$/, '')}/mail`

// ─────────────────────────────────────────────────────────────────────────────
// Public types (consumed by callers — DO NOT rename without grepping)
// ─────────────────────────────────────────────────────────────────────────────

export type DeliveryStatus =
  | 'queued'      // accepted by our server, waiting for SMTP worker
  | 'sending'     // handing off to SMTP provider
  | 'sent'        // SMTP provider accepted the message
  | 'delivered'   // recipient mail server accepted (some providers only)
  | 'bounced'     // hard bounce (bad address, mailbox full)
  | 'failed'      // server error, will retry up to N times
  | 'scheduled'   // Zoho has queued for future delivery; deliveryStatus will
                  // transition to 'sent' at scheduleTime

/** Reference to an attachment that has already been uploaded to Zoho. Acquired
 *  via uploadAttachment() before calling sendThread(). */
export interface AttachmentRef {
  /** Zoho's "storeName" — stable id Zoho assigns to the uploaded blob. */
  storeName: string
  /** Original filename — what the recipient sees in their mail client. */
  attachmentName: string
  /** Zoho's "attachmentPath" — opaque pointer Zoho uses to fetch the blob. */
  attachmentPath: string
  /** Size in bytes. Used for total-size validation. */
  sizeBytes: number
}

export interface SendThreadRequest {
  caseId: string
  carrierCode: string
  /** Comma-separated address list. Multi-TO is allowed and used for group
   *  sends — Zoho's toAddress takes this format natively. */
  to: string
  cc: string
  subject: string
  body: string
  /** Human-readable template label, e.g. "ติดตามสถานะ (Pending Carrier)".
   *  Stored as metadata for audit; Zoho doesn't see this. */
  template: string
  /** Optional attachments — must be uploaded via uploadAttachment() first. */
  attachments?: AttachmentRef[]
  /** If set, send as scheduled mail via Zoho's scheduleType=6 path.
   *  Format: ISO 8601 (UTC). The mapper converts to Zoho's MM/dd/yyyy. */
  scheduleAt?: string
}

export interface SendThreadResponse {
  threadId: string            // our internal thread id (T-xxx)
  messageId: string           // outbound RFC-5322 Message-ID — from Zoho
  replyAddress: string        // plus-addressed reply target
  fromAddress: string         // From header as carriers see it
  trackedSubject: string      // subject with [#T-xxx] appended
  deliveryStatus: DeliveryStatus
  acceptedAt: string          // when our server accepted the message
  /** Set when scheduleAt was used. Use cancelScheduled(scheduledMailId). */
  scheduledMailId?: string
}

export interface BatchSendItem {
  caseId: string
  carrierCode: string
  to: string
  cc: string
  subject: string
  body: string
  template: string
}

export interface BatchSendResponse {
  batchId: string
  results: SendThreadResponse[]
  acceptedAt: string
}

export interface IncomingReplyEvent {
  threadId: string
  fromName: string
  fromAddress: string
  body: string
  receivedAt: string
}

// ─────────────────────────────────────────────────────────────────────────────
// Zoho-internal types (proxy talks Zoho-shaped JSON; frontend types stay clean)
// ─────────────────────────────────────────────────────────────────────────────

/** Body POSTed to /api/mail/send. Mirrors Zoho's /messages payload 1-for-1
 *  so the proxy is a passthrough with credential injection. */
export interface ZohoSendRequest {
  fromAddress: string
  toAddress: string                  // comma-separated
  ccAddress?: string
  bccAddress?: string
  subject: string
  content: string
  mailFormat: 'plaintext' | 'html'
  /** "yes" requests a read-receipt — we don't use it but Zoho accepts. */
  askReceipt?: 'yes' | 'no'
  /** 1 = normal, 6 = scheduled. */
  scheduleType?: 1 | 6
  /** Required when scheduleType=6. Zoho format: "MM/dd/yyyy HH:mm:ss" UTC. */
  scheduleTime?: string
  /** Custom Reply-To — we set this to the plus-addressed alias. */
  replyTo?: string
  attachments?: Array<{
    storeName: string
    attachmentName: string
    attachmentPath: string
  }>
}

export interface ZohoSendResponse {
  status: { code: number; description: string }
  data: {
    /** Zoho's message id. We adopt it as our messageId. */
    messageId: string
    /** Only present when scheduleType=6. */
    scheduledMailId?: string
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Tenant config — replace with real values from useTenantStore + .env
// ─────────────────────────────────────────────────────────────────────────────

// Outbound identity — all mail from this app appears as no-reply@insurehub.co.th.
// Replies are routed via plus-addressing (no-reply+T-<threadId>@insurehub.co.th)
// so the polling job can still associate inbound mail with our threads even
// though the sender mailbox is unmonitored.
const TENANT_DOMAIN = 'insurehub.co.th'
const TENANT_FROM_ADDRESS = `no-reply@${TENANT_DOMAIN}`
const TENANT_FROM_NAME = 'InsureHub'

// ─────────────────────────────────────────────────────────────────────────────
// Shared helpers (mode-agnostic)
// ─────────────────────────────────────────────────────────────────────────────

function makeThreadId(): string {
  return 'T-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6)
}

function makeMessageId(threadId: string): string {
  return `<${threadId}.${Date.now()}@${TENANT_DOMAIN}>`
}

function makeReplyAddress(threadId: string): string {
  // Plus-addressing on the canonical send identity — the proxy's polling job
  // greps inbound To/CC for `no-reply+T-xxx@…` to associate replies to threads.
  return `no-reply+${threadId}@${TENANT_DOMAIN}`
}

function trackedSubject(subject: string, threadId: string): string {
  if (subject.includes(`[#${threadId}]`)) return subject
  return `${subject} [#${threadId}]`
}

function nowStamp(): string {
  return new Date().toISOString().slice(0, 16).replace('T', ' ')
}

/** Format an ISO timestamp into Zoho's required "MM/dd/yyyy HH:mm:ss" UTC. */
function toZohoScheduleTime(iso: string): string {
  const d = new Date(iso)
  const pad = (n: number) => n.toString().padStart(2, '0')
  return `${pad(d.getUTCMonth() + 1)}/${pad(d.getUTCDate())}/${d.getUTCFullYear()} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())}`
}

/** Map our SendThreadRequest to the Zoho-shaped payload the proxy expects.
 *  Centralized here so when MODE flips, no caller logic changes. */
function buildZohoSendRequest(req: SendThreadRequest, threadId: string): ZohoSendRequest {
  const payload: ZohoSendRequest = {
    fromAddress: `${TENANT_FROM_NAME} <${TENANT_FROM_ADDRESS}>`,
    toAddress: req.to,
    ccAddress: req.cc || undefined,
    subject: trackedSubject(req.subject, threadId),
    content: req.body,
    mailFormat: 'plaintext',
    askReceipt: 'no',
    replyTo: makeReplyAddress(threadId),
    scheduleType: req.scheduleAt ? 6 : 1,
    scheduleTime: req.scheduleAt ? toZohoScheduleTime(req.scheduleAt) : undefined,
    attachments: req.attachments?.map((a) => ({
      storeName: a.storeName,
      attachmentName: a.attachmentName,
      attachmentPath: a.attachmentPath,
    })),
  }
  return payload
}

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms))
}

// ─────────────────────────────────────────────────────────────────────────────
// MOCK implementation
// ─────────────────────────────────────────────────────────────────────────────

async function mockSendThread(
  req: SendThreadRequest,
  onDeliveryUpdate?: (status: DeliveryStatus, when: string) => void,
): Promise<SendThreadResponse> {
  const threadId = makeThreadId()
  const messageId = makeMessageId(threadId)
  const replyAddress = makeReplyAddress(threadId)

  // Demonstrate that the mapper works — produces the Zoho-shaped payload that
  // the proxy will receive in MODE = 'zoho-proxy'. Kept as a void call so the
  // unused-var lint doesn't fire.
  void buildZohoSendRequest(req, threadId)

  // If a future schedule is requested, short-circuit to a "scheduled" status
  // and don't simulate delivery — caller is responsible for re-firing at time.
  if (req.scheduleAt) {
    await sleep(120)
    onDeliveryUpdate?.('scheduled', nowStamp())
    return {
      threadId, messageId, replyAddress,
      fromAddress: `${TENANT_FROM_NAME} <${TENANT_FROM_ADDRESS}>`,
      trackedSubject: trackedSubject(req.subject, threadId),
      deliveryStatus: 'scheduled',
      acceptedAt: nowStamp(),
      scheduledMailId: 'sched-' + Math.random().toString(36).slice(2, 10),
    }
  }

  await sleep(120)
  onDeliveryUpdate?.('queued', nowStamp())
  await sleep(250)
  onDeliveryUpdate?.('sending', nowStamp())
  await sleep(350)

  const willBounce = /bounce|invalid|noreply/i.test(req.to) || Math.random() < 0.02
  if (willBounce) {
    onDeliveryUpdate?.('bounced', nowStamp())
    return {
      threadId, messageId, replyAddress,
      fromAddress: `${TENANT_FROM_NAME} <${TENANT_FROM_ADDRESS}>`,
      trackedSubject: trackedSubject(req.subject, threadId),
      deliveryStatus: 'bounced',
      acceptedAt: nowStamp(),
    }
  }
  onDeliveryUpdate?.('sent', nowStamp())
  setTimeout(() => onDeliveryUpdate?.('delivered', nowStamp()), 1200)

  return {
    threadId, messageId, replyAddress,
    fromAddress: `${TENANT_FROM_NAME} <${TENANT_FROM_ADDRESS}>`,
    trackedSubject: trackedSubject(req.subject, threadId),
    deliveryStatus: 'sent',
    acceptedAt: nowStamp(),
  }
}

async function mockUploadAttachment(file: File): Promise<AttachmentRef> {
  await sleep(200 + Math.random() * 400)
  const id = 'mock-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 5)
  return {
    storeName: id,
    attachmentName: file.name,
    attachmentPath: `/mock/attachments/${id}/${encodeURIComponent(file.name)}`,
    sizeBytes: file.size,
  }
}

async function mockCancelScheduled(scheduledMailId: string): Promise<boolean> {
  await sleep(150)
  void scheduledMailId
  return true
}

// ─────────────────────────────────────────────────────────────────────────────
// ZOHO-PROXY implementation (skeleton — uncomment when backend is live)
// ─────────────────────────────────────────────────────────────────────────────

/** Header bag with bearer token if the user is logged in. */
function authHeaders(extra: Record<string, string> = {}): Record<string, string> {
  const token = getToken()
  const out: Record<string, string> = { Accept: 'application/json', ...extra }
  if (token) {
    out.Authorization = `Bearer ${token}`
  }
  return out
}

async function proxySendThread(
  req: SendThreadRequest,
  _onDeliveryUpdate?: (status: DeliveryStatus, when: string) => void,
): Promise<SendThreadResponse> {
  const threadId = makeThreadId()
  const body = buildZohoSendRequest(req, threadId)
  const endpoint = req.scheduleAt ? '/schedule' : '/send'

  const resp = await fetch(`${PROXY_BASE_URL}${endpoint}`, {
    method: 'POST',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(body),
  })
  if (!resp.ok) {
    throw new Error(`mail proxy ${endpoint} failed: ${resp.status}`)
  }
  const zoho = (await resp.json()) as ZohoSendResponse

  return {
    threadId,
    messageId: zoho.data.messageId,
    replyAddress: makeReplyAddress(threadId),
    fromAddress: body.fromAddress,
    trackedSubject: body.subject,
    deliveryStatus: req.scheduleAt ? 'scheduled' : 'sent',
    acceptedAt: nowStamp(),
    scheduledMailId: zoho.data.scheduledMailId,
  }
}

async function proxyUploadAttachment(file: File): Promise<AttachmentRef> {
  const form = new FormData()
  form.append('file', file)
  const resp = await fetch(`${PROXY_BASE_URL}/attachments`, {
    method: 'POST',
    headers: authHeaders(), // browser sets multipart Content-Type automatically
    body: form,
  })
  if (!resp.ok) {
    throw new Error(`attachment upload failed: ${resp.status}`)
  }
  const zoho = (await resp.json()) as {
    storeName: string
    attachmentName: string
    attachmentPath: string
  }
  return { ...zoho, sizeBytes: file.size }
}

async function proxyCancelScheduled(scheduledMailId: string): Promise<boolean> {
  const resp = await fetch(`${PROXY_BASE_URL}/schedule/${scheduledMailId}`, {
    method: 'DELETE',
    headers: authHeaders(),
  })
  return resp.ok
}

// ─────────────────────────────────────────────────────────────────────────────
// Public API (routes to mock or proxy based on MODE)
// ─────────────────────────────────────────────────────────────────────────────

async function sendThread(
  req: SendThreadRequest,
  onDeliveryUpdate?: (status: DeliveryStatus, when: string) => void,
): Promise<SendThreadResponse> {
  return MODE === 'mock'
    ? mockSendThread(req, onDeliveryUpdate)
    : proxySendThread(req, onDeliveryUpdate)
}

async function sendBatch(
  items: BatchSendItem[],
  onItemUpdate?: (caseId: string, status: DeliveryStatus, when: string) => void,
): Promise<BatchSendResponse> {
  const batchId = 'B-' + Date.now().toString(36)
  const results = await Promise.all(
    items.map((item) =>
      sendThread(item, (status, when) => onItemUpdate?.(item.caseId, status, when)),
    ),
  )
  return { batchId, results, acceptedAt: nowStamp() }
}

/** Upload an attachment to Zoho (or mock) and get a reference for use in
 *  sendThread().attachments. Zoho requires this two-step because attachments
 *  can be reused across messages within a thread. */
async function uploadAttachment(file: File): Promise<AttachmentRef> {
  return MODE === 'mock' ? mockUploadAttachment(file) : proxyUploadAttachment(file)
}

/** Cancel a previously-scheduled Zoho mail. Returns true on success. */
async function cancelScheduled(scheduledMailId: string): Promise<boolean> {
  return MODE === 'mock' ? mockCancelScheduled(scheduledMailId) : proxyCancelScheduled(scheduledMailId)
}

/** MOCK-ONLY: simulate an inbound reply hitting our webhook/polling job.
 *  In MODE = 'zoho-proxy' the real source is /api/mail/incoming polling. */
function simulateIncomingReply(
  threadId: string,
  body: string,
  fromName: string,
  fromAddress: string,
): IncomingReplyEvent {
  return {
    threadId,
    fromName,
    fromAddress,
    body,
    receivedAt: nowStamp(),
  }
}

export function useEmailApi() {
  return {
    // Outbound
    sendThread,
    sendBatch,
    uploadAttachment,
    cancelScheduled,
    // Inbound (mock only for now)
    simulateIncomingReply,
    // Introspection
    mode: MODE,
    config: {
      tenantDomain: TENANT_DOMAIN,
      fromAddress: TENANT_FROM_ADDRESS,
      fromName: TENANT_FROM_NAME,
      proxyBaseUrl: PROXY_BASE_URL,
    },
  }
}
