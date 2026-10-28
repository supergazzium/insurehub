<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money leaves a track even when the policy is deleted.
 *
 * The commission / payout / receivable / rebate tables were declared
 * cascadeOnDelete on policy_id (and the ledger on policy_payment_id), so a hard
 * delete of a policy would silently erase its financial history. Today a policy
 * is only ever SOFT-deleted and non-draft deletes are blocked, so the cascade
 * never fires — but the audit trail must be protected by the schema, not by
 * convention. Flip those FKs to RESTRICT: a policy (or payment) that carries any
 * money row simply cannot be hard-deleted. Draft policies with no money history
 * stay freely deletable.
 */
return new class extends Migration
{
    /** [table, column, referenced-table] — the money FKs to protect. */
    private array $fks = [
        ['commission_ledgers', 'policy_id', 'policies'],
        ['commission_ledgers', 'policy_payment_id', 'policy_payments'],
        ['commission_payout_batch_items', 'policy_id', 'policies'],
        ['commission_receivables', 'policy_id', 'policies'],
        ['policy_rebates', 'policy_id', 'policies'],
    ];

    public function up(): void
    {
        foreach ($this->fks as [$table, $column, $ref]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($column, $ref): void {
                $t->dropForeign([$column]);
                $t->foreign($column)->references('id')->on($ref)->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->fks as [$table, $column, $ref]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($column, $ref): void {
                $t->dropForeign([$column]);
                $t->foreign($column)->references('id')->on($ref)->cascadeOnDelete();
            });
        }
    }
};
