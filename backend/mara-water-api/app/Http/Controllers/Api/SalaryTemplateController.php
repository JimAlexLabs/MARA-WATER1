<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Round 2 Phase 4: role/salary templates ("Driver", "Production",
 * "Management") so onboarding a new hire means picking a role and
 * adjusting numbers, not building a pay structure from scratch.
 * Deliberately not seeded with real amounts -- see the migration's
 * docblock.
 */
class SalaryTemplateController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => SalaryTemplate::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:salary_templates,name',
            'basic_salary' => 'nullable|numeric|min:0',
            'house_allowance' => 'nullable|numeric|min:0',
            'telephone_allowance' => 'nullable|numeric|min:0',
            'other_allowance' => 'nullable|numeric|min:0',
            'terms_of_employment' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $template = SalaryTemplate::create(array_merge(
            $request->only(['name', 'basic_salary', 'house_allowance', 'telephone_allowance', 'other_allowance', 'terms_of_employment', 'notes']),
            ['created_by' => Auth::id(), 'updated_by' => Auth::id()]
        ));

        return response()->json(['success' => true, 'message' => 'Template created', 'data' => $template], 201);
    }

    public function update(Request $request, $id)
    {
        $template = SalaryTemplate::find($id);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100|unique:salary_templates,name,' . $id,
            'basic_salary' => 'sometimes|nullable|numeric|min:0',
            'house_allowance' => 'sometimes|nullable|numeric|min:0',
            'telephone_allowance' => 'sometimes|nullable|numeric|min:0',
            'other_allowance' => 'sometimes|nullable|numeric|min:0',
            'terms_of_employment' => 'sometimes|nullable|string|max:50',
            'notes' => 'sometimes|nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $template->update(array_merge(
            $request->only(['name', 'basic_salary', 'house_allowance', 'telephone_allowance', 'other_allowance', 'terms_of_employment', 'notes']),
            ['updated_by' => Auth::id()]
        ));

        return response()->json(['success' => true, 'message' => 'Updated', 'data' => $template->fresh()]);
    }

    public function destroy($id)
    {
        $template = SalaryTemplate::find($id);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        $template->update(['updated_by' => Auth::id()]);
        $template->delete();

        return response()->json(['success' => true, 'message' => 'Deleted']);
    }
}
