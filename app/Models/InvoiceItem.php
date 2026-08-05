<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'time_entry_id',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
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
     * Create from time entry
     */
    public static function createFromTimeEntry(TimeEntry $timeEntry): self
    {
        $description = $timeEntry->description ?: 'Professional services';
        if ($timeEntry->project) {
            $description = $timeEntry->project->name . ' - ' . $description;
        }
        
        $item = new self([
            'time_entry_id' => $timeEntry->id,
            'description' => $description,
            'quantity' => $timeEntry->hours,
            'unit_price' => $timeEntry->effective_rate,
            'tax_rate' => config('australian.gst.rate', 10),
            'sort_order' => 0,
        ]);
        
        $item->calculateTotals();
        
        return $item;
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
