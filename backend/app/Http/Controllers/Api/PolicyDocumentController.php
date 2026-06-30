<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\PolicyDocumentResource;
use App\Models\Policy;
use App\Models\PolicyDocument;
use App\Models\PolicyEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PolicyDocumentController extends ApiController
{
    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'type' => ['required', 'string', 'in:application,policy,receipt,medical,endorsement,cancellation,other'],
            'fileName' => ['required', 'string', 'max:255'],
            'filePath' => ['nullable', 'string', 'max:512'],
        ]);

        $doc = DB::transaction(function () use ($policy, $data, $request) {
            $doc = $policy->documents()->create([
                'type' => $data['type'],
                'file_name' => $data['fileName'],
                'file_path' => $data['filePath'] ?? null,
                'uploaded_at' => now(),
                'uploaded_by_user_id' => $request->user()->id,
            ]);
            PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => 'documentUploaded',
                'occurred_at' => now(),
                'by_user_id' => $request->user()->id,
                'payload' => [
                    'documentId' => (string) $doc->id,
                    'type' => $data['type'],
                    'fileName' => $data['fileName'],
                ],
            ]);
            return $doc;
        });

        return (new PolicyDocumentResource($doc))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Policy $policy, PolicyDocument $document): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        if ((int) $document->policy_id !== (int) $policy->id) {
            abort(404);
        }
        $document->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
