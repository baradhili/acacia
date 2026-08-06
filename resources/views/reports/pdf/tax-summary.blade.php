<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Summary</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
        h1 { font-size: 18px; margin-bottom: 5px; }
        h2 { font-size: 14px; margin-top: 20px; margin-bottom: 10px; }
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
    </style>
</head>
<body>
    <div class="header">
        <h1>Tax Summary Report</h1>
        <p class="meta">
            Period: {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}
        </p>
    </div>

    <div class="summary">
        <div class="summary-box box-green">
            <div class="summary-label">Output Tax (GST Collected)</div>
            <div class="summary-value text-green">${{ number_format($totalSalesTax, 2) }}</div>
        </div>
        <div class="summary-box box-red">
            <div class="summary-label">Input Tax (GST Paid)</div>
            <div class="summary-value text-red">${{ number_format($totalPurchaseTax, 2) }}</div>
        </div>
        <div class="summary-box box-indigo">
            <div class="summary-label">Net Tax Payable</div>
            <div class="summary-value">${{ number_format($netTaxPayable, 2) }}</div>
        </div>
    </div>

    <h2>Sales by Tax Rate (Output Tax)</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 100px;">Tax Rate</th>
                <th style="width: 100px;" class="text-right">Transactions</th>
                <th style="width: 120px;" class="text-right">Net Amount</th>
                <th style="width: 120px;" class="text-right">Tax Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($salesByTaxRate as $row)
            <tr>
                <td>{{ $row['tax_rate'] }}%</td>
                <td class="text-right">{{ $row['transaction_count'] }}</td>
                <td class="text-right">${{ number_format($row['net_amount'], 2) }}</td>
                <td class="text-right text-green">${{ number_format($row['tax_amount'], 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="text-center">No sales with tax found</td>
            </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td>Total</td>
                <td class="text-right">{{ $salesByTaxRate->sum('transaction_count') }}</td>
                <td class="text-right">${{ number_format($salesByTaxRate->sum('net_amount'), 2) }}</td>
                <td class="text-right text-green">${{ number_format($totalSalesTax, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>Purchases by Tax Rate (Input Tax)</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 100px;">Tax Rate</th>
                <th style="width: 100px;" class="text-right">Transactions</th>
                <th style="width: 120px;" class="text-right">Net Amount</th>
                <th style="width: 120px;" class="text-right">Tax Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($purchasesByTaxRate as $row)
            <tr>
                <td>{{ $row['tax_rate'] }}%</td>
                <td class="text-right">{{ $row['transaction_count'] }}</td>
                <td class="text-right">${{ number_format($row['net_amount'], 2) }}</td>
                <td class="text-right text-red">${{ number_format($row['tax_amount'], 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="text-center">No purchases with tax found</td>
            </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td>Total</td>
                <td class="text-right">{{ $purchasesByTaxRate->sum('transaction_count') }}</td>
                <td class="text-right">${{ number_format($purchasesByTaxRate->sum('net_amount'), 2) }}</td>
                <td class="text-right text-red">${{ number_format($totalPurchaseTax, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <p>Generated on {{ now()->format('d/m/Y H:i') }} | Laravel ERP System</p>
    </div>
</body>
</html>
