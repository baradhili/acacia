<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prepayment Amortisation Schedule</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
        h1 { font-size: 18px; margin-bottom: 5px; }
        h2 { font-size: 13px; margin: 18px 0 6px; }
        .meta { color: #666; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #ddd; padding: 5px 8px; text-align: left; }
        th { background-color: #f5f5f5; font-weight: bold; }
        .text-right { text-align: right; }
        .summary { margin-bottom: 20px; }
        .summary-box {
            display: inline-block; padding: 12px 18px; border: 1px solid #ddd;
            margin-right: 12px; background-color: #f9fafb;
        }
        .summary-label { font-size: 10px; color: #666; text-transform: uppercase; }
        .summary-value { font-size: 16px; font-weight: bold; margin-top: 4px; }
        .state-posted { color: #16a34a; }
        .state-planned { color: #999; }
        .state-reversed { color: #dc2626; }
        .footer { margin-top: 30px; font-size: 10px; color: #999; }
    </style>
</head>
<body>
    <h1>Prepaid Subscriptions — Amortisation Schedule</h1>
    <p class="meta">As at {{ now()->format('d/m/Y') }} · amounts ex GST</p>

    <div class="summary">
        <div class="summary-box">
            <div class="summary-label">Total funded</div>
            <div class="summary-value">${{ number_format($totals['funded'], 2) }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Amortised</div>
            <div class="summary-value">${{ number_format($totals['amortised'], 2) }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Prepaid asset remaining</div>
            <div class="summary-value">${{ number_format($totals['remaining'], 2) }}</div>
        </div>
    </div>

    @forelse ($prepayments as $prepayment)
        <h2>
            {{ $prepayment->description }} —
            {{ $prepayment->service_start->format('d/m/Y') }} to {{ $prepayment->service_end->format('d/m/Y') }} —
            Cr {{ $prepayment->assetAccount?->code }} / Dr {{ $prepayment->expenseAccount?->code }} —
            {{ ucfirst($prepayment->status) }}
        </h2>
        <table>
            <thead>
                <tr><th>Month end</th><th class="text-right">Amount</th><th>State</th><th>Ledger entry</th></tr>
            </thead>
            <tbody>
                @foreach ($schedules[$prepayment->id] as $row)
                <tr>
                    <td>{{ $row['period_date']->format('M Y') }}</td>
                    <td class="text-right">${{ number_format($row['amount'], 2) }}</td>
                    <td class="{{ $row['reversed'] ? 'state-reversed' : ($row['posted'] ? 'state-posted' : 'state-planned') }}">
                        {{ $row['reversed'] ? 'Reversed' : ($row['posted'] ? 'Posted' : 'Planned') }}
                    </td>
                    <td>{{ $row['transaction_id'] ? 'JE #' . $row['transaction_id'] : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p>No prepayments recorded yet.</p>
    @endforelse

    <div class="footer">
        <p>Generated on {{ now()->format('d/m/Y H:i') }} | Laravel ERP System</p>
    </div>
</body>
</html>
