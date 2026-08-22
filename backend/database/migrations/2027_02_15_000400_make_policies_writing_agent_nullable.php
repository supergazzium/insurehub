<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * C-14 — relax `policies.writing_agent_id` to nullable so the wizard's
 * draft-safe autosave (POST /policies/draft) can persist a partial row
 * before the operator picks an agent on Step 1.
 *
 * Rationale: the 5-step wizard's autosave fires as soon as a customer
 * is picked, but writing_agent lives in a separate Step 1 slot. Forcing
 * NOT NULL here means the operator loses the auto-save affordance for
 * any partial fill that hasn't reached the agent picker yet.
 *
 * The promote endpoints (POST /policies/{id}/promote-to-quotation and
 * /promote-to-submitted) don't add an explicit non-null check on
 * writing_agent_id — but PolicyController::storeDraft already defaults
 * it to the authenticated user's agent_id where available, so most
 * drafts end up with the column populated regardless.
 *
 * A follow-up migration in C-20 can re-tighten the constraint (via a
 * partial index gated on `status != 'draft'`) once we're confident the
 * wizard populates it before the promote step.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Use raw SQL because Blueprint's `->change()` needs doctrine/dbal.
        // Three FKs relaxed together — all three are picked in Step 1-2 of
        // the wizard and any of them may still be unset while the auto-save
        // fires on the first field blur.
        DB::statement('ALTER TABLE `policies` MODIFY `writing_agent_id` BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE `policies` MODIFY `product_id` BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE `policies` MODIFY `carrier_id` BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Down assumes no drafts exist that would violate NOT NULL. Safe
        // because C-14's shipping strategy keeps drafts short-lived
        // (retention cron in C-15).
        DB::statement('ALTER TABLE `policies` MODIFY `writing_agent_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `policies` MODIFY `product_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `policies` MODIFY `carrier_id` BIGINT UNSIGNED NOT NULL');
    }
};
