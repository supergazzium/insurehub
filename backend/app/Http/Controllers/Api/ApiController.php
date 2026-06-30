<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    /** Tenant id for the current request, derived from the authenticated user. */
    protected function tenantId(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    /** Scope a query to the current tenant. */
    protected function scopeTenant(Builder $query, Request $request): Builder
    {
        return $query->where('tenant_id', $this->tenantId($request));
    }

    /** Resolve `per_page` / `perPage` query string with bounds. */
    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        $raw = (int) ($request->input('perPage') ?? $request->input('per_page') ?? $default);
        return max(1, min($max, $raw));
    }
}
