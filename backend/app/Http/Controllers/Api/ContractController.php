<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ContractController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->scopeTenant(Contract::query(), $request)->with('scheduleRows');

        if ($search = $request->string('q')->toString()) {
            $like = "%{$search}%";
            $q->where('contract_no', 'like', $like);
        }
        if ($carrierId = $request->input('carrierId')) {
            $q->where('carrier_id', $carrierId);
        }
        if ($request->boolean('activeOnly')) {
            $q->where('active', true);
        }

        return ContractResource::collection($q->orderBy('effective_from', 'desc')->paginate($this->perPage($request)));
    }

    public function store(ContractRequest $request): JsonResponse
    {
        $contract = DB::transaction(function () use ($request) {
            $payload = $request->toModel() + ['tenant_id' => $this->tenantId($request)];
            $contract = Contract::create($payload);
            foreach ($request->scheduleRows() as $row) {
                $contract->scheduleRows()->create($row);
            }
            return $contract->load('scheduleRows');
        });
        return (new ContractResource($contract))->response()->setStatusCode(201);
    }

    public function show(Request $request, Contract $contract): ContractResource
    {
        $this->authorizeTenant($request, $contract);
        return new ContractResource($contract->load('scheduleRows'));
    }

    public function update(ContractRequest $request, Contract $contract): ContractResource
    {
        $this->authorizeTenant($request, $contract);
        DB::transaction(function () use ($request, $contract): void {
            $contract->update($request->toModel());
            $rows = $request->scheduleRows();
            if ($rows !== []) {
                // Replace schedule wholesale when the client sends it.
                $contract->scheduleRows()->delete();
                foreach ($rows as $row) {
                    $contract->scheduleRows()->create($row);
                }
            }
        });
        return new ContractResource($contract->fresh()->load('scheduleRows'));
    }

    public function destroy(Request $request, Contract $contract): JsonResponse
    {
        $this->authorizeTenant($request, $contract);
        $contract->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizeTenant(Request $request, Contract $contract): void
    {
        if ((int) $contract->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
