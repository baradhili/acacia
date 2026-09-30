<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Join row applying part of a payment to an invoice — unique per
 * (payment, invoice) pair, so a repeat allocation increments the
 * existing row. Created only by Payment::allocateToInvoice() inside
 * its lockForUpdate transaction; an invoice's allocation SUM drives
 * amount_paid and its status progression.
 */
class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get formatted amount
     */
    public function getFormattedAmountAttribute(): string
    {
        return config('australian.currency.symbol', 'A$').number_format($this->amount, 2);
    }
}
