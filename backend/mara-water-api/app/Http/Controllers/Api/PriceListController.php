<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * price_lists / price_list_items already existed as real, seeded tables
 * (Standard/Wholesale/Corporate 2024) with no controller exposing them --
 * index()/items() just surfaced what's already there (read-only).
 *
 * Round 2 Phase 10: upsertItem() makes the default price list ("Standard
 * Price List 2024") genuinely editable -- the "Products & Prices"
 * reference page's whole point is a real place to set/change a product's
 * current selling price, which every sales entry point (Log Sale,
 * Driver Trip Log) and Stock Reconciliation already reads from this
 * exact table.
 */
class PriceListController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => PriceList::whereNull('deleted_at')->orderByDesc('is_default')->orderBy('name')->get(),
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
     * Set (create or update) one SKU's price within a price list.
     */
    public function upsertItem(Request $request, $priceListId, $skuId)
    {
        $priceList = PriceList::whereNull('deleted_at')->find($priceListId);
        if (!$priceList) {
            return response()->json(['success' => false, 'message' => 'Price list not found'], 404);
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
}
