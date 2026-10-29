<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Services\BackupService;

class SettingsController extends Controller
{
    // Keys the Settings page is allowed to write. Anything else in the
    // request body is ignored, so this can't be used to smuggle arbitrary
    // key/value pairs into the table.
    private const ALLOWED_KEYS = [
        'company_name', 'company_email', 'company_phone', 'company_address',
        'timezone', 'date_format', 'time_format', 'language',
        'notifications_enabled', 'email_notifications', 'sms_notifications',
        'session_timeout',
        // Danger Zone gate (Phase 3) -- the reset endpoint checks this
        // server-side too, this isn't just a frontend show/hide flag.
        'danger_zone_unlocked',
        // Director toggles: lock manager-facing pages/sections (data stays live).
        'manager_ui_locks',
        // Per-manager overrides keyed by user id (partial lock bool maps).
        'manager_ui_locks_by_user',
        'driver_allow_cash',
    ];

    /** Defaults when a key has never been saved. locked=true → hidden on manager UI. */
    public static function defaultManagerUiLocks(): array
    {
        return [
            'dashboard' => false,
            'analytics' => false,
            'qa' => false,
            'production' => false,
            'pricing' => false,
            'inventory' => false,
            'inventory_arrivals' => true,
            'sales' => false,
            'finance' => false,
            'finance_invoices' => true,
            'finance_pettycash' => true,
            'finance_debtors' => true,
            'finance_payables' => true,
            'finance_costing' => true,
            'payments' => false,
            'fleet' => true,
            'hr' => false,
            'reports' => true,
            'issues' => false,
            'discrepancies' => false,
            // true = cash locked (M-Pesa only).
            'driver_cash_payment' => true,
            'manager_cash_payment' => true,
            'manager_edit_sales' => true,
            // Unlock = Admin approved temporary edit rights.
            'manager_edit_sales_7d' => true,
            'manager_edit_inventory' => true,
            'manager_edit_production' => true,
            'manager_fieldwork_mode' => false,
        ];
    }

    public function __construct(private BackupService $backups)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->settingsPayload($request),
        ]);
    }

    /**
     * Manager-readable locks only (global defaults + this user's overrides).
     * Full /settings stays director-only.
     */
    public function uiLocks(Request $request)
    {
        $payload = $this->settingsPayload($request);

        return response()->json([
            'success' => true,
            'data' => [
                'manager_ui_locks' => $payload['manager_ui_locks_effective'] ?? $payload['manager_ui_locks'],
                'manager_ui_locks_effective' => $payload['manager_ui_locks_effective'] ?? $payload['manager_ui_locks'],
                'driver_allow_cash' => $payload['driver_allow_cash'] ?? false,
            ],
        ]);
    }

    public function update(Request $request)
    {
        if (!$request->user()->isDirector()) {
            return response()->json([
                'success' => false,
                'message' => 'Only an administrator can change system settings',
            ], 403);
        }

        $values = $request->only(self::ALLOWED_KEYS);

        if (array_key_exists('manager_ui_locks', $values) && is_array($values['manager_ui_locks'])) {
            $values['manager_ui_locks'] = array_merge(
                self::defaultManagerUiLocks(),
                array_intersect_key(
                    $values['manager_ui_locks'],
                    self::defaultManagerUiLocks()
                )
            );
        }

        if (array_key_exists('manager_ui_locks_by_user', $values)) {
            $values['manager_ui_locks_by_user'] = $this->sanitizeManagerUiLocksByUser(
                $values['manager_ui_locks_by_user']
            );
        }

        // Round 5B Phase 8: unlocking Danger Zone requires a fresh backup
        // first — force one if missing, then allow unlock.
        if (array_key_exists('danger_zone_unlocked', $values)
            && filter_var($values['danger_zone_unlocked'], FILTER_VALIDATE_BOOLEAN)
            && !$this->backups->latestFreshBackup()
        ) {
            $backup = $this->backups->snapshot('pre_danger_unlock', $request->user()->id);
            $this->backups->cleanup();
            Setting::putMany($values);
            return response()->json([
                'success' => true,
                'message' => 'No fresh backup existed — one was created automatically, then Danger Zone was unlocked.',
                'data' => $this->settingsPayload($request),
                'forced_backup' => [
                    'id' => $backup->id,
                    'created_at' => $backup->created_at,
                ],
            ]);
        }

        Setting::putMany($values);

        return response()->json([
            'success' => true,
            'message' => 'Settings saved successfully',
            'data' => $this->settingsPayload($request),
        ]);
    }

    /**
     * Build the settings map with merged global locks, per-user lock map,
     * and the effective locks for the authenticated requester.
     */
    private function settingsPayload(Request $request): array
    {
        $data = Setting::allAsMap();
        $locks = \App\Services\ManagerUiLocksService::globalLocks();
        $data['manager_ui_locks'] = $locks;
        $data['manager_ui_locks_by_user'] = \App\Services\ManagerUiLocksService::byUserMap();
        // Effective = what this requester is gated by (directors get all unlocked).
        $data['manager_ui_locks_effective'] = \App\Services\ManagerUiLocksService::effectiveFor($request->user());

        if (! array_key_exists('driver_allow_cash', $data)) {
            $data['driver_allow_cash'] = ! ($locks['driver_cash_payment'] ?? false);
        }

        return $data;
    }

    /**
     * Sanitize per-user lock overrides: map of userId => partial lock bools
     * (only known default keys kept).
     */
    private function sanitizeManagerUiLocksByUser(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $defaults = self::defaultManagerUiLocks();
        $sanitized = [];

        foreach ($raw as $userId => $partial) {
            if (! is_array($partial)) {
                continue;
            }
            $filtered = array_intersect_key($partial, $defaults);
            $bools = [];
            foreach ($filtered as $key => $value) {
                $bools[$key] = (bool) $value;
            }
            $sanitized[(string) $userId] = $bools;
        }

        return $sanitized;
    }
}
