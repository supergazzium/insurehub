<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\PolicyDocumentResource;
use App\Models\Policy;
use App\Models\PolicyDocument;
use App\Models\PolicyEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyDocumentController extends ApiController
{
    /**
     * Allowed document types, shared by store() + upload(). The two
     * `renewal_quote_*` types feed the renewal quotation pipeline: uploading
     * one advances the policy's renewal stage (see recordRenewalStageEvent).
     */
    private const DOC_TYPES = 'application,policy,receipt,medical,endorsement,cancellation,renewal_quote_carrier,renewal_quote_insurehub,other';

    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.self::DOC_TYPES],
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
            $this->recordRenewalStageEvent($policy, $data['type'], $doc, $request);

            return $doc;
        });

        return (new PolicyDocumentResource($doc))->response()->setStatusCode(201);
    }

    /**
     * Phase 6b — multipart upload. Stores the file to the local disk under
     * policy-documents/{tenant}/{policyId}/{section}/, records the path
     * + original name in policy_documents, and emits a documentUploaded event.
     * Mirrors MeAgentController::handlePhotoUpload's tenant-scoped shape.
     */
    public function upload(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.self::DOC_TYPES],
            'file' => ['required', 'file', 'mimes:pdf,jpeg,jpg,png,webp', 'max:10240'],  // 10 MB
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $dir = "policy-documents/{$policy->tenant_id}/{$policy->id}/{$data['type']}";
        $storedPath = $file->store($dir, 'local');

        $doc = DB::transaction(function () use ($policy, $data, $request, $storedPath, $originalName) {
            $doc = $policy->documents()->create([
                'type' => $data['type'],
                'file_name' => $originalName,
                'file_path' => $storedPath,
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
                    'fileName' => $originalName,
                ],
            ]);
            $this->recordRenewalStageEvent($policy, $data['type'], $doc, $request);

            return $doc;
        });

        return (new PolicyDocumentResource($doc))->response()->setStatusCode(201);
    }

    /**
     * Emit the renewal-pipeline stage event that corresponds to a just-uploaded
     * quotation document, so the renewal stage advances automatically:
     *   renewal_quote_carrier   → renewalQuoteReceived  (stage: quote_received)
     *   renewal_quote_insurehub → renewalQuotePrepared   (stage: quote_prepared)
     * Any other document type advances no stage. Called inside the upload/store
     * transaction so the document + stage event commit together.
     */
    private function recordRenewalStageEvent(Policy $policy, string $docType, PolicyDocument $doc, Request $request): void
    {
        $eventType = match ($docType) {
            'renewal_quote_carrier' => 'renewalQuoteReceived',
            'renewal_quote_insurehub' => 'renewalQuotePrepared',
            default => null,
        };
        if ($eventType === null) {
            return;
        }
        PolicyEvent::create([
            'policy_id' => $policy->id,
            'type' => $eventType,
            'occurred_at' => now(),
            'by_user_id' => $request->user()->id,
            'payload' => [
                'documentId' => (string) $doc->id,
                'fileName' => $doc->file_name,
            ],
        ]);
    }

    /**
     * Stream a stored document back to the client. Files live on the `local`
     * disk (private), so we can't hand out plain /storage/... URLs — this
     * route authenticates the session, checks tenant ownership, then streams
     * the file inline for browser preview.
     */
    public function download(Request $request, Policy $policy, PolicyDocument $document): StreamedResponse
    {
        $this->authorizeTenant($request, $policy);
        if ((int) $document->policy_id !== (int) $policy->id) {
            abort(404);
        }
        if (! $document->file_path || ! Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'File missing.');
        }
        $mime = Storage::disk('local')->mimeType($document->file_path) ?: 'application/octet-stream';
        $name = $document->file_name ?: basename($document->file_path);

        return Storage::disk('local')->download($document->file_path, $name, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
        ]);
    }

    public function destroy(Request $request, Policy $policy, PolicyDocument $document): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        if ((int) $document->policy_id !== (int) $policy->id) {
            abort(404);
        }
        // Best-effort: remove the file too. Missing files don't block deletion.
        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            try {
                Storage::disk('local')->delete($document->file_path);
            } catch (\Throwable) { /* ignore */
            }
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
