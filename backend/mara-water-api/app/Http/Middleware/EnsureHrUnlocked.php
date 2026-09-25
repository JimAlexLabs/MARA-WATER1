<?php

namespace App\Http\Middleware;

use App\Services\HrUnlockService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ops brief §9: second-factor gate for payroll / profit endpoints.
 * Unlock is the logged-in user's own password (re-auth), not a shared secret.
 * Cache key user:{id}:hr_unlocked lasts 30 minutes after POST /hr/secure-unlock.
 */
class EnsureHrUnlocked
{
    public function __construct(private HrUnlockService $unlock)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$this->unlock->isUnlocked($user->id, $request->header('X-Hr-Unlock'))) {
            return response()->json([
                'success' => false,
                'code' => 'hr_unlock_required',
                'message' => 'Re-enter your password to access payroll and profit figures.',
            ], 403);
        }

        return $next($request);
    }
}
