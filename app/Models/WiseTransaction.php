<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WiseTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wise_id',
        'reference',
        'amount',
        'currency',
        'type',
        'transaction_date',
        'created_at_wise',
        'merchant_name',
        'status',
        'matched_transaction_id',
        'matched_transaction_type',
        'matched_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'created_at_wise' => 'datetime',
        'matched_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'PENDING';
    const STATUS_MATCHED = 'MATCHED';
    const STATUS_IGNORED = 'IGNORED';

    // Type constants
    const TYPE_DEBIT = 'DEBIT';
    const TYPE_CREDIT = 'CREDIT';

    /**
     * Scope for pending transactions
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for matched transactions
     */
    public function scopeMatched($query)
    {
        return $query->where('status', self::STATUS_MATCHED);
    }

    /**
     * Check if transaction is matched
     */
    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED;
    }

    /**
     * Mark transaction as matched
     */
    public function markAsMatched(int $transactionId, string $transactionType): void
    {
        $this->update([
            'status' => self::STATUS_MATCHED,
            'matched_transaction_id' => $transactionId,
            'matched_transaction_type' => $transactionType,
            'matched_at' => now(),
        ]);
    }

    /**
     * Mark transaction as ignored
     */
    public function markAsIgnored(string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_IGNORED,
            'notes' => $notes,
        ]);
    }
}
