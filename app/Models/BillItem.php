<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'bill_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'discount_percent',
        'discount_amount',
        'total',
        'expense_account_id',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($item) {
            $item->calculateTotals();
        });

        // Recalculate parent bill totals when items change
        static::saved(function ($item) {
            if ($item->bill_id) {
                $item->bill->recalculateTotals();
            }
        });

        static::deleted(function ($item) {
            if ($item->bill_id) {
                $item->bill->recalculateTotals();
            }
        });
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * The IFRS expense account this line posts to. Nullable for legacy
     * converted rows; posting falls back to the default expense account.
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(\IFRS\Models\Account::class, 'expense_account_id');
    }

    /**
     * Calculate item totals.
     *
     * The entered amount is what you PAY: Australian supplier bills quote
     * GST-inclusive totals, and small purchases often show nothing else.
     * The line total (qty × unit price, less discount) is therefore the
     * tax-inclusive amount, and the GST portion is back-calculated from it
     * (at 10%: $110 → $10 GST, $100 pre-tax).
     *
     * Per-line GST: tax_rate 10 means the amount includes GST (the payment
     * posting backs the GST out to the GST account), tax_rate 0 is GST-free
     * (some supplies are GST-free by regulation — bank fees, rego, basic
     * food…).
     */
    public function calculateTotals(): void
    {
        $gross = $this->quantity * $this->unit_price;

        // Calculate discount
        if ($this->discount_percent > 0) {
            $this->discount_amount = $gross * ($this->discount_percent / 100);
        }

        // Tax-inclusive line total — the amount actually paid
        $this->total = $gross - $this->discount_amount;

        // Back-calculate the GST portion from the inclusive total
        $rate = (float) $this->tax_rate;
        $this->tax_amount = $rate > 0
            ? round($this->total * $rate / (100 + $rate), 2)
            : 0;
    }

    /**
     * Get subtotal (before tax, after discount)
     */
    public function getSubtotalAttribute(): float
    {
        return round((float) $this->total - (float) $this->tax_amount, 2);
    }

    /**
     * Is this line GST-free (no claimable GST by regulation)?
     */
    public function getIsGstFreeAttribute(): bool
    {
        return (float) $this->tax_rate == 0;
    }

    /**
     * Get formatted unit price
     */
    public function getFormattedUnitPriceAttribute(): string
    {
        return config('australian.currency.symbol', 'A$') . number_format($this->unit_price, 2);
    }

    /**
     * Get formatted total
     */
    public function getFormattedTotalAttribute(): string
    {
        return config('australian.currency.symbol', 'A$') . number_format($this->total, 2);
    }
}
