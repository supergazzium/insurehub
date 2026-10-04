<?php

declare(strict_types=1);

namespace App\Support;

final class RenewalFollowUpOptions
{
    public const CHANNELS = ['phone', 'line', 'email', 'inperson', 'other'];

    public const RESULTS = ['unreachable', 'interested', 'thinking', 'not_renewing'];

    public const DECLINE_REASONS = [
        'price_too_high' => 'เบี้ยแพงเกินไป',
        'better_offer' => 'ได้ข้อเสนอที่ดีกว่า',
        'switched_insurer' => 'ทำประกันกับที่อื่นแล้ว',
        'sold_asset' => 'ขายรถ/ทรัพย์สินแล้ว',
        'no_longer_needed' => 'ไม่ต้องการประกันแล้ว',
        'long_unreachable' => 'ติดต่อไม่ได้เป็นเวลานาน',
        'service_unhappy' => 'ไม่พอใจบริการ',
        'other' => 'อื่นๆ',
    ];

    public static function reasonLabel(?string $code, ?string $note = null): ?string
    {
        if ($code === null) {
            return null;
        }
        $label = self::DECLINE_REASONS[$code] ?? $code;

        return $code === 'other' && $note ? "{$label}: {$note}" : $label;
    }
}
