<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'discount_percent',
        'discount_amount',
        'total',
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
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
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
        return 'A$' . number_format($this->unit_price, 2);
    }

    /**
     * Get formatted total
     */
    public function getFormattedTotalAttribute(): string
    {
        return 'A$' . number_format($this->total, 2);
    }
}
