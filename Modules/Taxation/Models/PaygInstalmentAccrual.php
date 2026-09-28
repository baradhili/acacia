<?php

namespace Modules\Taxation\Models;

use IFRS\Models\Entity;
use IFRS\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded quarterly PAYG instalment accrual — the estimate journal
 * (Dr income tax expense / Cr income tax payable) that raises the 2240
 * liability the payg_instalment BAS settlement later nets: instalment
 * income for the quarter × the configured instalment rate. Posting
 * logic lives in PaygInstalmentService; amounts are snapshots of what
 * was computed, so later backdated revenue never rewrites an accrued
 * history. Reversal mirrors the journal back out, and a reversed
 * quarter can be accrued again.
 */
class PaygInstalmentAccrual extends Model
{
    protected $table = 'payg_instalment_accruals';

    protected $fillable = [
        'entity_id',
        'period_start',
        'period_end',
        'fy',
        'quarter',
        'instalment_income',
        'rate',
        'amount',
        'ifrs_transaction_id',
        'reversal_transaction_id',
        'reversed_at',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'fy' => 'integer',
        'quarter' => 'integer',
        'instalment_income' => 'float',
        'rate' => 'float',
        'amount' => 'float',
        'reversed_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'ifrs_transaction_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * "Q1 FY2026 (ended 30 Sep 2025)" — the BAS report's quarter label.
     */
    public function label(): string
    {
        return sprintf('Q%d FY%d (ended %s)', $this->quarter, $this->fy, $this->period_end->format('d M Y'));
    }
}
