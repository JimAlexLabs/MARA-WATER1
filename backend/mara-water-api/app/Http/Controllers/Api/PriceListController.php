<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use Illuminate\Http\Request;

/**
 * Read-only reference list the "Log a Sale" form pulls unit prices from.
 * price_lists / price_list_items already existed as real, seeded tables
 * (Standard/Wholesale/Corporate 2024) with no controller exposing them --
 * this just surfaces what's already there.
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
}
