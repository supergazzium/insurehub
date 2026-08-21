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
  productTypeId: string | null
  // Denormalized product-type block from ProductResource. Nullable when the
  // product isn't linked to a product_type yet. `kind` drives the wizard's
  // Step 3 dynamic risk renderer; `riskSchema` is the JSON shape it reads.
  productType: {
    id: string
    code: string
    nameTh: string
    kind: 'motor' | 'travel' | 'fire' | 'health' | 'life' | 'misc' | null
    riskSchema: Record<string, unknown> | null
  } | null
  commissionTierId: string | null
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
  commissionRates: ProductCommissionRatesBlock
  commissionBands: ProductCommissionBandsBlock
}

/** One row in a Life product's banded commission table. All rate fields
 *  are fractions 0..1 (0.30 = 30 %). Nullable min/max = unbounded on that
 *  side (min=null → 0; max=null → +∞). Age fields are integer years. */
export interface ProductCommissionBandRow {
  sumAssuredMin: number | null
  sumAssuredMax: number | null
  entryAgeMin: number | null
  entryAgeMax: number | null
  yr1: number | null
  yr2: number | null
  yr3: number | null
  yr4: number | null
  yr5: number | null
  yr6Up: number | null
}

/** Bands grouped by direction. Both arrays present but empty when the
 *  product has no banded rates configured (typical for non-Life). */
export interface ProductCommissionBandsBlock {
  carrierToHub: ProductCommissionBandRow[]
  hubToAgent: ProductCommissionBandRow[]
}

/** Payload for writing bands via POST/PATCH products. Replace-all
 *  semantics: passing an array replaces every band on that direction; not
 *  passing the key leaves them untouched. */
export interface ProductCommissionBandsPayload {
  carrierToHub?: ProductCommissionBandRow[]
  hubToAgent?: ProductCommissionBandRow[]
}

/** One direction of commission on a product. Every value is a fraction
 *  0..1 (0.10 = 10 %). Which fields are used depends on the scheme —
 *  `flat` uses `flatRate`, `life_years` uses the per-year vector. */
export interface ProductCommissionRatePanel {
  flatRate: number | null
  yr1: number | null
  yr2: number | null
  yr3: number | null
  yr4: number | null
  yr5: number | null
  yr6_10: number | null
  yr11Up: number | null
}

/** Both directions plus the scheme derived from the product group. The
 *  panels are null when the product has no saved rate row yet (fresh
 *  product before admin fills in the rates). */
export interface ProductCommissionRatesBlock {
  scheme: 'flat' | 'life_years'
  carrierToHub: ProductCommissionRatePanel | null
  hubToAgent: ProductCommissionRatePanel | null
}

/** Payload shape when writing rates via POST/PATCH products. Matches
 *  ProductRequest's commissionRates validation. Fields left undefined
 *  are treated as "no change"; explicit nulls clear a field. */
export interface ProductCommissionRatesPayload {
  carrierToHub?: Partial<ProductCommissionRatePanel>
  hubToAgent?: Partial<ProductCommissionRatePanel>
}

export function fetchProduct(id: string) {
  return api.get<{ data: ProductDetail }>(`products/${id}`)
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
