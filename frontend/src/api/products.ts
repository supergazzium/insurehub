// Typed clients for /api/v1/products.

import { api, buildQuery, type Paginated } from './client'

/** Lean row returned by ProductController::index — matches ProductListResource. */
export interface ProductListRow {
  id: string
  code: string
  commissionCode: string
  name: string
  nameEn: string
  carrierId: string
  carrierCode: string | null
  carrierName: string | null
  carrierInsureType: string
  type: string
  category: string
  subCategory: string
  subCategory2: string
  mainRider: string
  /** UI-facing bucket derived from type/category/subCategory2. Drives the
   *  create-wizard's conditional block. */
  productKind: 'motor' | 'life' | 'travel' | 'property' | 'health' | 'other'
  minAge: number
  maxAge: number
  minSumAssure: number | null
  maxSumAssure: number | null
  validStart: string | null
  validEnd: string | null
  active: boolean
}

export interface ProductListFilters {
  q?: string
  carrierId?: string
  /** Carrier's insure_type — life / non-life / tax. Filters the joined
   *  carriers row, independent of `type` (which filters pr.type). */
  insureType?: 'life' | 'non-life' | 'tax'
  type?: string
  category?: string
  mainRider?: string
  activeOnly?: boolean
  page?: number
  perPage?: number
}

export function fetchProductList(filters: ProductListFilters = {}) {
  return api.get<Paginated<ProductListRow>>(`products${buildQuery({ ...filters })}`)
}

/** Full product detail (matches ProductResource). Extends ProductListRow
 *  with every editable field so the create-wizard can prefill from an
 *  existing product ("duplicate from…"). */
export interface ProductDetail extends ProductListRow {
  summary: string
  coverage: number
  coverageClass: '1' | '2+' | '2' | '3+' | '3' | null
  vehicleAgeMin: number | null
  vehicleAgeMax: number | null
  durationYears: number
  payYears: number
  premiumMode: 'monthly' | 'quarterly' | 'semiannual' | 'annual' | 'single'
  minPremium: number
  maxPremium: number
  gender: 'all' | 'male' | 'female'
  requireMedical: boolean
  smokerAccepted: boolean
  preexistingExcluded: boolean
  occupationClasses: string[]
  notes: string
}

export function fetchProduct(id: string) {
  return api.get<{ data: ProductDetail }>(`products/${id}`)
}

export interface CommissionRateRow {
  id: string
  party: string
  installmentTerm: string | null
  minSumAssure: number | null
  maxSumAssure: number | null
  rate: number
}

/** Per-party rate slot inside the structured `commissionRates` payload.
 *  All fields are percents (0..100). Null = "don't override this party". */
export interface RateTriple {
  inh: number | null
  ag: number | null
  ov: number | null
}

/** One row in a band-shape payload — a rate tier scoped to a sum-assured
 *  bracket and (optionally) a specific installment term. */
export interface BandRow extends RateTriple {
  minSumAssure: number | null
  maxSumAssure: number | null
  installmentTerm: string | null
}

/** One entry-age bracket in the age-year shape. Carries a full Y1..Y6+ ×
 *  3-party grid; both age bounds are nullable (null = unbounded that side). */
export interface AgeBracket {
  minAge: number | null
  maxAge: number | null
  years: Record<string, RateTriple>
}

/** Structured rate payload sent on product create/update. Matches
 *  ProductRequest::rules() `commissionRates.*`. */
export type CommissionRatesPayload =
  | { shape: 'skip' }
  | { shape: 'flat'; installments: Record<string, RateTriple> }
  | { shape: 'installment'; installments: Record<string, RateTriple> }
  | { shape: 'per-year'; years: Record<string, RateTriple> }
  | { shape: 'band'; bands: BandRow[] }
  | { shape: 'age-year'; brackets: AgeBracket[] }

/** Response of GET /products/{id}/commission-rates.
 *  - `years` is null when the product has no wide-table row. When the product
 *    uses age brackets, `years` holds the first bracket for backward compat.
 *  - `bands` is the installments data bucketed by (min, max, term).
 *  - `brackets` is the full list of age-year rows. */
export interface CommissionRatesResponse {
  data: CommissionRateRow[]
  years: Record<string, RateTriple> | null
  bands: BandRow[]
  brackets: AgeBracket[]
}

export function fetchProductCommissionRates(productId: string) {
  return api.get<CommissionRatesResponse>(`products/${productId}/commission-rates`)
}

/** PUT structured rates onto an existing product. Reuses the PATCH shape the
 *  product endpoint already accepts. */
export function updateProductCommissionRates(productId: string, rates: CommissionRatesPayload) {
  return api.patch<{ data: unknown }>(`products/${productId}`, { commissionRates: rates })
}

/** Next available `PD{carrierCode}{NNNN}` code for a carrier. */
export function fetchNextProductCode(carrierId: string) {
  return api.get<{ code: string; carrierCode: string; next: number }>(`carriers/${carrierId}/products/next-code`)
}

/** One entry in the flat product taxonomy — matches ProductTaxonomyController. */
export interface ProductTaxonomyRow {
  group: string
  category: string
  subcategory: string | null
}

export function fetchProductTaxonomy() {
  return api.get<{ data: ProductTaxonomyRow[] }>('product-categories')
}
