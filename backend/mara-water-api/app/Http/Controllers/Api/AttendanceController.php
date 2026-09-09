<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Attendance;
use App\Models\User;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Attendance::with(['user', 'shift', 'createdBy']);

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

            // Check if user already clocked in today
            $existingAttendance = Attendance::where('user_id', $request->user_id)
                ->whereDate('date', $today)
                ->first();

            if ($existingAttendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'User has already clocked in today'
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

            // Find today's attendance record
            $attendance = Attendance::where('user_id', $request->user_id)
                ->whereDate('date', $today)
                ->first();

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'No clock in record found for today'
                ], 404);
            }

            if ($attendance->clock_out_time) {
                return response()->json([
                    'success' => false,
                    'message' => 'User has already clocked out today'
                ], 422);
            }

            // Calculate total hours
            $clockIn = \Carbon\Carbon::parse($attendance->clock_in_time);
            $clockOut = \Carbon\Carbon::parse($currentTime);
            $totalHours = $clockIn->diffInHours($clockOut, true);

            // Calculate overtime (assuming 8 hours is standard work day)
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

            return response()->json([
                'success' => true,
                'message' => 'Clock out successful',
                'data' => [
                    'attendance' => $attendance->load(['user', 'shift', 'createdBy']),
                    'clock_out_time' => $currentTime,
                    'total_hours' => $totalHours,
                    'overtime_hours' => $overtimeHours
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
            $query = Attendance::query();

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
                ->whereDate('date', now()->toDateString());

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
}
