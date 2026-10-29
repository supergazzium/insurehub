<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agent;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The legacy importer raw-inserts agent PII (id_card, bank_account_no,
 * bank_account_name, license_number) as PLAINTEXT, but the Agent model casts
 * them as 'encrypted'. This re-saves those fields through Eloquent so the cast
 * encrypts them at rest. Idempotent: a value that already decrypts cleanly is
 * left alone. Uses saveQuietly() to avoid 385 noise audit rows.
 */
class ReencryptAgentPii extends Command
{
    protected $signature = 'agents:reencrypt-pii {--dry-run} {--tenant=1}';

    protected $description = 'Encrypt agent PII that was raw-inserted as plaintext by the legacy importer.';

    private const FIELDS = ['id_card', 'bank_account_no', 'bank_account_name', 'license_number'];

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $fixed = array_fill_keys(self::FIELDS, 0);
        $alreadyEnc = array_fill_keys(self::FIELDS, 0);
        $agentsTouched = 0;

        // Read RAW (bypass the cast) to inspect ciphertext vs plaintext.
        foreach (DB::table('agents')->where('tenant_id', $tenantId)->orderBy('id')->cursor() as $raw) {
            $dirty = [];
            foreach (self::FIELDS as $f) {
                $val = $raw->$f;
                if ($val === null || trim((string) $val) === '') {
                    continue;
                }
                // If it already decrypts, it's encrypted — leave it.
                try {
                    Crypt::decryptString($val);
                    $alreadyEnc[$f]++;

                    continue;
                } catch (DecryptException) {
                    // plaintext -> needs encryption
                }
                $dirty[$f] = Crypt::encryptString((string) $val);
                $fixed[$f]++;
            }
            if ($dirty !== []) {
                $agentsTouched++;
                if (! $dry) {
                    // write ciphertext directly to the raw column (no cast, no observer)
                    DB::table('agents')->where('id', $raw->id)->update($dirty);
                }
            }
        }

        $rows = [];
        foreach (self::FIELDS as $f) {
            $rows[] = [$f, $fixed[$f], $alreadyEnc[$f]];
        }
        $this->table(['field', 'encrypted_now', 'already_encrypted'], $rows);
        $this->info(($dry ? '[DRY-RUN] would encrypt ' : 'encrypted ').$agentsTouched.' agents\' PII');

        return self::SUCCESS;
    }
}
