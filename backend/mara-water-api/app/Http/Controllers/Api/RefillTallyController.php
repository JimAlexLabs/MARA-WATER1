<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RefillTally;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Refill tally. Customers bring their own jerricans, so these counts
 * never touch bottles, labels, caps, seals, or finished bales.
 */
class RefillTallyController extends Controller
{
    public function index()
    {
        $rows = RefillTally::query()
            ->whereNull('deleted_at')
            ->orderByDesc('tally_date')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $key = rtrim(rtrim(number_format((float) $row->size_liters, 2, '.', ''), '0'), '.');
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row->qty;
        }

        return response()->json([
            'success' => true,
            'data' => $rows,
            'totals' => $totals,
            'grand_total' => (int) $rows->sum('qty'),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tally_date' => 'required|date',
            'size_liters' => 'required|numeric|in:5,10,20',
            'qty' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Enter the date, a 5L, 10L or 20L size, and how many jerricans were filled.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = RefillTally::create([
            'tally_date' => $request->tally_date,
            'size_liters' => $request->size_liters,
            'qty' => (int) $request->qty,
            'notes' => $request->input('notes'),
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Refill tally saved. Bottles and finished stock were not changed.',
            'data' => $row,
        ], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user || (! $user->isDirector() && $user->accessTier() !== 'director')) {
            return response()->json(['success' => false, 'message' => 'Only an admin can delete a refill tally.'], 403);
        }

        $row = RefillTally::whereNull('deleted_at')->find($id);
        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Refill tally not found'], 404);
        }
        $row->delete();

        return response()->json(['success' => true, 'message' => 'Refill tally removed.']);
    }
}
