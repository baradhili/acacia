<?php

namespace Modules\Crm\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A monthly sales target. Progress is the estimated value of leads
 * won (converted) within the month — the funnel's output, not
 * invoiced revenue, so sales and delivery keep separate scoreboards.
 */
class SalesTarget extends Model
{
    protected $table = 'crm_targets';

    protected $fillable = ['month', 'amount'];

    protected $casts = [
        'month' => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

    public static function forMonth(Carbon $month): ?self
    {
        return static::whereDate('month', $month->startOfMonth()->toDateString())->first();
    }

    /**
     * Won-lead value within the target's month.
     */
    public function achieved(): float
    {
        $start = $this->month->copy()->startOfMonth()->startOfDay();
        $end = $this->month->copy()->endOfMonth()->endOfDay();

        return round((float) Lead::where('status', Lead::STATUS_WON)
            ->whereBetween('converted_at', [$start, $end])
            ->sum('estimated_value'), 2);
    }

    public function progressPercent(): float
    {
        if ((float) $this->amount <= 0) {
            return 0.0;
        }

        return round(min($this->achieved() / (float) $this->amount * 100, 999.9), 1);
    }
}
