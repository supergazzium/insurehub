<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the has_life_license / has_non_life_license booleans on agents.
 *
 * These two flags are meant to mirror "does the agent hold a life / non-life
 * license number". Migration 2027_01_04_000100 derives them from
 * license_life_no / license_non_life_no — but that migration ran BEFORE the
 * legacy agent import, so the imported agents (72 of which carry a license
 * number in the split license_*_no fields) came in with both flags at their
 * default 0. The agent portal (MyAgentResource / MeAgentController) reads these
 * flags for the license badges, so they must reflect the number fields.
 *
 * This re-applies the migration's exact derivation, idempotently: an agent
 * already carrying the correct flag is left untouched.
 *
 * It deliberately does NOT touch has_license — that is the admin-controlled
 * "may be promoted to Lv7+" business flag (see migration
 * 2027_02_01_000300), which is intentionally NOT derived from a license number
 * so that license expiry can't silently demote an agent.
 *
 *   php artisan agents:backfill-license-flags [--dry-run]
 */
class BackfillAgentLicenseFlags extends Command
{
    protected $signature = 'agents:backfill-license-flags {--dry-run : report only, write nothing}';

    protected $description = 'Set has_life_license / has_non_life_license from the license number fields (not has_license).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // agents whose life-license flag disagrees with the number field
        $lifeQuery = DB::table('agents')
            ->whereNull('deleted_at')
            ->whereNotNull('license_life_no')
            ->where('license_life_no', '<>', '')
            ->where(function ($q): void {
                $q->whereNull('has_life_license')->orWhere('has_life_license', 0);
            });
        $lifeCount = (clone $lifeQuery)->count();

        $nonLifeQuery = DB::table('agents')
            ->whereNull('deleted_at')
            ->whereNotNull('license_non_life_no')
            ->where('license_non_life_no', '<>', '')
            ->where(function ($q): void {
                $q->whereNull('has_non_life_license')->orWhere('has_non_life_license', 0);
            });
        $nonLifeCount = (clone $nonLifeQuery)->count();

        if (! $dry) {
            (clone $lifeQuery)->update(['has_life_license' => 1, 'updated_at' => DB::raw('NOW()')]);
            (clone $nonLifeQuery)->update(['has_non_life_license' => 1, 'updated_at' => DB::raw('NOW()')]);
        }

        $this->table(['flag', 'rows updated'], [
            ['has_life_license', $lifeCount],
            ['has_non_life_license', $nonLifeCount],
        ]);
        $this->info($dry ? '[dry-run] no writes.' : 'license flags backfilled (has_license untouched).');

        return self::SUCCESS;
    }
}
