<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Attendance;
use App\Models\Notification;
use App\Models\User;

class AttendanceController extends Controller
{
    // Round 4 Phase 9: the shared Driver/Sales/Field-Work dashboard has
    // three independent Check In/Check Out controls, one per person on
    // that trip -- so the requester (always the Driver-tier account
    // logged into the shared device) needs to be able to check someone
    // else in/out, not just themselves. Scoped tightly: only allowed
    // when the requester is themselves driver-tier, and only for a
    // target whose role is one of the three field-team roles -- never
    // an arbitrary user_id, and never a route to check in a Manager/
    // Director. Names are never freely typed; the target must already
    // exist as a real user with one of these roles (Round 2 Phase 4 HR/
    // Employee module is where the Director assigns them).
    private const FIELD_TEAM_ROLE_CODES = ['DRV', 'SALES', 'FIELDWORK'];

    private function resolveAttendanceTarget(Request $request)
    {
        $requester = $request->user();
        $targetUserId = $request->input('user_id');

        if (!$targetUserId || $targetUserId === $requester->id) {
            $request->merge(['user_id' => $requester->id]);
            return null;
        }

        if (!$requester->hasAccessTier('driver')) {
            return response()->json(['success' => false, 'message' => 'You can only check yourself in/out'], 403);
        }

        $target = User::with('role')->find($targetUserId);
        if (!$target || !in_array($target->role->code ?? null, self::FIELD_TEAM_ROLE_CODES)) {
            return response()->json(['success' => false, 'message' => 'You can only check in/out yourself or an assigned Driver/Sales/Field Work team member'], 403);
        }

        $request->merge(['user_id' => $targetUserId]);
        return null;
    }

    /** Latest open (checked-in, not yet out) session for a user — any date. */
    private function openSession(string $userId): ?Attendance
    {
        return Attendance::where('user_id', $userId)
            ->incomplete()
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->first();
    }

    private function formatClockTime($value): ?string
    {
        if (!$value) {
            return null;
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }

    private function sessionDateString(Attendance $attendance): string
    {
        $date = $attendance->date;
        if ($date instanceof \Carbon\CarbonInterface) {
            return $date->toDateString();
        }

        return (string) $date;
    }

    private function clockInDateTime(Attendance $attendance): \Carbon\Carbon
    {
        $time = $attendance->clock_in_time;
        $timeStr = $time instanceof \Carbon\CarbonInterface
            ? $time->format('H:i:s')
            : (string) $time;

        return \Carbon\Carbon::parse($this->sessionDateString($attendance).' '.$timeStr);
    }

    private function notifyManagersDirectors(string $title, string $body): void
    {
        $ids = User::whereHas('role', fn ($q) => $q->whereIn('access_tier', ['manager', 'director']))->pluck('id');
        foreach ($ids as $userId) {
            Notification::create([
                'user_id' => $userId,
                'channel' => 'in_app',
                'type' => 'attendance',
                'title' => $title,
                'body' => $body,
            ]);
        }
    }

    /**
     * Round 4 Phase 9 / multi-session: every active field-team user with
     * independent status. Open sessions can span overnight; finishing a
     * cycle never locks the person out of another check-in the same day.
     */
    public function fieldTeam(Request $request)
    {
        $today = now()->toDateString();
        $users = User::with('role')->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('code', self::FIELD_TEAM_ROLE_CODES))
            ->orderBy('first_name')
            ->get();

        $userIds = $users->pluck('id');

        $openByUser = Attendance::whereIn('user_id', $userIds)
            ->incomplete()
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        $latestTodayByUser = Attendance::whereIn('user_id', $userIds)
            ->whereDate('date', $today)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first());

        $sessionsTodayCounts = Attendance::whereIn('user_id', $userIds)
            ->whereDate('date', $today)
            ->selectRaw('user_id, COUNT(*) as c')
            ->groupBy('user_id')
            ->pluck('c', 'user_id');

