<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restore each payment's "paid to" classification from the source.
 *
 * The Access source records, per payment, who the money went to in
 * Payment_InsComp_To — with two encodings for the same two destinations:
 *   ISH  or  1   → InsureHub           (money collected by us)
 *   IC   or  2   → the Insurance Company (customer paid the carrier directly)
 *   (SBJ / ISH-SBJ / Special / blank = rare edge cases, left unmapped)
 *
 * The importer tried to map this via the payment_inscomp_tos lookup but keyed the
 * cache by name_th ("อินชัวร์ฮับ" / "บริษัทประกัน"), so the raw codes never matched
 * and EVERY payment landed with payment_inscomp_to_id = NULL. Separately the
 * `payee` column (which the payment UI actually shows — insurehub vs carrier)
 * defaulted to 'insurehub' on every row, so ~9,600 payments that in fact went to
 * the carrier were shown as paid to InsureHub.
 *
 * MATCH — exact, by id. The importer inserted legacy.payments (ids 1..20317) into
 * policy_payments in id order, 1:1, so policy_payments.id == legacy.payments.id for
 * the whole import batch (verified: amounts align position-for-position, the only
 * diffs being the D2 refund-sign flips which keep the same magnitude). We read
 * to_company straight off the paired legacy row — no fuzzy amount/date matching.
 *
 * Sets, per matched payment:
 *   - payment_inscomp_to_id → 1 (InsureHub) or 2 (Insurance Company)
 *   - payee                 → 'insurehub' or 'carrier'  (the operational field)
 *
 * The 798 synthetic backfill payments (payments:backfill-legacy, method
 * 'legacyImport') have no legacy source row and are left as-is.
 *
 * SAFETY
 *   - Writes only payee + payment_inscomp_to_id; amount/date/policy untouched.
 *   - Raw update → no observers. Idempotent. --dry-run supported.
 *
 *   php artisan payments:backfill-payee [--dry-run]
 */
class BackfillPaymentPayee extends Command
{
    protected $signature = 'payments:backfill-payee {--dry-run : report only, write nothing}';

    protected $description = 'Restore payment payee / payment_inscomp_to_id from the source (ISH/1=InsureHub, IC/2=carrier).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Resolve the lookup ids by name (robust to id ordering).
        $tos = DB::table('payment_inscomp_tos')->pluck('id', 'name_th');
        $idInsurehub = (int) ($tos['อินชัวร์ฮับ'] ?? 1);
        $idCarrier = (int) ($tos['บริษัทประกัน'] ?? 2);

        // code → [payee, inscomp_to_id]
        $map = static function (?string $code) use ($idInsurehub, $idCarrier): ?array {
            $c = strtoupper(trim((string) $code));
            return match (true) {
                $c === 'ISH' || $c === '1' || $c === 'ISH/SBJ' => ['insurehub', $idInsurehub],
                $c === 'IC' || $c === '2' => ['carrier', $idCarrier],
                default => null,   // SBJ / Special / blank — leave unmapped
            };
        };

        // Bucket the paired legacy ids by destination (join is pp.id == lp.id).
        $insurehubIds = [];
        $carrierIds = [];
        $skipped = 0;

        foreach (DB::connection('insurehub_legacy')->table('payments')
            ->select('id', 'to_company')->orderBy('id')->cursor() as $lp) {
            $mapped = $map($lp->to_company);
            if ($mapped === null) {
                $skipped++;

                continue;
            }
            $mapped[0] === 'insurehub' ? $insurehubIds[] = (int) $lp->id : $carrierIds[] = (int) $lp->id;
        }

        if (! $dry) {
            // Set-based updates in chunks (pp.id == lp.id). Guard on method so a
            // re-used id can never hit a synthetic backfill row.
            foreach (array_chunk($insurehubIds, 5000) as $ids) {
                DB::table('policy_payments')->whereIn('id', $ids)->where('method', '<>', 'legacyImport')
                    ->update(['payee' => 'insurehub', 'payment_inscomp_to_id' => $idInsurehub, 'updated_at' => now()]);
            }
            foreach (array_chunk($carrierIds, 5000) as $ids) {
                DB::table('policy_payments')->whereIn('id', $ids)->where('method', '<>', 'legacyImport')
                    ->update(['payee' => 'carrier', 'payment_inscomp_to_id' => $idCarrier, 'updated_at' => now()]);
            }
        }

        $this->info(sprintf('%spayee set → InsureHub: %d, Insurance Company: %d  (unmapped SBJ/Special/blank: %d).',
            $dry ? '[dry-run] ' : '', count($insurehubIds), count($carrierIds), $skipped));
        if ($dry) {
            $this->warn('DRY RUN — nothing written.');
        }

        return self::SUCCESS;
    }
}
