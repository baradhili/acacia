<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dividend Statement {{ $distribution->declaration->declaration_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 25px; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .meta { color: #666; margin-bottom: 18px; }
        .parties { width: 100%; margin-bottom: 18px; }
        .parties td { vertical-align: top; width: 50%; padding: 0 12px 0 0; }
        .party-label { font-size: 10px; color: #666; text-transform: uppercase; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #ddd; padding: 5px 8px; text-align: left; }
        table.data th { background-color: #f5f5f5; }
        .text-right { text-align: right; }
        .totals td { border: none; padding: 3px 0; }
        .totals .label { text-align: left; }
        .grand td { font-weight: bold; font-size: 14px; border-top: 1px solid #999; padding-top: 6px; }
        .footer { margin-top: 28px; font-size: 10px; color: #999; }
    </style>
</head>
<body>
    <h1>Dividend Statement</h1>
    <p class="meta">
        {{ $companyName }}@if($companyAbn) · ABN {{ $companyAbn }}@endif<br>
        Financial year ending 30 June {{ $distribution->declaration->financial_year + 1 }}
    </p>

    <table class="parties">
        <tr>
            <td>
                <div class="party-label">Paid to</div>
                <strong>{{ $distribution->shareholder_name }}</strong><br>
                @if($distribution->shareholder)
                    {{ $distribution->shareholder->addressLine() }}
                @endif
            </td>
            <td>
                <div class="party-label">Declaration</div>
                <strong>{{ $distribution->declaration->declaration_number }}</strong><br>
                Declared {{ $distribution->declaration->declaration_date->format('d/m/Y') }}<br>
                Paid {{ $distribution->declaration->payment_date->format('d/m/Y') }}<br>
                Books closed {{ $distribution->declaration->books_close_date->format('d/m/Y') }}
            </td>
        </tr>
    </table>

    <table class="data">
        <tr>
            <th>Share class</th>
            <th class="text-right">Shares eligible</th>
            <th class="text-right">Amount per share</th>
            <th class="text-right">Franking %</th>
        </tr>
        <tr>
            <td>{{ $distribution->declaration->shareClass?->code }} — {{ $distribution->declaration->shareClass?->description }}</td>
            <td class="text-right">{{ number_format($distribution->shares_eligible) }}</td>
            <td class="text-right">${{ number_format($distribution->declaration->amount_per_share, 4) }}</td>
            <td class="text-right">{{ number_format($distribution->declaration->franking_percentage, 2) }}%</td>
        </tr>
    </table>

    <table class="data totals">
        <tr>
            <td class="label">Cash dividend</td>
            <td class="text-right">${{ number_format($distribution->cash_dividend, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Franking credit attached (gross-up at {{ number_format($distribution->declaration->franking_credit_rate, 2) }}%)</td>
            <td class="text-right">${{ number_format($distribution->franking_credit, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Withholding tax</td>
            <td class="text-right">${{ number_format($distribution->withholding_tax, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Net cash payment</td>
            <td class="text-right">${{ number_format($distribution->net_payment, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">Grossed-up dividend (assessable income)</td>
            <td class="text-right">${{ number_format($distribution->grossed_up_dividend, 2) }}</td>
        </tr>
    </table>

    @if($distribution->payment_reference)
    <p>Payment reference: {{ $distribution->payment_reference }}</p>
    @endif

    <div class="footer">
        This statement shows the frankable dividend paid to you and the franking credit attached.
        Keep it with your tax records — the grossed-up dividend is assessable income and the
        franking credit is offsetable against your income tax liability.
    </div>
</body>
</html>
