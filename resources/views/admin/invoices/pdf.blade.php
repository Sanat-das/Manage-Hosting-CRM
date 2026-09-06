<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_no }}</title>
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; color: #333; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #007bff; padding-bottom: 10px; margin-bottom: 20px; }
        .company-name { font-size: 20px; font-weight: bold; color: #007bff; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 8px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background-color: #f8f9fa; font-weight: 600; }
        .text-right { text-align: right; }
        .total-row { font-weight: bold; font-size: 14px; border-top: 2px solid #333; }
        .section-title { font-size: 14px; font-weight: bold; margin: 20px 0 10px; color: #007bff; }
        .badge { padding: 2px 8px; border-radius: 4px; font-size: 11px; }
        .badge-paid { background: #28a745; color: white; }
        .badge-draft { background: #6c757d; color: white; }
        .badge-sent { background: #007bff; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <div class="company-name">Hosting CRM</div>
            <div style="color: #666;">{{ config('app.name') }}</div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 18px; font-weight: bold;">INVOICE</div>
            <div><strong>{{ $invoice->invoice_no }}</strong></div>
            <div>Date: {{ $invoice->created_at?->format('M j, Y') }}</div>
            @if ($invoice->due_date)
                <div>Due: {{ $invoice->due_date->format('M j, Y') }}</div>
            @endif
        </div>
    </div>

    <div style="margin-bottom: 20px;">
        <div class="section-title">Bill To</div>
        <div>{{ $invoice->customer->full_name ?? 'N/A' }}</div>
        @if ($invoice->customer?->user)
            <div>{{ $invoice->customer->user->email }}</div>
            @if ($invoice->customer->user->formatted_address)
                <div style="color:#555; margin-top:4px;">{{ $invoice->customer->user->formatted_address }}</div>
            @endif
            @if ($invoice->customer->tax_id)
                <div style="color:#555;">GSTIN: {{ $invoice->customer->tax_id }}</div>
            @endif
        @endif
    </div>

    <table>
        <thead>
            <tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Unit Price</th><th class="text-right">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="text-right">{{ number_format($item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="width: 250px; margin-left: auto;">
        <table style="width: 100%;">
            <tr><td>Subtotal</td><td class="text-right">{{ number_format($invoice->amount, 2) }}</td></tr>
            @if ($gstBreakdown['tax'] > 0)
                <tr><td>Tax ({{ $gstBreakdown['type'] === 'intra' ? 'CGST + SGST' : 'IGST' }})</td><td class="text-right">{{ number_format($gstBreakdown['tax'], 2) }}</td></tr>
            @endif
            @if ($invoice->discount > 0)
                <tr><td>Discount</td><td class="text-right">-{{ number_format($invoice->discount, 2) }}</td></tr>
            @endif
            <tr class="total-row"><td>Total</td><td class="text-right">{{ number_format($invoice->total, 2) }}</td></tr>
            <tr><td>Paid</td><td class="text-right text-right" style="color: #28a745;">{{ number_format($invoice->paid_amount ?? 0, 2) }}</td></tr>
            <tr><td><strong>Balance Due</strong></td><td class="text-right"><strong>{{ number_format(max(0, $invoice->total - ($invoice->paid_amount ?? 0)), 2) }}</strong></td></tr>
        </table>
    </div>

    @if ($invoice->notes)
        <div style="margin-top: 30px;">
            <div class="section-title">Notes</div>
            <div style="color: #666;">{{ $invoice->notes }}</div>
        </div>
    @endif
</body>
</html>
