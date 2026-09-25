<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ops brief §9: short-lived HR/payroll/profit unlock after password re-auth.
 * Cache key user:{id}:hr_unlocked holds the opaque token for 30 minutes.
 */
class HrUnlockService
{
    public const TTL_MINUTES = 30;

    public function cacheKey(string $userId): string
    {
        return "user:{$userId}:hr_unlocked";
    }

    public function unlock(string $userId): array
    {
        $token = Str::random(48);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        Cache::put($this->cacheKey($userId), [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'ttl_minutes' => self::TTL_MINUTES,
        ];
    }

    public function isUnlocked(string $userId, ?string $presentedToken = null): bool
    {
        $payload = Cache::get($this->cacheKey($userId));
        if (!is_array($payload) || empty($payload['token'])) {
            return false;
        }

        // Token header is optional: cache hit alone is enough on a single
        // instance. When the client sends X-Hr-Unlock, it must match.
        if ($presentedToken !== null && $presentedToken !== '' && !hash_equals($payload['token'], $presentedToken)) {
            return false;
        }

        return true;
    }

    public function status(string $userId): array
    {
        $payload = Cache::get($this->cacheKey($userId));
        if (!is_array($payload) || empty($payload['token'])) {
            return ['unlocked' => false, 'expires_at' => null];
        }

        return [
            'unlocked' => true,
            'expires_at' => $payload['expires_at'] ?? null,
            'token' => $payload['token'],
        ];
    }

    public function lock(string $userId): void
    {
        Cache::forget($this->cacheKey($userId));
    }
}
