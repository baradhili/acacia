<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_number }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px;">
        <h1 style="color: #2563eb; margin-top: 0;">Invoice {{ $invoice->invoice_number }}</h1>
        
        <p>Dear {{ $invoice->client->name }},</p>
        
        <p>{{ $body }}</p>
        
        <div style="background-color: #e5e7eb; padding: 15px; border-radius: 4px; margin: 20px 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 5px 0;"><strong>Invoice Number:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $invoice->invoice_number }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Issue Date:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $invoice->issue_date->format('d M Y') }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Due Date:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $invoice->due_date->format('d M Y') }}</td>
                </tr>
                <tr style="font-size: 1.2em;">
                    <td style="padding: 10px 0 0 0;"><strong>Total Due:</strong></td>
                    <td style="padding: 10px 0 0 0; text-align: right; color: #2563eb;"><strong>{{ $invoice->formatted_total }}</strong></td>
                </tr>
            </table>
        </div>
        
        <p>Please find the attached PDF invoice for your records.</p>
        
        <p>If you have any questions about this invoice, please don't hesitate to contact us.</p>
        
        <p>Thank you for your business!</p>
        
        <p>Best regards,<br>{{ config('app.name') }}</p>
    </div>
    
    <p style="font-size: 12px; color: #6b7280; margin-top: 20px; text-align: center;">
        This email and any attachments are confidential. If you received this email in error, please notify the sender and delete it immediately.
    </p>
</body>
</html>
