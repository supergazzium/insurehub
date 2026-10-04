<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Support\AgentDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentDocumentController extends Controller
{
    public function upload(Request $request, Agent $agent, string $kind): AgentResource
    {
        $column = $this->guard($request, $agent, $kind);
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,pdf', 'max:10240'],
        ], [
            'file.mimes' => 'รองรับเฉพาะไฟล์รูป (jpg, png, webp) หรือ PDF',
            'file.max' => 'ไฟล์ต้องไม่เกิน 10 MB',
        ]);

        $stored = $request->file('file')->store("agent-uploads/{$agent->tenant_id}/{$agent->id}/{$kind}", 'local');
        $this->removeFile($agent->{$column});
        $agent->update([$column => $stored]);

        return new AgentResource($agent->fresh());
    }

    public function show(Request $request, Agent $agent, string $kind): StreamedResponse|JsonResponse
    {
        $column = $this->guard($request, $agent, $kind);
        $path = $agent->{$column};
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404);

        $mime = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';
        if ($request->query('as') === 'base64') {
            return response()->json(['data' => [
                'mime' => $mime,
                'name' => basename($path),
                'content' => base64_encode((string) Storage::disk('local')->get($path)),
            ]]);
        }

        return Storage::disk('local')->response($path, basename($path), [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.addslashes(basename($path)).'"',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    public function destroy(Request $request, Agent $agent, string $kind): AgentResource
    {
        $column = $this->guard($request, $agent, $kind);
        $this->removeFile($agent->{$column});
        $agent->update([$column => null]);

        return new AgentResource($agent->fresh());
    }

    private function guard(Request $request, Agent $agent, string $kind): string
    {
        $tenantId = (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
        abort_unless((int) $agent->tenant_id === $tenantId, 404);
        $column = AgentDocuments::column($kind);
        abort_if($column === null, 404);
        Gate::authorize('agents.update');

        return $column;
    }

    private function removeFile(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable) {
            }
        }
    }
}
