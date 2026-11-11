<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RefillTally;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        // The log keeps every refill sale. The cards start again on 11 Nov 2026.
        $freshFrom = '2026-11-11';
        $totals = [];
        $salesBySize = [];
        $salesTotal = 0.0;
        foreach ($rows as $row) {
            $key = rtrim(rtrim(number_format((float) $row->size_liters, 2, '.', ''), '0'), '.');
            $price = $row->unit_price !== null
                ? (float) $row->unit_price
                : $this->refillListPrice($key);
            $line = round($price * (int) $row->qty, 2);
            $date = optional($row->tally_date)->toDateString();
            $row->setAttribute('unit_price', $price);
            $row->setAttribute('line_total', $line);
            $row->setAttribute('tally_date', $date);
            if ($date < $freshFrom) {
                continue;
            }
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row->qty;
            $salesBySize[$key] = round(($salesBySize[$key] ?? 0) + $line, 2);
            $salesTotal += $line;
        }

        return response()->json([
            'success' => true,
            'data' => $rows,
            'totals' => $totals,
            'sales_by_size' => $salesBySize,
            'sales_total' => round($salesTotal, 2),
            'fresh_from' => $freshFrom,
            'grand_total' => (int) array_sum($totals),
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

        $sizeKey = rtrim(rtrim(number_format((float) $request->size_liters, 2, '.', ''), '0'), '.');
        $row = RefillTally::create([
            'tally_date' => $request->tally_date,
            'size_liters' => $request->size_liters,
            'qty' => (int) $request->qty,
            'unit_price' => $this->refillListPrice($sizeKey),
            'qty_basis' => 'jerrican',
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

    /** Refill price list: 5L, 10L and 20L jerricans. Bales keep their own price. */
    private function refillListPrice(string $sizeKey): float
    {
        $code = 'REFILL-'.$sizeKey.'L';
        $price = DB::table('price_list_items as i')
            ->join('skus as s', 's.id', '=', 'i.sku_id')
            ->join('price_lists as p', 'p.id', '=', 'i.price_list_id')
            ->where('p.is_default', true)
            ->where('s.code', $code)
            ->whereNull('i.deleted_at')
            ->whereNull('p.deleted_at')
            ->value('i.unit_price');

        return $price !== null ? (float) $price : 0.0;
    }
}
