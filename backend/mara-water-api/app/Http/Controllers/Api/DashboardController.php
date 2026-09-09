<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Health check endpoint
     */
    public function health(Request $request)
    {
        try {
            // Check database connection
            DB::connection()->getPdo();
            $dbStatus = 'connected';
        } catch (\Exception $e) {
            $dbStatus = 'disconnected';
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'healthy',
                'timestamp' => now()->toISOString(),
                'version' => '1.0.0',
                'database' => $dbStatus,
                'user' => Auth::user() ? [
                    'id' => Auth::user()->id,
                    'name' => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                    'role' => Auth::user()->role->name ?? 'Unknown',
                ] : null,
            ]
        ]);
    }

    /**
     * Dashboard overview with key metrics
     */
    public function overview(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->toDateString();
            $thisMonth = now()->startOfMonth()->toDateString();

            // Get basic counts (these will be 0 until we have data)
            $metrics = [
                'today' => [
                    'water_tests' => 0,
                    'batches' => 0,
                    'orders' => 0,
                    'deliveries' => 0,
                ],
                'this_month' => [
                    'water_tests' => 0,
                    'batches' => 0,
                    'orders' => 0,
                    'revenue' => 0,
                ],
                'alerts' => [
                    'low_stock_items' => 0,
                    'pending_water_tests' => 0,
                    'overdue_orders' => 0,
                ],
                'user_info' => [
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'role' => $user->role->name ?? 'Unknown',
                    'department' => $user->department->name ?? 'Unknown',
                    'last_login' => $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : 'Never',
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $metrics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard overview',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
