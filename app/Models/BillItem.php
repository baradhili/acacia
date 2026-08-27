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
        'gst_added',
        'tax_amount',
        'discount_percent',
        'discount_amount',
        'total',
        'expense_account_id',
        'is_prepaid',
        'service_start',
        'service_end',
        'amortise_to_account_id',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'gst_added' => 'boolean',
        'tax_amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'is_prepaid' => 'boolean',
        'service_start' => 'date',
        'service_end' => 'date',
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
     * The account prepaid amounts are amortised to (defaults to the
     * subscription expense account when null).
     */
    public function amortiseAccount(): BelongsTo
    {
        return $this->belongsTo(\IFRS\Models\Account::class, 'amortise_to_account_id');
    }

    /**
     * Ex-GST funded amount for this line (what a prepayment carries).
     */
    public function getNetAmountAttribute(): float
    {
        return round((float) $this->total - (float) $this->tax_amount, 2);
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
     * Per-line GST mode:
     * - gst_added, tax_rate > 0: "Add GST" — the entered amount is ex-GST
     *   (suppliers who quote ex-GST lines and add GST at the subtotal), so
     *   GST goes on top: $100 → $110 with $10 GST.
     * - !gst_added, tax_rate > 0: "Incl. GST" — the entered amount is the
     *   amount paid and the GST portion is back-calculated: $110 → $100
     *   + $10 GST.
     * - tax_rate 0: GST-free (by regulation — bank fees, rego, basic food…).
     */
    public function calculateTotals(): void
    {
        $gross = $this->quantity * $this->unit_price;

        // Calculate discount
        if ($this->discount_percent > 0) {
            $this->discount_amount = $gross * ($this->discount_percent / 100);
        }

        $afterDiscount = $gross - $this->discount_amount;
        $rate = (float) $this->tax_rate;

        if ($rate <= 0) {
            $this->total = $afterDiscount;
            $this->tax_amount = 0;
        } elseif ($this->gst_added) {
            // Ex-GST entry: GST is added on top of the discounted amount
            $this->tax_amount = round($afterDiscount * $rate / 100, 2);
            $this->total = round($afterDiscount + $this->tax_amount, 2);
        } else {
            // Inclusive entry: the amount paid, GST backed out of it
            $this->total = $afterDiscount;
            $this->tax_amount = round($this->total * $rate / (100 + $rate), 2);
        }
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
