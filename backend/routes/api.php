<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CarrierContactGroupController;
use App\Http\Controllers\Api\CarrierController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CustomerAssignmentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerKycDocController;
use App\Http\Controllers\Api\CustomerReferralLinkController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\MailController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\PolicyDocumentController;
use App\Http\Controllers\Api\PolicyEventController;
use App\Http\Controllers\Api\PolicyPaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RecruitmentLinkController;
use App\Http\Controllers\Api\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — mounted at /api/v1 (see bootstrap/app.php).
|--------------------------------------------------------------------------
*/

Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // Tenant settings (single resource).
    Route::get('tenant', [TenantController::class, 'show']);
    Route::patch('tenant', [TenantController::class, 'update']);

    // Business entities — REST resource routes.
    Route::apiResource('agents', AgentController::class);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('carriers', CarrierController::class);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('contracts', ContractController::class);
    Route::apiResource('policies', PolicyController::class);

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

    // Commission ledger.
    Route::get('commissions/transactions', [CommissionController::class, 'transactions']);
    Route::post('commissions/transactions', [CommissionController::class, 'storeTransaction']);

    // Mail (Zoho-proxied). Bodies are Zoho-shaped — see useEmailApi.ts on the frontend.
    Route::prefix('mail')->group(function (): void {
        Route::post('send', [MailController::class, 'send']);
        Route::post('schedule', [MailController::class, 'schedule']);
        Route::delete('schedule/{scheduledMailId}', [MailController::class, 'cancelScheduled']);
        Route::post('attachments', [MailController::class, 'uploadAttachment']);
        Route::get('incoming', [MailController::class, 'incoming']);
    });

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
