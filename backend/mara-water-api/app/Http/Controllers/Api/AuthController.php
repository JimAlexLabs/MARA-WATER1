<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Round 2 Phase 1: login(), me(), and updateProfile() each hand-built
     * their own `user` response shape and had quietly drifted apart --
     * updateProfile() in particular dropped role/department/permissions
     * entirely. The frontend's global user state gets overwritten with
     * whatever any of these three return (AuthContext's setUser()), and
     * Layout.tsx reads user.role.name/user.department.name on every page
     * -- so updateProfile()'s incomplete shape crashed the whole app to a
     * blank screen the moment someone saved their profile. One shared
     * serializer means all three can never disagree again.
     */
    private function serializeUser(User $user): array
    {
        $user->loadMissing('role.permissions', 'department');

        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'theme' => $user->theme,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'code' => $user->role->code,
                'name' => $user->role->name,
                'description' => $user->role->description,
                // Round 2 Phase 11: which of the four access tiers this
                // role maps to -- the frontend uses this to land the user
                // on the right dashboard and hide nav it has no access to
                // (the real enforcement is server-side; this is just so
                // the UI doesn't show a link that 403s).
                'access_tier' => $user->role->access_tier,
            ] : null,
            'department' => $user->department ? [
                'id' => $user->department->id,
                'code' => $user->department->code,
                'name' => $user->department->name,
                'description' => $user->department->description,
            ] : null,
            'permissions' => $user->role
                ? $user->role->permissions->map(fn ($p) => [
                    'code' => $p->code,
                    'name' => $p->name,
                    'module' => $p->module,
                ])
                : [],
        ];
    }

    /**
     * User login
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'device_info' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Account is not active'
            ], 403);
        }

        // Update last login
        $user->update([
            'last_login_at' => now()
        ]);

        // Create token
        $token = $user->createToken($request->device_info ?? 'web')->plainTextToken;

        // Create session record
        $user->sessions()->create([
            'device_info' => $request->device_info ?? 'web',
            'ip' => $request->ip(),
            'refresh_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $this->serializeUser($user),
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 30 * 24 * 60 * 60, // 30 days
            ]
        ]);
    }

    /**
     * Get authenticated user profile
     */
    public function me(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->serializeUser($user),
            ]
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            // Round 2 Phase 2 (dark mode) -- the toggle in the top bar
            // PATCHes just this field, so it has to be independently
            // valid without requiring the other profile fields.
            'theme' => 'sometimes|in:light,dark',
            'current_password' => 'required_with:new_password|string',
            'new_password' => 'sometimes|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check current password if changing password
        if ($request->has('new_password')) {
            if (!Hash::check($request->current_password, $user->password_hash)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 422);
            }
        }

        $updateData = $request->only(['first_name', 'last_name', 'phone', 'theme']);
        
        if ($request->has('new_password')) {
            $updateData['password_hash'] = Hash::make($request->new_password);
        }

        $user->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $this->serializeUser($user->fresh()),
            ]
        ]);
    }

    /**
     * User logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        
        // Delete current token
        $request->user()->currentAccessToken()->delete();
        
        // Delete session record
        $user->sessions()->where('refresh_token_hash', hash('sha256', $request->bearerToken()))->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find session by refresh token
        $session = \App\Models\UserSession::where('refresh_token_hash', hash('sha256', $request->refresh_token))
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired refresh token'
            ], 401);
        }

        $user = $session->user;

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Account is not active'
            ], 403);
        }

        // Create new token
        $token = $user->createToken($session->device_info)->plainTextToken;

        // Update session
        $session->update([
            'refresh_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 30 * 24 * 60 * 60, // 30 days
            ]
        ]);
    }
}
