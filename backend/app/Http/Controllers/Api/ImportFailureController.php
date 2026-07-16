<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\ApplicationImportFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin triage queue for legacy applications that couldn't be imported
 * because a referenced client/agent/product/company code did not resolve.
 * Populated by `php artisan insurehub:import`.
 */
class ImportFailureController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $q = ApplicationImportFailure::query();

        if ($reason = $request->string('reason')->toString()) {
            $q->where('reason', $reason);
        }
        if ($request->has('resolved')) {
            $q->where('resolved', (bool) $request->boolean('resolved'));
        }
        if ($search = $request->string('q')->toString()) {
            $q->where('application_code', 'like', "%{$search}%");
        }

        $rows = $q->orderBy('id', 'desc')->paginate($this->perPage($request, 50, 200));

        return response()->json([
            'data' => collect($rows->items())->map(fn (ApplicationImportFailure $r) => [
                'id' => (string) $r->id,
                'applicationCode' => $r->application_code,
                'reason' => $r->reason,
                'detail' => $r->detail,
                'raw' => $r->raw_json,
                'importedAt' => $r->imported_at?->toIso8601String(),
                'resolved' => $r->resolved,
                'resolutionNotes' => $r->resolution_notes,
                'createdAt' => $r->created_at?->toIso8601String(),
                'updatedAt' => $r->updated_at?->toIso8601String(),
            ]),
            'meta' => [
                'currentPage' => $rows->currentPage(),
                'perPage' => $rows->perPage(),
                'total' => $rows->total(),
                'lastPage' => $rows->lastPage(),
            ],
        ]);
    }

    public function summary(): JsonResponse
    {
        $rows = ApplicationImportFailure::query()
            ->selectRaw('reason, resolved, COUNT(*) as count')
            ->groupBy('reason', 'resolved')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'reason' => $r->reason,
                'resolved' => (bool) $r->resolved,
                'count' => (int) $r->count,
            ]),
        ]);
    }

    public function resolve(Request $request, ApplicationImportFailure $failure): JsonResponse
    {
        $validated = $request->validate([
            'resolutionNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        $failure->update([
            'resolved' => true,
            'resolution_notes' => $validated['resolutionNotes'] ?? null,
        ]);

        return response()->json(['data' => ['id' => (string) $failure->id, 'resolved' => true]]);
    }
}
