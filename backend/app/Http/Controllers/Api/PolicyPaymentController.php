<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\PolicyPaymentResource;
use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\PolicyPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PolicyPaymentController extends ApiController
{
    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'paymentDate' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['required', 'string', 'in:bankTransfer,creditCard,cash,cheque,directDebit'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = DB::transaction(function () use ($policy, $data, $request) {
            $payment = $policy->payments()->create([
                'payment_date' => $data['paymentDate'],
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'recorded_by_user_id' => $request->user()->id,
            ]);
            // Flip issued → active on first paid premium.
            if ($policy->status === 'issued') {
                $policy->update(['status' => 'active']);
            }
            PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => 'premiumPaid',
                'occurred_at' => now(),
                'by_user_id' => $request->user()->id,
                'payload' => [
                    'paymentId' => (string) $payment->id,
                    'amount' => (float) $data['amount'],
                    'method' => $data['method'],
                ],
            ]);
            return $payment;
        });

        return (new PolicyPaymentResource($payment))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Policy $policy, PolicyPayment $payment): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        if ((int) $payment->policy_id !== (int) $policy->id) {
            abort(404);
        }
        $payment->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
