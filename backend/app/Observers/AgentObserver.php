<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes an audit_entries row whenever a change to sensitive agent data
 * lands. Runs on `updated` (after the model is saved), so both the old and
 * new value are available via getOriginal() / getAttribute().
 *
 * We deliberately DO NOT log old/new values for PII columns — the audit
 * trail records "id_card was changed", not the actual number, so the audit
 * log itself isn't a PII exfiltration channel.
 */
class AgentObserver
{
    /**
     * Columns whose changes always produce an audit row.
     * @var array<int, string>
     */
    private const SENSITIVE_COLUMNS = [
        'id_card',
        'bank_id',
        'bank_account_no',
        'bank_account_name',
        'bank_name_text',
        'license_number',
        'license_life_no',
        'license_life_expiry',
        'license_non_life_no',
        'license_non_life_expiry',
        'approval_status',
        'active',
        'rank_id',
        'kind',
        'juristic_name',
        'tax_id',
        'has_vat',
        'vat_mode',
        'vat_type',
        'wht_rate',
        'agent_type',
        'id_card_photo_path',
        'bank_book_photo_path',
        'license_life_doc_path',
        'license_non_life_doc_path',
    ];

    private const MASKED_COLUMNS = [
        'tax_id',
    ];

    /**
     * Columns where we ARE safe to log the new value (non-PII flags/status).
     * @var array<int, string>
     */
    private const VALUE_LOGGABLE_COLUMNS = [
        'approval_status',
        'active',
        'rank_id',
        'kind',
        'juristic_name',
        'tax_id',
        'has_vat',
        'vat_mode',
        'vat_type',
        'wht_rate',
        'agent_type',
    ];

    public function creating(Agent $agent): void
    {
        if ($agent->rank_id !== null) {
            return;
        }
        $lv1 = Rank::query()->where('level', 1)->value('id');
        if ($lv1 !== null) {
            $agent->rank_id = $lv1;
            $agent->level = 'l1';
        }
    }

    public function created(Agent $agent): void
    {
        $this->log($agent, 'agent.created', [
            'agent_code' => $agent->agent_code,
            'signup_type' => $agent->signup_type,
            'approval_status' => $agent->approval_status,
        ]);
    }

    public function updated(Agent $agent): void
    {
        $changed = collect($agent->getChanges())->keys()
            ->intersect(self::SENSITIVE_COLUMNS)
            ->values()
            ->all();

        if ($changed === []) {
            return;
        }

        $metadata = ['fields' => $changed];
        foreach ($changed as $col) {
            if (in_array($col, self::VALUE_LOGGABLE_COLUMNS, true)) {
                $old = $agent->getOriginal($col);
                $new = $agent->getAttribute($col);
                if (in_array($col, self::MASKED_COLUMNS, true)) {
                    $old = $this->mask($old);
                    $new = $this->mask($new);
                }
                $metadata['old'][$col] = $old;
                $metadata['new'][$col] = $new;
            }
        }

        $this->log($agent, 'agent.updated', $metadata);

        if ($agent->wasChanged('active') && ! $agent->active) {
            User::query()->where('agent_id', $agent->id)->get()->each(fn (User $u) => $u->tokens()->delete());
        }
    }

    private function mask(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = (string) $value;

        return str_repeat('•', max(0, strlen($s) - 4)).substr($s, -4);
    }

    public function deleted(Agent $agent): void
    {
        $this->log($agent, 'agent.deleted', [
            'agent_code' => $agent->agent_code,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function log(Agent $agent, string $action, array $metadata): void
    {
        $user = Auth::user();
        AuditEntry::create([
            'tenant_id' => $agent->tenant_id,
            'user_id' => $user?->id,
            'occurred_at' => now(),
            'actor' => $user?->name ?? 'system',
            'action' => $action,
            'target' => 'agent:'.$agent->id,
            'ip' => Request::ip() ?? null,
            'result' => 'success',
            'metadata' => $metadata,
        ]);
    }
}
