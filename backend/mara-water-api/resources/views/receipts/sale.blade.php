<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
    .header { text-align: center; margin-bottom: 16px; }
    .header h1 { font-size: 16px; margin: 0; }
    .header p { margin: 2px 0; color: #555; }
    .meta { width: 100%; margin-bottom: 12px; }
    .meta td { padding: 2px 0; }
    .meta td.label { color: #555; width: 140px; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    table.items th, table.items td { border-bottom: 1px solid #ddd; padding: 6px 4px; text-align: left; font-size: 11px; }
    table.items th { background: #f3f4f6; }
    .total-row td { font-weight: bold; border-top: 2px solid #333; }
    .footer { margin-top: 20px; text-align: center; color: #777; font-size: 10px; }
    .paid-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; background: #dcfce7; color: #166534; font-weight: bold; font-size: 11px; }
</style>
</head>
<body>
    <div class="header">
        <h1>HOMA SPRINGS LIMITED -- MARA DRINKING WATER</h1>
        <p>Sale Receipt</p>
    </div>

    <table class="meta">
        <tr><td class="label">Receipt No.</td><td>{{ $receiptNo }}</td></tr>
        <tr><td class="label">Date</td><td>{{ $date }}</td></tr>
        <tr><td class="label">Customer</td><td>{{ $customerName }}</td></tr>
        <tr><td class="label">Payment Method</td><td>{{ $paymentMethodLabel }} @if($mpesaReceipt) -- {{ $mpesaReceipt }} @endif</td></tr>
        @if($route)
        <tr><td class="label">Route</td><td>{{ $route }}</td></tr>
        @endif
        <tr><td class="label">Status</td><td><span class="paid-badge">PAID</span></td></tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>Item</th><th>Bales</th><th>Unit Price (KES)</th><th>Line Total (KES)</th></tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td>{{ $item['name'] }}</td>
                <td>{{ $item['qty_bales'] }}</td>
                <td>{{ number_format($item['unit_price'], 2) }}</td>
                <td>{{ number_format($item['line_total'], 2) }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3">Total</td>
                <td>KES {{ number_format($amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Computer-generated receipt -- no signature required.
    </div>
</body>
</html>
