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
    ];

    public function __construct(private BackupService $backups)
    {
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Setting::allAsMap(),
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
                'data' => Setting::allAsMap(),
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
            'data' => Setting::allAsMap(),
        ]);
    }
}
