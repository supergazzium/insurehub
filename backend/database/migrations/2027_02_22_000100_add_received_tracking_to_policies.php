<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * การได้รับกรมธรรม์ (policy received) tracking — records when the physical /
 * digital policy document was received back from the carrier, separate from
 * issue_date (when the carrier issued it) and mailing_date (when it was
 * dispatched to the customer). Two nullable columns:
 *
 *   received_date  — date the policy was received from the carrier
 *   received_note  — free-text note (courier, condition, who received, ...)
 *
 * Delivery-to-customer tracking reuses the existing mailing_date / mailing_note
 * columns, so no new column is needed there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->date('received_date')->nullable()->after('issue_date');
            $table->text('received_note')->nullable()->after('received_date');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn(['received_date', 'received_note']);
        });
    }
};
