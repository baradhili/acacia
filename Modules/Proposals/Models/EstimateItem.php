<?php

namespace Modules\Proposals\Models;

use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quoted line on an estimate. Totals (discount, tax, total) are
 * derived on every save from quantity × unit_price — never set them
 * directly; they are recalculated before any dirty state persists.
 * A line may reference a catalogue Service and stay linked to the
 * rate card even when the description or price is tailored, carry a
 * section label for grouping, and be optional (an extra quoted
 * outside the estimate's committed totals).
 */
class EstimateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_id',
        'service_id',
        'section',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'discount_percent',
        'discount_amount',
        'total',
        'sort_order',
        'is_optional',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'is_optional' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($item) {
            $item->calculateTotals();
        });
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * The catalogue service this line was quoted from, if any — the
     * link keeps the estimate tied to the standard rate card even
     * after the description or price is tailored.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Calculate item totals
     */
    public function calculateTotals(): void
    {
        $subtotal = $this->quantity * $this->unit_price;

        // Calculate discount
        if ($this->discount_percent > 0) {
            $this->discount_amount = $subtotal * ($this->discount_percent / 100);
        }

        $afterDiscount = $subtotal - $this->discount_amount;

        // Calculate tax
        $this->tax_amount = $afterDiscount * ($this->tax_rate / 100);

        // Calculate total including tax
        $this->total = $afterDiscount + $this->tax_amount;
    }

    /**
     * Get subtotal (before tax, after discount)
     */
    public function getSubtotalAttribute(): float
    {
        return ($this->quantity * $this->unit_price) - $this->discount_amount;
    }

    /**
     * Convert to invoice item
     */
    public function toInvoiceItem(): InvoiceItem
    {
        return new InvoiceItem([
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'tax_rate' => $this->tax_rate,
            'discount_percent' => $this->discount_percent,
            'discount_amount' => $this->discount_amount,
            'sort_order' => $this->sort_order,
        ]);
    }

    /**
     * Get formatted unit price
     */
    public function getFormattedUnitPriceAttribute(): string
    {
        return config('australian.currency.symbol', 'A$').number_format($this->unit_price, 2);
    }

    /**
     * Get formatted total
     */
    public function getFormattedTotalAttribute(): string
    {
        return config('australian.currency.symbol', 'A$').number_format($this->total, 2);
    }
}
