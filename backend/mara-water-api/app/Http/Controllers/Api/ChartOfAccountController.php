<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

/**
 * Chart of Accounts (Phase 9) -- a starter set was seeded (see the
 * migration for why), but the real list is expected to grow to Homa
 * Springs' actual ~200 codes over time. This is what lets that happen
 * without needing another migration for every account added.
 */
class ChartOfAccountController extends Controller
{
    public function index(Request $request)
    {
        $query = ChartOfAccount::whereNull('deleted_at');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:20|unique:chart_of_accounts,code',
            'description' => 'required|string|max:255',
            'category' => 'required|in:asset,liability,equity,income,expense',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $account = ChartOfAccount::create([
            'code' => $request->code,
            'description' => $request->description,
            'category' => $request->category,
            'is_active' => true,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['success' => true, 'message' => 'Account created successfully', 'data' => ['account' => $account]], 201);
    }

    public function update(Request $request, $id)
    {
        $account = ChartOfAccount::whereNull('deleted_at')->find($id);
        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Account not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|required|string|max:20|unique:chart_of_accounts,code,' . $id,
            'description' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|in:asset,liability,equity,income,expense',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $account->update(array_merge(
            $request->only(['code', 'description', 'category', 'is_active']),
            ['updated_by' => Auth::id()]
        ));

        return response()->json(['success' => true, 'message' => 'Account updated successfully', 'data' => ['account' => $account]]);
    }
}
