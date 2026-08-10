<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Credit Note {{ $creditNote->credit_note_number }}</title>
</head>
<body>
    <h1>Credit Note {{ $creditNote->credit_note_number }}</h1>
    <p><strong>Client:</strong> {{ $creditNote->client->name }}</p>
    <p><strong>Issue Date:</strong> {{ $creditNote->issue_date }}</p>
    <p><strong>Status:</strong> {{ ucfirst($creditNote->status) }}</p>

    <table border="1" cellpadding="5" cellspacing="0">
        <thead>
            <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($creditNote->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>${{ number_format($item->unit_price, 2) }}</td>
                    <td>${{ number_format($item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p><strong>Total:</strong> ${{ number_format($creditNote->total, 2) }}</p>
</body>
</html>
