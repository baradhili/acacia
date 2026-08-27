<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prepaid service contract funded by a bill payment — subscriptions,
 * annual licences, prepaid domain renewals and finite-life intangibles.
 * The prepayments:amortise runner expenses it monthly over the service
 * period (Dr expense_account / Cr asset_account at each month-end).
 */
class Prepayment extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'entity_id',
        'bill_payment_id',
        'bill_item_id',
        'description',
        'asset_account_id',
        'expense_account_id',
        'service_start',
        'service_end',
        'periods',
        'total_amount',
        'monthly_amount',
        'next_period_date',
        'status',
    ];

    protected $casts = [
        'service_start' => 'date',
        'service_end' => 'date',
        'next_period_date' => 'date',
        'periods' => 'integer',
        'total_amount' => 'decimal:2',
        'monthly_amount' => 'decimal:2',
    ];

    public function billPayment(): BelongsTo
    {
        return $this->belongsTo(BillPayment::class);
    }

    public function billItem(): BelongsTo
    {
        return $this->belongsTo(BillItem::class);
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(\IFRS\Models\Account::class, 'asset_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(\IFRS\Models\Account::class, 'expense_account_id');
    }

    public function amortisations(): HasMany
    {
        return $this->hasMany(PrepaymentAmortisation::class)->orderBy('period_date');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Net amount expensed to date: posted entries less their reversals.
     */
    public function amortisedAmount(): float
    {
        return round($this->amortisations()
            ->whereNotNull('ifrs_transaction_id')
            ->whereNull('reversed_at')
            ->sum('amount'), 2);
    }

    public function remainingAmount(): float
    {
        return round((float) $this->total_amount - $this->amortisedAmount(), 2);
    }
}
