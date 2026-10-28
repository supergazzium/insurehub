<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

final class CustomerTier
{
    public const SALES_STATUSES = ['issued', 'active', 'expired', 'lapsed'];

    public const KEYS = ['black_diamond', 'diamond', 'platinum', 'gold', 'silver'];

    // Customer wealth tiers (internal) — by rolling 1-year accumulated premium.
    // Source: "การกำหนด Tier ลูกค้า" spec (Sheet1). I_Silver is the floor (min 0).
    public const DEFAULTS = [
        ['key' => 'black_diamond', 'label' => 'I_Black Diamond', 'min' => 700001],
        ['key' => 'diamond', 'label' => 'I_Diamond', 'min' => 300001],
        ['key' => 'platinum', 'label' => 'I_Platinum', 'min' => 100001],
        ['key' => 'gold', 'label' => 'I_Gold', 'min' => 15001],
        ['key' => 'silver', 'label' => 'I_Silver', 'min' => 0],
    ];

    public static function forTenant(?Tenant $tenant): array
    {
        return self::normalize(self::settings($tenant));
    }

    public static function settings(?Tenant $tenant): array
    {
        $stored = [];
        foreach ((array) ($tenant?->customer_tiers ?? []) as $t) {
            if (in_array($t['key'] ?? null, self::KEYS, true)) {
                $stored[$t['key']] = $t;
            }
        }
        $out = [];
        foreach (self::DEFAULTS as $d) {
            $s = $stored[$d['key']] ?? null;
            $out[] = [
                'key' => $d['key'],
                'label' => $s !== null && trim((string) ($s['label'] ?? '')) !== '' ? trim((string) $s['label']) : $d['label'],
                'min' => $s === null ? $d['min'] : (($s['min'] ?? null) === null ? null : (float) $s['min']),
            ];
        }

        return $out;
    }

    public static function normalize(array $tiers): array
    {
        $byKey = [];
        foreach (self::DEFAULTS as $d) {
            $byKey[$d['key']] = $d;
        }
        foreach ($tiers as $t) {
            $key = (string) ($t['key'] ?? '');
            if (! in_array($key, self::KEYS, true)) {
                continue;
            }
            $min = $t['min'] ?? null;
            $byKey[$key] = [
                'key' => $key,
                'label' => trim((string) ($t['label'] ?? '')) !== '' ? trim((string) $t['label']) : $byKey[$key]['label'],
                'min' => $min === null || $min === '' ? null : (float) $min,
            ];
        }
        $out = array_values(array_filter($byKey, fn ($t) => $t['min'] !== null));
        usort($out, fn ($a, $b) => $b['min'] <=> $a['min']);

        return $out;
    }

    public static function classify(float $total, array $tiers): ?array
    {
        foreach ($tiers as $t) {
            if ($total >= (float) $t['min']) {
                return ['key' => $t['key'], 'label' => $t['label']];
            }
        }

        return null;
    }

    public static function label(?string $key, array $tiers): ?string
    {
        foreach ($tiers as $t) {
            if ($t['key'] === $key) {
                return $t['label'];
            }
        }

        return null;
    }

    public static function totalPremiumSql(string $customerIdColumn = 'c.id'): string
    {
        $statuses = "'".implode("','", self::SALES_STATUSES)."'";

        return "(SELECT COALESCE(SUM(sp.annual_premium), 0) FROM policies sp WHERE sp.customer_id = {$customerIdColumn} AND sp.deleted_at IS NULL AND sp.status IN ({$statuses}))";
    }

    public static function caseSql(string $totalExpr, array $tiers): array
    {
        if ($tiers === []) {
            return ['NULL', []];
        }
        $sql = 'CASE';
        $bindings = [];
        foreach ($tiers as $t) {
            $sql .= " WHEN {$totalExpr} >= ? THEN ?";
            $bindings[] = (float) $t['min'];
            $bindings[] = $t['key'];
        }

        return [$sql.' ELSE NULL END', $bindings];
    }
}
