<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Create portal login (users row) for each migrated agent, per the agreed
 * password policy:
 *   - birth_date present        -> password = ddmmyyyy (from birth_date)
 *   - Group C (no DOB, real)    -> password = Insurehub@2026 (force reset later)
 *   - stubs (LGC*) + house/corp -> login DISABLED (no user row created)
 * Idempotent: skips agents that already have a user row. Dry-run supported.
 */
class CreateAgentLogins extends Command
{
    protected $signature = 'agents:create-logins {--dry-run} {--tenant=1}';

    protected $description = 'Create user logins for migrated agents (ddmmyyyy / default+reset / disabled).';

    /** House/corporate codes that get NO login (Group B). */
    private const LOGIN_DISABLED_CODES = ['Internal', 'IN210009', 'AG200000', 'IN210013', 'IN260717'];

    private const DEFAULT_PASSWORD = 'Insurehub@2026';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $agentRoleId = \DB::table('roles')->where('key', 'agent')->value('id');
        if (! $agentRoleId) {
            $this->error('agent role not found');

            return self::FAILURE;
        }

        // emails already taken (by existing users, any role) — users.email is globally unique
        $takenEmails = \DB::table('users')->whereNotNull('email')->pluck('email')
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))->flip();
        // agents that already have a user row
        $linkedAgentIds = \DB::table('users')->whereNotNull('agent_id')->pluck('agent_id')->flip();

        $stats = ['ddmmyyyy' => 0, 'default_reset' => 0, 'disabled_nodob_group' => 0,
            'skipped_stub_house' => 0, 'skipped_no_email' => 0, 'skipped_dup_email' => 0,
            'skipped_existing' => 0];
        $seenEmail = [];
        $createdSamples = [];

        foreach (Agent::where('tenant_id', $tenantId)->orderBy('agent_code')->cursor() as $a) {
            if (isset($linkedAgentIds[$a->id])) { $stats['skipped_existing']++; continue; }

            // stubs + house/corp -> no login
            if (str_starts_with($a->agent_code, 'LGC') || in_array($a->agent_code, self::LOGIN_DISABLED_CODES, true)) {
                $stats['skipped_stub_house']++;
                continue;
            }

            $email = mb_strtolower(trim((string) $a->email));
            if ($email === '') { $stats['skipped_no_email']++; continue; }
            if (isset($takenEmails[$email]) || isset($seenEmail[$email])) { $stats['skipped_dup_email']++; continue; }

            // password: ddmmyyyy if DOB, else default + (future) force reset
            $dob = $a->birth_date; // Carbon (cast date) or null
            if ($dob) {
                $password = $dob->format('dmY');
                $stats['ddmmyyyy']++;
            } else {
                $password = self::DEFAULT_PASSWORD;
                $stats['default_reset']++;
            }

            $seenEmail[$email] = true;
            if (count($createdSamples) < 5) {
                $createdSamples[] = "{$a->agent_code} <{$email}> pw=".($dob ? 'ddmmyyyy' : 'default');
            }

            if (! $dry) {
                User::create([
                    'tenant_id' => $tenantId,
                    'name' => trim(($a->name_th ?? '').' '.($a->surname_th ?? '')) ?: $a->agent_code,
                    'email' => $a->email,
                    'password' => Hash::make($password),
                    'role' => 'agent',
                    'role_id' => $agentRoleId,
                    'agent_id' => $a->id,
                    'locale' => 'th',
                    'active' => true,
                ]);
            }
        }

        $this->table(['bucket', 'count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line('samples: '.implode(' | ', $createdSamples));
        $this->info(($dry ? '[DRY-RUN] would create ' : 'created ').($stats['ddmmyyyy'] + $stats['default_reset']).' logins');

        return self::SUCCESS;
    }
}
