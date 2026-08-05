<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Payment::class, 'credit_note_id');
    }

    /**
     * Check if credit note has remaining balance
     */
    public function hasRemainingBalance(): bool
    {
        return $this->remaining_amount > 0;
    }

    /**
     * Apply credit note to invoice
     */
    public function applyToInvoice(Invoice $invoice): bool
    {
        if (!$this->hasRemainingBalance()) {
            return false;
        }

        // Create a payment with negative amount (credit)
        $refund = Payment::create([
            'client_id' => $this->client_id,
            'amount' => -$this->remaining_amount, // Negative to indicate credit
            'payment_date' => now()->toDateString(),
            'payment_method' => Payment::METHOD_OTHER,
            'reference' => 'Credit Note ' . $this->credit_note_number,
            'notes' => 'Applied from Credit Note ' . $this->credit_note_number,
        ]);

        // Update credit note
        $this->update([
            'status' => self::STATUS_APPLIED,
            'applied_at' => now(),
            'applied_amount' => $this->total,
            'remaining_amount' => 0,
        ]);

        return true;
    }

    /**
     * Void the credit note
     */
    public function void(): bool
    {
        if ($this->status === self::STATUS_APPLIED) {
            return false;
        }

        $this->update(['status' => self::STATUS_VOID]);
        return true;
    }

    /**
     * Get formatted total
     */
    public function getFormattedTotalAttribute(): string
    {
        return 'A$' . number_format($this->total, 2);
    }

    /**
     * Get formatted remaining amount
     */
    public function getFormattedRemainingAttribute(): string
    {
        return 'A$' . number_format($this->remaining_amount, 2);
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
}
