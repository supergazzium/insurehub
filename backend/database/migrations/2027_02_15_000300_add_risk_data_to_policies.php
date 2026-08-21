<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C-4 — additive `risk_data` JSON column on policies.
 *
 * See docs/audit-2026-08-21/B2-schema-plan.md §2. The column ships
 * empty; the writer shim in PolicyRequest starts populating it on the
 * next write; the backfill command (C-5) fills historical rows.
 *
 * Positioned after `motor_notes` to group the retired-motor columns
 * next to their new home during the shim window (visually easier for
 * DBA inspection).
 *
 * Zero breaking risk: column is nullable, no code reads it yet. Reader
 * shim in PolicyResource (§C-4 code changes) prefers `risk_data` when
 * present and falls back to the top-level columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $t): void {
            $t->json('risk_data')->nullable()->after('motor_notes');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $t): void {
            $t->dropColumn('risk_data');
        });
    }
};
