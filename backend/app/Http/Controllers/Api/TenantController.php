<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\TenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends ApiController
{
    /** GET /tenant — returns the current user's tenant. */
    public function show(Request $request): TenantResource
    {
        $tenant = Tenant::findOrFail($this->tenantId($request));
        return new TenantResource($tenant);
    }

    /** PATCH /tenant — update the current user's tenant. */
    public function update(TenantRequest $request): TenantResource
    {
        $tenant = Tenant::findOrFail($this->tenantId($request));
        $tenant->update($request->toModel());
        return new TenantResource($tenant->fresh());
    }
}
