<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BAS FY{{ $fyEnd }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
        h1 { font-size: 18px; margin-bottom: 5px; }
        .header { margin-bottom: 20px; }
        .meta { color: #666; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f5f5f5; font-weight: bold; }
        .text-right { text-align: right; }
        .text-red { color: #dc2626; }
        .text-green { color: #16a34a; }
        .total-row { font-weight: bold; background-color: #f5f5f5; }
        .summary { margin-bottom: 30px; }
        .summary-box {
            display: inline-block;
            padding: 15px 20px;
            border: 1px solid #ddd;
            margin-right: 15px;
            background-color: #f9fafb;
        }
        .summary-label { font-size: 10px; color: #666; text-transform: uppercase; }
        .summary-value { font-size: 18px; font-weight: bold; margin-top: 5px; }
        .box-green { background-color: #f0fdf4; border-color: #86efac; }
        .box-red { background-color: #fef2f2; border-color: #fca5a5; }
        .box-indigo { background-color: #eef2ff; border-color: #a5b4fc; }
        .footer { margin-top: 30px; font-size: 10px; color: #999; }
        .notes { font-size: 10px; color: #666; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>BAS — Business Activity Statement (FY{{ $fyEnd }})</h1>
        <p class="meta">
            Period: {{ $statement['fyStart']->format('d/m/Y') }} to {{ $statement['fyEnd']->format('d/m/Y') }}
        </p>
    </div>

    <div class="summary">
        <div class="summary-box box-green">
            <div class="summary-label">1A GST on Sales</div>
            <div class="summary-value text-green">${{ number_format($statement['totals']['gst_sales'], 2) }}</div>
        </div>
        <div class="summary-box box-red">
            <div class="summary-label">1B GST on Purchases</div>
            <div class="summary-value text-red">${{ number_format($statement['totals']['gst_purchases'], 2) }}</div>
        </div>
        <div class="summary-box box-indigo">
            <div class="summary-label">Net GST {{ $statement['totals']['net'] >= 0 ? 'Payable' : 'Refundable' }}</div>
            <div class="summary-value">${{ number_format(abs($statement['totals']['net']), 2) }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Quarter</th>
                <th>Period</th>
                <th class="text-right">G1 Sales (incl GST)</th>
                <th class="text-right">G10 Capital purchases</th>
                <th class="text-right">G11 Non-capital purchases</th>
                <th class="text-right">1A GST sales</th>
                <th class="text-right">1B GST purchases</th>
                <th class="text-right">Net GST</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statement['quarters'] as $q)
            <tr>
                <td>{{ $q['label'] }}</td>
                <td>{{ $q['start']->format('d/m/Y') }} – {{ $q['end']->format('d/m/Y') }}</td>
                <td class="text-right">${{ number_format($q['g1'], 2) }}</td>
                <td class="text-right">${{ number_format($q['g10'], 2) }}</td>
                <td class="text-right">${{ number_format($q['g11'], 2) }}</td>
                <td class="text-right text-green">${{ number_format($q['gst_sales'], 2) }}</td>
                <td class="text-right text-red">${{ number_format($q['gst_purchases'], 2) }}</td>
                <td class="text-right">${{ number_format($q['net'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="2">FY{{ $fyEnd }} Total</td>
                <td class="text-right">${{ number_format($statement['totals']['g1'], 2) }}</td>
                <td class="text-right">${{ number_format($statement['totals']['g10'], 2) }}</td>
                <td class="text-right">${{ number_format($statement['totals']['g11'], 2) }}</td>
                <td class="text-right text-green">${{ number_format($statement['totals']['gst_sales'], 2) }}</td>
                <td class="text-right text-red">${{ number_format($statement['totals']['gst_purchases'], 2) }}</td>
                <td class="text-right">${{ number_format($statement['totals']['net'], 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="notes">
        <p>Capital purchases (G10) are bill lines categorised to a non-current-asset account; all other bill lines are non-capital (G11).</p>
        <p>Cash basis — only posted payments count: G1 is client receipts (GST-inclusive, refunds netting), 1A/1B are the GST ledger legs, and G10/G11 apportion supplier payments across their bills' lines. Unposted payments appear once backfilled (ifrs:post-payments).</p>
    </div>

    <div class="footer">
        <p>Generated on {{ now()->format('d/m/Y H:i') }} | Laravel ERP System</p>
    </div>
</body>
</html>
