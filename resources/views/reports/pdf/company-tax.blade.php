<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Tax Return FY{{ $fyEnd }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
        h1 { font-size: 18px; margin-bottom: 5px; }
        h2 { font-size: 14px; margin: 20px 0 8px; }
        .header { margin-bottom: 20px; }
        .meta { color: #666; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background-color: #f5f5f5; font-weight: bold; }
        .text-right { text-align: right; }
        .total-row { font-weight: bold; background-color: #f5f5f5; }
        .label-cell { width: 50px; }
        .note { font-size: 10px; color: #999; }
        .summary { margin-bottom: 30px; }
        .summary-box {
            display: inline-block; padding: 12px 18px; border: 1px solid #ddd;
            margin-right: 12px; margin-bottom: 8px; background-color: #f9fafb;
        }
        .summary-label { font-size: 10px; color: #666; text-transform: uppercase; }
        .summary-value { font-size: 16px; font-weight: bold; margin-top: 4px; }
        .footer { margin-top: 30px; font-size: 10px; color: #999; }
        .notes { font-size: 10px; color: #666; margin-top: 10px; }
        .pass { color: #16a34a; font-weight: bold; }
        .fail { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
    @php
        $money = fn ($amount) => $amount === null ? '—' : ($amount < 0 ? '-' : '') . '$' . number_format(abs($amount));
    @endphp
    <div class="header">
        <h1>Company Tax Return {{ $fyEnd }} — Annual Report</h1>
        <p class="meta">
            {{ $statement['entity']['name'] }} ·
            ABN: {{ $statement['entity']['abn'] !== '' ? $statement['entity']['abn'] : 'not configured' }} ·
            TFN: {{ $statement['entity']['tfn'] !== '' ? $statement['entity']['tfn'] : 'not configured' }}<br>
            Income year: {{ $statement['fyStart']->format('d/m/Y') }} to {{ $statement['fyEnd']->format('d/m/Y') }} ·
            Cash basis — small business entity · Amounts GST-exclusive, whole dollars
        </p>
    </div>

    <div class="summary">
        <div class="summary-box">
            <div class="summary-label">6-S Total income</div>
            <div class="summary-value">{{ $money($statement['totalIncome']) }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">6-Q Total expenses</div>
            <div class="summary-value">{{ $money($statement['totalExpenses']) }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">6-T Profit or loss</div>
            <div class="summary-value">{{ $money($statement['profitOrLoss']) }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">7-T Taxable income</div>
            <div class="summary-value">{{ $money($statement['taxableIncome']) }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Est. tax @ {{ $statement['taxRate'] }}%</div>
            <div class="summary-value">{{ $money($statement['estimatedTax']) }}</div>
        </div>
    </div>

    <h2>Item 6 — Income</h2>
    <table>
        <thead>
            <tr><th class="label-cell">Label</th><th>Description</th><th class="text-right">Amount (AUD)</th></tr>
        </thead>
        <tbody>
            @foreach ($statement['income'] as $row)
            <tr class="{{ $row['total'] ? 'total-row' : '' }}">
                <td>{{ $row['label'] }}</td>
                <td>{{ $row['name'] }}@if ($row['note']) <span class="note">— {{ $row['note'] }}</span>@endif</td>
                <td class="text-right">{{ $money($row['amount']) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Item 6 — Expenses</h2>
    <table>
        <thead>
            <tr><th class="label-cell">Label</th><th>Description</th><th class="text-right">Amount (AUD)</th></tr>
        </thead>
        <tbody>
            @foreach ($statement['expenses'] as $row)
            <tr class="{{ $row['total'] ? 'total-row' : '' }}">
                <td>{{ $row['label'] }}</td>
                <td>{{ $row['name'] }}@if ($row['note']) <span class="note">— {{ $row['note'] }}</span>@endif</td>
                <td class="text-right">{{ $money($row['amount']) }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td>T</td>
                <td>Total profit or loss</td>
                <td class="text-right">{{ $money($statement['profitOrLoss']) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Item 7 — Reconciliation to taxable income or loss</h2>
    <table>
        <thead>
            <tr><th class="label-cell">Label</th><th>Description</th><th class="text-right">Amount (AUD)</th></tr>
        </thead>
        <tbody>
            @foreach ($statement['reconciliation'] as $row)
            <tr class="{{ $row['total'] ? 'total-row' : '' }}">
                <td>{{ $row['label'] }}</td>
                <td>{{ $row['name'] }}@if ($row['note']) <span class="note">— {{ $row['note'] }}</span>@endif</td>
                <td class="text-right">{{ $money($row['amount']) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Item 8 — Financial and other information</h2>
    <table>
        <thead>
            <tr><th class="label-cell">Label</th><th>Description</th><th class="text-right">Amount (AUD)</th></tr>
        </thead>
        <tbody>
            @foreach ($statement['financialInfo'] as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td>{{ $row['name'] }}@if ($row['note']) <span class="note">— {{ $row['note'] }}</span>@endif</td>
                <td class="text-right">{{ $money($row['amount']) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Item 10 — Capital purchases (SBE simplified depreciation reference)</h2>
    <table>
        <thead>
            <tr><th>Code</th><th>Asset account</th><th class="text-right">Net paid (AUD)</th></tr>
        </thead>
        <tbody>
            @forelse ($statement['capitalPurchases']['accounts'] as $account)
            <tr>
                <td>{{ $account['code'] }}</td>
                <td>{{ $account['name'] }}</td>
                <td class="text-right">{{ $money($account['amount']) }}</td>
            </tr>
            @empty
            <tr><td colspan="3">No capital purchases in the period.</td></tr>
            @endforelse
            <tr class="total-row">
                <td colspan="2">Total</td>
                <td class="text-right">{{ $money($statement['capitalPurchases']['total']) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Validation summary</h2>
    <table>
        <thead>
            <tr><th class="label-cell">Rule</th><th>Status</th><th>Detail</th></tr>
        </thead>
        <tbody>
            @foreach ($statement['validations'] as $validation)
            <tr>
                <td>{{ $validation['code'] }}</td>
                <td class="{{ $validation['status'] === 'PASS' ? 'pass' : 'fail' }}">{{ $validation['status'] }}</td>
                <td class="note">{{ $validation['detail'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="notes">
        <p>Cash-basis amounts exclude GST; only bank-settled ledger transactions are included (non-cash journals excluded).</p>
        <p>Franking account balances (labels 8-P/8-M) are not tracked — the franking module is not implemented.</p>
        <p>The tax estimate is informational; apply the applicable company tax rate (25% base rate entity / 30%).</p>
    </div>

    <div class="footer">
        <p>Generated on {{ now()->format('d/m/Y H:i') }} | Laravel ERP System</p>
    </div>
</body>
</html>
