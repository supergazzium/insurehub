<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PendingChange;
use App\Services\Policy\PolicyVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VerificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $scope = (string) $request->input('scope', 'pending');
        $userId = (int) $request->user()->id;

        $q = PendingChange::query()
            ->with(['maker:id,name', 'checker:id,name', 'policy'])
            ->where('tenant_id', $tenantId)
            ->where('entity_type', PolicyVerification::ENTITY);

        match ($scope) {
            'mine' => $q->where('maker_user_id', $userId),
            'all' => null,
            default => $q->where('status', PendingChange::STATUS_PENDING),
        };

        $rows = $q->orderByDesc('id')->limit(300)->get()->map(fn (PendingChange $c): array => PolicyVerification::row($c));

        return response()->json([
            'data' => $rows,
            'meta' => [
                'pendingCount' => PendingChange::query()->where('tenant_id', $tenantId)->where('status', PendingChange::STATUS_PENDING)->count(),
                'enabled' => PolicyVerification::enabled($tenantId),
                'canVerify' => Gate::allows('policies.verify'),
            ],
        ]);
    }

    public function show(Request $request, PendingChange $verification): JsonResponse
    {
        $this->authorizeTenant($request, $verification);

        $row = PolicyVerification::row($verification, true);
        $row['summary'] = PolicyVerification::summary($verification->policy);

        return response()->json([
            'data' => $row,
            'meta' => [
                'canVerify' => Gate::allows('policies.verify'),
                'isMaker' => (int) $verification->maker_user_id === (int) $request->user()->id,
            ],
        ]);
    }

    public function approve(Request $request, PendingChange $verification): JsonResponse
    {
        $this->authorizeTenant($request, $verification);
        Gate::authorize('policies.verify');
        $change = PolicyVerification::approve($verification, $request->user());

        return response()->json(['data' => PolicyVerification::row($change, true)]);
    }

    public function reject(Request $request, PendingChange $verification): JsonResponse
    {
        $this->authorizeTenant($request, $verification);
        Gate::authorize('policies.verify');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ']);
        $change = PolicyVerification::reject($verification, $request->user(), trim($data['reason']));

        return response()->json(['data' => PolicyVerification::row($change, true)]);
    }

    public function cancel(Request $request, PendingChange $verification): JsonResponse
    {
        $this->authorizeTenant($request, $verification);
        $change = PolicyVerification::cancel($verification, $request->user());

        return response()->json(['data' => PolicyVerification::row($change, true)]);
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
    }

    private function authorizeTenant(Request $request, PendingChange $change): void
    {
        abort_unless((int) $change->tenant_id === $this->tenantId($request), 404);
    }
}
