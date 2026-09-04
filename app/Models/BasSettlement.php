<?php

namespace App\Models;

use IFRS\Models\Entity;
use IFRS\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded BAS settlement — the ATO payment (or refund) that nets GST
 * Payable against GST Receivable and clears both accounts. Posting
 * logic lives in BasSettlementService; amounts are snapshots of what
 * was netted, so later backdated postings never rewrite a settled
 * history. Reversal mirrors the journal back out.
 */
class BasSettlement extends Model
{
    public const TYPE_GST = 'gst';

    public const TYPE_PAYG = 'payg_withholding';

    public const TYPE_INCOME_TAX = 'income_tax';

    public const TYPES = [
        self::TYPE_GST,
        self::TYPE_PAYG,
        self::TYPE_INCOME_TAX,
    ];

    public const DIRECTION_PAY = 'pay';

    public const DIRECTION_REFUND = 'refund';

    protected $table = 'bas_settlements';

    protected $fillable = [
        'entity_id',
        'type',
        'as_at',
        'settled_at',
        'gst_payable',
        'gst_receivable',
        'net_amount',
        'bank_amount',
        'direction',
        'ifrs_transaction_id',
        'reversal_transaction_id',
        'reversed_at',
        'reference',
        'notes',
    ];

    protected $casts = [
        'as_at' => 'date',
        'settled_at' => 'date',
        'gst_payable' => 'float',
        'gst_receivable' => 'float',
        'net_amount' => 'float',
        'bank_amount' => 'float',
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
     * Human label for a settlement type ("GST", "PAYG withholding",
     * "income tax") — used in narrations, flashes and the screen.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_PAYG => 'PAYG withholding',
            self::TYPE_INCOME_TAX => 'income tax',
            default => 'GST',
        };
    }

    /**
     * "GST to 30 Jun 2026" for report labels and narrations.
     */
    public function label(): string
    {
        return static::typeLabel($this->type ?: self::TYPE_GST).' to '.$this->as_at->format('d M Y');
    }
}
