<?php

declare(strict_types=1);

namespace App\Services\Policy;

use App\Models\Policy;
use App\Models\PolicyEvent;
use Illuminate\Support\Facades\DB;

final class EndorsementRecorder
{
    public const TYPES = [
        'endorsement.premium_change',
        'endorsement.coverage_change',
        'endorsement.date_change',
        'endorsement.cancel_reissue',
        'endorsement.other',
    ];

    public static function record(Policy $policy, string $type, array $payload, ?int $userId, ?callable $apply = null): PolicyEvent
    {
        return DB::transaction(function () use ($policy, $type, $payload, $userId, $apply): PolicyEvent {
            $locked = Policy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail(['id', 'endorsement_count']);
            $seq = (int) $locked->endorsement_count + 1;
            Policy::query()->whereKey($policy->id)->update(['endorsement_count' => $seq]);
            $policy->setRawAttributes(array_merge($policy->getAttributes(), ['endorsement_count' => $seq]), true);

            if ($apply !== null) {
                $apply($policy);
            }

            return PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => $type,
                'endorsement_seq' => $seq,
                'occurred_at' => now(),
                'by_user_id' => $userId,
                'payload' => $payload,
            ]);
        });
    }

    public static function void(Policy $policy, PolicyEvent $event, ?int $userId, string $reason): PolicyEvent
    {
        DB::transaction(function () use ($policy, $event, $userId, $reason): void {
            $event->forceFill([
                'voided_at' => now(),
                'voided_by_user_id' => $userId,
                'void_reason' => $reason,
            ])->save();

            if ($event->type === 'endorsement.premium_change') {
                self::resyncPremium($policy);
            }
        });

        return $event->fresh(['user', 'voidedBy']);
    }

    public static function resyncPremium(Policy $policy): void
    {
        $premiumEvents = PolicyEvent::query()
            ->where('policy_id', $policy->id)
            ->where('type', 'endorsement.premium_change')
            ->orderBy('endorsement_seq')
            ->orderBy('id')
            ->get(['id', 'payload', 'voided_at', 'endorsement_seq']);

        if ($premiumEvents->isEmpty()) {
            return;
        }

        $active = $premiumEvents->whereNull('voided_at');
        $target = $active->isNotEmpty()
            ? ($active->last()->payload['after'] ?? null)
            : ($premiumEvents->first()->payload['before'] ?? null);

        if (! is_array($target)) {
            return;
        }

        if (isset($target['annualPremium'])) {
            $policy->annual_premium = (float) $target['annualPremium'];
            $policy->main_premium = isset($target['mainPremium']) && $target['mainPremium'] !== null
                ? (float) $target['mainPremium']
                : (float) $target['annualPremium'];
        }
        if (isset($target['coverage'])) {
            $policy->coverage = (float) $target['coverage'];
        }
        $policy->save();
    }
}
