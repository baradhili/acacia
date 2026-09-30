<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Join row applying part of a BillPayment to one Bill; unique on
 * (bill_payment_id, bill_id). Created only through
 * BillPayment::allocateToBill(), which checks the payment's
 * unallocated balance under a row lock; both FKs cascade on delete.
 */
class BillPaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function billPayment(): BelongsTo
    {
        return $this->belongsTo(BillPayment::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * Get formatted amount
     */
    public function getFormattedAmountAttribute(): string
    {
        return config('australian.currency.symbol', 'A$').number_format($this->amount, 2);
    }
}
