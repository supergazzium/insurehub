<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * C-17 — shim-soak monitoring command.
 *
 * Reads the `risk-shim-YYYY-MM-DD.log` daily buckets and aggregates
 * PolicyRiskShim::readerField fallback events. Reports whether the
 * shim has been silent for the requested consecutive window — the
 * ops signal for flipping POLICY_RISK_SHIM_COMPLETE=true and running
 * C-18's drop-columns migration.
 *
 * See docs/audit-2026-08-21/B2-schema-plan.md §5.
 *
 * The command is READ-ONLY — it never modifies state or clears logs.
 * Run daily in cron OR ad-hoc when planning the C-18 cutover.
 *
 * Exit codes are meaningful so a CI/monitoring job can gate on them:
 *   0 = silent for the requested window (green — safe to drop)
 *   1 = fallback events found within the window (red — hold)
 *   2 = no log files at all (unknown — either shim not deployed OR
 *       storage volume mount changed and history is lost)
 */
class PolicyShimReport extends Command
{
    protected $signature = 'policies:shim-report
        {--days=14 : How many consecutive silent days to require for green}
        {--top=10 : Top-N most-called (kind,key,column) tuples to print when noisy}
        {--json : Emit machine-readable JSON instead of the table view}';

    protected $description = 'Report PolicyRiskShim fallback activity across the risk-shim daily log buckets.';

    private const LOG_DIR_REL = 'logs';
    private const LOG_PREFIX = 'risk-shim';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $topN = max(1, (int) $this->option('top'));
        $asJson = (bool) $this->option('json');

        $logDir = storage_path(self::LOG_DIR_REL);
        $files = $this->collectLogFiles($logDir, $days);

        if (count($files) === 0) {
            $msg = "No risk-shim log files found under {$logDir}.";
            if ($asJson) {
                $this->line(json_encode(['status' => 'unknown', 'reason' => $msg], JSON_UNESCAPED_UNICODE));
            } else {
                $this->error($msg);
                $this->line('Either the shim has never been exercised or the storage volume is not mounted correctly.');
            }
            return 2;
        }

        // Aggregate per (kind, key, column) + per date. Two dimensions:
        //   $totalsByDate[date] = int          — fallback events per day
        //   $totalsByTuple[key] = { count, sample_policy_id, first_seen, last_seen }
        $totalsByDate = [];
        $totalsByTuple = [];
        $totalEvents = 0;

        foreach ($files as $date => $path) {
            $count = 0;
            $handle = @fopen($path, 'r');
            if ($handle === false) {
                $this->warn("  cannot open {$path} — skipping");
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                // Match Monolog's default single-line format. We only
                // count entries that mention `risk_data fallback` — the
                // channel is dedicated but a defensive filter beats a
                // false-positive count from an accidental Log::channel
                // usage elsewhere.
                if (! str_contains($line, 'risk_data fallback')) continue;
                $count++;
                $totalEvents++;

                // Extract the JSON blob for the tuple aggregation. The
                // regex is loose because Monolog's context serializer
                // uses json_encode by default (single-quote-safe).
                if (preg_match('/\{[^}]+\}\s*$/u', $line, $m) === 1) {
                    $ctx = json_decode($m[0], true);
                    if (is_array($ctx)) {
                        $tk = ($ctx['kind'] ?? '?').'|'.($ctx['key'] ?? '?').'|'.($ctx['column'] ?? '?');
                        if (! isset($totalsByTuple[$tk])) {
                            $totalsByTuple[$tk] = [
                                'kind' => $ctx['kind'] ?? '?',
                                'key' => $ctx['key'] ?? '?',
                                'column' => $ctx['column'] ?? '?',
                                'count' => 0,
                                'samplePolicyId' => $ctx['policy_id'] ?? null,
                                'firstSeen' => $date,
                                'lastSeen' => $date,
                            ];
                        }
                        $totalsByTuple[$tk]['count']++;
                        // firstSeen stays; lastSeen advances as we walk
                        // chronologically (collectLogFiles yields ascending).
                        $totalsByTuple[$tk]['lastSeen'] = $date;
                    }
                }
            }
            fclose($handle);
            $totalsByDate[$date] = $count;
        }

        // Sort the tuple leaderboard descending by count.
        usort($totalsByTuple, fn ($a, $b) => $b['count'] - $a['count']);
        $topTuples = array_slice($totalsByTuple, 0, $topN);

