<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Generator;
use RuntimeException;

trait SeedsFromCsv
{
    /**
     * Stream a CSV as associative rows. Use this for large files so memory stays bounded.
     *
     * @return Generator<int, array<string, string>>
     */
    protected function streamCsv(string $relativePath): Generator
    {
        $path = database_path('seed-data/' . ltrim($relativePath, '/'));
        if (! is_readable($path)) {
            throw new RuntimeException("Seed CSV not found: {$path}");
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        try {
            $headers = fgetcsv($fh);
            if (! is_array($headers)) {
                return;
            }
            while (($row = fgetcsv($fh)) !== false) {
                if ($row === [null]) {
                    continue;
                }
                yield array_combine($headers, array_map(static fn ($v) => $v === null ? '' : (string) $v, $row));
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Read a CSV file into an array of associative rows. Convenience wrapper for small files.
     *
     * @return array<int, array<string, string>>
     */
    protected function readCsv(string $relativePath): array
    {
        return iterator_to_array($this->streamCsv($relativePath), false);
    }

    /**
     * Convert a date string to Y-m-d or null. Accepts ISO and dd/m/yyyy.
     * Rejects sentinel garbage years (<1950 or >2199) — common in legacy data.
     */
    protected function parseDate(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || $raw === '0') {
            return null;
        }
        $year = null;
        $out = null;
        // ISO datetime — keep date part.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
            $year = (int) $m[1];
            $out = sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
            // dd/m/yyyy or d/m/yyyy.
            $year = (int) $m[3];
            $out = sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }
        if ($year === null || $year < 1950 || $year > 2199) {
            return null;
        }
        return $out;
    }

    protected function num(?string $raw, float $default = 0.0): float
    {
        $raw = trim((string) $raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return $default;
        }
        return (float) $raw;
    }

    protected function nonEmpty(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        return $raw === '' ? null : $raw;
    }
}
