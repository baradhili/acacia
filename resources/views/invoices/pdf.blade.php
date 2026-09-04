<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
        }
        .header {
            display: table;
            width: 100%;
            margin-bottom: 40px;
        }
        .header-left, .header-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        .header-right {
            text-align: right;
        }
        .company-logo {
            height: 60px;
            margin-bottom: 10px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        .legal-name {
            font-size: 11px;
            color: #666;
            margin-bottom: 10px;
        }
        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #4f46e5;
            margin-bottom: 10px;
        }
        .invoice-number {
            font-size: 14px;
            color: #666;
        }
        .invoice-meta {
            margin-bottom: 30px;
        }
        .invoice-meta p {
            margin-bottom: 5px;
        }
        .addresses {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }
        .address-block {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 20px;
        }
        .address-label {
            font-weight: bold;
            color: #666;
            margin-bottom: 5px;
            font-size: 10px;
            text-transform: uppercase;
        }
        .address-content {
            font-size: 11px;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        table.items th {
            background-color: #f3f4f6;
            padding: 10px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
            border-bottom: 2px solid #e5e7eb;
        }
        table.items th:last-child,
        table.items td:last-child {
            text-align: right;
        }
        table.items td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        table.items .amount-col {
            text-align: right;
        }
        .totals {
            width: 300px;
            margin-left: auto;
        }
        .totals table {
            width: 100%;
        }
        .totals td {
            padding: 5px 10px;
        }
        .totals .total-row {
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #333;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-sent { background: #dbeafe; color: #1e40af; }
        .status-draft { background: #f3f4f6; color: #374151; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #666;
        }
        .notes {
            margin-top: 20px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 5px;
        }
        .notes-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .page-break {
            page-break-after: always;
        }
        @media print {
            body { font-size: 11px; }
            .container { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                @if($logo = $companyProfile->logoDataUri())
                    <img src="{{ $logo }}" alt="Company logo" class="company-logo">
                @endif
                <div class="company-name">{{ $companyProfile->trading_name ?: ($companyProfile->entity?->name ?: config('app.name')) }}</div>
                @if($companyProfile->trading_name && $companyProfile->entity?->name && $companyProfile->trading_name !== $companyProfile->entity->name)
                    <div class="legal-name">{{ $companyProfile->entity->name }}</div>
                @endif
                @if($abn = $companyProfile->formatted_abn ?: config('australian.abn'))
                    <p>ABN: {{ $abn }}</p>
                @endif
                @if($companyProfile->address_line1)
                    <p>{{ $companyProfile->address_line1 }}</p>
                @endif
                @if($companyProfile->address_line2)
                    <p>{{ $companyProfile->address_line2 }}</p>
                @endif
                @if($companyProfile->suburb || $companyProfile->state || $companyProfile->postcode)
                    <p>{{ implode(', ', array_filter([$companyProfile->suburb, $companyProfile->state, $companyProfile->postcode])) }}</p>
                @endif
                @if($companyProfile->email)
                    <p>Email: {{ $companyProfile->email }}</p>
                @endif
                @if($companyProfile->phone)
                    <p>Phone: {{ $companyProfile->phone }}</p>
                @endif
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
                <div class="invoice-meta" style="margin-top: 20px;">
                    <p><strong>Issue Date:</strong> {{ $invoice->issue_date->format('d M Y') }}</p>
                    <p><strong>Due Date:</strong> {{ $invoice->due_date?->format('d M Y') ?? 'On Receipt' }}</p>
                    <p>
                        <strong>Status:</strong> 
                        <span class="status status-{{ $invoice->status }}">
                            {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}
                        </span>
                    </p>
                </div>
            </div>
        </div>

        <div class="addresses">
            <div class="address-block">
                <div class="address-label">Bill To</div>
                <div class="address-content">
                    <strong>{{ $invoice->client->name }}</strong><br>
                    @if($invoice->client->address)
                        {{ $invoice->client->address }}<br>
                    @endif
                    @if($invoice->client->city)
                        {{ $invoice->client->city }}, 
                    @endif
                    @if($invoice->client->state)
                        {{ $invoice->client->state }}
                    @endif
                    @if($invoice->client->postcode)
                        {{ $invoice->client->postcode }}<br>
                    @endif
                    @if($invoice->client->country)
                        {{ $invoice->client->country }}<br>
                    @endif
                    @if($invoice->client->abn)
                        ABN: {{ $invoice->client->abn }}<br>
                    @endif
                    {{ $invoice->client->email }}
                </div>
            </div>
            <div class="address-block">
                @if($invoice->project)
                    <div class="address-label">Project</div>
                    <div class="address-content">
                        {{ $invoice->project->name }}
                    </div>
                @endif
                @if($invoice->purchaseOrder)
                    <div class="address-label" style="margin-top: 15px;">Purchase Order</div>
                    <div class="address-content">
                        {{ $invoice->purchaseOrder->po_number }}<br>
                        <span style="font-size: 10px; color: #6b7280;">
                            Remaining after this invoice: ${{ number_format($invoice->po_remaining_after, 2) }}
                        </span>
                    </div>
                @endif
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 50%;">Description</th>
                    <th style="width: 10%;">Qty</th>
                    <th style="width: 15%;">Unit Price</th>
                    <th style="width: 10%;">Tax</th>
                    <th style="width: 15%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $item)
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td>{{ number_format($item->quantity, 2) }}</td>
                        <td class="amount-col">${{ $item->formatted_unit_price }}</td>
                        <td class="amount-col">{{ $item->tax_rate }}%</td>
                        <td class="amount-col">${{ number_format($item->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal</td>
                    <td class="amount-col">${{ number_format($invoice->subtotal, 2) }}</td>
                </tr>
                @if($invoice->discount_amount > 0)
                    <tr>
                        <td>Discount</td>
                        <td class="amount-col">-${{ number_format($invoice->discount_amount, 2) }}</td>
                    </tr>
                @endif
                <tr>
                    <td>GST (10%)</td>
                    <td class="amount-col">${{ number_format($invoice->tax_amount, 2) }}</td>
                </tr>
                <tr class="total-row">
                    <td>Total (AUD)</td>
                    <td class="amount-col">${{ number_format($invoice->total, 2) }}</td>
                </tr>
                @if($invoice->amount_paid > 0)
                    <tr>
                        <td>Paid</td>
                        <td class="amount-col">-${{ number_format($invoice->amount_paid, 2) }}</td>
                    </tr>
                    <tr class="total-row" style="color: #dc2626;">
                        <td>Balance Due</td>
                        <td class="amount-col">${{ number_format($invoice->amount_due, 2) }}</td>
                    </tr>
                @endif
            </table>
        </div>

        @if($invoice->notes)
            <div class="notes">
                <div class="notes-title">Notes</div>
                <p>{{ $invoice->notes }}</p>
            </div>
        @endif

        @if($invoice->terms)
            <div class="footer">
                <strong>Terms & Conditions</strong><br>
                {{ $invoice->terms }}
            </div>
        @endif

        <div class="footer">
            <p>Thank you for your business!</p>
            <p>Payment is due within 30 days of invoice date.</p>
            <p>Please include invoice number with payment.</p>
        </div>
    </div>
</body>
</html>
