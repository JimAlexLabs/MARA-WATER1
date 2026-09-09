<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Customer;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Invoice::with(['customer', 'order', 'createdBy']);

            // Filtering
            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }
            if ($request->filled('order_id')) {
                $query->where('order_id', $request->order_id);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('date_from')) {
                $query->whereDate('invoice_date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('invoice_date', '<=', $request->date_to);
            }
            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'invoice_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $invoices = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $invoices->items()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve invoices',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|exists:orders,id',
                'customer_id' => 'required|exists:customers,id',
                'invoice_date' => 'required|date',
                'due_date' => 'required|date|after:invoice_date',
                'subtotal' => 'required|numeric|min:0',
                'tax_amount' => 'required|numeric|min:0',
                'discount_amount' => 'required|numeric|min:0',
                'total_amount' => 'required|numeric|min:0',
                'notes' => 'nullable|string|max:1000',
                'payment_terms' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validate order status
            $order = Order::find($request->order_id);
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            if ($order->status !== 'delivered') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice can only be created for delivered orders'
                ], 422);
            }

            // Check if invoice already exists for this order
            $existingInvoice = Invoice::where('order_id', $request->order_id)->first();
            if ($existingInvoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice already exists for this order'
                ], 422);
            }

            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber();

            $invoice = Invoice::create([
                'invoice_no' => $invoiceNumber,
                'order_id' => $request->order_id,
                'customer_id' => $request->customer_id,
                'invoice_date' => $request->invoice_date,
                'due_date' => $request->due_date,
                'subtotal' => $request->subtotal,
                'tax_amount' => $request->tax_amount,
                'discount_amount' => $request->discount_amount,
                'total_amount' => $request->total_amount,
                'notes' => $request->notes,
                'payment_terms' => $request->payment_terms,
                'status' => 'draft',
                'payment_status' => 'pending',
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => [
                    'invoice' => $invoice->load(['customer', 'order', 'createdBy'])
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $invoice = Invoice::with(['customer', 'order', 'createdBy'])->find($id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'invoice' => $invoice
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $invoice = Invoice::find($id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }

            // Check if invoice can be updated
            if ($invoice->status === 'sent' || $invoice->status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update sent or paid invoice'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'invoice_date' => 'sometimes|required|date',
                'due_date' => 'sometimes|required|date|after:invoice_date',
                'subtotal' => 'sometimes|required|numeric|min:0',
                'tax_amount' => 'sometimes|required|numeric|min:0',
                'discount_amount' => 'sometimes|required|numeric|min:0',
                'total_amount' => 'sometimes|required|numeric|min:0',
                'notes' => 'nullable|string|max:1000',
                'payment_terms' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $invoice->update([
                'invoice_date' => $request->invoice_date ?? $invoice->invoice_date,
                'due_date' => $request->due_date ?? $invoice->due_date,
                'subtotal' => $request->subtotal ?? $invoice->subtotal,
                'tax_amount' => $request->tax_amount ?? $invoice->tax_amount,
                'discount_amount' => $request->discount_amount ?? $invoice->discount_amount,
                'total_amount' => $request->total_amount ?? $invoice->total_amount,
                'notes' => $request->notes,
                'payment_terms' => $request->payment_terms,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice updated successfully',
                'data' => [
                    'invoice' => $invoice->load(['customer', 'order', 'createdBy'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $invoice = Invoice::find($id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }

            // Check if invoice can be deleted
            if ($invoice->status === 'sent' || $invoice->status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete sent or paid invoice'
                ], 422);
            }

            $invoice->delete();

            return response()->json([
                'success' => true,
                'message' => 'Invoice deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function send($id)
    {
        try {
            $invoice = Invoice::find($id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }

            if ($invoice->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only draft invoices can be sent'
                ], 422);
            }

            $invoice->update([
                'status' => 'sent',
                'sent_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice sent successfully',
                'data' => [
                    'invoice' => $invoice->load(['customer', 'order', 'createdBy'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function markAsPaid(Request $request, $id)
    {
        try {
            $invoice = Invoice::find($id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }

            if ($invoice->payment_status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice is already marked as paid'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'payment_date' => 'required|date',
                'payment_method' => 'required|string|max:100',
                'payment_reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $invoice->update([
                'status' => 'paid',
                'payment_status' => 'paid',
                'payment_date' => $request->payment_date,
                'payment_method' => $request->payment_method,
                'payment_reference' => $request->payment_reference,
                'notes' => $request->notes,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice marked as paid successfully',
                'data' => [
                    'invoice' => $invoice->load(['customer', 'order', 'createdBy'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark invoice as paid',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function statistics(Request $request)
    {
        try {
            $query = Invoice::query();

            // Filter by date range
            if ($request->filled('date_from')) {
                $query->whereDate('invoice_date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('invoice_date', '<=', $request->date_to);
            }

            // Filter by customer
            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            $stats = $query->selectRaw('
                COUNT(*) as total_invoices,
                SUM(total_amount) as total_amount,
                SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE 0 END) as paid_amount,
                SUM(CASE WHEN payment_status = "pending" THEN total_amount ELSE 0 END) as pending_amount,
                SUM(CASE WHEN payment_status = "overdue" THEN total_amount ELSE 0 END) as overdue_amount,
                AVG(total_amount) as avg_invoice_amount
            ')->first();

            // Get invoices by status
            $invoicesByStatus = $query->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')->get();

            // Get invoices by payment status
            $invoicesByPaymentStatus = $query->selectRaw('payment_status, COUNT(*) as count')
                ->groupBy('payment_status')->get();

            // Calculate payment rate
            $paymentRate = $stats->total_amount > 0 ? 
                ($stats->paid_amount / $stats->total_amount) * 100 : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => [
                        'total_invoices' => $stats->total_invoices ?? 0,
                        'total_amount' => round($stats->total_amount ?? 0, 2),
                        'paid_amount' => round($stats->paid_amount ?? 0, 2),
                        'pending_amount' => round($stats->pending_amount ?? 0, 2),
                        'overdue_amount' => round($stats->overdue_amount ?? 0, 2),
                        'avg_invoice_amount' => round($stats->avg_invoice_amount ?? 0, 2),
                        'payment_rate_percentage' => round($paymentRate, 2)
                    ],
                    'invoices_by_status' => $invoicesByStatus,
                    'invoices_by_payment_status' => $invoicesByPaymentStatus
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function byCustomer(Request $request, $customerId)
    {
        try {
            $query = Invoice::with(['order', 'createdBy'])
                ->where('customer_id', $customerId);

            // Sorting
            $sortBy = $request->get('sort_by', 'invoice_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $invoices = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'invoices' => $invoices->items(),
                    'pagination' => [
                        'current_page' => $invoices->currentPage(),
                        'per_page' => $invoices->perPage(),
                        'total' => $invoices->total(),
                        'last_page' => $invoices->lastPage(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve invoices for customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function overdue(Request $request)
    {
        try {
            $query = Invoice::with(['customer', 'order'])
                ->where('payment_status', 'pending')
                ->where('due_date', '<', now());

            // Sorting
            $sortBy = $request->get('sort_by', 'due_date');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $invoices = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'overdue_invoices' => $invoices->items(),
                    'pagination' => [
                        'current_page' => $invoices->currentPage(),
                        'per_page' => $invoices->perPage(),
                        'total' => $invoices->total(),
                        'last_page' => $invoices->lastPage(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve overdue invoices',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function generateInvoiceNumber()
    {
        $prefix = 'INV';
        $year = date('Y');
        $month = date('m');
        
        // Get the last invoice number for this month
        $lastInvoice = Invoice::where('invoice_no', 'like', "{$prefix}-{$year}{$month}-%")
            ->orderBy('invoice_no', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_no, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('%s-%s%s-%04d', $prefix, $year, $month, $newNumber);
    }
}
