<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Services\PriceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 2 Phase 10 + Round 5B Phase 4:
 * - Default list (retail) editable by Director only
 * - Corporate/competitive list editable by Manager (and Director)
 * - Stable getCurrentPrice read API for Sales / trip flows
 */
class PriceListController extends Controller
{
    public function __construct(private PriceService $prices)
    {
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => PriceList::whereNull('deleted_at')
                ->orderByDesc('is_default')
                ->orderBy('list_kind')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function items(Request $request, $id)
    {
        $priceList = PriceList::whereNull('deleted_at')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $priceList->items()->with('sku')->get(),
        ]);
    }

    /**
     * Round 5B: GET /sales/prices/current?brand=&size_liters=&kind=default|corporate
     * Also accepts sku_id for direct lookup.
     */
    public function currentPrice(Request $request)
    {
        $kind = $request->get('kind', 'default');
        if (!in_array($kind, ['default', 'corporate'], true)) {
            return response()->json(['success' => false, 'message' => 'kind must be default or corporate'], 422);
        }

        if ($request->filled('sku_id')) {
            $price = $this->prices->resolveForSku($request->sku_id, $kind);
            return response()->json([
                'success' => true,
                'data' => [
                    'sku_id' => $request->sku_id,
                    'kind' => $kind,
                    'unit_price' => $price,
                ],
            ]);
        }

        $price = $this->prices->getCurrentPrice(
            $request->get('brand'),
            $request->get('size_liters'),
            $kind
        );

        return response()->json([
            'success' => true,
            'data' => [
                'brand' => $request->get('brand'),
                'size_liters' => $request->get('size_liters'),
                'kind' => $kind,
                'unit_price' => $price,
            ],
        ]);
    }

    /**
     * Set (create or update) one SKU's price within a price list.
     * Default list → Director only. Corporate list → Manager or Director.
     */
    public function upsertItem(Request $request, $priceListId, $skuId)
    {
        $priceList = PriceList::whereNull('deleted_at')->find($priceListId);
        if (!$priceList) {
            return response()->json(['success' => false, 'message' => 'Price list not found'], 404);
        }

        $user = $request->user();
        $kind = $priceList->list_kind ?: ($priceList->is_default ? 'default' : 'other');

        if ($kind === 'default' && !$user->isDirector()) {
            return response()->json([
                'success' => false,
                'message' => 'Only the Director can edit the default price list.',
            ], 403);
        }
        if ($kind === 'corporate' && !in_array($user->accessTier(), ['manager', 'director'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only Manager or Director can edit the corporate price list.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'unit_price' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $item = PriceListItem::withTrashed()
            ->where('price_list_id', $priceListId)
            ->where('sku_id', $skuId)
            ->first();

        if ($item) {
            $item->restore();
            $item->update(['unit_price' => $request->unit_price, 'updated_by' => Auth::id()]);
        } else {
            $item = PriceListItem::create([
                'price_list_id' => $priceListId,
                'sku_id' => $skuId,
                'unit_price' => $request->unit_price,
                'currency' => 'KES',
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Price updated successfully',
            'data' => $item->load('sku'),
        ]);
    }

    /**
     * Excel export of a price list (Director default or Manager corporate).
     */
    public function export(Request $request, $id): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $priceList = PriceList::whereNull('deleted_at')->find($id);
        if (!$priceList) {
            return response()->json(['success' => false, 'message' => 'Price list not found'], 404);
        }

        $items = $priceList->items()->with('sku')->get();
        $skus = Sku::where('active', true)->whereNull('deleted_at')->orderBy('brand')->orderBy('name')->get();
        $bySku = $items->keyBy('sku_id');

        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Prices');
        $ws->setCellValue('A1', $priceList->name);
        $ws->setCellValue('A2', 'Kind: ' . ($priceList->list_kind ?: 'other'));
        $ws->setCellValue('A3', 'Valid: ' . ($priceList->valid_from?->toDateString() ?? '—') . ' to ' . ($priceList->valid_to?->toDateString() ?? '—'));

        $headers = ['Code', 'Brand', 'Name', 'Size (L)', 'Unit', 'Unit Price (KES)'];
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$i + 1, 5], $h);
        }

        $row = 6;
        foreach ($skus as $sku) {
            $ws->setCellValue([1, $row], $sku->code);
            $ws->setCellValue([2, $row], $sku->brand);
            $ws->setCellValue([3, $row], $sku->name);
            $ws->setCellValue([4, $row], $sku->size_liters);
            $ws->setCellValue([5, $row], $sku->unit);
            $ws->setCellValue([6, $row], $bySku[$sku->id]->unit_price ?? '');
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'price-list-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($priceList->name)) . '.xlsx';

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
