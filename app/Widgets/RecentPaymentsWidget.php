<?php

namespace App\Widgets;

use App\Models\Payment;
use Arrilot\Widgets\AbstractWidget;

class RecentPaymentsWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $payments = Payment::with('client')
            ->where('status', Payment::STATUS_COMPLETED)
            ->orderBy('payment_date', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'client_name' => $payment->client?->name ?? 'Unknown',
                    'amount' => $payment->amount,
                    'amount_formatted' => number_format($payment->amount, 2),
                    'payment_date' => $payment->payment_date?->format('Y-m-d'),
                    'method' => $payment->payment_method,
                ];
            });

        return view('widgets.recent_payments', [
            'payments' => $payments,
            'count' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'total_formatted' => number_format($payments->sum('amount'), 2),
        ]);
    }
}
