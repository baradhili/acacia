<?php

namespace App\Models;

use App\Services\IfrsPosting;
use IFRS\Models\Account;
use IFRS\Models\LineItem;
use IFRS\Models\Vat;
use IFRS\Transactions\JournalEntry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;

class Payment extends Model
{
    use HasFactory;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_VOID = 'void';

    protected $fillable = [
        'payment_number',
        'client_id',
        'received_by',
        'amount',
        'payment_date',
        'payment_method',
        'reference',
        'notes',
        'status',
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

    // IFRS Account codes for payment posting
    const IFRS_BANK_ACCOUNT_CODE = 320; // Operating Account
    const IFRS_REVENUE_ACCOUNT_CODE = 4100; // Consulting Revenue
    const IFRS_GST_VAT_CODE = 'G'; // Seeded "GST 10%" Vat, linked to account 2200 (GST Payable)

    /**
     * Reason of the most recent postToIFRS() failure (null after a success
     * or an already-posted skip), so the backfill command can report it.
     */
    public ?string $lastPostingError = null;

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

    /**
     * Create a payment, retrying if two concurrent requests race on
     * generatePaymentNumber() and produce a duplicate payment_number.
     *
     * The unique() constraint on payment_number is the real source of truth —
     * the loser of a race gets a QueryException (SQLSTATE 23000). Each retry
     * re-enters the creating hook, which regenerates from the now-higher max,
     * so the next attempt picks the following number.
     */
    public static function createWithUniqueNumber(array $attributes): self
    {
        $attempts = 5;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return self::create($attributes);
            } catch (\Illuminate\Database\QueryException $e) {
                if (!self::isUniqueViolation($e) || $i === $attempts) {
                    throw $e;
                }
            }
        }
        // Unreachable — the loop either returns or rethrows.
        throw new \RuntimeException('Unable to create payment with a unique number.');
    }

    /**
     * Is the given QueryException a unique-constraint violation? Covers both
     * MySQL (SQLSTATE 23000 / driver code 1062) and SQLite (SQLSTATE 23000 /
     * driver codes 19, 2067) via the shared SQLSTATE.
     */
    protected static function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        // errorInfo[0] is the SQLSTATE; errorInfo[1] is the driver-specific code.
        return ($errorInfo[0] ?? null) === '23000'
            || ($errorInfo[1] ?? null) === 1062;
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
     * Get the documents attached to this payment
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
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
        // For negative payments (credits), unallocated is the absolute value minus allocations
        if ($this->amount < 0) {
            return abs($this->amount) - $this->allocated_amount;
        }
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
     * Allocate payment to specific invoice.
     *
     * @throws \InvalidArgumentException if $amount is <= 0 or exceeds the
     *         payment's unallocated balance. Callers run inside transactions
     *         so the throw rolls back any partial work cleanly.
     */
    public function allocateToInvoice(Invoice $invoice, float $amount): PaymentAllocation
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Allocation amount must be greater than zero.');
        }

        $unallocated = $this->unallocated_amount;
        if ($amount > $unallocated) {
            throw new \InvalidArgumentException(
                "Cannot allocate {$amount} to invoice {$invoice->id}: "
                . "only {$unallocated} unallocated on payment {$this->id}."
            );
        }

        $allocation = PaymentAllocation::firstOrCreate(
            [
                'payment_id' => $this->id,
                'invoice_id' => $invoice->id,
            ],
            [
                'amount' => $amount,
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
        return config('australian.currency.symbol', 'A$') . number_format($this->amount, 2);
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

    /**
     * Post payment to IFRS using the cash-basis double-entry pattern:
     *
     *   Dr Bank     (account 320, main account)        — tax-inclusive amount
     *   Cr Revenue  (account 4100, vat_inclusive line) — net amount
     *   Cr GST Payable (account 2200, auto via addVat) — GST component
     *
     * DESIGN DECISION — cash basis, do not reverse: invoices are subledger
     * documents only and deliberately NEVER post to IFRS (no Accounts
     * Receivable). Client receipts are the sole revenue ledger event, and
     * revenue is recognised when this payment is received, not when the
     * invoice is issued. Do not "complete" the ledger by posting invoices.
     *
     * The revenue LineItem is marked vat_inclusive with the seeded "GST 10%"
     * Vat (code G); the IFRS package backs the GST out and credits account
     * 2200 automatically, so the ledger reflects true ATO cash-basis receipts.
     *
     * Credit-note refunds (negative payments) post the absolute amount with
     * every leg flipped — Cr Bank / Dr Revenue / Dr GST — because IFRS line
     * items reject negative amounts; the result nets the original receipt
     * to zero.
     *
     * Returns the IFRS transaction id, or null on failure (accounts missing,
     * no entity, void payment, or any Throwable during posting). When null
     * is returned the reason is on $this->lastPostingError.
     */
    public function postToIFRS(): ?int
    {
        $this->lastPostingError = null;

        if ($this->ifrs_receipt_id) {
            Log::info("Payment {$this->id} already posted to IFRS", ['ifrs_receipt_id' => $this->ifrs_receipt_id]);
            return (int) $this->ifrs_receipt_id;
        }

        if ($this->status === self::STATUS_VOID) {
            $this->lastPostingError = 'payment is void — voided payments are never posted';
            Log::info("Payment {$this->id} is void; not posting to IFRS");
            return null;
        }

        try {
            // Find the bank and revenue accounts.
            $bankAccount = Account::where('code', self::IFRS_BANK_ACCOUNT_CODE)->first();
            $revenueAccount = Account::where('code', self::IFRS_REVENUE_ACCOUNT_CODE)->first();

            if (!$bankAccount || !$revenueAccount) {
                $this->lastPostingError = 'IFRS accounts not found (bank '
                    . self::IFRS_BANK_ACCOUNT_CODE . ' / revenue ' . self::IFRS_REVENUE_ACCOUNT_CODE . ')';
                Log::error('IFRS accounts not found for payment posting', [
                    'bank_code' => self::IFRS_BANK_ACCOUNT_CODE,
                    'revenue_code' => self::IFRS_REVENUE_ACCOUNT_CODE,
                ]);
                return null;
            }

            // IFRS transactions need an entity (for the reporting period and
            // currency). Prefer the authed user's entity, then fall back to
            // the first entity. Pass entity_id explicitly so posting works in
            // queued jobs where no user is authed.
            $entity = IfrsPosting::resolveEntity();
            if (!$entity) {
                $this->lastPostingError = 'no IFRS entity';
                Log::error('No IFRS entity available for payment posting', ['payment_id' => $this->id]);
                return null;
            }

            // Create the payment date's FY period if it doesn't exist yet —
            // Transaction::save() throws MissingReportingPeriod otherwise.
            IfrsPosting::ensureReportingPeriod($this->payment_date, $entity);

            $isRefund = (float) $this->amount < 0;

            // Main account = Bank. Receipts: credited = false → Dr Bank
            // (asset increases). Refunds flip to credited = true → Cr Bank.
            $journalEntry = new JournalEntry([
                'transaction_date' => $this->payment_date,
                'account_id' => $bankAccount->id,
                'credited' => $isRefund,
                'entity_id' => $entity->id,
                'narration' => $isRefund
                    ? "Credit note refund: {$this->payment_number} to {$this->client?->name}"
                    : "Payment received: {$this->payment_number} from {$this->client?->name}",
                'reference' => $this->payment_number,
            ]);

            // Revenue line is the tax-inclusive amount with the GST 10% Vat
            // applied. vat_inclusive=true makes the package split the net
            // amount to Revenue and the GST component to account 2200
            // (auto-credited for receipts, auto-debited for refunds — the
            // line legs follow the transaction's credited side).
            $revenueLine = new LineItem([
                'account_id' => $revenueAccount->id,
                'amount' => abs((float) $this->amount),
                'vat_inclusive' => true,
                'entity_id' => $entity->id,
            ]);

            $gstVat = Vat::where('code', self::IFRS_GST_VAT_CODE)
                ->where('entity_id', $entity->id)
                ->first();
            if ($gstVat) {
                $revenueLine->addVat($gstVat);
            }

            $journalEntry->addLineItem($revenueLine);
            // post() saves the transaction AND writes the ledger rows
            // (save() alone leaves it unposted and invisible to reports).
            $journalEntry->post();

            // Store the IFRS transaction id.
            $this->update(['ifrs_receipt_id' => $journalEntry->id]);

            Log::info("Payment {$this->id} posted to IFRS", [
                'ifrs_receipt_id' => $journalEntry->id,
                'amount' => $this->amount,
                'gst_posted' => (bool) $gstVat,
            ]);

            return $journalEntry->id;

        } catch (\Throwable $e) {
            // Throwable (not Exception) so a fatal Error (e.g. undefined
            // constant) is captured and logged rather than breaking the
            // reconciliation flow that calls this.
            $this->lastPostingError = $e->getMessage();
            Log::error('Failed to post payment to IFRS', [
                'payment_id' => $this->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return null;
        }
    }

    /**
     * Check if payment has been posted to IFRS
     */
    public function getIsPostedToIFRSAttribute(): bool
    {
        return $this->ifrs_receipt_id !== null;
    }

    /**
     * Void this payment and restore invoice statuses
     */
    public function void(): bool
    {
        if ($this->status === self::STATUS_VOID) {
            return false;
        }

        // Capture affected invoices, then delete allocations BEFORE
        // recomputing status — otherwise updateStatusFromPayments() still
        // sees the allocations and treats the invoice as paid.
        $invoiceIds = $this->allocations()->pluck('invoice_id');
        $this->allocations()->delete();

        foreach ($invoiceIds as $invoiceId) {
            $invoice = Invoice::find($invoiceId);
            if ($invoice) {
                $invoice->updateStatusFromPayments();
            }
        }

        // Update status to void
        $this->update(['status' => self::STATUS_VOID]);

        // If the payment was already posted, post a mirrored reversing
        // entry so the ledger nets to zero (original stays for audit).
        // Unposted payments just void as above.
        if ($this->ifrs_receipt_id) {
            IfrsPosting::reverseTransaction(
                (int) $this->ifrs_receipt_id,
                "Reversal of payment: {$this->payment_number} (voided)",
                $this->payment_number,
            );
        }

        return true;
    }
}
