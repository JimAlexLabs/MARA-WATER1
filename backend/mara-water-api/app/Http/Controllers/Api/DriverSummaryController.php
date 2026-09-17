<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\DriverTrip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Round 2 Phase 11: a driver's own landing dashboard -- "their own trip
 * history ... no visibility into other drivers, finance, or HR." Every
 * query here is scoped to the logged-in driver; there's no id param to
 * widen it to anyone else's data.
 */
class DriverSummaryController extends Controller
{
    public function summary(Request $request)
    {
        $userId = Auth::id();
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $todayAttendance = Attendance::where('user_id', $userId)->whereDate('date', $today)->first();

        $monthTrips = DriverTrip::with(['vehicle', 'sales'])
            ->where('driver_id', $userId)
            ->where('trip_date', '>=', $monthStart)
            ->get();

        $recentTrips = DriverTrip::with(['vehicle', 'route', 'sales'])
            ->where('driver_id', $userId)
            ->orderByDesc('trip_date')->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'trip_date' => $t->trip_date,
                'vehicle' => $t->vehicle->reg_no ?? null,
                'route' => $t->route->name ?? null,
                'km_covered' => $t->km_covered,
                'total_collected' => $t->total_collected,
                'reconciliation' => [
                    'matches' => $t->reconciliation['matches'],
                    'stock_matches' => $t->reconciliation['stock_matches'],
                ],
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'clocked_in' => (bool) ($todayAttendance?->clock_in_time),
                    'clocked_out' => (bool) ($todayAttendance?->clock_out_time),
                    'clock_in_time' => $todayAttendance?->clock_in_time?->format('H:i'),
                    'clock_out_time' => $todayAttendance?->clock_out_time?->format('H:i'),
                ],
                'this_month' => [
                    'trips' => $monthTrips->count(),
                    'km_covered' => (int) $monthTrips->sum('km_covered'),
                    'total_collected' => round((float) $monthTrips->sum('total_collected'), 2),
                ],
                'recent_trips' => $recentTrips,
            ],
        ]);
    }
}
