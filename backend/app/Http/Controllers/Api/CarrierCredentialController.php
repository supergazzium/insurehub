<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CarrierCredentialResource;
use App\Models\Carrier;
use App\Models\CarrierCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CarrierCredentialController extends ApiController
{
    public function index(Request $request, Carrier $carrier): AnonymousResourceCollection
    {
        $this->authorizeCarrier($request, $carrier);
        return CarrierCredentialResource::collection($carrier->credentials()->get());
    }

    public function store(Request $request, Carrier $carrier): JsonResponse
    {
        $this->authorizeCarrier($request, $carrier);
        $data = $this->validated($request);
        $data['tenant_id'] = $this->tenantId($request);
        $credential = $carrier->credentials()->create($data);
        return (new CarrierCredentialResource($credential->fresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Carrier $carrier, CarrierCredential $credential): CarrierCredentialResource
    {
        $this->authorizeCarrier($request, $carrier);
        $this->authorizeCredential($carrier, $credential);
        $credential->update($this->validated($request));
        return new CarrierCredentialResource($credential->fresh());
    }

    public function destroy(Request $request, Carrier $carrier, CarrierCredential $credential): JsonResponse
    {
        $this->authorizeCarrier($request, $carrier);
        $this->authorizeCredential($carrier, $credential);
        $credential->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Tenant-wide label suggestions. Each credential's `label` column is a
     * comma-separated string; we split, trim, and de-dupe into a distinct
     * set sorted by frequency-of-use (most-used first), then alphabetically.
     * Powers the sticky-note picker so operators can reuse labels across
     * different carriers, not just within the current carrier.
     *
     * Route: GET /api/v1/carrier-credentials/labels
     * Response: { data: [{ label: string, count: int }, ...] }
     */
    public function labels(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $rows = DB::table('carrier_credentials')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('label')
            ->where('label', '<>', '')
            ->pluck('label');

        $counts = [];
        foreach ($rows as $raw) {
            foreach (explode(',', (string) $raw) as $piece) {
                $t = trim($piece);
                if ($t === '') continue;
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
        }
        // Sort: frequency desc, then label asc (Thai-aware).
        uksort($counts, fn ($a, $b) => $counts[$b] <=> $counts[$a] ?: strcmp($a, $b));

        $data = [];
        foreach ($counts as $label => $count) {
            $data[] = ['label' => $label, 'count' => $count];
        }
        return response()->json(['data' => $data]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $v = $request->validate([
            'url' => ['sometimes', 'nullable', 'string', 'max:512'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'label' => ['sometimes', 'nullable', 'string', 'max:128'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ]);
        $map = [
            'url' => 'url',
            'username' => 'username',
            'password' => 'password',
            'label' => 'label',
            'sortOrder' => 'sort_order',
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

    private function authorizeCredential(Carrier $carrier, CarrierCredential $credential): void
    {
        if ((int) $credential->carrier_id !== (int) $carrier->id) {
            abort(404);
        }
    }
}
