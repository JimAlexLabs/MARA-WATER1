<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssueMessage;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Round 3 Phase 5: in-app issue reporting -- a lightweight ticket
 * inbox, not real-time chat. A Driver/salesperson raises an issue from
 * their own dashboard; Manager/Director see every issue in an inbox and
 * reply. Notifications reuse the existing Notification model/bell (same
 * one every other page already polls), not a second mechanism.
 */
class IssueController extends Controller
{
    /**
     * Driver tier: only their own issues. Manager/Director: everything,
     * optionally filtered by status.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Issue::with(['creator', 'latestMessage'])
            ->withCount('messages');

        if ($user->hasAccessTier('driver')) {
            $query->where('created_by', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $issues = $query->orderByDesc('updated_at')->paginate($request->get('limit', 20));

        return response()->json([
            'success' => true,
            'data' => $issues->items(),
            'meta' => [
                'current_page' => $issues->currentPage(),
                'last_page' => $issues->lastPage(),
                'total' => $issues->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:200',
            'message' => 'required|string|max:5000',
            'photo_path' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $issue = Issue::create([
            'subject' => $request->subject,
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $issue->messages()->create([
            'sender_id' => $user->id,
            'body' => $request->message,
            'photo_path' => $request->photo_path,
        ]);

        $this->notify(
            $this->managerAndDirectorIds(),
            'New issue: ' . $issue->subject,
            "{$user->full_name} raised an issue: {$request->message}",
            'alert'
        );

        $issue->load(['creator', 'messages.sender']);

        return response()->json([
            'success' => true,
            'message' => 'Issue reported',
            'data' => ['issue' => $issue],
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $issue = Issue::with(['creator', 'messages.sender'])->findOrFail($id);

        if ($request->user()->hasAccessTier('driver') && $issue->created_by !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You can only view your own issues'], 403);
        }

        return response()->json(['success' => true, 'data' => ['issue' => $issue]]);
    }

    public function reply(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'body' => 'required|string|max:5000',
            'photo_path' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $issue = Issue::findOrFail($id);
        $user = $request->user();
        $isDriverOwner = $user->hasAccessTier('driver') && $issue->created_by === $user->id;
        $isStaff = $user->hasAccessTier('manager', 'director');

        if (! $isDriverOwner && ! $isStaff) {
            return response()->json(['success' => false, 'message' => 'You can only reply to your own issues'], 403);
        }

        $issue->messages()->create([
            'sender_id' => $user->id,
            'body' => $request->body,
            'photo_path' => $request->photo_path,
        ]);

        // A Manager/Director reply on a still-open issue counts as picking
        // it up -- moves it to 'acknowledged' automatically. Resolving is
        // always an explicit action (updateStatus below), never implicit.
        if ($isStaff && $issue->status === 'open') {
            $issue->status = 'acknowledged';
        }
        $issue->touch();
        $issue->save();

        if ($isStaff) {
            $this->notify([$issue->created_by], 'Reply on: ' . $issue->subject, "{$user->full_name} replied: {$request->body}", 'info');
        } else {
            $this->notify($this->managerAndDirectorIds(), 'Update on: ' . $issue->subject, "{$user->full_name} replied: {$request->body}", 'info');
        }

        $issue->load(['creator', 'messages.sender']);

        return response()->json(['success' => true, 'data' => ['issue' => $issue]]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:open,acknowledged,resolved',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $issue = Issue::findOrFail($id);
        $issue->update(['status' => $request->status]);

        $this->notify([$issue->created_by], 'Issue ' . $request->status . ': ' . $issue->subject, "Your issue was marked {$request->status}.", 'success');

        return response()->json(['success' => true, 'data' => ['issue' => $issue]]);
    }

    private function managerAndDirectorIds(): array
    {
        return User::whereHas('role', function ($q) {
            $q->whereIn('access_tier', ['manager', 'director']);
        })->pluck('id')->all();
    }

    private function notify(array $userIds, string $title, string $body, string $type = 'info'): void
    {
        if (empty($userIds)) {
            return;
        }
        if (! (Setting::allAsMap()['notifications_enabled'] ?? true)) {
            return;
        }
        foreach ($userIds as $userId) {
            Notification::create([
                'user_id' => $userId,
                'channel' => 'in_app',
                'type' => $type,
                'title' => $title,
                'body' => $body,
            ]);
        }
    }
}
