<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dividend Statement {{ $distribution->declaration->declaration_number }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px;">
        <h1 style="color: #1e40af; margin-top: 0;">Dividend Statement</h1>

        <p>Dear {{ $distribution->shareholder_name }},</p>

        <p>A dividend has been paid to you as detailed below. Your statement is attached as a PDF and
            includes the franking credit details you will need for your income tax return.</p>

        <div style="background-color: #e5e7eb; padding: 15px; border-radius: 4px; margin: 20px 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 5px 0;"><strong>Declaration Number:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $distribution->declaration->declaration_number }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Payment Date:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $distribution->declaration->payment_date->format('d M Y') }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Share Class:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ $distribution->declaration->shareClass?->code }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Shares Eligible:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">{{ number_format($distribution->shares_eligible) }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Cash Dividend:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">${{ number_format($distribution->cash_dividend, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding: 5px 0;"><strong>Franking Credit Attached:</strong></td>
                    <td style="padding: 5px 0; text-align: right;">${{ number_format($distribution->franking_credit, 2) }}</td>
                </tr>
                <tr style="font-size: 1.2em;">
                    <td style="padding: 10px 0 0 0;"><strong>Grossed-Up Dividend:</strong></td>
                    <td style="padding: 10px 0 0 0; text-align: right; color: #1e40af;"><strong>${{ number_format($distribution->grossed_up_dividend, 2) }}</strong></td>
                </tr>
            </table>
        </div>

        <p>Please retain this statement for your records. If any detail is incorrect, contact us as
            soon as possible.</p>

        <p>Best regards,<br>{{ $companyName }}</p>
    </div>

    <p style="font-size: 12px; color: #6b7280; margin-top: 20px; text-align: center;">
        {{ $companyName }}@if($companyAbn) · ABN {{ $companyAbn }}@endif<br>
        This email and any attachments are confidential. If you received this email in error, please notify the sender and delete it immediately.
    </p>
</body>
</html>
