<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Rank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Rank tiers (Lv1–Lv10) for the level selector and the
 * level-progress UI. Ranks are global (not tenant-scoped).
 */
class RankController extends Controller
{
    public function index(): JsonResponse
    {
        $ranks = Rank::query()
            ->orderBy('level')
            ->get()
            ->map(fn (Rank $r): array => $this->row($r));

        return response()->json(['data' => $ranks]);
    }

    public function update(Request $request, Rank $rank): JsonResponse
    {
        Gate::authorize('admin.settings');

        $data = $request->validate([
            'nameTh' => ['sometimes', 'string', 'max:64'],
            'nameEn' => ['sometimes', 'string', 'max:64'],
            'threeMonthAccumTarget' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999'],
            'licenseRequired' => ['sometimes', 'boolean'],
        ]);

        $changes = [];
        if (array_key_exists('nameTh', $data)) {
            $changes['name_th'] = trim($data['nameTh']);
        }
        if (array_key_exists('nameEn', $data)) {
            $changes['name_en'] = trim($data['nameEn']);
        }
        if (array_key_exists('threeMonthAccumTarget', $data)) {
            $changes['three_month_accum_target'] = round((float) $data['threeMonthAccumTarget'], 2);
        }
        if (array_key_exists('licenseRequired', $data)) {
            $changes['license_required'] = (bool) $data['licenseRequired'];
        }

        $before = $this->row($rank);
        DB::transaction(function () use ($rank, $changes): void {
            $ranks = Rank::query()->orderBy('level')->lockForUpdate()->get();
            $this->assertConsistent($ranks->map(fn (Rank $r) => (int) $r->id === (int) $rank->id
                ? (clone $r)->forceFill($changes)
                : $r));
            $rank->forceFill($changes)->save();
        });
        $after = $this->row($rank->fresh());

        if ($before !== $after) {
            AuditEntry::create([
                'tenant_id' => (int) $request->attributes->get('tenant_id', $request->user()->tenant_id),
                'user_id' => $request->user()->id,
                'occurred_at' => now(),
                'actor' => $request->user()->name,
                'action' => 'rank.updated',
                'target' => 'rank:'.$rank->id,
                'ip' => $request->ip(),
                'result' => 'success',
                'metadata' => ['before' => $before, 'after' => $after],
            ]);
        }

        return response()->json(['data' => $after]);
    }

    private function assertConsistent($ranks): void
    {
        $prevTarget = null;
        $licenseStarted = false;
        foreach ($ranks as $r) {
            $target = (float) $r->three_month_accum_target;
            if ((int) $r->level === 1 && $target > 0) {
                throw ValidationException::withMessages(['threeMonthAccumTarget' => 'ระดับ 1 เป็นระดับเริ่มต้น เป้าต้องเป็น 0']);
            }
            if ($prevTarget !== null && $target < $prevTarget) {
                throw ValidationException::withMessages([
                    'threeMonthAccumTarget' => "เป้าระดับ {$r->level} ต้องไม่น้อยกว่าเป้าระดับ ".($r->level - 1),
                ]);
            }
            if ($licenseStarted && ! $r->license_required) {
                throw ValidationException::withMessages([
                    'licenseRequired' => "ระดับ {$r->level} ต้องบังคับใบอนุญาต เพราะระดับที่ต่ำกว่าบังคับแล้ว",
                ]);
            }
            $licenseStarted = $licenseStarted || (bool) $r->license_required;
            $prevTarget = $target;
        }
    }

    private function row(Rank $r): array
    {
        return [
            'id' => (string) $r->id,
            'level' => (int) $r->level,
            'levelKey' => 'l'.$r->level,
            'code' => $r->code,
            'nameTh' => $r->name_th,
            'nameEn' => $r->name_en,
            'threeMonthAccumTarget' => (float) $r->three_month_accum_target,
            'licenseRequired' => (bool) $r->license_required,
        ];
    }
}
