<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CustomerKycDocResource;
use App\Models\Customer;
use App\Models\CustomerKycDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerKycDocController extends ApiController
{
    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);
        $data = $request->validate([
            'type' => ['required', 'string', 'in:idCard,houseReg,bankBook,income,medical,photo,signature,other'],
            'fileName' => ['required', 'string', 'max:255'],
            'filePath' => ['sometimes', 'nullable', 'string', 'max:512'],
            'uploadedByAgentId' => ['sometimes', 'nullable', 'integer'],
            'verified' => ['sometimes', 'boolean'],
        ]);
        $doc = $customer->kycDocs()->create([
            'type' => $data['type'],
            'file_name' => $data['fileName'],
            'file_path' => $data['filePath'] ?? null,
            'uploaded_at' => now(),
            'uploaded_by_agent_id' => $data['uploadedByAgentId'] ?? null,
            'verified' => (bool) ($data['verified'] ?? false),
        ]);
        return (new CustomerKycDocResource($doc))->response()->setStatusCode(201);
    }

    public function verify(Request $request, Customer $customer, CustomerKycDoc $kycDoc): CustomerKycDocResource
    {
        $this->authorizeTenant($request, $customer);
        $this->ensureChildOf($customer, $kycDoc);
        $kycDoc->update(['verified' => true]);
        return new CustomerKycDocResource($kycDoc->fresh());
    }

    public function destroy(Request $request, Customer $customer, CustomerKycDoc $kycDoc): JsonResponse
    {
        $this->authorizeTenant($request, $customer);
        $this->ensureChildOf($customer, $kycDoc);
        $kycDoc->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeTenant(Request $request, Customer $customer): void
    {
        if ((int) $customer->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    private function ensureChildOf(Customer $customer, CustomerKycDoc $doc): void
    {
        if ((int) $doc->customer_id !== (int) $customer->id) {
            abort(404);
        }
    }
}
