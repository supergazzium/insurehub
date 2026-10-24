<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch reference code (money-safety 1F).
 *
 * Payout batches had no human reference — only a free-text payment_reference set
 * at pay time. This adds a stable PO-YYMM-NN code generated at creation (mirrors
 * the receipt module's REC-YYYYMM-NNNN), so每 batch is identifiable in history,
 * exports, and conversations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_payout_batches', function (Blueprint $table): void {
            $table->string('reference_code', 24)->nullable()->after('id');
            $table->unique(['tenant_id', 'reference_code'], 'cpb_tenant_ref_unq');
        });
    }

    public function down(): void
    {
        Schema::table('commission_payout_batches', function (Blueprint $table): void {
            $table->dropUnique('cpb_tenant_ref_unq');
            $table->dropColumn('reference_code');
        });
    }
};
