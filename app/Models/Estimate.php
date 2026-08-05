<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estimate extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_number',
        'client_id',
        'project_id',
        'created_by',
        'status',
        'issue_date',
        'valid_until',
        'converted_at',
        'converted_to_invoice_id',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'notes',
        'terms',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'valid_until' => 'date',
        'converted_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_SENT = 'sent';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CONVERTED = 'converted';

    // Valid state transitions
    protected static array $transitions = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['accepted', 'rejected', 'expired'],
        'accepted' => ['converted'],
        'rejected' => [],
        'expired' => ['sent'],
        'converted' => [],
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($estimate) {
            if (empty($estimate->estimate_number)) {
                $estimate->estimate_number = self::generateEstimateNumber();
            }
            if (empty($estimate->status)) {
                $estimate->status = self::STATUS_DRAFT;
            }
            if (empty($estimate->issue_date)) {
                $estimate->issue_date = now()->toDateString();
            }
            if (empty($estimate->valid_until)) {
                $estimate->valid_until = now()->addDays(30)->toDateString();
            }
        });

        static::saved(function ($estimate) {
            $estimate->recalculateTotals();
        });
    }

    public static function generateEstimateNumber(): string
    {
        $year = date('Y');
        $lastEstimate = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastEstimate) {
            preg_match('/EST-' . $year . '-(\d+)/', $lastEstimate->estimate_number, $matches);
            $nextNumber = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('EST-%s-%04d', $year, $nextNumber);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('sort_order');
    }

    public function convertedToInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_to_invoice_id');
    }

    /**
     * Recalculate estimate totals from items
     */
    public function recalculateTotals(): void
    {
        // Unset cached items to ensure we get fresh data from DB
        $this->unsetRelation('items');
        $items = $this->items;

        $subtotal = $items->sum('total');
        $taxAmount = $items->sum('tax_amount');
        $discountAmount = $items->sum('discount_amount');
        $total = $subtotal;

        // Use withoutEvents to prevent saved event from triggering recalculateTotals again
        static::withoutEvents(function () use ($subtotal, $taxAmount, $discountAmount, $total) {
            $this->updateQuietly([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total' => $total,
            ]);
        });
    }

    /**
     * Check if estimate is expired
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->valid_until && $this->valid_until->lt(now()->toDateString());
    }

    /**
     * Check if a transition to the given status is valid
     */
    public function canTransitionTo(string $status): bool
    {
        $allowedTransitions = self::$transitions[$this->status] ?? [];
        return in_array($status, $allowedTransitions);
    }

    /**
     * Get valid transitions from current status
     */
    public function getValidTransitions(): array
    {
        return self::$transitions[$this->status] ?? [];
    }

    /**
     * Transition to a new status with validation
     */
    public function transitionTo(string $status): bool
    {
        if (!$this->canTransitionTo($status)) {
            return false;
        }

        $this->update(['status' => $status]);
        return true;
    }

    /**
     * Mark estimate as sent
     */
    public function markAsSent(): bool
    {
        return $this->transitionTo(self::STATUS_SENT);
    }

    /**
     * Mark estimate as accepted
     */
    public function accept(): bool
    {
        return $this->transitionTo(self::STATUS_ACCEPTED);
    }

    /**
     * Mark estimate as rejected
     */
    public function reject(): bool
    {
        return $this->transitionTo(self::STATUS_REJECTED);
    }

    /**
     * Convert estimate to invoice
     */
    public function convertToInvoice(): ?Invoice
    {
        if (!$this->canTransitionTo(self::STATUS_CONVERTED)) {
            return null;
        }

        // Ensure fresh items data
        $this->unsetRelation('items');

        $invoice = Invoice::create([
            'client_id' => $this->client_id,
            'project_id' => $this->project_id,
            'created_by' => $this->created_by,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'notes' => $this->notes,
            'terms' => $this->terms,
        ]);

        // Copy items
        foreach ($this->items as $estimateItem) {
            $invoice->items()->create([
                'description' => $estimateItem->description,
                'quantity' => $estimateItem->quantity,
                'unit_price' => $estimateItem->unit_price,
                'tax_rate' => $estimateItem->tax_rate,
                'discount_percent' => $estimateItem->discount_percent,
                'discount_amount' => $estimateItem->discount_amount,
                'sort_order' => $estimateItem->sort_order,
            ]);
        }

        $invoice->recalculateTotals();

        $this->update([
            'status' => self::STATUS_CONVERTED,
            'converted_at' => now(),
            'converted_to_invoice_id' => $invoice->id,
        ]);

        return $invoice;
    }

    /**
     * Get formatted total
     */
    public function getFormattedTotalAttribute(): string
    {
        return 'A$' . number_format($this->total, 2);
    }

    /**
     * Scope for draft estimates
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope for active estimates (not rejected, expired, or converted)
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_REJECTED,
            self::STATUS_EXPIRED,
            self::STATUS_CONVERTED,
        ]);
    }

    /**
     * Scope for expired estimates
     */
    public function scopeExpired($query)
    {
        return $query->where('valid_until', '<', now()->toDateString())
            ->whereNotIn('status', [self::STATUS_CONVERTED, self::STATUS_REJECTED]);
    }
}
