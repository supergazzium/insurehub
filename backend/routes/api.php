<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AdminAgentApprovalController;
use App\Http\Controllers\Api\AdminRoleController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CarrierBankAccountController;
use App\Http\Controllers\Api\CarrierContactController;
use App\Http\Controllers\Api\CarrierContactGroupController;
use App\Http\Controllers\Api\CarrierController;
use App\Http\Controllers\Api\CarrierCredentialController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CustomerAssignmentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerKycDocController;
use App\Http\Controllers\Api\CustomerReferralLinkController;
use App\Http\Controllers\Api\EmailOtpController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\EndorsementController;
use App\Http\Controllers\Api\ImportFailureController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\MailController;
use App\Http\Controllers\Api\MeAgentController;
use App\Http\Controllers\Api\MotorActTariffController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\PolicyDocumentController;
use App\Http\Controllers\Api\PolicyEventController;
use App\Http\Controllers\Api\PolicyPaymentController;
use App\Http\Controllers\Api\PolicyRebateController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductTaxonomyController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\RecruitmentLinkController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — mounted at /api/v1 (see bootstrap/app.php).
|--------------------------------------------------------------------------
*/

Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/email-otp/send', [EmailOtpController::class, 'send']);
Route::post('auth/email-otp/verify', [EmailOtpController::class, 'verify']);
Route::post('auth/check-availability', [AuthController::class, 'checkAvailability']);
Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

