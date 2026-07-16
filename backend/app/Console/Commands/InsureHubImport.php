<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\InsureHubImporter;
use Illuminate\Console\Command;

/**
 * Import legacy Access → MySQL data into the Laravel schema.
 *
 * Reads from the `insurehub_legacy` connection (see config/database.php,
 * populated by the mysql_migration/ pipeline) and writes into the
 * default Laravel connection. Idempotent — matches on legacy codes
 * (agent_code, customer_code, application_no) and upserts, so re-runs
 * update rather than duplicate.
 *
 * Design: this command orchestrates. The step methods themselves live
 * in `App\Services\Import\InsureHubImporter` so the file stays reviewable.
 */
class InsureHubImport extends Command
{
    protected $signature = 'insurehub:import
        {--dry-run : Parse everything but write nothing (prints per-step count summary)}
        {--only= : Comma-separated step names to run (e.g. tenants,carriers,products)}
        {--truncate : Truncate Laravel tables first (asks for confirmation unless --force)}
        {--force : Skip confirmation prompts}
        {--tenant=1 : Target tenant id (default 1)}';

    protected $description = 'Import the migrated insurehub_legacy DB into the Laravel schema.';

    public function handle(InsureHubImporter $importer): int
    {
        $tenantId = (int) $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('only')
            ? array_map('trim', explode(',', (string) $this->option('only')))
            : null;

        if ($this->option('truncate')) {
            if (!$this->option('force') && !$this->confirm('Truncate all Laravel domain tables before import?', false)) {
                $this->warn('Aborted.');
                return self::FAILURE;
            }
            $importer->truncate($this);
        }

        $importer
            ->setCommand($this)
            ->setTenantId($tenantId)
            ->setDryRun($dryRun)
            ->setOnly($only);

        $summary = $importer->run();

        $this->newLine();
        $this->info('=== Import summary ===');
        $rows = [];
        foreach ($summary as $step => $counts) {
            $rows[] = [$step, $counts['inserted'] ?? 0, $counts['updated'] ?? 0, $counts['skipped'] ?? 0];
        }
        $this->table(['Step', 'Inserted', 'Updated', 'Skipped'], $rows);

        if ($dryRun) {
            $this->warn('DRY RUN — no rows were persisted.');
        }

        return self::SUCCESS;
    }
}
