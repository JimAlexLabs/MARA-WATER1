<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 12 (Final verification pass) found this whole controller was
 * pre-upgrade placeholder scaffolding: index() always returned an empty,
 * hardcoded list, and send() never actually inserted a row despite a
 * real `notifications` table + model existing. The bell icon (every
 * page, via Layout.tsx) always showed "No notifications" regardless of
 * what was really in the table -- a decorative button by Phase 1's own
 * definition. Rewritten to be genuinely backed by that table, scoped to
 * the authenticated user (each row belongs to one user_id).
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = Notification::where('user_id', $request->user()->id);

        $isRead = $request->get('read');
        if ($isRead !== null) {
            filter_var($isRead, FILTER_VALIDATE_BOOLEAN)
                ? $query->whereNotNull('read_at')
                : $query->whereNull('read_at');
        }

        $perPage = (int) $request->get('per_page', 15);
        $notifications = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function markAsRead(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'notification_ids' => 'required|array',
            'notification_ids.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $updated = Notification::where('user_id', $request->user()->id)
            ->whereIn('id', $request->notification_ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true, 'message' => "{$updated} notification(s) marked as read"]);
    }

    public function markAllAsRead(Request $request)
    {
        $updated = Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true, 'message' => "{$updated} notification(s) marked as read"]);
    }

    public function delete(Request $request, $id)
    {
        $notification = Notification::where('user_id', $request->user()->id)->find($id);
        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }
        $notification->delete();

        return response()->json(['success' => true, 'message' => 'Notification deleted successfully']);
    }

    /**
     * Create and persist a real notification for one or more recipients.
     * Only 'in_app' actually shows anywhere today -- 'email'/'sms'
     * channels are accepted (matching the column's enum) but nothing
     * sends them, same honestly-disclosed gap as Settings > Notifications.
     *
     * Settings > Notifications > "In-app Notifications" is a single
     * site-wide toggle (the Setting store has no per-user rows), so it's
     * wired here as a company-wide pause switch for the in_app channel --
     * flip it off and no new in-app notifications get created for anyone
     * until it's flipped back on. Existing unread ones are unaffected.
     */
    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:200',
            'message' => 'required|string',
            'type' => 'sometimes|in:info,warning,error,success,alert',
            'channel' => 'sometimes|in:in_app,email,sms',
            'recipient_ids' => 'required|array|min:1',
            'recipient_ids.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $channel = $request->get('channel', 'in_app');
        if ($channel === 'in_app' && !(Setting::allAsMap()['notifications_enabled'] ?? true)) {
            return response()->json([
                'success' => true,
                'message' => 'In-app notifications are disabled in Settings -- nothing sent',
                'data' => [],
            ]);
        }

        $created = collect($request->recipient_ids)->map(fn ($userId) => Notification::create([
            'user_id' => $userId,
            'channel' => $channel,
            'type' => $request->get('type', 'info'),
            'title' => $request->title,
            'body' => $request->message,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Notification sent to ' . $created->count() . ' recipient(s)',
            'data' => $created->values(),
        ]);
    }

    public function getUnreadCount(Request $request)
    {
        $count = Notification::where('user_id', $request->user()->id)->whereNull('read_at')->count();

        return response()->json(['success' => true, 'data' => ['unread_count' => $count]]);
    }
}
