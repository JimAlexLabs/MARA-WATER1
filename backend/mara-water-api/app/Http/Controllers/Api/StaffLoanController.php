<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffLoan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Round 2 Phase 4: "Advances & Loans" tracking. Balances are decremented
 * by PayrollController::finalize() as each payroll run actually deducts
 * against them -- not here.
 */
class StaffLoanController extends Controller
{
    public function index(Request $request)
    {
        $query = StaffLoan::with('user:id,first_name,last_name,department_id')->with('user.department');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(['success' => true, 'data' => $query->orderByDesc('issued_date')->get()]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'type' => 'required|in:advance,loan,sacco_loan,sacco_advance',
            'principal' => 'required|numeric|min:0.01',
            'monthly_deduction' => 'required|numeric|min:0.01',
            'issued_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $loan = StaffLoan::create([
            'user_id' => $request->user_id,
            'type' => $request->type,
            'principal' => $request->principal,
            'monthly_deduction' => $request->monthly_deduction,
            'balance' => $request->principal,
            'issued_date' => $request->issued_date,
            'status' => 'active',
            'notes' => $request->notes,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['success' => true, 'message' => 'Recorded', 'data' => $loan->load('user:id,first_name,last_name')], 201);
    }

    public function update(Request $request, $id)
    {
        $loan = StaffLoan::find($id);
        if (!$loan) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'monthly_deduction' => 'sometimes|numeric|min:0.01',
            'balance' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:active,settled',
            'notes' => 'sometimes|nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $loan->update(array_merge(
            $request->only(['monthly_deduction', 'balance', 'status', 'notes']),
            ['updated_by' => Auth::id()]
        ));

        return response()->json(['success' => true, 'message' => 'Updated', 'data' => $loan->fresh()]);
    }

    public function destroy($id)
    {
        $loan = StaffLoan::find($id);
        if (!$loan) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        $loan->update(['updated_by' => Auth::id()]);
        $loan->delete();

        return response()->json(['success' => true, 'message' => 'Deleted']);
    }
}
