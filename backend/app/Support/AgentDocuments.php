<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Agent;

final class AgentDocuments
{
    public const COLUMNS = [
        'id' => 'id_card_photo_path',
        'bank' => 'bank_book_photo_path',
        'license_life' => 'license_life_doc_path',
        'license_non_life' => 'license_non_life_doc_path',
        'profile' => 'profile_photo_path',
    ];

    public const LABELS = [
        'id' => 'บัตรประชาชน',
        'bank' => 'หน้า Book Bank',
        'license_life' => 'ใบอนุญาตชีวิต',
        'license_non_life' => 'ใบอนุญาตวินาศภัย',
        'profile' => 'รูปโปรไฟล์',
    ];

    public static function column(string $kind): ?string
    {
        return self::COLUMNS[$kind] ?? null;
    }

    public static function presence(Agent $agent): array
    {
        $out = [];
        foreach (self::COLUMNS as $kind => $column) {
            $out[self::camel($kind)] = filled($agent->{$column});
        }

        return $out;
    }

    private static function camel(string $kind): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $kind))));
    }
}
