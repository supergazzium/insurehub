<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Read product rates from the tall installments table
    |--------------------------------------------------------------------------
    |
    | Historically CommissionEngine::fetchProductRates() had two mismatches
    | that made it silently return zero for every party:
    |
    |   1. It compared `party` against 'InH' / 'AG' / 'Override' constants,
    |      but rows in product_commission_rate_installments are actually
    |      stored as 'com' / 'ag' / 'in' (Access legacy codes preserved
    |      by the importer).
    |   2. It filtered `installment_term = 0` (integer) against a string
    |      column that stores 'main' / '3' / '6' / '12'.
    |
    | As a result, product-level rates from the tall table have never
    | been applied. Every accrual since launch has come from the
    | per-policy overrides `policies.main_com_rate_{inh,ag}` — for
    | policies without those, no InH/AG/override rows were ever created.
    |
    | The fix (PR fix/commission-party-codes) corrects the match arm and
    | the term filter. Turning it on retroactively would start accruing
    | commission on payments whose policies were relying on the (broken)
    | fallback-to-zero behavior. To avoid a silent behavior change, the
    | fixed lookup is gated by this flag.
    |
    | Rollout:
    |   1. Deploy this PR with `COMMISSION_READ_PRODUCT_RATES=false`.
    |   2. On staging, flip to true. Audit which policies now accrue
    |      product-rate rows on next payment (see docs/commission-audit.md
    |      in the PR body).
    |   3. On production, run recomputeForPolicy() on affected policies
    |      in batches before flipping so the ledger back-fills
    |      consistently. Then set the env var.
    |
    */
    'read_product_rates' => env('COMMISSION_READ_PRODUCT_RATES', false),
];
