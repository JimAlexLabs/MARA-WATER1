<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;

/**
 * Round 3 Phase 9: plain reference list (KDN/KDQ/Warehouse) -- used by
 * the trip/order forms' location picker and the Daily Sales & Debt
 * export's per-location breakdown.
 */
class LocationController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => Location::orderBy('name')->get()]);
    }
}
