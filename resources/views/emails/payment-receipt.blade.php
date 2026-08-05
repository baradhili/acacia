<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Receipt {{ $payment->payment_number }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px;">
        <h1 style="color: #059669; margin-top: 0;">Payment Receipt</h1>
        
        <p>Dear {{ $payment->client->name }},</p>
        
        <p>Thank you for your payment. We have received your payment and are pleased to confirm the following:</p>
        
        <div style="background-color: #e5e7eb; padding: 15px; border-radius: 4px; margin: 20px 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 5px 0;"><strong>Receipt Number:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $payment->payment_number }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Payment Date:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $payment->payment_date->format('d M Y') }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Payment Method:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $payment->formatted_method }}</td>
                </tr>
                @if($payment->reference)
                <tr>
                    <td style="padding: 5px 0;"><strong>Reference:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $payment->reference }}</td>
                </tr>
                @endif
                <tr style="font-size: 1.2em;">
                    <td style="padding: 10px 0 0 0;"><strong>Amount Received:</strong></td>
                    <td style="padding: 10px 0 0 0; text-align: right; color: #059669;"><strong>{{ $payment->formatted_amount }}</strong></td>
                </tr>
            </table>
        </div>
        
        @if($payment->allocations->count() > 0)
        <h3 style="color: #374151; margin-top: 20px;">Allocated to Invoices:</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr style="border-bottom: 2px solid #d1d5db;">
                    <th style="padding: 8px 0; text-align: left;">Invoice</th>
                    <th style="padding: 8px 0; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payment->allocations as $allocation)
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="padding: 8px 0;">{{ $allocation->invoice->invoice_number }}</td>
                    <td style="padding: 8px 0; text-align: right;">{{ number_format($allocation->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
        
        <p>If you have any questions about this receipt, please don't hesitate to contact us.</p>
        
        <p>Thank you for your payment!</p>
        
        <p>Best regards,<br>{{ config('app.name') }}</p>
    </div>
    
    <p style="font-size: 12px; color: #6b7280; margin-top: 20px; text-align: center;">
        This email and any attachments are confidential. If you received this email in error, please notify the sender and delete it immediately.
    </p>
</body>
</html>
