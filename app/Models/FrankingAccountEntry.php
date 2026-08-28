<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the notional franking account. Credits increase the balance
 * (income tax paid, franked dividends received, FDT paid), debits decrease
 * it (franked dividends paid, tax refunds). The account is a lifetime
 * running balance — the financial_year column groups entries for reporting,
 * and the balance itself is always computed, never stored.
 *
 * FD (franked dividend paid) entries are system-generated when a dividend
 * run is recorded as paid; the other types are entered manually. Entries
 * flagged is_estimated record AASB 1054.13 anticipated movements (e.g.
 * franking credits expected from the current tax provision) and are
 * excluded from the actual balance.
 */
class FrankingAccountEntry extends Model
{
    public const TYPE_TAX_PAYMENT = 'TC';
    public const TYPE_DIVIDEND_RECEIVED = 'DR';
    public const TYPE_FRANKED_DIVIDEND_PAID = 'FD';
    public const TYPE_REFUND_RECEIVED = 'RF';
    public const TYPE_FDT_PAID = 'FT';
    public const TYPE_ADJUSTMENT = 'AJ';

    /** Types that can be created manually from the franking account screen. */
    public const MANUAL_TYPES = [
        self::TYPE_TAX_PAYMENT,
        self::TYPE_DIVIDEND_RECEIVED,
        self::TYPE_REFUND_RECEIVED,
        self::TYPE_FDT_PAID,
        self::TYPE_ADJUSTMENT,
    ];

    protected $fillable = [
        'entity_id',
        'financial_year',
        'entry_date',
        'entry_type',
        'reference',
        'description',
        'credit_amount',
        'debit_amount',
        'is_estimated',
        'dividend_declaration_id',
        'ifrs_transaction_id',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'financial_year' => 'integer',
        'credit_amount' => 'decimal:2',
        'debit_amount' => 'decimal:2',
        'is_estimated' => 'boolean',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_TAX_PAYMENT => 'Income tax paid',
            self::TYPE_DIVIDEND_RECEIVED => 'Franked dividend received',
            self::TYPE_FRANKED_DIVIDEND_PAID => 'Franked dividend paid',
            self::TYPE_REFUND_RECEIVED => 'Tax refund received',
            self::TYPE_FDT_PAID => 'Franking deficit tax paid',
            self::TYPE_ADJUSTMENT => 'Adjustment',
        ];
    }

    public static function manualTypes(): array
    {
        return collect(self::types())
            ->only(self::MANUAL_TYPES)
            ->all();
    }

    public function typeLabel(): string
    {
        return self::types()[$this->entry_type] ?? $this->entry_type;
    }

    /**
     * Credit-positive net effect on the franking balance.
     */
    public function netAmount(): float
    {
        return round((float) $this->credit_amount - (float) $this->debit_amount, 2);
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(DividendDeclaration::class, 'dividend_declaration_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForYear($query, int $financialYear)
    {
        return $query->where('financial_year', $financialYear);
    }

    public function scopeActual($query)
    {
        return $query->where('is_estimated', false);
    }

    public function scopeEstimated($query)
    {
        return $query->where('is_estimated', true);
    }
}
