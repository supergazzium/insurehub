<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fold legacy group-insurance endorsements into their base policy.
 *
 * Group insurance (ประกันกลุ่ม PA / health) is ONE policy whose membership
 * changes over the year via endorsements (สลักหลัง): a member added collects
 * more premium (positive), a member leaving refunds premium (negative). The
 * 25/10/2026 Access export stored each endorsement as its OWN Application row
 * sharing Client_Code + Policy_Number, so the importer made each one a separate
 * policy — including nonsensical negative-premium "policies".
 *
 * This command (manifest-driven, idempotent, observer-safe, money-reconciled)
 * folds each group's endorsement rows into the surviving base policy:
 *   - the member's payment moves onto the base (flagged adjustment_kind=endorsement);
 *   - a policy_events 'endorsement.premium_change' event is recorded on the base
 *     with additionalTotal = the member's signed premium delta (the Premium sign
 *     is authoritative; the legacy Payment_Amount sign is unreliable), so
 *     PremiumDue adds it to the base policy's due;
 *   - endorsement_count is bumped and endorsement_seq assigned;
 *   - the member policy + its per-policy children are soft-deleted / removed.
 *
 * Manifest action per row:
 *   fold-endorsements — base survives, members fold as above.
 *   flag-ambiguous    — base lives in another year/policy_no; leave as-is.
 *
 *   php artisan policies:fold-group-endorsements --manifest=<endorsement_manifest.csv>
 *       [--tenant=1] [--dry-run] [--force]
 */
