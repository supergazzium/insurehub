<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy data reuses the same `policy_number` across many application rows
     * (up to ~100 in extreme cases) — one master policy with multiple
     * installment applications, or long-running renewals sharing the number.
     *
     * The original `unique(tenant_id, policy_no)` constraint is too strict for
     * that domain reality. Downgrade to a plain index so we still have fast
     * lookup by `policy_no` without rejecting duplicates.
     *
     * `application_no` remains unique per tenant — that's the true identity.
     */
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropUnique('policies_tenant_id_policy_no_unique');
            $table->index(['tenant_id', 'policy_no']);
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'policy_no']);
            $table->unique(['tenant_id', 'policy_no']);
        });
    }
};
