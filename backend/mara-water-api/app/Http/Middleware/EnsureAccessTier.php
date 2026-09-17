<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Round 2 Phase 11: real, server-side role-based access control -- "not
 * just hiding UI elements client-side, a hidden button is not real
 * security." Applied per route group as middleware('tier:manager,director')
 * etc.; a request from a user whose role's access_tier isn't in the
 * allowed list is rejected with 403 before the controller ever runs,
 * regardless of what the frontend does or doesn't show.
 */
class EnsureAccessTier
{
    public function handle(Request $request, Closure $next, string ...$tiers): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasAccessTier(...$tiers)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this area.',
            ], 403);
        }

        return $next($request);
    }
}