        $data = $users->map(function ($u) use ($openByUser, $latestTodayByUser, $sessionsTodayCounts, $today) {
            $open = $openByUser->get($u->id);
            $latestToday = $latestTodayByUser->get($u->id);
            $openDate = $open ? $this->sessionDateString($open) : null;
            $overnight = $open && $openDate !== $today;

            return [
                'id' => $u->id,
                'full_name' => $u->full_name,
                'role_code' => $u->role->code ?? null,
                'role_name' => $u->role->name ?? null,
                // has_open_session drives the button; clocked_out is never a day-lock
                'has_open_session' => (bool) $open,
                'overnight_open' => $overnight,
                'open_session_date' => $openDate,
                'clocked_in' => (bool) $open,
                'clocked_out' => false,
                'clock_in_time' => $this->formatClockTime($open?->clock_in_time ?? $latestToday?->clock_in_time),
                'clock_out_time' => $open ? null : $this->formatClockTime($latestToday?->clock_out_time),
                'sessions_today' => (int) ($sessionsTodayCounts[$u->id] ?? 0),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Manager attendance employee picker — id/name only, live from HR,
     * excludes Director/Investor. Does NOT reopen full /users to Manager.
     */
    public function attendanceEmployees()
    {
        $users = User::with('role:id,name,code,access_tier')
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereNotIn('access_tier', ['director', 'investor']))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'staff_number', 'role_id', 'department_id']);

        $data = $users->map(fn ($u) => [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'full_name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
            'staff_number' => $u->staff_number,
            'role_name' => optional($u->role)->name,
            'role_code' => optional($u->role)->code,
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function index(Request $request)
    {
        try {
            $query = Attendance::with(['user', 'shift', 'createdBy'])
                // Round 3 Phase 8 / Round 5A Phase 1: neither Director nor
                // Investor is tracked staff -- they technically can clock in
                // (same tier grant as Manager/Driver from Round 2), but
                // shouldn't show up as a row in the Manager's staff
                // attendance list. Driver/Sales/Field-Work keep arriving
                // automatically from the Round 4 Phase 9 check-in/checkout
                // flow; everyone else is still recorded the existing way.
                ->whereHas('user.role', function ($q) {
                    $q->whereNotIn('access_tier', ['director', 'investor']);
                });

            // Filtering
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->filled('shift_id')) {
                $query->where('shift_id', $request->shift_id);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('date_from')) {
                $query->whereDate('date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('date', '<=', $request->date_to);
            }
            if ($request->filled('department_id')) {
                $query->whereHas('user.department', function($q) use ($request) {
                    $q->where('id', $request->department_id);
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $attendances = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $attendances->items()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve attendance records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'shift_id' => 'nullable|exists:shifts,id',
                'date' => 'required|date',
                'clock_in_time' => 'required|date_format:H:i:s',
                'clock_out_time' => 'nullable|date_format:H:i:s|after:clock_in_time',
                'break_start_time' => 'nullable|date_format:H:i:s',
                'break_end_time' => 'nullable|date_format:H:i:s|after:break_start_time',
                'total_hours' => 'nullable|numeric|min:0',
                'overtime_hours' => 'nullable|numeric|min:0',
                'status' => 'required|string|max:50',
                'notes' => 'nullable|string|max:1000',
                'location' => 'nullable|string|max:255',
                'photo_url' => 'nullable|url|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if attendance record already exists for this user and date
            $existingAttendance = Attendance::where('user_id', $request->user_id)
                ->whereDate('date', $request->date)
                ->first();

            if ($existingAttendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance record already exists for this user and date'
                ], 422);
            }

            // Calculate total hours if not provided
            $totalHours = $request->total_hours;
            if (!$totalHours && $request->clock_out_time) {
                $clockIn = \Carbon\Carbon::parse($request->clock_in_time);
                $clockOut = \Carbon\Carbon::parse($request->clock_out_time);
                $totalHours = $clockIn->diffInHours($clockOut, true);
            }

            $attendance = Attendance::create([
                'user_id' => $request->user_id,
                'shift_id' => $request->shift_id,
                'date' => $request->date,
                'clock_in_time' => $request->clock_in_time,
                'clock_out_time' => $request->clock_out_time,
                'break_start_time' => $request->break_start_time,
                'break_end_time' => $request->break_end_time,
                'total_hours' => $totalHours,
                'overtime_hours' => $request->overtime_hours,
                'status' => $request->status,
                'notes' => $request->notes,
                'location' => $request->location,
                'photo_url' => $request->photo_url,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Attendance record created successfully',
                'data' => [
                    'attendance' => $attendance->load(['user', 'shift', 'createdBy'])
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create attendance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $attendance = Attendance::with(['user', 'shift', 'createdBy'])->find($id);

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance record not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'attendance' => $attendance
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve attendance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $attendance = Attendance::find($id);

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance record not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'shift_id' => 'nullable|exists:shifts,id',
                'clock_out_time' => 'nullable|date_format:H:i:s|after:clock_in_time',
                'break_start_time' => 'nullable|date_format:H:i:s',
                'break_end_time' => 'nullable|date_format:H:i:s|after:break_start_time',
                'total_hours' => 'nullable|numeric|min:0',
                'overtime_hours' => 'nullable|numeric|min:0',
                'status' => 'sometimes|required|string|max:50',
                'notes' => 'nullable|string|max:1000',
                'location' => 'nullable|string|max:255',
                'photo_url' => 'nullable|url|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Calculate total hours if clock out time is provided
            $totalHours = $request->total_hours;
            if ($request->clock_out_time && !$totalHours) {
                $clockIn = \Carbon\Carbon::parse($attendance->clock_in_time);
                $clockOut = \Carbon\Carbon::parse($request->clock_out_time);
                $totalHours = $clockIn->diffInHours($clockOut, true);
            }

            $attendance->update([
                'shift_id' => $request->shift_id ?? $attendance->shift_id,
                'clock_out_time' => $request->clock_out_time,
                'break_start_time' => $request->break_start_time,
                'break_end_time' => $request->break_end_time,
                'total_hours' => $totalHours,
                'overtime_hours' => $request->overtime_hours,
                'status' => $request->status ?? $attendance->status,
                'notes' => $request->notes,
                'location' => $request->location,
                'photo_url' => $request->photo_url,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Attendance record updated successfully',
                'data' => [
                    'attendance' => $attendance->load(['user', 'shift', 'createdBy'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update attendance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $attendance = Attendance::find($id);

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance record not found'
                ], 404);
            }

            $attendance->delete();

            return response()->json([
                'success' => true,
                'message' => 'Attendance record deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete attendance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function clockIn(Request $request)
    {
        if ($deny = $this->resolveAttendanceTarget($request)) {
            return $deny;
        }

        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'shift_id' => 'nullable|exists:shifts,id',
                'location' => 'nullable|string|max:255',
                'photo_url' => 'nullable|url|max:500',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $today = now()->toDateString();
            $currentTime = now()->format('H:i:s');

            // Multi-session: only block a new check-in while an open
            // session exists (including overnight). After check-out they
            // can check in again the same day — each cycle is its own row.
            $open = $this->openSession($request->user_id);
            if ($open) {
                $openDate = $this->sessionDateString($open);
                $msg = $openDate !== $today
                    ? "Still checked in from {$openDate} — check out that session first, then you can check in again."
                    : 'Already checked in — check out first before starting another session.';

                return response()->json([
                    'success' => false,
                    'message' => $msg,
                    'data' => [
                        'open_session_date' => $openDate,
                        'overnight_open' => $openDate !== $today,
                    ],
                ], 422);
            }

            $attendance = Attendance::create([
                'user_id' => $request->user_id,
                'shift_id' => $request->shift_id,
                'date' => $today,
                'clock_in_time' => $currentTime,
                'status' => 'present',
                'location' => $request->location,
                'photo_url' => $request->photo_url,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $who = User::find($request->user_id);
            $name = $who ? trim(($who->first_name ?? '').' '.($who->last_name ?? '')) : 'Team member';
            $this->notifyManagersDirectors(
                'Check-in',
                "{$name} checked in at {$currentTime}."
            );

            return response()->json([
                'success' => true,
                'message' => 'Clock in successful',
                'data' => [
                    'attendance' => $attendance->load(['user', 'shift', 'createdBy']),
                    'clock_in_time' => $currentTime
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clock in',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function clockOut(Request $request)
    {
        if ($deny = $this->resolveAttendanceTarget($request)) {
            return $deny;
        }

        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'location' => 'nullable|string|max:255',
                'photo_url' => 'nullable|url|max:500',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $today = now()->toDateString();
            $currentTime = now()->format('H:i:s');

            // Close the latest open session (any date) — overnight
            // forgotten check-outs are closed here without locking the day.
            $attendance = $this->openSession($request->user_id);

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'No open check-in to close'
                ], 404);
            }

            $sessionDate = $this->sessionDateString($attendance);
            $overnight = $sessionDate !== $today;
            $clockIn = $this->clockInDateTime($attendance);
            $clockOut = now();
            $totalHours = round($clockIn->diffInMinutes($clockOut) / 60, 2);
            $overtimeHours = max(0, $totalHours - 8);

            $attendance->update([
                'clock_out_time' => $currentTime,
                'total_hours' => $totalHours,
                'overtime_hours' => $overtimeHours,
                'location' => $request->location,
                'photo_url' => $request->photo_url,
                'notes' => $request->notes,
                'updated_by' => Auth::id(),
            ]);

            $who = User::find($request->user_id);
            $name = $who ? trim(($who->first_name ?? '').' '.($who->last_name ?? '')) : 'Team member';
            $alert = $overnight
                ? "Closed open check-in from {$sessionDate} ({$totalHours}h). Ready to check in again."
                : "Checked out · {$totalHours}h. Ready to check in again anytime.";

            $this->notifyManagersDirectors(
                'Check-out',
                "{$name} checked out at {$currentTime} ({$totalHours}h".($overnight ? ", session from {$sessionDate}" : '').').'
            );

            return response()->json([
                'success' => true,
                'message' => $alert,
                'data' => [
                    'attendance' => $attendance->fresh()->load(['user', 'shift', 'createdBy']),
                    'clock_out_time' => $currentTime,
                    'total_hours' => $totalHours,
                    'overtime_hours' => $overtimeHours,
                    'overnight_closed' => $overnight,
                    'session_date' => $sessionDate,
                    'can_check_in_again' => true,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clock out',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function statistics(Request $request)
    {
        try {
            $query = Attendance::query()
                // Round 5A Phase 1: same exclusion as index() above.
                ->whereHas('user.role', function ($q) {
                    $q->whereNotIn('access_tier', ['director', 'investor']);
                });

            // Filter by date range
            if ($request->filled('date_from')) {
                $query->whereDate('date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('date', '<=', $request->date_to);
            }

            // Filter by department
            if ($request->filled('department_id')) {
                $query->whereHas('user.department', function($q) use ($request) {
                    $q->where('id', $request->department_id);
                });
            }

            $stats = $query->selectRaw('
                COUNT(*) as total_records,
                COUNT(DISTINCT user_id) as unique_users,
                SUM(total_hours) as total_hours_worked,
                SUM(overtime_hours) as total_overtime_hours,
                AVG(total_hours) as avg_hours_per_day,
                AVG(overtime_hours) as avg_overtime_hours
            ')->first();

            // Get attendance by status
            $attendanceByStatus = $query->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')->get();

            // Get attendance by user
            $attendanceByUser = $query->selectRaw('
                user_id, 
                COUNT(*) as days_worked,
                SUM(total_hours) as total_hours,
                SUM(overtime_hours) as overtime_hours,
                AVG(total_hours) as avg_hours_per_day
            ')->groupBy('user_id')
              ->with('user')
              ->orderBy('total_hours', 'desc')
              ->limit(10)
              ->get();

            // Get late arrivals (after 8:00 AM)
            $lateArrivals = $query->whereTime('clock_in_time', '>', '08:00:00')
                ->count();

            // Get early departures (before 5:00 PM)
            $earlyDepartures = $query->whereTime('clock_out_time', '<', '17:00:00')
                ->whereNotNull('clock_out_time')
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => [
                        'total_records' => $stats->total_records ?? 0,
                        'unique_users' => $stats->unique_users ?? 0,
                        'total_hours_worked' => round($stats->total_hours_worked ?? 0, 2),
                        'total_overtime_hours' => round($stats->total_overtime_hours ?? 0, 2),
                        'avg_hours_per_day' => round($stats->avg_hours_per_day ?? 0, 2),
                        'avg_overtime_hours' => round($stats->avg_overtime_hours ?? 0, 2),
                        'late_arrivals' => $lateArrivals,
                        'early_departures' => $earlyDepartures
                    ],
                    'attendance_by_status' => $attendanceByStatus,
                    'top_workers' => $attendanceByUser
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function byUser(Request $request, $userId)
    {
        try {
            $query = Attendance::with(['shift', 'createdBy'])
                ->where('user_id', $userId);

            // Filter by date range
            if ($request->filled('date_from')) {
                $query->whereDate('date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('date', '<=', $request->date_to);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $attendances = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'attendances' => $attendances->items(),
                    'pagination' => [
                        'current_page' => $attendances->currentPage(),
                        'per_page' => $attendances->perPage(),
                        'total' => $attendances->total(),
                        'last_page' => $attendances->lastPage(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve attendance for user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function today(Request $request)
    {
        try {
            $query = Attendance::with(['user', 'shift'])
                ->whereDate('date', now()->toDateString())
                // Round 5A Phase 1: same exclusion as index() above.
                ->whereHas('user.role', function ($q) {
                    $q->whereNotIn('access_tier', ['director', 'investor']);
                });

            // Filter by department
            if ($request->filled('department_id')) {
                $query->whereHas('user.department', function($q) use ($request) {
                    $q->where('id', $request->department_id);
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'clock_in_time');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            $attendances = $query->get();

            // Calculate summary
            $present = $attendances->where('status', 'present')->count();
            $absent = $attendances->where('status', 'absent')->count();
            $late = $attendances->where('clock_in_time', '>', '08:00:00')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'attendances' => $attendances,
                    'summary' => [
                        'present' => $present,
                        'absent' => $absent,
                        'late' => $late,
                        'total' => $attendances->count()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve today\'s attendance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /** Excel export of attendance rows for Manager/Director. */
    public function export(Request $request)
    {
        $query = Attendance::with(['user.role', 'user.department'])
            ->whereHas('user.role', fn ($q) => $q->whereNotIn('access_tier', ['director', 'investor']));

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        $rows = $query->orderByDesc('date')->orderBy('clock_in_time')->limit(5000)->get();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Attendance');
        $headers = ['Date', 'Staff', 'Role', 'Department', 'Clock In', 'Clock Out', 'Hours', 'Status', 'Notes'];
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$i + 1, 1], $h);
        }
        $r = 2;
        foreach ($rows as $a) {
            $u = $a->user;
            $ws->setCellValue([1, $r], $a->date?->toDateString() ?? $a->date);
            $ws->setCellValue([2, $r], $u ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) : '');
            $ws->setCellValue([3, $r], optional($u?->role)->name);
            $ws->setCellValue([4, $r], optional($u?->department)->name);
            $ws->setCellValue([5, $r], $a->clock_in_time);
            $ws->setCellValue([6, $r], $a->clock_out_time);
            $ws->setCellValue([7, $r], $a->total_hours);
            $ws->setCellValue([8, $r], $a->status);
            $ws->setCellValue([9, $r], $a->notes);
            $r++;
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="attendance-export.xlsx"',
        ]);
    }
}
