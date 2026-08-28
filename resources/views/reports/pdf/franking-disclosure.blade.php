<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AASB 1054 Franking Credit Disclosure FY{{ $data['year'] }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 25px; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        .meta { color: #666; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        td { padding: 6px 0; border-bottom: 1px solid #eee; }
        .text-right { text-align: right; }
        .total td { font-weight: bold; border-top: 1px solid #999; border-bottom: none; font-size: 14px; }
        .note { margin-top: 24px; line-height: 1.6; color: #333; }
        .deficit { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
    <h1>AASB 1054 — Franking Credit Disclosure</h1>
    <p class="meta">Financial year FY{{ $data['year'] }} (1 July {{ $data['year'] }} – 30 June {{ $data['year'] + 1 }})</p>

    <table>
        <tr>
            <td>Franking account balance at 30 June {{ $data['year'] + 1 }}</td>
            <td class="text-right">${{ number_format($data['closing_balance'], 2) }}</td>
        </tr>
        <tr>
            <td>(a) Franking credits that will arise on payment of the provision for income tax (estimated entries)</td>
            <td class="text-right">${{ number_format($data['anticipated_credits'], 2) }}</td>
        </tr>
        <tr>
            <td>(b) Franking debits that will arise on payment of dividends recognised as a liability (approved, unpaid)</td>
            <td class="text-right">(${{ number_format(abs($data['anticipated_debits']), 2) }})</td>
        </tr>
        <tr class="total">
            <td>Franking credits available for use in subsequent financial years</td>
            <td class="text-right {{ $data['available'] < 0 ? 'deficit' : '' }}">${{ number_format($data['available'], 2) }}</td>
        </tr>
    </table>

    <div class="note">
        <p>Franking credits available for use in subsequent financial years: ${{ number_format($data['available'], 2) }}.</p>
        <p>
            The above amount represents the balance of the franking account as at the reporting date adjusted for:
            (a) franking credits that will arise from the payment of the amount of the provision for income tax
            (${{ number_format($data['anticipated_credits'], 2) }}); and (b) franking debits that will arise from the
            payment of dividends recognised as a liability at the reporting date
            (${{ number_format(abs($data['anticipated_debits']), 2) }}).
        </p>
    </div>
</body>
</html>
