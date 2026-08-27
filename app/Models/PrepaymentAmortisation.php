<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One monthly amortisation entry of a Prepayment, dated at a month-end
 * of the service period. ifrs_transaction_id links the posted
 * JournalEntry; reversal_transaction_id its mirrored reversal when the
 * entry was reversed (posted entries are never mutated or deleted).
 */
class PrepaymentAmortisation extends Model
{
    protected $fillable = [
        'prepayment_id',
        'period_date',
        'amount',
        'ifrs_transaction_id',
        'reversal_transaction_id',
        'reversed_at',
    ];

    protected $casts = [
        'period_date' => 'date',
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function prepayment(): BelongsTo
    {
        return $this->belongsTo(Prepayment::class);
    }

    public function isPosted(): bool
    {
        return $this->ifrs_transaction_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
