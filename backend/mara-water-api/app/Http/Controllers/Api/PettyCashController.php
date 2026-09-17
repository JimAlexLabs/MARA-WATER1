<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PettyCashEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

/**
 * Petty cash journal (Phase 9) -- replaces "PETTY CASH SUMMARY G".
 * Running balance is never stored: it's the cumulative amount_in -
 * amount_out over all entries up to and including each row, ordered by
 * date, computed fresh on every read. Storing it would mean every edit
 * or deletion has to cascade-recompute every later row or the balance
 * silently goes stale -- deriving it removes that whole failure mode.
 */
class PettyCashController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = PettyCashEntry::with(['account', 'requestor'])->whereNull('deleted_at');

            if ($request->filled('date_from')) {
                $query->whereDate('entry_date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('entry_date', '<=', $request->date_to);
            }
            if ($request->filled('account_id')) {
                $query->where('account_id', $request->account_id);
            }

            // Running balance has to be computed over every entry up to
            // this point, not just the filtered/paginated page -- so the
            // opening balance for a filtered date range still reflects
            // everything that happened before it.
            $openingBalance = 0;
            if ($request->filled('date_from')) {
                $before = PettyCashEntry::whereNull('deleted_at')
                    ->whereDate('entry_date', '<', $request->date_from);
                $openingBalance = (float) $before->sum('amount_in') - (float) $before->sum('amount_out');
            }

            $all = (clone $query)->orderBy('entry_date')->orderBy('created_at')->get();

            $running = $openingBalance;
            $withBalance = $all->map(function ($entry) use (&$running) {
                $running += (float) $entry->amount_in - (float) $entry->amount_out;
                $entry->running_balance = round($running, 2);
                return $entry;
            });

            $sortOrder = $request->get('sort_order', 'desc');
            $sorted = $sortOrder === 'asc' ? $withBalance : $withBalance->reverse()->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'opening_balance' => round($openingBalance, 2),
                    'closing_balance' => round($running, 2),
                    'entries' => $sorted,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to retrieve petty cash entries', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'entry_date' => 'required|date',
            'mpesa_reference' => 'nullable|string|max:50',
            'account_id' => 'required|exists:chart_of_accounts,id',
            'description' => 'required|string|max:500',
            'requestor_id' => 'nullable|exists:users,id',
            'requestor_name' => 'nullable|string|max:150',
            'amount_in' => 'nullable|numeric|min:0',
            'amount_out' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $amountIn = (float) ($request->amount_in ?? 0);
        $amountOut = (float) ($request->amount_out ?? 0);
        if ($amountIn == 0 && $amountOut == 0) {
            return response()->json([
                'success' => false,
                'message' => 'Enter an amount in or out',
                'errors' => ['amount_in' => ['Must be more than zero on one side']],
            ], 422);
        }
        if ($amountIn > 0 && $amountOut > 0) {
            return response()->json([
                'success' => false,
                'message' => 'An entry is either money in or money out, not both',
                'errors' => ['amount_out' => ['Clear one side']],
            ], 422);
        }

        $entry = PettyCashEntry::create([
            'entry_date' => $request->entry_date,
            'mpesa_reference' => $request->mpesa_reference,
            'account_id' => $request->account_id,
            'description' => $request->description,
            'requestor_id' => $request->requestor_id,
            'requestor_name' => $request->requestor_name,
            'amount_in' => $amountIn,
            'amount_out' => $amountOut,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Petty cash entry recorded',
            'data' => ['entry' => $entry->load(['account', 'requestor'])],
        ], 201);
    }

    public function destroy($id)
    {
        $entry = PettyCashEntry::whereNull('deleted_at')->find($id);
        if (!$entry) {
            return response()->json(['success' => false, 'message' => 'Entry not found'], 404);
        }

        // Round 2 Phase 3: deleted_at isn't in $fillable (correctly), so
        // mass-assigning it here silently did nothing -- see WaterTestController.
        $entry->update(['updated_by' => Auth::id()]);
        $entry->delete();

        return response()->json(['success' => true, 'message' => 'Entry removed']);
    }

    /**
     * Utilization summary -- sum of IN/OUT per account, live instead of a
     * manually rebuilt report.
     */
    public function utilization(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());

            $rows = PettyCashEntry::whereNull('deleted_at')
                ->whereBetween('entry_date', [$dateFrom, $dateTo])
                ->selectRaw('account_id, SUM(amount_in) as total_in, SUM(amount_out) as total_out, COUNT(*) as entries_count')
                ->groupBy('account_id')
                ->with('account')
                ->orderByDesc('total_out')
                ->get()
                ->map(fn ($r) => [
                    'account' => $r->account,
                    'total_in' => round((float) $r->total_in, 2),
                    'total_out' => round((float) $r->total_out, 2),
                    'entries_count' => $r->entries_count,
                ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => ['from' => $dateFrom, 'to' => $dateTo],
                    'total_in' => round($rows->sum('total_in'), 2),
                    'total_out' => round($rows->sum('total_out'), 2),
                    'by_account' => $rows,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to build utilization report', 'error' => $e->getMessage()], 500);
        }
    }
}
