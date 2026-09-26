<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant from the authenticated user. Registered in the middleware
 * priority list before SubstituteBindings, so route model binding is already
 * tenant-scoped: another tenant's ticket id resolves to 404, not 403.
 */
class SetCurrentOrganization
{
    public function __construct(private readonly CurrentOrganization $organization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->is_active) {
            abort(Response::HTTP_FORBIDDEN, 'This account has been deactivated.');
        }

        $this->organization->set($user->organization_id);

        return $next($request);
    }
}
