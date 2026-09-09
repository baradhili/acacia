<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payroll run over a pay period. Draft runs collect payslips and
 * are freely editable; processing posts the compound journal (wages
 * and super expense against PAYG-withheld, super-payable and the net
 * bank payment) and stamps the IFRS transaction for reversal.
 */
class PayRun extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROCESSED = 'processed';

    public const FREQUENCIES = ['weekly', 'fortnightly', 'monthly'];

    protected $fillable = [
        'entity_id',
        'period_start',
        'period_end',
        'payment_date',
        'frequency',
        'status',
        'ifrs_transaction_id',
        'processed_at',
        'processed_by',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'payment_date' => 'date',
        'processed_at' => 'datetime',
    ];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function label(): string
    {
        return sprintf(
            '%s pay — %s to %s',
            ucfirst($this->frequency),
            $this->period_start->format('d M Y'),
            $this->period_end->format('d M Y'),
        );
    }
}
