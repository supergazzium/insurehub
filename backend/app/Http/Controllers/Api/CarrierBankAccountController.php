<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CarrierBankAccountResource;
use App\Models\Carrier;
use App\Models\CarrierBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CarrierBankAccountController extends ApiController
{
    public function index(Request $request, Carrier $carrier): AnonymousResourceCollection
    {
        $this->authorizeCarrier($request, $carrier);
        return CarrierBankAccountResource::collection($carrier->bankAccounts()->get());
    }

    public function store(Request $request, Carrier $carrier): JsonResponse
    {
        $this->authorizeCarrier($request, $carrier);
        $data = $this->validated($request, false);
        $account = $carrier->bankAccounts()->create($data);
        $this->enforceSinglePrimary($carrier, $account);
        return (new CarrierBankAccountResource($account->fresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Carrier $carrier, CarrierBankAccount $bankAccount): CarrierBankAccountResource
    {
        $this->authorizeCarrier($request, $carrier);
        $this->authorizeAccount($carrier, $bankAccount);
        $data = $this->validated($request, true);
        $bankAccount->update($data);
        $this->enforceSinglePrimary($carrier, $bankAccount);
        return new CarrierBankAccountResource($bankAccount->fresh());
    }

    public function destroy(Request $request, Carrier $carrier, CarrierBankAccount $bankAccount): JsonResponse
    {
        $this->authorizeCarrier($request, $carrier);
        $this->authorizeAccount($carrier, $bankAccount);
        $bankAccount->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $isUpdate): array
    {
        $sometimes = $isUpdate ? 'sometimes' : 'nullable';
        $rules = [
            'bankId' => [$sometimes, 'integer', 'exists:banks,id'],
            'bankName' => [$sometimes, 'string', 'max:128'],
            'branch' => [$sometimes, 'string', 'max:128'],
            'accountNo' => [$sometimes, 'string', 'max:64'],
            'accountName' => [$sometimes, 'string', 'max:255'],
            'isPrimary' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
        $v = $request->validate($rules);

        $map = [
            'bankId' => 'bank_id',
            'bankName' => 'bank_name',
            'branch' => 'branch',
            'accountNo' => 'account_no',
            'accountName' => 'account_name',
            'isPrimary' => 'is_primary',
            'sortOrder' => 'sort_order',
            'active' => 'active',
        ];
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }
        return $out;
    }

    private function authorizeCarrier(Request $request, Carrier $carrier): void
    {
        if ((int) $carrier->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    private function authorizeAccount(Carrier $carrier, CarrierBankAccount $bankAccount): void
    {
        if ((int) $bankAccount->carrier_id !== (int) $carrier->id) {
            abort(404);
        }
    }

    /**
     * If the just-saved account was flagged primary, clear the flag on every
     * sibling. Guarantees at most one primary per carrier.
     */
    private function enforceSinglePrimary(Carrier $carrier, CarrierBankAccount $account): void
    {
        if (! $account->is_primary) {
            return;
        }
        $carrier->bankAccounts()
            ->where('id', '<>', $account->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