        // The green/red verdict: was the WINDOW clean? Every date in the
        // sample must have zero fallback events.
        $silentDays = 0;
        $windowNoisy = false;
        $sortedDates = array_keys($totalsByDate);
        // Walk chronologically newest-first — we want "the most recent N
        // consecutive days" not "any N days in history".
        rsort($sortedDates);
        foreach ($sortedDates as $d) {
            if (($totalsByDate[$d] ?? 0) === 0) {
                $silentDays++;
            } else {
                $windowNoisy = true;
                break;
            }
            if ($silentDays >= $days) break;
        }
        $isGreen = ! $windowNoisy && $silentDays >= $days;

        // Machine-readable path.
        if ($asJson) {
            $this->line(json_encode([
                'status' => $isGreen ? 'green' : 'red',
                'totalEvents' => $totalEvents,
                'daysScanned' => count($totalsByDate),
                'requiredSilentDays' => $days,
                'silentDaysObserved' => $silentDays,
                'byDate' => $totalsByDate,
                'topTuples' => $topTuples,
                'recommendation' => $isGreen
                    ? 'Safe to set POLICY_RISK_SHIM_COMPLETE=true and run C-18 drop-columns migration.'
                    : 'HOLD — fallback reads still occurring. Investigate top tuples before dropping columns.',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $isGreen ? self::SUCCESS : self::FAILURE;
        }

        // Human path.
        $this->info("policies:shim-report — {$totalEvents} total events across ".count($totalsByDate).' day(s)');
        $this->newLine();

        $this->line('Per-day fallback events (newest first):');
        $rows = [];
        foreach ($sortedDates as $d) {
            $n = $totalsByDate[$d] ?? 0;
            $marker = $n === 0 ? '✓' : '✗';
            $rows[] = [$marker, $d, $n];
        }
        $this->table(['ok', 'date', 'events'], $rows);

        if ($totalEvents > 0) {
            $this->newLine();
            $this->line("Top {$topN} noisy (kind, key, column) tuples:");
            $rows = [];
            foreach ($topTuples as $t) {
                $rows[] = [
                    $t['kind'], $t['key'], $t['column'],
                    $t['count'], $t['samplePolicyId'],
                    "{$t['firstSeen']} → {$t['lastSeen']}",
                ];
            }
            $this->table(['kind', 'key', 'column', 'count', 'sample policy', 'seen'], $rows);
        }

        $this->newLine();
        if ($isGreen) {
            $this->info("GREEN — {$silentDays} consecutive silent days (need {$days}). Safe to drop retired columns:");
            $this->line('  1. Set POLICY_RISK_SHIM_COMPLETE=true in the deploy env');
            $this->line('  2. Take a pre-flight mysqldump of the 29 columns (see B2 §5 retention notes)');
            $this->line('  3. Deploy C-18 drop-columns migration');

            return self::SUCCESS;
        }

        $this->error("RED — fallback events observed within the last {$days} days. Do NOT drop columns.");
        $this->line('  Investigate the top tuples above. Common causes:');
        $this->line('  - a code path that writes only to the legacy column (bypasses shim writer)');
        $this->line('  - a policy row that missed the C-5 risk_data backfill');
        $this->line('  - a fresh column added post-C-5 that isn\'t in PolicyRiskShim::FIELDS');

        return self::FAILURE;
    }

    /**
     * Enumerate daily log files under storage_path('logs'), keyed by
     * date-string ('YYYY-MM-DD'), ascending. Includes empty files so
     * a day with zero events is visible in the histogram as `events=0`.
     */
    private function collectLogFiles(string $dir, int $days): array
    {
        if (! is_dir($dir)) return [];
        $out = [];
        $today = Carbon::today();
        foreach (scandir($dir) ?: [] as $name) {
            if (! preg_match('/^'.preg_quote(self::LOG_PREFIX).'-(\d{4})-(\d{2})-(\d{2})\.log$/', $name, $m)) continue;
            $date = "{$m[1]}-{$m[2]}-{$m[3]}";
            // Skip files older than the scan window + a small buffer so the
            // "silent for N days" check doesn't get confused by ancient noise.
            try {
                $fileDate = Carbon::parse($date);
                if ($fileDate->diffInDays($today) > $days * 2) continue;
            } catch (\Throwable) {
                continue;
            }
            $out[$date] = $dir.'/'.$name;
        }
        ksort($out);

        return $out;
    }
}
