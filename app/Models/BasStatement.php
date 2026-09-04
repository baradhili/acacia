<?php

namespace App\Models;

use IFRS\Models\Entity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A BAS quarter frozen at lodgement — the figures as lodged, kept so
 * backdated postings can never rewrite a BAS already sent to the ATO
 * (ReportController::buildBasStatement prefers these over live ledger
 * recomputation). Freezing again refreshes the figures; unfreezing
 * deletes the row and returns the quarter to live recomputation.
 */
class BasStatement extends Model
{
    protected $table = 'bas_statements';

    protected $fillable = [
        'entity_id',
        'fy_end',
        'quarter',
        'period_start',
        'period_end',
        'g1',
        'g10',
        'g11',
        'gst_sales',
        'gst_purchases',
        'net',
        'lodged_at',
        'lodged_by',
        'notes',
    ];

    protected $casts = [
        'fy_end' => 'integer',
        'quarter' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'g1' => 'float',
        'g10' => 'float',
        'g11' => 'float',
        'gst_sales' => 'float',
        'gst_purchases' => 'float',
        'net' => 'float',
        'lodged_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function lodgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lodged_by');
    }

    /**
     * The BAS report's quarter figure keys, as frozen.
     *
     * @return array{g1: float, g10: float, g11: float, gst_sales: float, gst_purchases: float, net: float}
     */
    public function frozenFigures(): array
    {
        return [
            'g1' => (float) $this->g1,
            'g10' => (float) $this->g10,
            'g11' => (float) $this->g11,
            'gst_sales' => (float) $this->gst_sales,
            'gst_purchases' => (float) $this->gst_purchases,
            'net' => (float) $this->net,
        ];
    }

    /**
     * "Q1 FY2027 (lodged 28 Jul 2026)".
     */
    public function label(): string
    {
        return sprintf('Q%d FY%d', $this->quarter, $this->fy_end);
    }
}
