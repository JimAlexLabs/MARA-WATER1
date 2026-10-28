<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplyOrder;
use App\Services\DemandForecaster;
use App\Services\OperationsModelService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin-only ledger of supply orders (Intripet trips, NEOPACK labels,
 * bailing paper, seals). Open orders feed the reorder advisor.
 */
class SupplyOrderController extends Controller
{
    private const KINDS = ['bottle_trip', 'labels', 'bailing', 'seals', 'other'];
    private const STATUSES = ['placed', 'in_transit', 'received', 'cancelled'];

    public function index(Request $request)
    {
        $q = SupplyOrder::query()->orderByDesc('order_date')->orderByDesc('created_at');
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        return response()->json([
            'success' => true,
            'data' => $q->limit(200)->get()->map(fn ($o) => $o->toApi())->values(),
        ]);
    }

    public function store(Request $request, OperationsModelService $model)
    {
        $v = $request->validate([
            'kind' => 'required|in:'.implode(',', self::KINDS),
            'supplier' => 'required|string|max:200',
            'reference_no' => 'nullable|string|max:100',
            'order_date' => 'required|date',
            'lead_business_days' => 'nullable|integer|min:0|max:60',
            'vat_applies' => 'sometimes|boolean',
            'transport_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:30',
            'lines.*.item_code' => 'required|string|max:60',
            'lines.*.label' => 'nullable|string|max:200',
            'lines.*.qty' => 'required|numeric|gt:0',
            'lines.*.unit_cost_ex_vat' => 'required|numeric|min:0',
        ]);

        $a = $model->effective();
        $lead = $v['lead_business_days'] ?? $this->defaultLead($v['kind'], $a);
        $vatApplies = $v['vat_applies'] ?? in_array($v['kind'], ['bottle_trip', 'labels'], true);
        $goods = 0.0;
        $lines = [];
        foreach ($v['lines'] as $l) {
            $goods += (float) $l['qty'] * (float) $l['unit_cost_ex_vat'];
            $lines[] = [
                'item_code' => $l['item_code'],
                'label' => $l['label'] ?? $l['item_code'],
                'qty' => (float) $l['qty'],
                'unit_cost_ex_vat' => (float) $l['unit_cost_ex_vat'],
            ];
        }
        $vat = $vatApplies ? $goods * (float) $a['vat_rate'] : 0.0;
        $transport = (float) ($v['transport_cost'] ?? 0);
        $orderDate = Carbon::parse($v['order_date']);

        $order = SupplyOrder::create([
            'kind' => $v['kind'],
            'supplier' => $v['supplier'],
            'reference_no' => $v['reference_no'] ?? null,
            'order_date' => $orderDate->toDateString(),
            'lead_business_days' => $lead,
            'expected_date' => DemandForecaster::addBusinessDays($orderDate, (int) $lead)->format('Y-m-d'),
            'status' => 'placed',
            'lines' => $lines,
            'vat_applies' => $vatApplies,
            'goods_cost' => round($goods, 2),
            'vat_amount' => round($vat, 2),
            'transport_cost' => round($transport, 2),
            'total_cost' => round($goods + $vat + $transport, 2),
            'notes' => $v['notes'] ?? null,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['success' => true, 'message' => 'Supply order recorded.', 'data' => $order->toApi()], 201);
    }

    public function update(Request $request, string $id)
    {
        $v = $request->validate([
            'status' => 'required|in:'.implode(',', self::STATUSES),
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);
        $order = SupplyOrder::query()->findOrFail($id);
        $order->status = $v['status'];
        if (! empty($v['expected_date'])) {
            $order->expected_date = $v['expected_date'];
        }
        if (array_key_exists('notes', $v)) {
            $order->notes = $v['notes'];
        }
        $order->received_date = $v['status'] === 'received' ? now()->toDateString() : null;
        $order->updated_by = Auth::id();
        $order->save();

        return response()->json(['success' => true, 'data' => $order->toApi()]);
    }

    public function destroy(string $id)
    {
        SupplyOrder::query()->findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Supply order removed.']);
    }

    private function defaultLead(string $kind, array $a): int
    {
        $lead = $a['supply']['lead_business_days'];

        return (int) match ($kind) {
            'bottle_trip' => $lead['bottles'],
            'labels' => $lead['labels'],
            'bailing' => $lead['bailing'],
            'seals' => $lead['seals'],
            default => 7,
        };
    }
}
