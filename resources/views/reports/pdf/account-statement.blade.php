<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Statement</title>
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
        .summary { margin-bottom: 20px; }
        .summary-box { 
            display: inline-block; 
            padding: 10px 15px; 
            border: 1px solid #ddd; 
            margin-right: 10px;
            background-color: #f9fafb;
        }
        .summary-label { font-size: 10px; color: #666; }
        .summary-value { font-size: 14px; font-weight: bold; }
        .footer { margin-top: 30px; font-size: 10px; color: #999; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Account Statement</h1>
        <p class="meta">
            <strong>{{ $account->code }}</strong> - {{ $account->name }}<br>
            Period: {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}
        </p>
    </div>

    <div class="summary">
        <div class="summary-box">
            <div class="summary-label">Opening Balance</div>
            <div class="summary-value">${{ number_format($openingBalance, 2) }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Total Debit</div>
            <div class="summary-value text-red">${{ number_format($totalDebit, 2) }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Total Credit</div>
            <div class="summary-value text-green">${{ number_format($totalCredit, 2) }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Closing Balance</div>
            <div class="summary-value">${{ number_format($closingBalance, 2) }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 80px;">Date</th>
                <th style="width: 100px;">Reference</th>
                <th>Description</th>
                <th style="width: 90px;" class="text-right">Debit</th>
                <th style="width: 90px;" class="text-right">Credit</th>
                <th style="width: 100px;" class="text-right">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $startDate->format('d/m/Y') }}</td>
                <td colspan="2"><em>Opening Balance</em></td>
                <td></td>
                <td></td>
                <td class="text-right">${{ number_format($openingBalance, 2) }}</td>
            </tr>
            @foreach($transactions as $txn)
            <tr>
                <td>{{ $txn['date']->format('d/m/Y') }}</td>
                <td>{{ $txn['reference'] ?: '-' }}</td>
                <td>{{ $txn['narration'] }}</td>
                <td class="text-right text-red">{{ $txn['debit'] ? '$' . number_format($txn['debit'], 2) : '-' }}</td>
                <td class="text-right text-green">{{ $txn['credit'] ? '$' . number_format($txn['credit'], 2) : '-' }}</td>
                <td class="text-right">${{ number_format($txn['balance'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3">Totals</td>
                <td class="text-right text-red">${{ number_format($totalDebit, 2) }}</td>
                <td class="text-right text-green">${{ number_format($totalCredit, 2) }}</td>
                <td class="text-right">${{ number_format($closingBalance, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <p>Generated on {{ now()->format('d/m/Y H:i') }} | Laravel ERP System</p>
    </div>
</body>
</html>
