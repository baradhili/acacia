<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'amount',
        'allocation_type',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // Allocation type constants
    const TYPE_FIFO = 'fifo';
    const TYPE_MANUAL = 'manual';

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
        return 'A$' . number_format($this->amount, 2);
    }

    /**
     * Scope for FIFO allocations
     */
    public function scopeFifo($query)
    {
        return $query->where('allocation_type', self::TYPE_FIFO);
    }

    /**
     * Scope for manual allocations
     */
    public function scopeManual($query)
    {
        return $query->where('allocation_type', self::TYPE_MANUAL);
    }
}
