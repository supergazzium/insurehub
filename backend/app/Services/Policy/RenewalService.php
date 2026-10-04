<?php

declare(strict_types=1);

namespace App\Services\Policy;

use App\Models\Policy;
use App\Models\PolicyEvent;
use Illuminate\Http\Exceptions\HttpResponseException;

final class RenewalService
{
    public static function assertCanLink(int $tenantId, int|string $sourceId, ?Policy $child = null): Policy
    {
        $source = Policy::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($sourceId)
            ->lockForUpdate()
            ->first();
        if ($source === null) {
            self::fail('source_not_found', 'ไม่พบกรมธรรม์ต้นทางที่จะต่ออายุ');
        }
        self::assertRenewable($source, $child, $child?->id, lock: true);

        return $source;
    }

    public static function assertRenewable(Policy $source, ?Policy $child = null, int|string|null $exceptId = null, bool $lock = false): void
    {
        if ($child !== null && $child->exists) {
            if ((string) $child->id === (string) $source->id) {
                self::fail('self_renewal', 'กรมธรรม์ต่ออายุจากตัวเองไม่ได้');
            }
            if ((string) $source->ref_app_to_id === (string) $child->id) {
                self::fail('renewal_cycle', 'กรมธรรม์นี้เป็นฉบับก่อนหน้าของต้นทางอยู่แล้ว — ต่ออายุย้อนกลับไม่ได้');
            }
        }
        if (! in_array($source->status, Policy::RENEWABLE_STATUSES, true)) {
            self::fail(
                'source_not_renewable',
                'ต่ออายุได้เฉพาะกรมธรรม์ที่คุ้มครองอยู่หรือหมดอายุแล้ว (สถานะปัจจุบัน: '.$source->status.')',
                ['sourceStatus' => $source->status],
            );
        }

        $existing = self::liveRenewalOf($source, $exceptId, $lock);
        if ($existing !== null) {
            $ref = $existing->application_no ?: ($existing->policy_no ?: '#'.$existing->id);
            self::fail(
                'already_renewed',
                "กรมธรรม์นี้ถูกต่ออายุไปแล้ว (ใบคำขอ {$ref} สถานะ {$existing->status}) — เปิดฉบับนั้นแทน หรือยกเลิกก่อนจึงจะต่ออายุใหม่ได้",
                ['existingRenewal' => self::summary($existing)],
            );
        }
    }

    public static function liveRenewalOf(Policy $source, int|string|null $exceptId = null, bool $lock = false): ?Policy
    {
        $q = Policy::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('ref_app_to_id', $source->id)
            ->whereNotIn('status', Policy::DEAD_STATUSES)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->orderByDesc('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }

    public static function needsCheck(Policy $policy): bool
    {
        if ($policy->ref_app_to_id === null) {
            return false;
        }
        if (! $policy->exists || $policy->isDirty('ref_app_to_id')) {
            return true;
        }
        $was = $policy->getOriginal('status');

        return $policy->isDirty('status')
            && in_array($was, Policy::DEAD_STATUSES, true)
            && ! in_array($policy->status, Policy::DEAD_STATUSES, true);
    }

    public static function recordLink(Policy $source, Policy $renewal, ?int $userId): void
    {
        $already = PolicyEvent::query()
            ->where('policy_id', $source->id)
            ->where('type', 'renewalStarted')
            ->where('payload->newPolicyId', (string) $renewal->id)
            ->exists();
        if ($already) {
            return;
        }
        PolicyEvent::create([
            'policy_id' => $source->id,
            'type' => 'renewalStarted',
            'occurred_at' => now(),
            'by_user_id' => $userId,
            'payload' => [
                'source' => 'renewal-draft',
                'newPolicyId' => (string) $renewal->id,
            ],
        ]);
    }

    public static function summary(Policy $p): array
    {
        return [
            'id' => (string) $p->id,
            'applicationNo' => $p->application_no,
            'policyNo' => $p->policy_no,
            'status' => $p->status,
            'policyYear' => (int) $p->policy_year,
            'effectiveDate' => $p->effective_date?->toDateString(),
            'expiryDate' => $p->expiry_date?->toDateString(),
        ];
    }

    private static function fail(string $code, string $message, array $extra = []): never
    {
        throw new HttpResponseException(response()->json([
            'code' => $code,
            'message' => $message,
            'errors' => ['refAppToId' => [$message]],
        ] + $extra, 422));
    }
}
