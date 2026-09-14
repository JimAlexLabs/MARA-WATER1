<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;

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
        Setting::putMany($values);

        return response()->json([
            'success' => true,
            'message' => 'Settings saved successfully',
            'data' => Setting::allAsMap(),
        ]);
    }
}
