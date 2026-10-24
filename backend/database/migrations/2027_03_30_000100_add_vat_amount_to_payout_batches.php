<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT visibility on payout batches (money-safety 1A).
 *
 * The per-agent PDF already computes VAT correctly (commission + 7% for
 * VAT-exclude agents) but the stored net_transfer — the amount actually sent to
 * the bank — never included it, so the statement and the deposit disagreed.
 *
 * This migration only makes VAT VISIBLE: it persists the VAT amount and the
 * "what net would be if VAT were included" figure so the UI can flag the
 * discrepancy. It does NOT change net_transfer (the paid amount) — that waits
 * for accounting sign-off on the VAT rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_payout_batch_agents', function (Blueprint $table): void {
            // VAT computed from vat_type (exclude 7% add, include carve-out, none 0).
            $table->decimal('vat_amount', 15, 2)->default(0)->after('wht_amount');
            // gross + vat − deduct − wht — what net_transfer WOULD be if VAT were
            // included. Differs from net_transfer whenever vat_amount > 0.
            $table->decimal('vat_expected_net', 15, 2)->nullable()->after('net_transfer');
        });

        Schema::table('commission_payout_batch_items', function (Blueprint $table): void {
            $table->decimal('vat_amount', 15, 2)->default(0)->after('snapshot_wht_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commission_payout_batch_agents', function (Blueprint $table): void {
            $table->dropColumn(['vat_amount', 'vat_expected_net']);
        });
        Schema::table('commission_payout_batch_items', function (Blueprint $table): void {
            $table->dropColumn('vat_amount');
        });
    }
};
