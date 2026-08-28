<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A declared dividend for one share class. Draft declarations are freely
 * editable and their distribution lines can be recalculated; approval
 * locks the calculation and posts the declaration journal (Dr Dividends
 * Paid / Cr Dividends Payable — franking credits never enter the GL).
 * Recording the manually-paid run posts the payment journal, creates the
 * franking debit and emails shareholder statements.
 */
class DividendDeclaration extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const DIVIDEND_TYPE_INTERIM = 'I';
    public const DIVIDEND_TYPE_FINAL = 'F';
    public const DIVIDEND_TYPE_SPECIAL = 'S';

    protected $fillable = [
        'declaration_number',
        'entity_id',
        'declaration_date',
        'financial_year',
        'share_class_id',
        'dividend_type',
        'amount_per_share',
        'franking_percentage',
        'franking_credit_rate',
        'payment_date',
        'books_close_date',
        'total_shares_eligible',
        'total_cash_dividend',
        'total_franking_credit',
        'total_grossed_up',
        'status',
        'approved_by',
        'approved_at',
        'paid_at',
        'ifrs_declaration_transaction_id',
        'ifrs_payment_transaction_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'declaration_date' => 'date',
        'payment_date' => 'date',
        'books_close_date' => 'date',
        'financial_year' => 'integer',
        'total_shares_eligible' => 'integer',
        'amount_per_share' => 'decimal:6',
        'franking_percentage' => 'decimal:2',
        'franking_credit_rate' => 'decimal:2',
        'total_cash_dividend' => 'decimal:2',
        'total_franking_credit' => 'decimal:2',
        'total_grossed_up' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [], // paid dividends cannot be cancelled — post a new reversing run
        self::STATUS_CANCELLED => [],
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($declaration) {
            if (empty($declaration->declaration_number)) {
                $declaration->declaration_number = self::generateDeclarationNumber();
            }
            if (empty($declaration->status)) {
                $declaration->status = self::STATUS_DRAFT;
            }
        });
    }

    public static function generateDeclarationNumber(): string
    {
        $year = date('Y');
        $last = self::whereYear('created_at', $year)->orderByDesc('id')->first();

        if ($last) {
            preg_match('/DIV-' . $year . '-(\d+)/', $last->declaration_number, $matches);
            $nextNumber = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('DIV-%s-%04d', $year, $nextNumber);
    }

    /**
     * Same duplicate-number race guard as Payment::createWithUniqueNumber().
     */
    public static function createWithUniqueNumber(array $attributes): self
    {
        $attempts = 5;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return self::create($attributes);
            } catch (\Illuminate\Database\QueryException $e) {
                $errorInfo = $e->errorInfo ?? [];
                $isUniqueViolation = ($errorInfo[0] ?? null) === '23000' || ($errorInfo[1] ?? null) === 1062;
                if (!$isUniqueViolation || $i === $attempts) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('Unable to create dividend declaration with a unique number.');
    }

    public static function dividendTypes(): array
    {
        return [
            self::DIVIDEND_TYPE_INTERIM => 'Interim',
            self::DIVIDEND_TYPE_FINAL => 'Final',
            self::DIVIDEND_TYPE_SPECIAL => 'Special',
        ];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => $this->status,
        };
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::$transitions[$this->status] ?? [], true);
    }

    public function transitionTo(string $status): void
    {
        if (!$this->canTransitionTo($status)) {
            throw new \InvalidArgumentException(
                "A {$this->status} dividend declaration cannot move to {$status}."
            );
        }
        $this->status = $status;
        $this->save();
    }

    /**
     * The franked portion of the cash dividend (ATO label 8-J); the
     * remainder is unfranked (label 8-K).
     */
    public function frankedCashPortion(): float
    {
        return round((float) $this->total_cash_dividend * ((float) $this->franking_percentage / 100), 2);
    }

    public function unfrankedCashPortion(): float
    {
        return round((float) $this->total_cash_dividend - $this->frankedCashPortion(), 2);
    }

    public function shareClass(): BelongsTo
    {
        return $this->belongsTo(ShareClass::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(DividendDistribution::class, 'dividend_declaration_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
