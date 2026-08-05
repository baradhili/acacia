<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_number',
        'client_id',
        'received_by',
        'amount',
        'payment_date',
        'payment_method',
        'reference',
        'notes',
        'ifrs_receipt_id',
        'credit_note_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    // Payment method constants
    const METHOD_BANK_TRANSFER = 'bank_transfer';
    const METHOD_CREDIT_CARD = 'credit_card';
    const METHOD_CASH = 'cash';
    const METHOD_CHEQUE = 'cheque';
    const METHOD_OTHER = 'other';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            if (empty($payment->payment_number)) {
                $payment->payment_number = self::generatePaymentNumber();
            }
            if (empty($payment->payment_date)) {
                $payment->payment_date = now()->toDateString();
            }
        });
    }

    public static function generatePaymentNumber(): string
    {
        $year = date('Y');
        $lastPayment = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPayment) {
            preg_match('/PAY-' . $year . '-(\d+)/', $lastPayment->payment_number, $matches);
            $nextNumber = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('PAY-%s-%04d', $year, $nextNumber);
    }

    public static function paymentMethods(): array
    {
        return [
            self::METHOD_BANK_TRANSFER => 'Bank Transfer',
            self::METHOD_CREDIT_CARD => 'Credit Card',
            self::METHOD_CASH => 'Cash',
            self::METHOD_CHEQUE => 'Cheque',
            self::METHOD_OTHER => 'Other',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Get the credit note this payment is linked to (for credit notes/refunds)
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Check if this is a credit/refund payment (negative amount)
     */
    public function getIsCreditAttribute(): bool
    {
        return $this->amount < 0;
    }

    /**
     * Get amount allocated to invoices
     */
    public function getAllocatedAmountAttribute(): float
    {
        return $this->allocations()->sum('amount');
    }

    /**
     * Get amount remaining to be allocated
     */
    public function getUnallocatedAmountAttribute(): float
    {
        return max(0, (float) $this->amount - $this->allocated_amount);
    }

    /**
     * Check if payment is fully allocated
     */
    public function getIsFullyAllocatedAttribute(): bool
    {
        return $this->unallocated_amount <= 0;
    }

    /**
     * Allocate payment to invoices using FIFO (oldest first)
     */
    public function allocateToInvoicesFIFO(): void
    {
        $remainingAmount = $this->unallocated_amount;
        
        if ($remainingAmount <= 0) {
            return;
        }

        // Get outstanding invoices for this client, ordered by due date (oldest first)
        $invoices = Invoice::where('client_id', $this->client_id)
            ->whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_VIEWED, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_OVERDUE])
            ->orderBy('due_date')
            ->get();

        foreach ($invoices as $invoice) {
            if ($remainingAmount <= 0) {
                break;
            }

            $invoiceAmountDue = $invoice->amount_due;
            
            if ($invoiceAmountDue <= 0) {
                continue;
            }

            $allocationAmount = min($remainingAmount, $invoiceAmountDue);
            
            PaymentAllocation::create([
                'payment_id' => $this->id,
                'invoice_id' => $invoice->id,
                'amount' => $allocationAmount,
                'allocation_type' => 'fifo',
            ]);

            $remainingAmount -= $allocationAmount;

            // Update invoice status
            $invoice->updateStatusFromPayments();
        }
    }

    /**
     * Allocate payment to specific invoice
     */
    public function allocateToInvoice(Invoice $invoice, float $amount): PaymentAllocation
    {
        // Ensure we don't allocate more than available
        $amount = min($amount, $this->unallocated_amount);
        
        $allocation = PaymentAllocation::firstOrCreate(
            [
                'payment_id' => $this->id,
                'invoice_id' => $invoice->id,
            ],
            [
                'amount' => $amount,
                'allocation_type' => 'manual',
            ]
        );

        // Update allocation amount if it already exists
        if ($allocation->wasRecentlyCreated === false) {
            $allocation->increment('amount', $amount);
        }

        // Update invoice status
        $invoice->updateStatusFromPayments();

        return $allocation;
    }

    /**
     * Remove allocation from an invoice
     */
    public function removeAllocation(Invoice $invoice): bool
    {
        $allocation = $this->allocations()->where('invoice_id', $invoice->id)->first();
        
        if ($allocation) {
            $allocation->delete();
            $invoice->updateStatusFromPayments();
            return true;
        }
        
        return false;
    }

    /**
     * Update status of all allocated invoices
     */
    public function updateAllocatedInvoicesStatus(): void
    {
        foreach ($this->allocations as $allocation) {
            $allocation->invoice->updateStatusFromPayments();
        }
    }

    /**
     * Get formatted amount
     */
    public function getFormattedAmountAttribute(): string
    {
        return 'A$' . number_format($this->amount, 2);
    }

    /**
     * Get formatted payment method
     */
    public function getFormattedMethodAttribute(): string
    {
        return self::paymentMethods()[$this->payment_method] ?? $this->payment_method;
    }

    /**
     * Scope for payments by client
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope for payments in date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('payment_date', [$startDate, $endDate]);
    }
}