class FoldGroupEndorsements extends Command
{
    protected $signature = 'policies:fold-group-endorsements
        {--manifest= : path to endorsement_manifest.csv (required)}
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}
        {--force : skip the destructive-write confirmation}';

    protected $description = 'Fold legacy group-insurance endorsement rows into their base policy.';

    private const FOLLOW_REMOVE = ['policy_rebates', 'policy_installments'];

    public function handle(): int
    {
        $manifestPath = (string) $this->option('manifest');
        if ($manifestPath === '' || ! is_file($manifestPath)) {
            $this->error('--manifest=<endorsement_manifest.csv> is required and must exist.');

            return self::FAILURE;
        }
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $manifest = $this->readManifest($manifestPath);
        $this->info(($dry ? '[dry-run] ' : '').'Loaded '.count($manifest).' manifest groups.');

        if (! $dry && ! $this->option('force')) {
            if (! $this->confirm('This will SOFT-DELETE endorsement policies and record them as endorsements on the base. Continue?')) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }
        }

        $before = $this->paymentUniverse($manifest, $tenant);
        $now = Carbon::now();
        $stats = [
            'groups_folded' => 0, 'groups_ambiguous' => 0, 'groups_skipped' => 0,
            'endorsements_recorded' => 0, 'payments_moved' => 0,
            'policies_softdeleted' => 0, 'child_rows_removed' => 0, 'members_unmapped' => 0,
        ];

        foreach ($manifest as $g) {
            if ($g['action'] === 'flag-ambiguous') {
                $stats['groups_ambiguous']++;

                continue;
            }

            $keeper = $this->livePolicy($g['keeper_app'], $tenant);
            if ($keeper === null) {
                $stats['groups_skipped']++;
                $this->warn("  skip group {$g['group_id']} ({$g['client_code']}/{$g['policy_no']}): base {$g['keeper_app']} not a live policy.");

                continue;
            }

            $members = [];
            foreach ($g['member_apps'] as $app) {
                $m = $this->livePolicyFull($app, $tenant);
                if ($m === null) {
                    $stats['members_unmapped']++;

                    continue;
                }
                if ((int) $m->id === (int) $keeper->id) {
                    continue;
                }
                $members[] = $m;
            }
            if ($members === []) {
                $stats['groups_skipped']++;   // already folded or all unmapped

                continue;
            }

            if ($dry) {
                $stats['endorsements_recorded'] += count($members);
                $stats['policies_softdeleted'] += count($members);
                foreach ($members as $m) {
                    $stats['payments_moved'] += (int) DB::table('policy_payments')->where('policy_id', $m->id)->whereNull('voided_at')->count();
                }
                $stats['groups_folded']++;

                continue;
            }

            DB::transaction(function () use ($keeper, $members, $now, &$stats): void {
                // next endorsement_seq on the base
                $seq = (int) (DB::table('policy_events')->where('policy_id', $keeper->id)
                    ->whereNotNull('endorsement_seq')->max('endorsement_seq') ?? 0);
                // base figure before the first fold (for before/after payload)
                $runningPremium = (float) ($keeper->main_premium ?? 0);

                foreach ($members as $m) {
                    $seq++;
                    $delta = (float) ($m->main_premium ?? 0);  // signed delta (authoritative)
                    $deltaTotal = (float) ($m->total_premium_paid ?? $delta); // incl VAT/duty
                    $before = ['annualPremium' => $runningPremium, 'mainPremium' => $runningPremium];
                    $runningPremium = round($runningPremium + $delta, 2);
                    $after = ['annualPremium' => $runningPremium, 'mainPremium' => $runningPremium];

                    // 1. record the endorsement event on the base
                    DB::table('policy_events')->insert([
                        'policy_id' => $keeper->id,
                        'type' => 'endorsement.premium_change',
                        'endorsement_seq' => $seq,
                        'occurred_at' => $m->effective_date ?? $now->toDateString(),
                        'by_user_id' => null,
                        'payload' => json_encode([
                            'reason' => $delta < 0 ? 'สมาชิกออก (คืนเบี้ย)' : 'สมาชิกเพิ่ม (เก็บเบี้ยเพิ่ม)',
                            'effectiveDate' => $m->effective_date,
                            'before' => $before,
                            'after' => $after,
                            'additionalPremium' => round($delta, 2),
                            'additionalDutyStamp' => 0,
                            'additionalVat' => 0,
                            'additionalTotal' => round($deltaTotal, 2),
                            'source' => 'legacy-endorsement-fold',
                            'fromApplication' => $m->application_no,
                        ], JSON_UNESCAPED_UNICODE),
                        'voided_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $stats['endorsements_recorded']++;

                    // 2. move the member's payment(s) onto the base, flagged as endorsement
                    $moved = DB::table('policy_payments')->where('policy_id', $m->id)
                        ->update(['policy_id' => $keeper->id, 'adjustment_kind' => 'endorsement', 'updated_at' => $now]);
                    $stats['payments_moved'] += $moved;

                    // 3. remove the member's per-policy children
                    foreach (self::FOLLOW_REMOVE as $t) {
                        if (DB::getSchemaBuilder()->hasColumn($t, 'deleted_at')) {
                            $stats['child_rows_removed'] += DB::table($t)->where('policy_id', $m->id)->whereNull('deleted_at')->update(['deleted_at' => $now]);
                        } else {
                            $stats['child_rows_removed'] += DB::table($t)->where('policy_id', $m->id)->delete();
                        }
                    }

                    // 4. soft-delete the member policy
                    $noteCol = DB::getSchemaBuilder()->hasColumn('policies', 'notes') ? 'notes' : null;
                    $upd = ['deleted_at' => $now, 'updated_at' => $now];
                    if ($noteCol !== null) {
                        $upd[$noteCol] = DB::raw("TRIM(CONCAT(COALESCE($noteCol,''), '\n[folded as endorsement #{$seq} into policy #{$keeper->id} — legacy group endorsement]'))");
                    }
                    DB::table('policies')->where('id', $m->id)->update($upd);
                    $stats['policies_softdeleted']++;
                }

                // bump endorsement_count on the base, refresh total_premium_paid
                $sumPaid = (float) DB::table('policy_payments')->where('policy_id', $keeper->id)->whereNull('voided_at')->sum('amount');
                DB::table('policies')->where('id', $keeper->id)->update([
                    'endorsement_count' => $seq,
                    'total_premium_paid' => $sumPaid,
                    'updated_at' => $now,
                ]);
            });

            $stats['groups_folded']++;
        }

        // refresh customer policy counts (members soft-deleted)
        $countsFixed = 0;
        if (! $dry) {
            $countsFixed = DB::update(
                'UPDATE customers c SET '.
                'c.total_policy_count = (SELECT COUNT(*) FROM policies p WHERE p.customer_id=c.id AND p.deleted_at IS NULL), '.
                'c.active_policy_count = (SELECT COUNT(*) FROM policies p WHERE p.customer_id=c.id AND p.deleted_at IS NULL AND p.status NOT IN (?,?)) '.
                'WHERE c.deleted_at IS NULL AND c.tenant_id = ?',
                ['cancelled', 'expired', $tenant]
            );
        }

        $after = $this->paymentUniverse($manifest, $tenant);

        $this->newLine();
        $this->table(['metric', 'value'], [
            ['groups folded', $stats['groups_folded']],
            ['groups ambiguous (left as-is)', $stats['groups_ambiguous']],
            ['groups skipped (already/empty)', $stats['groups_skipped']],
            ['endorsements recorded', $stats['endorsements_recorded']],
            ['payments moved to base', $stats['payments_moved']],
            ['policies soft-deleted', $stats['policies_softdeleted']],
            ['child rows removed', $stats['child_rows_removed']],
            ['members unmapped', $stats['members_unmapped']],
            ['customer counts refreshed', $countsFixed],
            ['payment $ before', number_format($before, 2)],
            ['payment $ after', number_format($after, 2)],
            ['payment $ delta (MUST be 0.00)', number_format($after - $before, 2)],
        ]);

        if (! $dry && abs($after - $before) > 0.01) {
            $this->error('MONEY MISMATCH — payment total changed. Review before trusting this run.');

            return self::FAILURE;
        }
        $this->info($dry ? '[dry-run] no writes.' : 'Endorsements folded.');

        return self::SUCCESS;
    }

    /** @return array<int,array{group_id:string,client_code:string,policy_no:string,keeper_app:string,member_apps:array<int,string>,action:string}> */
    private function readManifest(string $path): array
    {
        $out = [];
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $idx = array_flip($header);
        while (($r = fgetcsv($fh)) !== false) {
            $members = array_values(array_filter(explode(';', $r[$idx['member_apps']] ?? '')));
            $out[] = [
                'group_id' => (string) $r[$idx['group_id']],
                'client_code' => (string) $r[$idx['client_code']],
                'policy_no' => (string) $r[$idx['policy_no']],
                'keeper_app' => (string) $r[$idx['keeper_app']],
                'member_apps' => $members,
                'action' => (string) $r[$idx['action']],
            ];
        }
        fclose($fh);

        return $out;
    }

    private function livePolicy(string $appNo, int $tenant): ?object
    {
        if ($appNo === '') {
            return null;
        }

        return DB::table('policies')->where('tenant_id', $tenant)
            ->where('application_no', $appNo)->whereNull('deleted_at')->first(['id', 'main_premium']);
    }

    private function livePolicyFull(string $appNo, int $tenant): ?object
    {
        if ($appNo === '') {
            return null;
        }

        return DB::table('policies')->where('tenant_id', $tenant)
            ->where('application_no', $appNo)->whereNull('deleted_at')
            ->first(['id', 'application_no', 'main_premium', 'total_premium_paid', 'effective_date']);
    }

    private function paymentUniverse(array $manifest, int $tenant): float
    {
        $apps = [];
        foreach ($manifest as $g) {
            if ($g['keeper_app'] !== '') {
                $apps[] = $g['keeper_app'];
            }
            foreach ($g['member_apps'] as $m) {
                $apps[] = $m;
            }
        }
        $apps = array_values(array_unique($apps));
        if ($apps === []) {
            return 0.0;
        }

        return (float) DB::table('policy_payments as pp')
            ->join('policies as p', 'p.id', '=', 'pp.policy_id')
            ->where('p.tenant_id', $tenant)
            ->whereIn('p.application_no', $apps)
            ->whereNull('pp.voided_at')
            ->sum('pp.amount');
    }
}
