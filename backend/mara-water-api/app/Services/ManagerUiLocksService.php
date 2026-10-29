<?php

namespace App\Services;

use App\Http\Controllers\Api\SettingsController;
use App\Models\Setting;
use App\Models\User;

/**
 * Resolve effective manager_ui_locks for a user (global + per-user overrides).
 * Directors always bypass locks.
 */
class ManagerUiLocksService
{
    public static function defaults(): array
    {
        return SettingsController::defaultManagerUiLocks();
    }

    public static function globalLocks(): array
    {
        $defaults = self::defaults();
        $raw = Setting::allAsMap()['manager_ui_locks'] ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }

        return array_merge($defaults, array_intersect_key($raw, $defaults));
    }

    public static function byUserMap(): array
    {
        $raw = Setting::allAsMap()['manager_ui_locks_by_user'] ?? [];

        return is_array($raw) ? $raw : [];
    }

    /**
     * Effective lock map for this user. Directors get unlocked (all false)
     * for enforcement helpers — they never hit manager gates.
     */
    public static function effectiveFor(?User $user): array
    {
        $defaults = self::defaults();
        $global = self::globalLocks();

        if (! $user) {
            return $global;
        }

        if ($user->isDirector() || $user->role?->access_tier === 'director' || $user->role?->code === 'ADMIN') {
            return array_fill_keys(array_keys($defaults), false);
        }

        $byUser = self::byUserMap();
        $userId = (string) $user->id;
        $overrides = is_array($byUser[$userId] ?? null) ? $byUser[$userId] : [];

        return array_merge($global, array_intersect_key($overrides, $defaults));
    }

    public static function isLocked(?User $user, string $key): bool
    {
        if ($user && ($user->isDirector() || $user->role?->access_tier === 'director' || $user->role?->code === 'ADMIN')) {
            return false;
        }

        $locks = self::effectiveFor($user);
        if (array_key_exists($key, $locks)) {
            return (bool) $locks[$key];
        }

        return (bool) (self::defaults()[$key] ?? true);
    }
}
