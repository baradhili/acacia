<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One amendment to a contract-type purchase order: a snapshot of the
 * terms before and after (rate, allocation, period, implied budget),
 * the reason given and who recorded it. Created only through
 * PurchaseOrder::amend(), which numbers it from the parent's
 * document number (-A1, -A2, …) and applies the new terms to the
 * parent in the same write. Purchase-order-type documents are never
 * amended — their budget is fixed for life.
 */
class PurchaseOrderAmendment extends Model
{
    protected $fillable = [
        'amendment_number',
        'previous_rate',
        'previous_allocation',
        'previous_start_date',
        'previous_end_date',
        'previous_budgeted_amount',
        'new_rate',
        'new_allocation',
        'new_start_date',
        'new_end_date',
        'new_budgeted_amount',
        'reason',
    ];

    protected $casts = [
        'previous_rate' => 'decimal:2',
        'previous_allocation' => 'decimal:2',
        'previous_start_date' => 'date',
        'previous_end_date' => 'date',
        'previous_budgeted_amount' => 'decimal:2',
        'new_rate' => 'decimal:2',
        'new_allocation' => 'decimal:2',
        'new_start_date' => 'date',
        'new_end_date' => 'date',
        'new_budgeted_amount' => 'decimal:2',
    ];

    /**
     * The contract this amendment modifies.
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * The user who recorded the amendment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
