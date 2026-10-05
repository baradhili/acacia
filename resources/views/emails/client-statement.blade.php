<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement {{ $statement['period_label'] }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px;">
        <h1 style="color: #2563eb; margin-top: 0;">Statement for {{ $statement['period_label'] }}</h1>

        <p>Dear {{ $client->name }},</p>

        <p>Please find your account statement for
            {{ $statement['period_start']->format('d M Y') }} –
            {{ $statement['period_end']->format('d M Y') }} below.</p>

        <div style="background-color: #e5e7eb; padding: 15px; border-radius: 4px; margin: 20px 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 5px 0;"><strong>Opening Balance:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">${{ number_format($statement['opening_balance'], 2) }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Invoiced this period:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">${{ number_format($statement['total_invoiced'], 2) }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Paid this period:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">${{ number_format($statement['total_paid'], 2) }}</td>
                </tr>
                <tr style="font-size: 1.2em;">
                    <td style="padding: 10px 0 0 0;"><strong>Closing Balance:</strong></td>
                    <td style="padding: 10px 0 0 0; text-align: right; color: #2563eb;">
                        <strong>${{ number_format($statement['closing_balance'], 2) }}</strong>
                    </td>
                </tr>
            </table>
        </div>

        @if (! empty($statement['line_items']))
            <h2 style="font-size: 1.1em; color: #374151;">Activity</h2>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.95em;">
                <tr style="border-bottom: 2px solid #9ca3af; text-align: left;">
                    <th style="padding: 6px 4px;">Date</th>
                    <th style="padding: 6px 4px;">Description</th>
                    <th style="padding: 6px 4px; text-align: right;">Amount</th>
                    <th style="padding: 6px 4px; text-align: right;">Balance</th>
                </tr>
                @php($runningBalance = (float) $statement['opening_balance'])
                @foreach ($statement['line_items'] as $item)
                    @php($runningBalance += (float) $item['amount'])
                    <tr style="border-bottom: 1px solid #e5e7eb; {{ $item['type'] === 'payment' ? 'color: #059669;' : '' }}">
                        <td style="padding: 6px 4px;">{{ $item['date']->format('d M Y') }}</td>
                        <td style="padding: 6px 4px;">{{ $item['description'] }}</td>
                        <td style="padding: 6px 4px; text-align: right;">${{ number_format($item['amount'], 2) }}</td>
                        <td style="padding: 6px 4px; text-align: right;">${{ number_format($runningBalance, 2) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($statement['closing_balance'] > 0)
            <p>Please arrange payment of the outstanding balance at your earliest convenience.</p>
        @endif

        <p>If you have any questions about this statement, please don't hesitate to contact us.</p>

        <p>Thank you for your business!</p>

        <p>Best regards,<br>{{ config('app.name') }}</p>
    </div>

    <p style="font-size: 12px; color: #6b7280; margin-top: 20px; text-align: center;">
        This email is confidential. If you received it in error, please notify the sender and delete it immediately.
    </p>
</body>
</html>
