<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationHistory extends Model
{
    use HasFactory;

    protected $table = 'reconciliation_history';

    const ACTION_AUTO_MATCH = 'auto_match';
    const ACTION_MANUAL_MATCH = 'manual_match';
    const ACTION_AUTO_CREATE_RECEIPT = 'auto_create_receipt';
    const ACTION_AUTO_CREATE_EXPENSE = 'auto_create_expense';
    const ACTION_AUTO_CREATE_BILL = 'auto_create_bill';
    const ACTION_IGNORE = 'ignore';
    const ACTION_UNMATCH = 'unmatch';
    const ACTION_UNIGNORE = 'unignore';

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'bank_transaction_id',
        'action',
        'status',
        'linked_transaction_id',
        'linked_transaction_type',
        'details',
        'notes',
        'user_id',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForTransaction($query, int $transactionId)
    {
        return $query->where('bank_transaction_id', $transactionId);
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function getLinkedTransaction(): ?Model
    {
        if (!$this->linked_transaction_id || !$this->linked_transaction_type) {
            return null;
        }

        return match ($this->linked_transaction_type) {
            'payment' => Payment::find($this->linked_transaction_id),
            'bill', 'expense' => Bill::find($this->linked_transaction_id),
            'invoice' => Invoice::find($this->linked_transaction_id),
            'ledger' => \IFRS\Models\Ledger::find($this->linked_transaction_id),
            default => null,
        };
    }
}
