<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CreditNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_note_number',
        'client_id',
        'invoice_id',
        'created_by',
        'status',
        'issue_date',
        'applied_at',
        'total',
        'applied_amount',
        'remaining_amount',
        'reason',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'applied_at' => 'datetime',
        'total' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    // Status constants
    const STATUS_ISSUED = 'issued';
    const STATUS_APPLIED = 'applied';
    const STATUS_VOID = 'void';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($creditNote) {
            if (empty($creditNote->credit_note_number)) {
                $creditNote->credit_note_number = self::generateCreditNoteNumber();
            }
            if (empty($creditNote->issue_date)) {
                $creditNote->issue_date = now()->toDateString();
            }
            if (empty($creditNote->status)) {
                $creditNote->status = self::STATUS_ISSUED;
            }
        });
    }

    public static function generateCreditNoteNumber(): string
    {
        $year = date('Y');
        $lastCN = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastCN) {
            preg_match('/CN-' . $year . '-(\d+)/', $lastCN->credit_note_number, $matches);
            $nextNumber = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('CN-%s-%04d', $year, $nextNumber);
    }

    /**
     * A Credit Note is linked to a Client
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * A Credit Note is optionally linked to an Invoice (the invoice it's correcting)
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the user who created this credit note
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Credit note line items
     */
    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    /**
     * Payment/refund created when credit note is applied
     */
    public function refund(): HasOne
    {
        return $this->hasOne(Payment::class, 'credit_note_id');
    }

    /**
     * Check if credit note has remaining balance to apply
     */
    public function hasRemainingBalance(): bool
    {
        return $this->remaining_amount > 0;
    }

    /**
     * Apply this credit note to reduce the balance of an invoice
     * This is a "negative invoice" - it reduces what the client owes
     */
    public function applyToInvoice(Invoice $invoice, ?float $amount = null): bool
    {
        $amountToApply = $amount ?? $this->remaining_amount;

        if ($amountToApply > $this->remaining_amount) {
            $amountToApply = $this->remaining_amount;
        }

        if ($amountToApply <= 0) {
            return false;
        }

        // Verify the invoice belongs to the same client
        if ($invoice->client_id !== $this->client_id) {
            return false;
        }

        // Create a payment with negative amount (credit against the invoice)
        $refund = Payment::create([
            'client_id' => $this->client_id,
            'amount' => -$amountToApply, // Negative to indicate credit
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_OTHER,
            'reference' => 'Credit Note ' . $this->credit_note_number,
            'notes' => 'Credit note ' . $this->credit_note_number . ' applied to invoice ' . $invoice->invoice_number,
        ]);

        // Link the payment to this credit note
        $refund->update(['credit_note_id' => $this->id]);

        // Allocate the negative payment to the invoice
        $refund->allocateToInvoice($invoice, $amountToApply);

        // Update credit note - update amounts
        $newRemainingAmount = $this->remaining_amount - $amountToApply;
        $newAppliedAmount = ($this->applied_amount ?? 0) + $amountToApply;

        // Refresh invoice to get updated amount_due
        $invoice->refresh();
        
        // Set status to APPLIED only when the invoice is fully paid
        $newStatus = $invoice->amount_due <= 0 ? self::STATUS_APPLIED : $this->status;

        $this->update([
            'invoice_id' => $invoice->id,
            'status' => $newStatus,
            'applied_at' => now(),
            'applied_amount' => $newAppliedAmount,
            'remaining_amount' => $newRemainingAmount,
        ]);

        return true;
    }

    /**
     * Void the credit note (can only void issued credit notes)
     */
    public function void(): bool
    {
        // Can only void if some amount remains (not fully or partially applied)
        if ($this->remaining_amount < $this->total) {
            return false;
        }

        $this->update(['status' => self::STATUS_VOID]);
        return true;
    }

    /**
     * Get the credit amount as a positive number for display
     */
    public function getCreditAmountAttribute(): float
    {
        return abs($this->total);
    }

    /**
     * Get formatted total (always shown as negative for credit)
     */
    public function getFormattedTotalAttribute(): string
    {
        return '-$' . number_format($this->total, 2);
    }

    /**
     * Get formatted remaining amount
     */
    public function getFormattedRemainingAttribute(): string
    {
        return '-$' . number_format($this->remaining_amount, 2);
    }

    /**
     * Scope for issued credit notes
     */
    public function scopeIssued($query)
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    /**
     * Scope for active (not void) credit notes
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_VOID);
    }

    /**
     * Scope for credit notes with remaining balance
     */
    public function scopeWithBalance($query)
    {
        return $query->where('remaining_amount', '>', 0);
    }

    /**
     * Scope for credit notes linked to a specific invoice
     */
    public function scopeForInvoice($query, Invoice $invoice)
    {
        return $query->where('invoice_id', $invoice->id);
    }
}
