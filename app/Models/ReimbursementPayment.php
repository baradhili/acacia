<?php

namespace App\Models;

use App\Services\IfrsPosting;
use IFRS\Models\Account;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Modules\Payroll\Models\Employee;

/**
 * The pay-back leg of an employee-paid expense: money the company owes
 * (and pays) to an employee who financed a purchase out of pocket.
 *
 * The capture leg is a BillPayment with payment_method
 * employee_reimbursement, which credits Employee Reimbursements Payable
 * (2280) instead of the bank. This payment clears that liability:
 *
 *   Dr Employee Reimbursements Payable (2280, line item)
 *   Cr Bank                            (320, main account)
 *
 * Posting happens on creation — the expense itself was already approved
 * before its capture posted. The GST input credit was claimed at
 * capture, so no Vat leg belongs here. Reconciliation matches the bank
 * debit against this record ("reimbursement_payment" match type).
 */
class ReimbursementPayment extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';

    const STATUS_COMPLETED = 'completed';

    const STATUS_VOID = 'void';

    protected $fillable = [
        'payment_number',
        'employee_id',
        'paid_by',
        'amount',
        'payment_date',
        'payment_method',
        'reference',
        'notes',
        'status',
        'ifrs_transaction_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    /**
     * Reason of the most recent postToIFRS() failure (null after a
     * success or an already-posted skip), so callers can report it.
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
            preg_match('/REIMB-'.$year.'-(\d+)/', $lastPayment->payment_number, $matches);
            $nextNumber = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('REIMB-%s-%04d', $year, $nextNumber);
    }

    /**
     * Create with a retry on payment_number races — same pattern as
     * BillPayment::createWithUniqueNumber().
     */
    public static function createWithUniqueNumber(array $attributes): self
    {
        $attempts = 5;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return self::create($attributes);
            } catch (QueryException $e) {
                $errorInfo = $e->errorInfo ?? [];
                $isUnique = ($errorInfo[0] ?? null) === '23000' || ($errorInfo[1] ?? null) === 1062;
                if (! $isUnique || $i === $attempts) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('Unable to create reimbursement payment with a unique number.');
    }

    public static function paymentMethods(): array
    {
        // The company paying the employee back — no employee_reimbursement
        // here; that method means the employee paid, not the company.
        return [
            BillPayment::METHOD_BANK_TRANSFER => 'Bank Transfer',
            BillPayment::METHOD_CREDIT_CARD => 'Credit Card',
            BillPayment::METHOD_CASH => 'Cash',
            BillPayment::METHOD_CHEQUE => 'Cheque',
            BillPayment::METHOD_OTHER => 'Other',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * What the company still owes an employee: approved (completed)
     * employee-paid supplier payments not yet cleared by completed
     * reimbursement payments. Pending captures are excluded — they have
     * not hit the ledger yet.
     */
    public static function outstandingFor(int $employeeId): float
    {
        $captured = (float) BillPayment::query()
            ->where('employee_id', $employeeId)
            ->where('payment_method', BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT)
            ->where('status', BillPayment::STATUS_COMPLETED)
            ->sum('amount');

        $reimbursed = (float) self::query()
            ->where('employee_id', $employeeId)
            ->where('status', self::STATUS_COMPLETED)
            ->sum('amount');

        return round($captured - $reimbursed, 2);
    }

    public function getFormattedAmountAttribute(): string
    {
        return config('australian.currency.symbol', 'A$').number_format($this->amount, 2);
    }

    public function getFormattedMethodAttribute(): string
    {
        return self::paymentMethods()[$this->payment_method] ?? $this->payment_method;
    }

    /**
     * Post to IFRS: Cr Bank (320, main) / Dr Employee Reimbursements
     * Payable (2280, line). Returns the IFRS transaction id, or null on
     * failure with the reason on $this->lastPostingError.
     */
    public function postToIFRS(): ?int
    {
        $this->lastPostingError = null;

        if ($this->ifrs_transaction_id) {
            Log::info("Reimbursement payment {$this->id} already posted to IFRS", [
                'ifrs_transaction_id' => $this->ifrs_transaction_id,
            ]);

            return (int) $this->ifrs_transaction_id;
        }

        if ($this->status === self::STATUS_VOID) {
            $this->lastPostingError = 'reimbursement payment is void — voided payments are never posted';
            Log::info("Reimbursement payment {$this->id} is void; not posting to IFRS");

            return null;
        }

        if ($this->status === self::STATUS_PENDING) {
            $this->lastPostingError = 'reimbursement payment is pending — complete it before posting';
            Log::info("Reimbursement payment {$this->id} is pending; not posting to IFRS");

            return null;
        }

        try {
            $entity = IfrsPosting::resolveEntity();
            if (! $entity) {
                $this->lastPostingError = 'no IFRS entity';
                Log::error('No IFRS entity available for reimbursement payment posting', [
                    'reimbursement_payment_id' => $this->id,
                ]);

                return null;
            }

            $bankAccount = Account::where('code', BillPayment::IFRS_BANK_ACCOUNT_CODE)->first();
            $payableAccount = BillPayment::ensureReimbursementAccount($entity);

            if (! $bankAccount) {
                $this->lastPostingError = 'IFRS bank account not found ('
                    .BillPayment::IFRS_BANK_ACCOUNT_CODE.')';
                Log::error('IFRS bank account not found for reimbursement payment posting', [
                    'bank_code' => BillPayment::IFRS_BANK_ACCOUNT_CODE,
                ]);

                return null;
            }

            IfrsPosting::ensureReportingPeriod($this->payment_date, $entity);

            $journalEntry = new JournalEntry([
                'transaction_date' => IfrsPosting::transactionDate($this->payment_date, $entity),
                'account_id' => $bankAccount->id,
                'credited' => true,
                'entity_id' => $entity->id,
                'narration' => "Employee reimbursement: {$this->payment_number} to {$this->employee?->name}",
                'reference' => $this->payment_number,
            ]);

            // Debit the payable; addLineItem() flips credited to false
            // (the transaction is credited) → Dr liability. Persisted
            // before addLineItem() per the package's constraint.
            $payableLine = LineItem::create([
                'account_id' => $payableAccount->id,
                'amount' => (float) $this->amount,
                'quantity' => 1,
                'vat_inclusive' => false,
                'entity_id' => $entity->id,
            ]);
            $journalEntry->addLineItem($payableLine);

            // post() saves the transaction AND writes the ledger rows.
            $journalEntry->post();

            $this->update(['ifrs_transaction_id' => $journalEntry->id]);

            Log::info("Reimbursement payment {$this->id} posted to IFRS", [
                'ifrs_transaction_id' => $journalEntry->id,
                'amount' => $this->amount,
            ]);

            return $journalEntry->id;
        } catch (\Throwable $e) {
            $this->lastPostingError = $e->getMessage();
            Log::error('Failed to post reimbursement payment to IFRS', [
                'reimbursement_payment_id' => $this->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * Void this reimbursement payment. Voiding RAISES the employee's
     * outstanding balance, so — unlike voiding a capture — it can never
     * drive the balance negative and needs no guard.
     */
    public function void(): bool
    {
        if ($this->status === self::STATUS_VOID) {
            return false;
        }

        $this->update(['status' => self::STATUS_VOID]);

        if ($this->ifrs_transaction_id) {
            IfrsPosting::reverseTransaction(
                (int) $this->ifrs_transaction_id,
                "Reversal of employee reimbursement: {$this->payment_number} (voided)",
                $this->payment_number,
            );
        }

        return true;
    }
}
