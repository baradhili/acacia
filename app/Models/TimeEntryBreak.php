<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntryBreak extends Model
{
    protected $fillable = [
        'time_entry_id',
        'start_time',
        'end_time',
    ];

    protected static function boot()
    {
        parent::boot();

        // Break changes recompute the parent's hours (span − breaks)
        static::saved(function ($break) {
            $break->timeEntry->recalculateHours();
        });

        static::deleted(function ($break) {
            if ($break->timeEntry) {
                $break->timeEntry->recalculateHours();
            }
        });
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    /**
     * Break length in minutes, from the HH:MM(:SS) time columns.
     */
    public function durationMinutes(): float
    {
        $start = substr($this->start_time, 0, 5);
        $end = substr($this->end_time, 0, 5);

        return (strtotime($end) - strtotime($start)) / 60;
    }

    /**
     * "13:30" style display (drops seconds).
     */
    public function getStartDisplayAttribute(): string
    {
        return substr($this->start_time, 0, 5);
    }

    public function getEndDisplayAttribute(): string
    {
        return substr($this->end_time, 0, 5);
    }
}
