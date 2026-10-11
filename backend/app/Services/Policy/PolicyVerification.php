<?php

declare(strict_types=1);

namespace App\Services\Policy;

use App\Http\Resources\PolicyResource;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Carrier;
use App\Models\Customer;
use App\Models\PendingChange;
use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class PolicyVerification
{
    public const ENTITY = 'policy';

    public const KEY_COLUMNS = [
        'customer_id', 'writing_agent_id', 'carrier_id', 'product_id',
        'policy_no', 'application_no', 'effective_date', 'expiry_date',
        'coverage', 'sum_assured', 'annual_premium', 'main_premium', 'net_premium', 'duty_stamp', 'vat',
        'compulsory_premium', 'discount_amount',
        'comm_hub_to_agent_rate', 'comm_hub_to_agent_amount', 'comm_carrier_to_hub_rate', 'comm_carrier_to_hub_amount',
        'comm_override', 'premium_mode', 'installment_term', 'installment_mode', 'status',
    ];

    public const NUMERIC_COLUMNS = [
        'coverage', 'sum_assured', 'annual_premium', 'main_premium', 'net_premium', 'duty_stamp', 'vat',
        'compulsory_premium', 'discount_amount',
        'comm_hub_to_agent_rate', 'comm_hub_to_agent_amount', 'comm_carrier_to_hub_rate', 'comm_carrier_to_hub_amount',
        'installment_term',
    ];

    public const CREATE_FROM = ['draft', 'quotation', 'quote'];

    public const NON_CREATE_TARGETS = ['draft', 'quotation', 'quote', 'cancelled', 'rejected'];

    public const LABELS = [
        'customer_id' => 'ลูกค้า', 'writing_agent_id' => 'ตัวแทน', 'carrier_id' => 'บริษัทประกัน', 'product_id' => 'ผลิตภัณฑ์',
        'policy_no' => 'เลขกรมธรรม์', 'application_no' => 'เลขใบคำขอ', 'app_date' => 'วันที่ใบคำขอ',
        'effective_date' => 'วันเริ่มคุ้มครอง', 'expiry_date' => 'วันหมดอายุ', 'issue_date' => 'วันออกกรมธรรม์',
        'coverage' => 'ทุนประกัน', 'sum_assured' => 'จำนวนเงินเอาประกัน', 'annual_premium' => 'เบี้ยรวม',
        'main_premium' => 'เบี้ยหลัก', 'net_premium' => 'เบี้ยสุทธิ', 'duty_stamp' => 'อากรแสตมป์', 'vat' => 'VAT',
        'compulsory_premium' => 'เบี้ย พ.ร.บ.', 'discount_amount' => 'ส่วนลด',
        'comm_hub_to_agent_rate' => 'อัตราค่าคอมตัวแทน (%)', 'comm_hub_to_agent_amount' => 'ค่าคอมตัวแทน',
        'comm_carrier_to_hub_rate' => 'อัตราค่าคอมจากบริษัท (%)', 'comm_carrier_to_hub_amount' => 'ค่าคอมจากบริษัท',
        'comm_override' => 'ค่าคอม override', 'premium_mode' => 'งวดชำระ', 'installment_term' => 'จำนวนงวด',
        'installment_mode' => 'รูปแบบผ่อน', 'status' => 'สถานะ', 'approved_date' => 'วันที่อนุมัติ',
        'approval_note' => 'หมายเหตุอนุมัติ', 'approval_ref' => 'เลขอ้างอิงอนุมัติ', 'status_note' => 'หมายเหตุสถานะ',
        'cancel_date' => 'วันที่ยกเลิก', 'cancel_status' => 'เหตุผลยกเลิก', 'lapse_date' => 'วันที่ขาดอายุ',
        'quote_no' => 'เลขใบเสนอราคา', 'quote_date' => 'วันที่ใบเสนอราคา', 'period_paid_end' => 'ชำระถึง',
        'policy_end' => 'สิ้นสุดกรมธรรม์', 'mailing_date' => 'วันที่จัดส่ง', 'mailing_note' => 'หมายเหตุจัดส่ง',
        'mailing_add_by_policy' => 'ที่อยู่จัดส่ง',
    ];

    public const STATUS_LABELS = [
        'draft' => 'ร่าง', 'quotation' => 'ใบเสนอราคา', 'quote' => 'ใบเสนอราคา', 'submitted' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว', 'issued' => 'ออกกรมธรรม์แล้ว', 'active' => 'มีผลบังคับ', 'expired' => 'หมดอายุ',
        'cancelled' => 'ยกเลิก', 'rejected' => 'ไม่อนุมัติ', 'lapsed' => 'ขาดอายุ',
    ];

    public const PREMIUM_MODE_LABELS = [
        'monthly' => 'รายเดือน', 'quarterly' => 'ราย 3 เดือน', 'semiannual' => 'ราย 6 เดือน',
        'annual' => 'รายปี', 'single' => 'ชำระครั้งเดียว',
    ];

    public const ACTION_LABELS =['create' => 'สร้างกรมธรรม์', 'update' => 'แก้ไขข้อมูล', 'delete' => 'ลบกรมธรรม์'];

    public static function enabled(int $tenantId): bool
    {
        return (bool) Tenant::query()->whereKey($tenantId)->value('policy_verify_required');
    }

    public static function open(Policy $policy): ?PendingChange
    {
        return PendingChange::query()
            ->where('entity_type', self::ENTITY)
            ->where('entity_id', $policy->id)
            ->where('status', PendingChange::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    public static function lastRejected(Policy $policy): ?PendingChange
    {
        $last = PendingChange::query()
            ->where('entity_type', self::ENTITY)
            ->where('entity_id', $policy->id)
            ->latest('id')
            ->first();

        return $last?->status === PendingChange::STATUS_REJECTED ? $last : null;
    }

    public static function isCreateTransition(Policy $policy, ?string $to): bool
    {
        return $to !== null && $to !== ''
            && in_array((string) $policy->status, self::CREATE_FROM, true)
            && ! in_array($to, self::NON_CREATE_TARGETS, true);
    }

    public static function assertNotLocked(Policy $policy): void
    {
        $open = self::open($policy);
        if ($open !== null && $open->action !== 'update') {
            throw new HttpResponseException(response()->json([
                'code' => 'pending_verification',
                'message' => 'กรมธรรม์นี้มีคำขอรอตรวจสอบอยู่ แก้ไขไม่ได้จนกว่าจะอนุมัติ/ไม่อนุมัติ หรือถอนคำขอ',
                'pendingChange' => self::row($open),
            ], 409));
        }
    }

    public static function pendingResponse(Policy $policy, PendingChange $change): JsonResponse
    {
        $fresh = Policy::withTrashed()->findOrFail($policy->id)
            ->load(['riders', 'beneficiaries', 'events', 'payments', 'documents', 'rebate', 'legacyStatus', 'product.productType']);

        return response()->json([
            'data' => (new PolicyResource($fresh))->resolve(request()),
            'meta' => ['pendingChange' => self::row($change)],
        ], 202);
    }

    public static function keyChanges(Policy $policy, array $model): array
    {
        $after = [];
        foreach (self::KEY_COLUMNS as $col) {
            if (! array_key_exists($col, $model)) {
                continue;
            }
            if (self::differs($col, $policy->getAttribute($col), $model[$col])) {
                $after[$col] = $model[$col];
            }
        }

        return $after;
    }

    public static function differs(string $col, mixed $current, mixed $new): bool
    {
        return self::norm($col, $current) !== self::norm($col, $new);
    }

    public static function submit(Policy $policy, string $action, array $attributes, array $events, User $maker, ?string $note = null): PendingChange
    {
        return DB::transaction(function () use ($policy, $action, $attributes, $events, $maker, $note): PendingChange {
            Policy::query()->whereKey($policy->id)->lockForUpdate()->first();
            $open = self::open($policy);
            if ($open !== null && ! ($open->action === 'update' && $action === 'update')) {
                throw new HttpResponseException(response()->json([
                    'code' => 'pending_verification',
                    'message' => 'กรมธรรม์นี้มีคำขอรอตรวจสอบอยู่แล้ว',
                    'pendingChange' => self::row($open),
                ], 409));
            }

            $before = [];
            foreach (array_keys($attributes) as $col) {
                $before[$col] = self::snapshot($policy->getAttribute($col));
            }

            if ($open !== null) {
                $mergedAttrs = array_merge($open->payload['attributes'] ?? [], $attributes);
                $mergedBefore = array_merge($before, $open->before ?? []);
                $open->update([
                    'payload' => ['attributes' => $mergedAttrs, 'events' => array_merge($open->payload['events'] ?? [], $events)],
                    'before' => $mergedBefore,
                    'fields' => array_keys($mergedAttrs),
                    'maker_user_id' => $maker->id,
                    'note' => $note ?? $open->note,
                ]);
                $change = $open->fresh();
            } else {
                $change = PendingChange::create([
                    'tenant_id' => $policy->tenant_id,
                    'entity_type' => self::ENTITY,
                    'entity_id' => $policy->id,
                    'action' => $action,
                    'payload' => ['attributes' => self::serialize($attributes), 'events' => $events],
                    'before' => $before,
                    'fields' => array_keys($attributes),
                    'status' => PendingChange::STATUS_PENDING,
                    'maker_user_id' => $maker->id,
                    'note' => $note,
                ]);
            }

            self::audit($change, $maker, 'policy.change_requested');

            return $change;
        });
    }

    public static function approve(PendingChange $change, User $checker): PendingChange
    {
        self::assertDecidable($change, $checker);

        DB::transaction(function () use ($change, $checker): void {
            $policy = Policy::withTrashed()->lockForUpdate()->findOrFail($change->entity_id);
            if ($change->action === 'delete') {
                PolicyEvent::create([
                    'policy_id' => $policy->id,
                    'type' => 'deletedAfterVerify',
                    'occurred_at' => now(),
                    'by_user_id' => $change->maker_user_id,
                    'payload' => ['reason' => $change->note, 'verifiedBy' => $checker->id, 'changeId' => $change->id],
                ]);
                $policy->delete();
            } else {
                $policy->fill($change->payload['attributes'] ?? []);
                $policy->save();
                foreach ($change->payload['events'] ?? [] as $event) {
                    PolicyEvent::create([
                        'policy_id' => $policy->id,
                        'type' => $event['type'],
                        'occurred_at' => now(),
                        'by_user_id' => $change->maker_user_id,
                        'payload' => array_merge($event['payload'] ?? [], ['verifiedBy' => $checker->id, 'changeId' => $change->id]),
                    ]);
                }
            }
            $change->update([
                'status' => PendingChange::STATUS_APPROVED,
                'checker_user_id' => $checker->id,
                'decided_at' => now(),
            ]);
        });

        $change = $change->fresh();
        self::audit($change, $checker, 'policy.change_approved');

        return $change;
    }

    public static function reject(PendingChange $change, User $checker, string $reason): PendingChange
    {
        self::assertDecidable($change, $checker);
        $change->update([
            'status' => PendingChange::STATUS_REJECTED,
            'checker_user_id' => $checker->id,
            'decided_at' => now(),
            'reason' => $reason,
        ]);
        self::audit($change, $checker, 'policy.change_rejected');

        return $change->fresh();
    }

    public static function cancel(PendingChange $change, User $user): PendingChange
    {
        if ($change->status !== PendingChange::STATUS_PENDING) {
            throw new HttpResponseException(response()->json(['code' => 'not_pending', 'message' => 'คำขอนี้ไม่ได้รอตรวจสอบแล้ว'], 422));
        }
        if ((int) $change->maker_user_id !== (int) $user->id) {
            throw new HttpResponseException(response()->json(['code' => 'not_maker', 'message' => 'ถอนได้เฉพาะผู้ส่งคำขอ'], 403));
        }
        $change->update(['status' => PendingChange::STATUS_CANCELLED, 'decided_at' => now()]);
        self::audit($change, $user, 'policy.change_cancelled');

        return $change->fresh();
    }

    public static function row(PendingChange $change, bool $withDiff = false): array
    {
        $change->loadMissing(['maker:id,name', 'checker:id,name', 'policy']);
        $policy = $change->policy;
        $row = [
            'id' => (string) $change->id,
            'action' => $change->action,
            'actionLabel' => self::ACTION_LABELS[$change->action] ?? $change->action,
            'status' => $change->status,
            'policyId' => (string) $change->entity_id,
            'policyNo' => $policy?->policy_no,
            'applicationNo' => $policy?->application_no,
            'policyStatus' => $policy?->status,
            'customerName' => $policy?->customer_id ? self::display('customer_id', $policy->customer_id) : null,
            'makerId' => $change->maker_user_id !== null ? (string) $change->maker_user_id : null,
            'makerName' => $change->maker?->name,
            'checkerName' => $change->checker?->name,
            'createdAt' => $change->created_at?->toIso8601String(),
            'decidedAt' => $change->decided_at?->toIso8601String(),
            'reason' => $change->reason,
            'note' => $change->note,
            'fieldCount' => count($change->fields ?? []),
        ];
        if ($withDiff) {
            $row['diff'] = self::diff($change, $policy);
        }

        return $row;
    }

    public static function diff(PendingChange $change, ?Policy $policy): array
    {
        if ($change->action === 'delete') {
            return [];
        }
        $out = [];
        $attrs = $change->payload['attributes'] ?? [];
        foreach ($attrs as $col => $after) {
            $before = $change->before[$col] ?? null;
            $current = $policy !== null ? self::snapshot($policy->getAttribute($col)) : null;
            $out[] = [
                'field' => $col,
                'label' => self::LABELS[$col] ?? $col,
                'before' => self::display($col, $before),
                'after' => self::display($col, $after),
                'currentChanged' => $change->status === PendingChange::STATUS_PENDING && self::differs($col, $current, $before),
            ];
        }

        return $out;
    }

    public static function summary(?Policy $policy): array
    {
        if ($policy === null) {
            return [];
        }
        $out = [];
        foreach (self::KEY_COLUMNS as $col) {
            $value = self::display($col, self::snapshot($policy->getAttribute($col)));
            if ($value !== null) {
                $out[] = ['field' => $col, 'label' => self::LABELS[$col] ?? $col, 'value' => $value];
            }
        }

        return $out;
    }

    public static function display(string $col, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return match ($col) {
            'customer_id' => self::nameOf(Customer::query()->find($value), fn ($c) => trim(($c->customer_code ? $c->customer_code.' · ' : '').trim(($c->first_name ?? '').' '.($c->last_name ?? '')))),
            'writing_agent_id' => self::nameOf(Agent::query()->find($value), fn ($a) => trim($a->agent_code.' · '.trim(($a->first_name ?? '').' '.($a->last_name ?? '')))),
            'carrier_id' => self::nameOf(Carrier::query()->find($value), fn ($c) => (string) $c->name),
            'product_id' => self::nameOf(Product::query()->find($value), fn ($p) => (string) $p->name),
            'status' => self::STATUS_LABELS[(string) $value] ?? (string) $value,
            'premium_mode' => self::PREMIUM_MODE_LABELS[(string) $value] ?? (string) $value,
            'comm_override' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
            default => in_array($col, self::NUMERIC_COLUMNS, true) && is_numeric($value)
                ? number_format((float) $value, 2)
                : (string) $value,
        };
    }

    private static function nameOf(?object $model, callable $fn): ?string
    {
        return $model !== null ? $fn($model) : null;
    }

    private static function assertDecidable(PendingChange $change, User $checker): void
    {
        if ($change->status !== PendingChange::STATUS_PENDING) {
            throw new HttpResponseException(response()->json(['code' => 'not_pending', 'message' => 'คำขอนี้ไม่ได้รอตรวจสอบแล้ว'], 422));
        }
        if ((int) $change->maker_user_id === (int) $checker->id) {
            throw new HttpResponseException(response()->json([
                'code' => 'maker_cannot_verify',
                'message' => 'ผู้ส่งคำขอตรวจสอบคำขอของตัวเองไม่ได้ ต้องให้ผู้ใช้อีกคนตรวจ',
            ], 403));
        }
    }

    private static function audit(PendingChange $change, User $user, string $action): void
    {
        AuditEntry::create([
            'tenant_id' => $change->tenant_id,
            'user_id' => $user->id,
            'occurred_at' => now(),
            'actor' => $user->name,
            'action' => $action,
            'target' => 'policy:'.$change->entity_id,
            'ip' => request()?->ip(),
            'result' => 'success',
            'metadata' => [
                'change_id' => $change->id,
                'change_action' => $change->action,
                'fields' => $change->fields ?? [],
                'reason' => $change->reason,
            ],
        ]);
    }

    private static function serialize(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $k => $v) {
            $out[$k] = self::snapshot($v);
        }

        return $out;
    }

    private static function snapshot(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }

    private static function norm(string $col, mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_array($v)) {
            return json_encode($v);
        }
        if (in_array($col, self::NUMERIC_COLUMNS, true) && is_numeric($v)) {
            return number_format((float) $v, 4, '.', '');
        }
        $s = (string) $v;
        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T].*)?$/', $s) === 1) {
            return substr($s, 0, 10);
        }

        return $s;
    }
}
