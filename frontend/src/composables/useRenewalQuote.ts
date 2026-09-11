/**
 * useRenewalQuote — assemble an InsureHub-branded renewal quotation from a
 * policy row + a manual-entry form, render it to a PDF, and hand back a File
 * ready to upload as a `renewal_quote_insurehub` document.
 *
 * Phase D of the renewal quotation pipeline. Reuses the shared Quotation model
 * and the jsPDF renderer (useQuotationPdf) so the output matches the rest of
 * the app's quotations. The numbers come from the operator (manual entry,
 * prefilled) — we do NOT parse the carrier's PDF (that's a deferred
 * enhancement).
 */

import type { ExpiringPolicy } from '../api/reports'
import { useQuotation, type Quotation, type QuotationExtraction } from './useQuotation'
import { useQuotationPdf } from './useQuotationPdf'

// Agency identity stamped on generated quotations. Mirrors the constants in
// AgentSupport.vue; centralize both here in a later cleanup.
const AGENCY_NAME = 'บริษัท เอบีซี อินชัวรันส์ จำกัด'
const AGENCY_PHONE = '02-555-0100'
const AGENCY_EMAIL = 'support@abc-insure.co.th'

/** The operator-editable fields of a renewal quotation. Prefilled from the
 *  policy, then adjusted against the carrier's returned figures. */
export interface RenewalQuoteForm {
  coverageAmount: number
  annualPremium: number
  premiumMode: QuotationExtraction['premium_mode']
  coveragePeriodYears: number
  paymentPeriodYears: number
  proposalSummary: string
  riders: string[]
  conditions: string[]
  exclusions: string[]
  nextSteps: string
}

/** A blank form seeded from the policy row's known figures. */
export function emptyRenewalQuoteForm(policy: ExpiringPolicy): RenewalQuoteForm {
  return {
    coverageAmount: 0,
    // Seed the premium from the current policy — the operator overrides it
    // with the carrier's renewal figure.
    annualPremium: Math.round(policy.annualPremium ?? 0),
    premiumMode: 'annual',
    coveragePeriodYears: 1,
    paymentPeriodYears: 1,
    proposalSummary: '',
    riders: [],
    conditions: [],
    exclusions: [],
    nextSteps: '',
  }
}

export function useRenewalQuote() {
  const quotationApi = useQuotation()
  const pdf = useQuotationPdf()

  /** Build the full Quotation object from a policy row + the manual form. */
  function buildQuotation(policy: ExpiringPolicy, form: RenewalQuoteForm): Quotation {
    const extraction: QuotationExtraction = {
      proposal_summary: form.proposalSummary,
      is_quotation_ready: true,
      policy_number: policy.policyNo ?? policy.applicationNo ?? null,
      coverage_amount: form.coverageAmount,
      annual_premium: form.annualPremium,
      premium_mode: form.premiumMode,
      coverage_period_years: form.coveragePeriodYears,
      payment_period_years: form.paymentPeriodYears,
      effective_date_thai: null,
      waiting_period_days: 0,
      riders: form.riders,
      conditions: form.conditions,
      exclusions: form.exclusions,
      documents_required: [],
      next_steps: form.nextSteps,
    }
    // Use the policy id as the "case id" seed for the quotation number.
    const caseId = policy.policyNo ?? policy.applicationNo ?? policy.policyId
    return {
      caseId,
      carrierName: policy.carrierName ?? policy.carrierCode ?? '',
      carrierCode: policy.carrierCode ?? '',
      clientName: policy.customerName,
      productName: policy.productName ?? policy.productCode ?? '',
      ...extraction,
      quotationNumber: quotationApi.makeQuotationNumber(caseId),
      generatedAt: new Date().toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }),
      agentName: policy.agentName ?? '',
      agencyName: AGENCY_NAME,
      agencyPhone: AGENCY_PHONE,
      agencyEmail: AGENCY_EMAIL,
      validUntil: quotationApi.defaultValidUntil(),
    }
  }

  /** Render the quotation to a PDF File named by its quotation number. */
  async function renderToFile(quotation: Quotation): Promise<File> {
    const blob = await pdf.renderToBlob(quotation)
    return new File([blob], `${quotation.quotationNumber}.pdf`, { type: 'application/pdf' })
  }

  return { buildQuotation, renderToFile, downloadPdf: pdf.downloadPdf }
}
