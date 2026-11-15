<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Two post-migration payment tidy-ups surfaced by the payment-anomaly sweep.
 * Both are cosmetic (no money changes) and idempotent.
 *
 *  1. Flag un-flagged negative payment rows as endorsement refunds. A negative
 *     payment is a สลักหลัง refund (a member left a group policy). The group
 *     endorsement fold already flagged the folded ones adjustment_kind=
 *     'endorsement'; this catches the residual negatives (the un-folded
 *     ambiguous group refunds + a stray refund mixed into a payment stream) so
 *     every negative row is consistently a flagged endorsement, not a mystery
 *     negative "payment".
 *
 *  2. Remove ฿0 placeholder payment rows on policies that ALSO have a real
 *     (> 0) payment. These empty rows are legacy scheduled/placeholder entries
 *     that carry no money and clutter the payment history. Deleting them does
 *     not change any sum (they are ฿0) and only touches rows with no installment
 *     or proof-document link. Policies whose ONLY payment is ฿0 (genuinely no
 *     payment recorded in the source) are left untouched.
 *
 *   php artisan payments:tidy-legacy [--tenant=1] [--dry-run]
 */
class TidyLegacyPayments extends Command
{
    protected $signature = 'payments:tidy-legacy {--tenant=1} {--dry-run}';

    protected $description = 'Flag negative payments as endorsement refunds and remove ฿0 placeholder rows (no money change).';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $sumBefore = (float) DB::table('policy_payments')->whereNull('voided_at')->sum('amount');

        // --- 1. flag un-flagged negatives as endorsement refunds ---
        $negQuery = DB::table('policy_payments as pp')
            ->join('policies as po', 'po.id', '=', 'pp.policy_id')
            ->where('po.tenant_id', $tenant)
            ->whereNull('po.deleted_at')
            ->whereNull('pp.voided_at')
            ->where('pp.amount', '<', 0)
            ->where(function ($q): void {
                $q->whereNull('pp.adjustment_kind')->orWhere('pp.adjustment_kind', '<>', 'endorsement');
            });
        $negCount = (clone $negQuery)->count();
        if (! $dry && $negCount > 0) {
            $ids = (clone $negQuery)->pluck('pp.id');
            DB::table('policy_payments')->whereIn('id', $ids)
                ->update(['adjustment_kind' => 'endorsement', 'updated_at' => DB::raw('NOW()')]);
        }

        // --- 2. delete ฿0 placeholder rows on policies that also have a real payment ---
        // policies (this tenant, live) with at least one >0 payment
        $zeroQuery = DB::table('policy_payments as pp')
            ->join('policies as po', 'po.id', '=', 'pp.policy_id')
            ->where('po.tenant_id', $tenant)
            ->whereNull('po.deleted_at')
            ->whereNull('pp.voided_at')
            ->where(function ($q): void {
                $q->whereNull('pp.amount')->orWhere('pp.amount', '=', 0);
            })
            ->whereNull('pp.policy_installment_id')
            ->whereNull('pp.proof_document_id')
            ->whereExists(function ($sub): void {
                $sub->select(DB::raw(1))->from('policy_payments as pp2')
                    ->whereColumn('pp2.policy_id', 'pp.policy_id')
                    ->whereNull('pp2.voided_at')
                    ->where('pp2.amount', '>', 0);
            });
        $zeroCount = (clone $zeroQuery)->count();
        if (! $dry && $zeroCount > 0) {
            $ids = (clone $zeroQuery)->pluck('pp.id');
            DB::table('policy_payments')->whereIn('id', $ids)->delete();
        }

        $sumAfter = (float) DB::table('policy_payments')->whereNull('voided_at')->sum('amount');

        $this->table(['action', 'count'], [
            ['negative rows flagged as endorsement', $negCount],
            ['฿0 placeholder rows removed', $zeroCount],
            ['payment $ before', number_format($sumBefore, 2)],
            ['payment $ after (MUST equal before)', number_format($sumAfter, 2)],
        ]);

        if (! $dry && abs($sumAfter - $sumBefore) > 0.01) {
            $this->error('MONEY CHANGED — abort. Review.');

            return self::FAILURE;
        }
        $this->info($dry ? '[dry-run] no writes.' : 'tidy applied (money unchanged).');

        return self::SUCCESS;
    }
}
