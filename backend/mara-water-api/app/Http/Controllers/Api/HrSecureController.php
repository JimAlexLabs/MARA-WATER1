<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HrUnlockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Ops brief §9: secondary password gate for HR payroll / profit.
 * Uses Auth::user()'s own password_hash via Hash::check — never a shared secret.
 */
class HrSecureController extends Controller
{
    public function __construct(private HrUnlockService $unlock)
    {
    }

    public function unlock(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = Auth::user();
        if (!$user || empty($user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to verify password for this account.',
            ], 422);
        }

        if (!Hash::check($request->password, $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Incorrect password.',
            ], 422);
        }

        $payload = $this->unlock->unlock($user->id);

        return response()->json([
            'success' => true,
            'message' => 'HR unlock granted for ' . HrUnlockService::TTL_MINUTES . ' minutes.',
            'data' => $payload,
        ]);
    }

    public function status()
    {
        $user = Auth::user();
        $status = $this->unlock->status($user->id);

        // Never leak the raw token on a casual status poll from another tab
        // that did not unlock — only confirm unlocked + expiry.
        return response()->json([
            'success' => true,
            'data' => [
                'unlocked' => $status['unlocked'],
                'expires_at' => $status['expires_at'],
            ],
        ]);
    }

    public function lock()
    {
        $user = Auth::user();
        $this->unlock->lock($user->id);

        return response()->json([
            'success' => true,
            'message' => 'HR unlock cleared.',
        ]);
    }
}
