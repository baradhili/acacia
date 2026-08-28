<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated shareholding transaction against a shareholder's holding in a
 * share class: issues and transfers-in carry a positive quantity, buybacks
 * and consolidations a negative one. Holdings as at any date (in particular
 * a dividend's books-close date) are the sum of active transactions up to
 * that date — see ShareholdingService::totalShares().
 */
class Shareholding extends Model
{
    public const TYPE_ISSUE = 'I';

    public const TYPE_TRANSFER = 'T';

    public const TYPE_BUYBACK = 'B';

    public const TYPE_CONSOLIDATION = 'C';

    public const STATUS_ACTIVE = 'A';

    public const STATUS_CANCELLED = 'C';

    protected $fillable = [
        'company_shareholder_id',
        'share_class_id',
        'transaction_type',
        'transaction_date',
        'quantity',
        'unit_price',
        'amount_paid',
        'reference',
        'status',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'quantity' => 'integer',
        'unit_price' => 'decimal:4',
        'amount_paid' => 'decimal:2',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_ISSUE => 'Issue',
            self::TYPE_TRANSFER => 'Transfer',
            self::TYPE_BUYBACK => 'Buyback',
            self::TYPE_CONSOLIDATION => 'Consolidation',
        ];
    }

    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(CompanyShareholder::class, 'company_shareholder_id');
    }

    public function shareClass(): BelongsTo
    {
        return $this->belongsTo(ShareClass::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
