<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Creditors / payables ledger — bills we owe and payments we made.
 * Balance per supplier = SUM(credit) - SUM(debit).
 */
class SupplierLedgerController extends Controller
{
    public function summary(Request $request)
    {
        $from = $request->get('date_from');
        $to = $request->get('date_to');

        $query = SupplierLedgerEntry::whereNull('deleted_at');
        if ($from) {
            $query->whereDate('entry_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('entry_date', '<=', $to);
        }

        $bySupplier = (clone $query)
            ->selectRaw('supplier_name, supplier_code, SUM(credit) as billed, SUM(debit) as paid, SUM(credit) - SUM(debit) as balance')
            ->groupBy('supplier_name', 'supplier_code')
            ->orderBy('supplier_name')
            ->get()
            ->map(fn ($row) => [
                'supplier_name' => $row->supplier_name,
                'supplier_code' => $row->supplier_code,
                'billed' => round((float) $row->billed, 2),
                'paid' => round((float) $row->paid, 2),
                'balance' => round((float) $row->balance, 2),
            ]);

        $totals = [
            'billed' => round((float) (clone $query)->sum('credit'), 2),
            'paid' => round((float) (clone $query)->sum('debit'), 2),
        ];
        $totals['balance'] = round($totals['billed'] - $totals['paid'], 2);

        $entries = (clone $query)
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->limit((int) $request->get('limit', 200))
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => $totals,
                'by_supplier' => $bySupplier,
                'entries' => $entries,
            ],
        ]);
    }

    public function bySupplier(Request $request, string $supplierName)
    {
        $name = urldecode($supplierName);
        $all = SupplierLedgerEntry::whereNull('deleted_at')
            ->where('supplier_name', $name)
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->get();

        $running = 0;
        $withBalance = $all->map(function ($entry) use (&$running) {
            $running += (float) $entry->credit - (float) $entry->debit;
            $entry->running_balance = round($running, 2);
            return $entry;
        })->reverse()->values();

        return response()->json([
            'success' => true,
            'data' => [
                'supplier_name' => $name,
                'balance' => round($running, 2),
                'entries' => $withBalance,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_name' => 'required|string|max:200',
            'supplier_code' => 'nullable|string|max:50',
            'entry_date' => 'required|date',
            'details' => 'required|string|max:500',
            'reference_no' => 'nullable|string|max:100',
            'voucher_no' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:50',
            'status' => 'nullable|in:open,paid,partial',
            'debit' => 'nullable|numeric|min:0',
            'credit' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $debit = (float) ($request->debit ?? 0);
        $credit = (float) ($request->credit ?? 0);
        if ($debit == 0 && $credit == 0) {
            return response()->json([
                'success' => false,
                'message' => 'Enter a bill amount (credit) or a payment (debit)',
            ], 422);
        }
        if ($debit > 0 && $credit > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Record the bill and the payment as separate lines',
            ], 422);
        }

        $entry = SupplierLedgerEntry::create([
            'supplier_name' => $request->supplier_name,
            'supplier_code' => $request->supplier_code,
            'entry_date' => $request->entry_date,
            'details' => $request->details,
            'reference_no' => $request->reference_no,
            'voucher_no' => $request->voucher_no,
            'category' => $request->category,
            'status' => $request->status ?? ($debit > 0 ? 'paid' : 'open'),
            'debit' => $debit,
            'credit' => $credit,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $credit > 0 ? 'Payable bill recorded' : 'Supplier payment recorded',
            'data' => ['entry' => $entry],
        ], 201);
    }

    public function destroy(string $id)
    {
        $entry = SupplierLedgerEntry::whereNull('deleted_at')->find($id);
        if (!$entry) {
            return response()->json(['success' => false, 'message' => 'Entry not found'], 404);
        }
        $entry->update(['updated_by' => Auth::id()]);
        $entry->delete();

        return response()->json(['success' => true, 'message' => 'Entry removed']);
    }
}
