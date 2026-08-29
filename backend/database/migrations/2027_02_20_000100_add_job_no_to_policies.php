<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เลขงาน (work/job number) — a per-policy running number minted at draft
 * creation alongside application_no. Format: J<YYMMDD><4-digit daily serial>
 * (e.g. J2608270001), matching the daily-reset pattern used for
 * application_no (A<YYMMDD>NNNN). Allocated by App\Support\PolicyNumbering.
 *
 * Nullable + no unique index: legacy imported rows have no job_no, and the
 * allocator already guards against collisions via MAX+1 per (tenant, day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->string('job_no', 32)->nullable()->after('application_no');
            $table->index(['tenant_id', 'job_no']);
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'job_no']);
            $table->dropColumn('job_no');
        });
    }
};
