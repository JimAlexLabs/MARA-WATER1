<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverTripSale;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Round 5A Phase 5 / Round 6 Phase B4: "every logged sale should
 * generate a downloadable receipt immediately" -- one controller for
 * both of MARA's sale entities (field-trip sales and counter/warehouse
 * Orders) rather than two near-duplicate implementations, since a
 * receipt is the same concept either way: who, what, how much, how
 * paid.
 */
class ReceiptController extends Controller
{
    public function driverTripSale(Request $request, $id)
    {
        $sale = DriverTripSale::with(['customer', 'trip', 'items.sku'])->find($id);
        if (!$sale) {
            return response()->json(['success' => false, 'message' => 'Sale not found'], 404);
        }
        if ($request->user()->hasAccessTier('driver') && $sale->trip->driver_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this sale.'], 403);
        }

        $pdf = Pdf::loadView('receipts.sale', [
            'receiptNo' => 'TRP-' . strtoupper(substr($sale->id, 0, 8)),
            'date' => $sale->created_at->toDayDateTimeString(),
            'customerName' => $sale->customer->name ?? 'Walk-in',
            'paymentMethodLabel' => ucfirst(str_replace('_', ' ', $sale->payment_method)),
            'mpesaReceipt' => $sale->mpesa_reference,
            'route' => $sale->trip->route ?? null,
            'items' => $sale->items->map(fn ($i) => [
                'name' => $i->sku->name ?? 'Item',
                'qty_bales' => $i->qty_bales,
                'unit_price' => (float) $i->unit_price,
                'line_total' => (float) $i->line_total,
            ])->all(),
            'amount' => (float) $sale->amount,
        ]);

        return $pdf->download("receipt-{$sale->id}.pdf");
    }

    public function order(Request $request, $id)
    {
        $order = Order::with(['customer', 'items.sku'])->find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }
        if (!$order->payment_method) {
            return response()->json(['success' => false, 'message' => 'This order has not been paid yet'], 422);
        }

        $pdf = Pdf::loadView('receipts.sale', [
            'receiptNo' => $order->order_no,
            'date' => $order->created_at->toDayDateTimeString(),
            'customerName' => $order->customer->name ?? 'Walk-in',
            'paymentMethodLabel' => ucfirst(str_replace('_', ' ', $order->payment_method)),
            'mpesaReceipt' => $order->payment_method === 'mpesa' ? $order->payment_reference : null,
            'route' => null,
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->sku->name ?? 'Item',
                'qty_bales' => $i->qty,
                'unit_price' => (float) $i->unit_price,
                'line_total' => (float) ($i->qty * $i->unit_price),
            ])->all(),
            'amount' => (float) $order->total_amount,
        ]);

        return $pdf->download("receipt-{$order->order_no}.pdf");
    }
}
