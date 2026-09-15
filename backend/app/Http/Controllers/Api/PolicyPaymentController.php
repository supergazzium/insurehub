<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\PolicyPaymentResource;
use App\Models\Policy;
use App\Models\PolicyEvent;
use App\Models\PolicyPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PolicyPaymentController extends ApiController
{
    /** List a policy's recorded payments, newest first. */
    public function index(Request $request, Policy $policy): AnonymousResourceCollection
    {
        $this->authorizeTenant($request, $policy);

        return PolicyPaymentResource::collection(
            $policy->payments()->orderByDesc('payment_date')->orderByDesc('id')->get()
        );
    }

    /**
     * Record one or more payment rows for a policy. The payment modal submits a
     * batch: a `payMode` (full / less_commission / with_discount / split /
     * installment), a `payee` (insurehub / carrier), and one `payments[]` row
     * per งวด. Each row becomes a policy_payments record — the PolicyPayment
     * observer fires the MGM pipeline (volume → rank → commission) per row.
     *
     * Back-compat: a single flat {paymentDate, amount, method} body is still
     * accepted (wrapped into a one-row batch).
     */
    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);

        // Normalize a legacy single-payment body into the batch shape.
        if (! $request->has('payments')) {
            $request->merge(['payments' => [[
                'paymentDate' => $request->input('paymentDate'),
                'amount' => $request->input('amount'),
                'method' => $request->input('method'),
                'reference' => $request->input('reference'),
            ]]]);
        }

        $data = $request->validate([
            'payMode' => ['sometimes', 'nullable', 'string', 'in:full,less_commission,with_discount,split,installment'],
            'payee' => ['sometimes', 'nullable', 'string', 'in:insurehub,carrier'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.paymentDate' => ['required', 'date'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.method' => ['required', 'string', 'in:bankTransfer,creditCard,cash,cheque,directDebit,transfer,credit_card'],
            'payments.*.reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payments.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $payMode = $data['payMode'] ?? 'full';
        $payee = $data['payee'] ?? 'insurehub';

        $created = DB::transaction(function () use ($policy, $data, $payMode, $payee, $request) {
            $rows = collect();
            foreach ($data['payments'] as $p) {
                $payment = $policy->payments()->create([
                    'payment_date' => $p['paymentDate'],
                    'amount' => $p['amount'],
                    'method' => self::normalizeMethod($p['method']),
                    // No dedicated columns for note/payMode/payee — fold the
                    // operator note into `reference` so it's not lost.
                    'reference' => $p['reference'] ?? ($p['note'] ?? null),
                    'recorded_by_user_id' => $request->user()->id,
                ]);
                PolicyEvent::create([
                    'policy_id' => $policy->id,
                    'type' => 'premiumPaid',
                    'occurred_at' => now(),
                    'by_user_id' => $request->user()->id,
                    'payload' => [
                        'paymentId' => (string) $payment->id,
                        'amount' => (float) $p['amount'],
                        'method' => self::normalizeMethod($p['method']),
                        'payMode' => $payMode,
                        'payee' => $payee,
                        'note' => $p['note'] ?? null,
                    ],
                ]);
                $rows->push($payment);
            }
            // Flip issued → active once any premium is recorded.
            if ($policy->status === 'issued') {
                $policy->update(['status' => 'active']);
            }

            return $rows;
        });

        return PolicyPaymentResource::collection($created)->response()->setStatusCode(201);
    }

    /** Map the modal's method values onto the stored enum. */
    private static function normalizeMethod(string $m): string
    {
        return match ($m) {
            'transfer' => 'bankTransfer',
            'credit_card' => 'creditCard',
            default => $m,
        };
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
