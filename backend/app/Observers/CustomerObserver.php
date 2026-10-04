<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AuditEntry;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class CustomerObserver
{
    public const MASKED = [
        'id_card', 'passport', 'tax_id', 'phone', 'tel_phone', 'email', 'email2', 'line_id',
        'birth_date', 'address', 'mailing_address', 'contact_phone', 'contact_email', 'monthly_income',
    ];

    private const IGNORED = ['updated_at', 'created_at', 'active_policy_count', 'total_policy_count'];

    public function updated(Customer $customer): void
    {
        $changes = [];
        foreach (array_keys($customer->getChanges()) as $col) {
            if (in_array($col, self::IGNORED, true)) {
                continue;
            }
            $changes[$col] = [
                'from' => $this->present($col, $customer->getOriginal($col)),
                'to' => $this->present($col, $customer->getAttribute($col)),
            ];
        }
        if ($changes === []) {
            return;
        }
        try {
            $user = auth()->user();
            AuditEntry::create([
                'tenant_id' => $customer->tenant_id,
                'user_id' => $user?->id,
                'occurred_at' => now(),
                'actor' => $user?->name ?? 'system',
                'action' => 'customer.updated',
                'target' => 'customer:'.$customer->id,
                'ip' => request()?->ip(),
                'result' => 'success',
                'metadata' => ['changes' => $changes],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Customer audit failed', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
        }
    }

    private function present(string $col, mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (! in_array($col, self::MASKED, true)) {
            return is_bool($value) ? $value : (string) $value;
        }
        $s = (string) $value;
        $len = mb_strlen($s);

        return $len <= 4 ? str_repeat('•', $len) : str_repeat('•', min(6, $len - 4)).mb_substr($s, -4);
    }
}
