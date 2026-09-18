<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\DebtorLedgerEntry;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Debtors ledger (Phase 9) -- replaces "HSL DEBTORS LEDGER". Auto-
 * populated from credit sales (OrderController::logSale() posts a debit
 * here the moment a credit sale creates a Debt -- see that method).
 * Manual entries (adjustments, opening balances) and payments against a
 * specific debt are the other two ways rows get created. Running
 * balance is derived per customer the same way PettyCashController
 * derives its own -- never stored.
 */
class DebtorLedgerController extends Controller
{
    /**
     * A customer's still-outstanding debts (one per credit invoice) --
     * what the "Record Payment" picker on the ledger needs, since a
     * payment always has to be applied against a specific invoice.
     */
    public function openDebts($customerId)
    {
        $debts = Debt::with('invoice')
            ->where('customer_id', $customerId)
            ->where('balance', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $debts]);
    }

    public function index(Request $request, $customerId)
    {
        try {
            $customer = Customer::whereNull('deleted_at')->find($customerId);
            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
            }

            $all = DebtorLedgerEntry::where('customer_id', $customerId)
                ->whereNull('deleted_at')
                ->orderBy('entry_date')->orderBy('created_at')
                ->get();

            $running = 0;
            $withBalance = $all->map(function ($entry) use (&$running) {
                $running += (float) $entry->debit - (float) $entry->credit;
                $entry->running_balance = round($running, 2);
                return $entry;
            })->reverse()->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'customer' => ['id' => $customer->id, 'name' => $customer->name, 'code' => $customer->code],
                    'balance' => round($running, 2),
                    'entries' => $withBalance,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to retrieve debtor ledger', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Manual entry -- adjustments and opening balances. Not tied to a
     * specific debt (debt_id stays null); those come from logSale() or
     * recordPayment() instead.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'entry_date' => 'required|date',
            'details' => 'required|string|max:500',
            'reference_no' => 'nullable|string|max:100',
            'voucher_no' => 'nullable|string|max:100',
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
                'message' => 'Enter a debit or credit amount',
                'errors' => ['debit' => ['Must be more than zero on one side']],
            ], 422);
        }
        if ($debit > 0 && $credit > 0) {
            return response()->json([
                'success' => false,
                'message' => 'An entry is either a debit or a credit, not both',
                'errors' => ['credit' => ['Clear one side']],
            ], 422);
        }

        $entry = DebtorLedgerEntry::create([
            'customer_id' => $request->customer_id,
            'entry_date' => $request->entry_date,
            'details' => $request->details,
            'reference_no' => $request->reference_no,
            'voucher_no' => $request->voucher_no,
            'debit' => $debit,
            'credit' => $credit,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['success' => true, 'message' => 'Ledger entry recorded', 'data' => ['entry' => $entry]], 201);
    }

    /**
     * A payment against a specific invoice/debt -- reduces debts.balance,
     * posts a credit ledger entry, and marks the invoice paid once its
     * debt reaches zero. All in one transaction so the three can never
     * go out of sync.
     */
    public function recordPayment(Request $request, $debtId)
    {
        try {
            $debt = Debt::whereNull('deleted_at')->find($debtId);
            if (!$debt) {
                return response()->json(['success' => false, 'message' => 'Debt not found'], 404);
            }

            $validator = Validator::make($request->all(), [
                'entry_date' => 'required|date',
                'amount' => 'required|numeric|min:0.01|max:' . $debt->balance,
                'reference_no' => 'nullable|string|max:100',
                'voucher_no' => 'nullable|string|max:100',
                'details' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $newBalance = round((float) $debt->balance - (float) $request->amount, 2);
            $debt->update(['balance' => $newBalance, 'updated_by' => Auth::id()]);

            $invoice = Invoice::find($debt->invoice_id);
            if ($invoice && $newBalance <= 0) {
                $invoice->update([
                    'payment_status' => 'paid',
                    'status' => 'paid',
                    'payment_date' => $request->entry_date,
                    'updated_by' => Auth::id(),
                ]);
            }

            $entry = DebtorLedgerEntry::create([
                'customer_id' => $debt->customer_id,
                'debt_id' => $debt->id,
                'entry_date' => $request->entry_date,
                'details' => $request->details ?? ('Payment received' . ($invoice ? " - Invoice {$invoice->invoice_no}" : '')),
                'reference_no' => $request->reference_no ?? $invoice?->invoice_no,
                'voucher_no' => $request->voucher_no,
                'debit' => 0,
                'credit' => $request->amount,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded',
                'data' => ['entry' => $entry, 'debt_balance' => $newBalance],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to record payment', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Round 3 Phase 9: "HOMA SPRINGS DEBTORS LEDGER" exact-format
     * .xlsx -- see DebtorsLedgerExportService for the layout.
     */
    public function export(Request $request)
    {
        return (new \App\Services\DebtorsLedgerExportService())->generate($request->get('fiscal_year_label'));
    }
}
