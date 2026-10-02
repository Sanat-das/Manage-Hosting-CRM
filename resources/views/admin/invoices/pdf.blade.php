<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_no }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #007bff; padding-bottom: 10px; margin-bottom: 20px; }
        .seller-name { font-size: 20px; font-weight: bold; color: #007bff; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 8px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background-color: #f8f9fa; font-weight: 600; }
        thead th { border-top: 2px solid #ddd; }
        .text-right { text-align: right; }
        .total-row { font-weight: bold; font-size: 14px; border-top: 2px solid #333; }
        .section-title { font-size: 14px; font-weight: bold; margin: 20px 0 10px; color: #007bff; }
        .badge { padding: 2px 8px; border-radius: 4px; font-size: 11px; }
        .badge-paid { background: #28a745; color: white; }
        .badge-draft { background: #6c757d; color: white; }
        .badge-sent { background: #007bff; color: white; }
        .badge-overdue { background: #fd7e14; color: white; }
        .badge-invalid { background: #dc3545; color: white; }
        .invalid-strip { background: #dc3545; color: white; text-align: center; padding: 8px; font-weight: bold; letter-spacing: 1px; margin-bottom: 20px; }
        /* The options partial inherits Bootstrap utility classes that are not
           defined here; strip list chrome so DomPDF renders plain lines. */
        ul { list-style: none; margin: 0; padding: 0; }
    </style>
</head>
<body>
    @php
        $logoPath = ($storedLogo = \App\Support\Branding::logoPath()) !== ''
            ? storage_path('app/public/'.$storedLogo)
            : public_path(\App\Support\Branding::DEFAULT_LOGO);
        if (! file_exists($logoPath)) { $logoPath = ''; }
    @endphp
    <div class="header">
        <div>
            @if ($logoPath !== '')
                <img src="{{ $logoPath }}" style="height: 36px; width: auto; margin-bottom: 6px;" alt="{{ config('app.name') }}">
            @else
                <div class="seller-name">{{ \App\Support\AppSettings::get('company_name') ?: config('app.name') }}</div>
            @endif
            @if ($sellerAddress = \App\Support\AppSettings::get('company_address'))
                <div style="color: #666;">{!! nl2br(e($sellerAddress)) !!}</div>
            @endif
            @if ($sellerGstin = \App\Support\AppSettings::get('company_gstin'))
                <div style="color: #666;">GSTIN: {{ $sellerGstin }}</div>
            @endif
            @if ($sellerPhone = \App\Support\AppSettings::get('company_phone'))
                <div style="color: #666;">{{ $sellerPhone }}</div>
            @endif
            @if ($sellerEmail = \App\Support\AppSettings::get('company_email'))
                <div style="color: #666;">{{ $sellerEmail }}</div>
            @endif
        </div>
        <div style="text-align: right;">
            <div style="font-size: 18px; font-weight: bold;">INVOICE</div>
            <div><strong>{{ $invoice->invoice_no }}</strong></div>
            <div>Date: {{ $invoice->created_at?->format('M j, Y') }}</div>
            @if ($invoice->due_date)
                <div>Due: {{ $invoice->due_date->format('M j, Y') }}</div>
            @endif
            @if ($invoice->order?->order_no)<div>Ref: {{ $invoice->order->order_no }}</div>@endif
            @php
                if ($invoice->isPaid()) {
                    $badgeClass = 'badge-paid';
                    $badgeLabel = 'PAID';
                } elseif ($invoice->isVoid() || $invoice->isCancelled()) {
                    $badgeClass = 'badge-invalid';
                    $badgeLabel = strtoupper($invoice->status);
                } elseif ($invoice->isOverdue()) {
                    $badgeClass = 'badge-overdue';
                    $badgeLabel = 'OVERDUE';
                } elseif ($invoice->isDraft()) {
                    $badgeClass = 'badge-draft';
                    $badgeLabel = 'DRAFT';
                } else {
                    $badgeClass = 'badge-sent';
                    $badgeLabel = strtoupper($invoice->status);
                }
            @endphp
            <div style="margin-top: 6px;"><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span></div>
        </div>
    </div>

    @if ($invoice->isVoid() || $invoice->isCancelled())
        <div class="invalid-strip">THIS DOCUMENT IS NOT VALID FOR PAYMENT</div>
    @endif

    @php
        $customerUser = $invoice->customer?->user;
    @endphp
    <div style="margin-bottom: 20px;">
        <div class="section-title">Bill To</div>
        <div>{{ $invoice->customer?->full_name ?? 'N/A' }}</div>
        @if ($customerUser)
            <div>{{ $customerUser->email }}</div>
            @if ($customerUser->formatted_address)
                <div style="color:#555; margin-top:4px;">{{ $customerUser->formatted_address }}</div>
            @endif
        @endif
        @if ($invoice->customer?->tax_id)
            <div style="color:#555;">GSTIN: {{ $invoice->customer->tax_id }}</div>
        @endif
        @if ($invoice->place_of_supply_code)
            <div style="color:#555;">Place of Supply: {{ $invoice->place_of_supply_code }}</div>
        @endif
        @if ($invoice->due_date)
            <div style="color:#555;">Payment due: {{ $invoice->due_date->format('M j, Y') }}</div>
        @endif
    </div>

    <table>
        <thead>
            <tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Unit Price</th><th class="text-right">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>
                        {{ $item->description }}
                        {{-- Configuration without price chips: the line already
                             states its amount, and dompdf need not render the
                             currency glyph twice per line. --}}
                        <div style="font-size: 11px; color: #555; margin-top: 4px;">
                            @include('partials._selected_options', [
                                'entries' => $item->config_options['options'] ?? [],
                                'modifiersByLink' => [],
                                'cycle' => $item->config_options['billing_cycle'] ?? 'monthly',
                                'includeUnselected' => false,
                                'showModifiers' => false,
                            ])
                        </div>
                    </td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">₹{{ number_format($item->unit_price, 2) }}</td>
                    <td class="text-right">₹{{ number_format($item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="width: 250px; margin-left: auto;">
        <table style="width: 100%;">
            <tr><td>Subtotal</td><td class="text-right">₹{{ number_format($invoice->amount, 2) }}</td></tr>
            @if ($gstBreakdown['tax'] > 0)
                @php
                    $rate = fn (float $rate): string => rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

                    if ($gstBreakdown['type'] === 'intra' && $gstBreakdown['cgst_rate'] !== null && $gstBreakdown['sgst_rate'] !== null) {
                        $taxLabel = 'CGST '.$rate((float) $gstBreakdown['cgst_rate']).'% + SGST '.$rate((float) $gstBreakdown['sgst_rate']).'%';
                    } elseif ($gstBreakdown['type'] === 'inter' && $gstBreakdown['igst_rate'] !== null) {
                        $taxLabel = 'IGST '.$rate((float) $gstBreakdown['igst_rate']).'%';
                    } else {
                        $taxLabel = $gstBreakdown['type'] === 'intra' ? 'CGST + SGST' : 'IGST';
                    }
                @endphp
                <tr><td>Tax ({{ $taxLabel }})</td><td class="text-right">₹{{ number_format($gstBreakdown['tax'], 2) }}</td></tr>
            @endif
            @if ($invoice->discount > 0)
                <tr><td>Discount</td><td class="text-right">-₹{{ number_format($invoice->discount, 2) }}</td></tr>
            @endif
            <tr class="total-row"><td>Total</td><td class="text-right">₹{{ number_format($invoice->total, 2) }} INR</td></tr>
            <tr><td>Paid</td><td class="text-right" style="color: #28a745;">₹{{ number_format($invoice->paid_amount ?? 0, 2) }}</td></tr>
            <tr><td><strong>Balance Due</strong></td><td class="text-right"><strong>₹{{ number_format(max(0, $invoice->total - ($invoice->paid_amount ?? 0)), 2) }}</strong></td></tr>
        </table>
    </div>

    <div style="margin-top: 12px; text-align: right;">
        <strong>Amount in words:</strong> {{ \App\Support\AmountInWords::convert($invoice->total) }}
    </div>

    @php
        $bankName = \App\Support\AppSettings::get('bank_name');
        $bankAccountHolder = \App\Support\AppSettings::get('bank_account_holder');
        $bankAccountNo = \App\Support\AppSettings::get('bank_account_no');
        $bankIfsc = \App\Support\AppSettings::get('bank_ifsc');
    @endphp
    @if ($bankName || $bankAccountHolder || $bankAccountNo || $bankIfsc)
        <div style="margin-top: 24px; color: #555;">
            @if ($bankName)<div>Bank: {{ $bankName }}</div>@endif
            @if ($bankAccountHolder)<div>Account holder: {{ $bankAccountHolder }}</div>@endif
            @if ($bankAccountNo)<div>Account no: {{ $bankAccountNo }}</div>@endif
            @if ($bankIfsc)<div>IFSC: {{ $bankIfsc }}</div>@endif
        </div>
    @endif

    @if ($invoice->notes)
        <div style="margin-top: 30px;">
            <div class="section-title">Notes</div>
            <div style="color: #666;">{{ $invoice->notes }}</div>
        </div>
    @endif
</body>
</html>