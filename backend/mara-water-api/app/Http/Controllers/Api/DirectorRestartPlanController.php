<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectorRestartPlan;
use App\Services\RestartPlanCalculator;
use Illuminate\Http\Request;

class DirectorRestartPlanController extends Controller
{
    public function show(RestartPlanCalculator $calc)
    {
        $plan = DirectorRestartPlan::query()->where('is_active', true)->latest('updated_at')->first();
        $assumptions = $plan?->assumptions ?? RestartPlanCalculator::defaults();
        $computed = $calc->compute($assumptions);

        return response()->json([
            'success' => true,
            'data' => [
                'plan_id' => $plan?->id,
                'name' => $plan?->name ?? 'Premium Restart Plan',
                'notes' => $plan?->notes,
                'updated_at' => $plan?->updated_at,
                'updated_by' => $plan?->updated_by,
                'computed' => $computed,
            ],
        ]);
    }

    public function update(Request $request, RestartPlanCalculator $calc)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'notes' => 'nullable|string|max:5000',
            'assumptions' => 'required|array',
            'assumptions.payroll' => 'sometimes|array',
            'assumptions.overheads' => 'sometimes|array',
            'assumptions.transport_per_trip' => 'sometimes|numeric|min:0',
            'assumptions.lorry_materials_cost' => 'sometimes|numeric|min:0',
            'assumptions.lorry_projected_revenue' => 'sometimes|numeric|min:0',
            'assumptions.restocks_per_month' => 'sometimes|integer|min:1|max:8',
            'assumptions.operating_cash' => 'sometimes|numeric|min:0',
            'assumptions.timeline' => 'sometimes|array',
            'assumptions.profit_targets' => 'sometimes|array',
        ]);

        $merged = $calc->merge($validated['assumptions']);
        $plan = DirectorRestartPlan::query()->where('is_active', true)->latest('updated_at')->first();
        if (!$plan) {
            $plan = new DirectorRestartPlan([
                'name' => $validated['name'] ?? 'Premium Restart Plan',
                'is_active' => true,
            ]);
        }

        if (isset($validated['name'])) {
            $plan->name = $validated['name'];
        }
        if (array_key_exists('notes', $validated)) {
            $plan->notes = $validated['notes'];
        }
        $plan->assumptions = $merged;
        $plan->updated_by = $request->user()?->id;
        $plan->is_active = true;
        $plan->save();

        // Keep a single active plan
        DirectorRestartPlan::query()
            ->where('is_active', true)
            ->where('id', '!=', $plan->id)
            ->update(['is_active' => false]);

        $computed = $calc->compute($merged);

        return response()->json([
            'success' => true,
            'message' => 'Restart plan saved.',
            'data' => [
                'plan_id' => $plan->id,
                'name' => $plan->name,
                'notes' => $plan->notes,
                'updated_at' => $plan->updated_at,
                'computed' => $computed,
            ],
        ]);
    }

    public function reset(RestartPlanCalculator $calc)
    {
        $defaults = RestartPlanCalculator::defaults();
        $plan = DirectorRestartPlan::query()->where('is_active', true)->latest('updated_at')->first()
            ?? new DirectorRestartPlan(['name' => 'Premium Restart Plan', 'is_active' => true]);
        $plan->assumptions = $defaults;
        $plan->notes = 'Reset to Premium Restart Plan workbook defaults.';
        $plan->updated_by = auth()->id();
        $plan->save();

        return response()->json([
            'success' => true,
            'message' => 'Plan reset to workbook defaults.',
            'data' => [
                'plan_id' => $plan->id,
                'computed' => $calc->compute($defaults),
            ],
        ]);
    }
}
