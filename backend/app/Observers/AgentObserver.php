<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Agent;
use App\Models\AuditEntry;
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
    ];

    /**
     * Columns where we ARE safe to log the new value (non-PII flags/status).
     * @var array<int, string>
     */
    private const VALUE_LOGGABLE_COLUMNS = [
        'approval_status',
        'active',
    ];

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
                $metadata['old'][$col] = $agent->getOriginal($col);
                $metadata['new'][$col] = $agent->getAttribute($col);
            }
        }

        $this->log($agent, 'agent.updated', $metadata);
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