// Unauthenticated public data (recruitment-link preview, lookups, etc.)
Route::get('public/recruit/{token}', [PublicController::class, 'recruitLink']);
Route::get('public/lookup/banks', [PublicController::class, 'banks']);
Route::get('public/lookup/provinces', [PublicController::class, 'provinces']);
Route::get('public/lookup/districts', [PublicController::class, 'districts']);
Route::get('public/lookup/sub-districts', [PublicController::class, 'subDistricts']);

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/change-password', [MeAgentController::class, 'changePassword']);

    // Agent portal — "me as an agent" endpoints. Every route checks
    // users.agent_id is set; admins-with-no-agent get 404.
    Route::get('me/agent', [MeAgentController::class, 'show']);
    Route::patch('me/agent/profile', [MeAgentController::class, 'updateProfile']);
    Route::patch('me/agent/id-document', [MeAgentController::class, 'updateIdDocument']);
    Route::patch('me/agent/license', [MeAgentController::class, 'updateLicense']);
    Route::patch('me/agent/bank', [MeAgentController::class, 'updateBank']);
    Route::patch('me/agent/address', [MeAgentController::class, 'updateAddress']);
    Route::patch('me/agent/delivery', [MeAgentController::class, 'updateDelivery']);
    Route::patch('me/agent', [MeAgentController::class, 'updateAll']);
    Route::post('me/agent/profile-photo', [MeAgentController::class, 'uploadProfilePhoto']);
    Route::post('me/agent/id-photo', [MeAgentController::class, 'uploadIdPhoto']);
    Route::post('me/agent/bank-book-photo', [MeAgentController::class, 'uploadBankBookPhoto']);
    // Streamed download of the current agent's own photos (profile/id/bank).
    // Files live on the private `local` disk — this route serves them
    // after session auth so <img> can render sensitive images without
    // exposing a public /storage URL.
    Route::get('me/agent/photo/{kind}', [MeAgentController::class, 'photo']);
    Route::get('me/agent/id-card-unmask', [MeAgentController::class, 'unmaskIdCard']);
    Route::get('me/agent/referral-link', [MeAgentController::class, 'referralLink']);
    Route::get('me/agent/downline', [MeAgentController::class, 'downline']);
    Route::get('me/agent/earnings', [MeAgentController::class, 'earnings']);

    // RBAC admin — roles, permissions catalog, user role assignment.
    Route::get('admin/roles', [AdminRoleController::class, 'index']);
    Route::post('admin/roles', [AdminRoleController::class, 'store']);
    Route::get('admin/roles/{role}', [AdminRoleController::class, 'show']);
    Route::patch('admin/roles/{role}', [AdminRoleController::class, 'update']);
    Route::put('admin/roles/{role}/permissions', [AdminRoleController::class, 'setPermissions']);
    Route::delete('admin/roles/{role}', [AdminRoleController::class, 'destroy']);
    Route::get('admin/permissions', [AdminRoleController::class, 'permissions']);
    Route::get('admin/users', [AdminUserController::class, 'index']);
    Route::get('admin/users/{user}', [AdminUserController::class, 'show']);
    Route::patch('admin/users/{user}/role', [AdminUserController::class, 'setRole']);
    Route::post('admin/users/{user}/overrides', [AdminUserController::class, 'addOverride']);
    Route::delete('admin/users/{user}/overrides/{overrideId}', [AdminUserController::class, 'removeOverride']);

    // Admin approval + oversight (role-gated inside the controller).
    Route::get('admin/agents/pending', [AdminAgentApprovalController::class, 'pending']);
    Route::post('admin/agents/{agent}/approve', [AdminAgentApprovalController::class, 'approve']);
    Route::post('admin/agents/{agent}/reject', [AdminAgentApprovalController::class, 'reject']);
    Route::get('admin/agents/{agent}/audit', [AdminAgentApprovalController::class, 'audit']);
    Route::get('admin/agents/{agent}/downline-tree', [AdminAgentApprovalController::class, 'downlineTree']);

    // Phase 7b — Admin payout cycle.
    // Admin payout routes removed with the old commission engine. The new
    // MGM commission_ledgers + payout system will re-introduce equivalents.

    // Tenant settings (single resource).
    Route::get('tenant', [TenantController::class, 'show']);
    Route::patch('tenant', [TenantController::class, 'update']);

    // Business entities — REST resource routes.
    Route::apiResource('agents', AgentController::class);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('carriers', CarrierController::class);
    Route::apiResource('carriers.bank-accounts', CarrierBankAccountController::class)
        ->parameters(['bank-accounts' => 'bankAccount'])
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('carriers.contacts', CarrierContactController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('carriers.credentials', CarrierCredentialController::class)
        ->parameters(['credentials' => 'credential'])
        ->only(['index', 'store', 'update', 'destroy']);
    // Tenant-wide label suggestions for the credential sticky-note picker.
    // Registered above the resource routes wouldn't be needed (no path
    // collision), but kept adjacent so the credentials surface is grouped.
    Route::get('carrier-credentials/labels', [CarrierCredentialController::class, 'labels']);
    Route::apiResource('products', ProductController::class);
    Route::get('carriers/{carrier}/products/next-code', [ProductController::class, 'nextCode']);
    Route::get('product-categories', [ProductTaxonomyController::class, 'index']);

    // Phase 5 — Quotation module. Quotes live in the policies table with
    // status='quote'; converted quotes flip to 'application'.
    Route::get('quotes', [QuoteController::class, 'index']);
    Route::post('quotes', [QuoteController::class, 'store']);
    Route::get('quotes/act-tariffs', [QuoteController::class, 'actTariffs']);
    Route::post('quotes/premium/preview', [QuoteController::class, 'premiumPreview']);
    Route::get('quotes/{policy}', [QuoteController::class, 'show']);
    Route::patch('quotes/{policy}', [QuoteController::class, 'update']);
    Route::post('quotes/{policy}/convert', [QuoteController::class, 'convert']);
    Route::apiResource('contracts', ContractController::class);
    Route::apiResource('policies', PolicyController::class);
    // Phase 6 — sectioned edit (parties/dates/premium/payment/notes/identifiers).
    Route::patch('policies/{policy}/section/{section}', [PolicyController::class, 'patchSection']);
    // Phase 8b — renewal actions (log touchpoints + email notice).
    Route::post('policies/{policy}/renewal/contacted', [PolicyController::class, 'markRenewalContacted']);
    Route::post('policies/{policy}/renewal/started', [PolicyController::class, 'markRenewalStarted']);
    Route::post('policies/{policy}/renewal/send-notice', [PolicyController::class, 'sendRenewalNotice']);
    // Phase 9 — endorsements (event log per policy).
    Route::get('policies/{policy}/endorsements', [EndorsementController::class, 'index']);
    Route::post('policies/{policy}/endorsements', [EndorsementController::class, 'store']);
    // Phase 9 — motor tariff admin CRUD.
    Route::get('motor-act-tariffs', [MotorActTariffController::class, 'index']);
    Route::post('motor-act-tariffs', [MotorActTariffController::class, 'store']);
    Route::patch('motor-act-tariffs/{tariff}', [MotorActTariffController::class, 'update']);
    Route::delete('motor-act-tariffs/{tariff}', [MotorActTariffController::class, 'destroy']);
    // Phase 6b — riders + beneficiaries sync (replace-all shape).
    Route::put('policies/{policy}/riders', [PolicyController::class, 'syncRiders']);
    Route::put('policies/{policy}/beneficiaries', [PolicyController::class, 'syncBeneficiaries']);
    // Phase 6b — multipart doc upload (in addition to the existing JSON store).
    Route::post('policies/{policy}/documents/upload', [PolicyDocumentController::class, 'upload']);
    Route::get('policies/{policy}/documents/{document}/download', [PolicyDocumentController::class, 'download']);

    // Carrier contact groups + email templates.
    Route::apiResource('carrier-contact-groups', CarrierContactGroupController::class)
        ->parameters(['carrier-contact-groups' => 'contactGroup']);
    Route::apiResource('email-templates', EmailTemplateController::class)
        ->parameters(['email-templates' => 'template']);

    // Recruitment links — create rotates the active link, delete revokes it.
    Route::get('recruitment-links', [RecruitmentLinkController::class, 'index']);
    Route::post('recruitment-links', [RecruitmentLinkController::class, 'store']);
    Route::delete('recruitment-links/{recruitmentLink}', [RecruitmentLinkController::class, 'destroy']);

    // Customer children: KYC docs + assignment history.
    Route::post('customers/{customer}/kyc-docs', [CustomerKycDocController::class, 'store']);
    Route::patch('customers/{customer}/kyc-docs/{kycDoc}/verify', [CustomerKycDocController::class, 'verify']);
    Route::delete('customers/{customer}/kyc-docs/{kycDoc}', [CustomerKycDocController::class, 'destroy']);
    Route::get('customers/{customer}/assignments', [CustomerAssignmentController::class, 'index']);
    Route::post('customers/{customer}/assignments', [CustomerAssignmentController::class, 'store']);
    Route::post('customers/{customer}/merge', [CustomerController::class, 'merge']);

    // Customer referral links — same shape as recruitment-links.
    Route::get('customer-referral-links', [CustomerReferralLinkController::class, 'index']);
    Route::post('customer-referral-links', [CustomerReferralLinkController::class, 'store']);
    Route::delete('customer-referral-links/{customerReferralLink}', [CustomerReferralLinkController::class, 'destroy']);

    // Policy children: payments + documents + lifecycle events.
    Route::post('policies/{policy}/payments', [PolicyPaymentController::class, 'store']);
    Route::delete('policies/{policy}/payments/{payment}', [PolicyPaymentController::class, 'destroy']);
    Route::post('policies/{policy}/documents', [PolicyDocumentController::class, 'store']);
    Route::delete('policies/{policy}/documents/{document}', [PolicyDocumentController::class, 'destroy']);
    Route::get('policies/{policy}/events', [PolicyEventController::class, 'index']);
    Route::post('policies/{policy}/events', [PolicyEventController::class, 'store']);

    // Editable rebate ledger — inline edits from /commissions/rebates.
    Route::patch('policy-rebates/{rebate}', [PolicyRebateController::class, 'update']);

    // Mail (Zoho-proxied). Bodies are Zoho-shaped — see useEmailApi.ts on the frontend.
    Route::prefix('mail')->group(function (): void {
        Route::post('send', [MailController::class, 'send']);
        Route::post('schedule', [MailController::class, 'schedule']);
        Route::delete('schedule/{scheduledMailId}', [MailController::class, 'cancelScheduled']);
        Route::post('attachments', [MailController::class, 'uploadAttachment']);
        Route::get('incoming', [MailController::class, 'incoming']);
    });

    // Reports — analytics endpoints ported from the insurehub_legacy v_* views.
    Route::prefix('reports')->group(function (): void {
        Route::get('dashboard-kpis', [ReportController::class, 'dashboardKpis']);
        Route::get('expiring-soon', [ReportController::class, 'expiringSoon']);
        Route::get('expiring-soon/pdf', [ReportController::class, 'expiringSoonPdf']);
        Route::get('active-policies', [ReportController::class, 'activePolicies']);
        Route::get('agent-performance', [ReportController::class, 'agentPerformance']);
        Route::get('product-performance', [ReportController::class, 'productPerformance']);
        Route::get('new-vs-renew-by-month', [ReportController::class, 'newVsRenewByMonth']);
        Route::get('cancellation-ledger', [ReportController::class, 'cancellationLedger']);
        Route::get('rebate-reconciliation', [ReportController::class, 'rebateReconciliation']);
        // Phase 8a — operational reports
        Route::get('freelook', [ReportController::class, 'freelook']);
        Route::get('mailing-pipeline', [ReportController::class, 'mailingPipeline']);
        Route::get('payment-history', [ReportController::class, 'paymentHistory']);
    });

    // Applications import failures triage queue (populated by insurehub:import).
    Route::get('import-failures', [ImportFailureController::class, 'index']);
    Route::get('import-failures/summary', [ImportFailureController::class, 'summary']);
    Route::patch('import-failures/{failure}/resolve', [ImportFailureController::class, 'resolve']);

    // Lookup tables (read-only).
    Route::prefix('lookups')->group(function (): void {
        Route::get('banks', [LookupController::class, 'banks']);
        Route::get('nationalities', [LookupController::class, 'nationalities']);
        Route::get('religions', [LookupController::class, 'religions']);
        Route::get('occupations', [LookupController::class, 'occupations']);
        Route::get('name-prefixes', [LookupController::class, 'prefixes']);
        Route::get('locations', [LookupController::class, 'locations']);
        Route::get('policy-statuses', [LookupController::class, 'policyStatuses']);
        Route::get('payment-methods', [LookupController::class, 'paymentMethods']);
        Route::get('motor-vehicles', [LookupController::class, 'motorVehicles']);
    });
});
