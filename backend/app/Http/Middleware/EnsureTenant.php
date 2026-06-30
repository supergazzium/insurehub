<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects authenticated requests where the user has no tenant_id.
 * Super-admin users may still pass; controllers scope by `$user->tenant_id` directly,
 * so missing tenant on a non-admin is a configuration error.
 */
class EnsureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }
        if ($user->tenant_id === null && $user->role !== 'super_admin') {
            abort(403, 'User has no tenant assignment.');
        }
        return $next($request);
    }
}
