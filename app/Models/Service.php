<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A service the practice sells — the catalogue behind estimates, invoices
 * and time billing (e.g. "Bookkeeping", "BAS Preparation"). The standard
 * hourly rate is a prefill for rate cards and time entries, not a ledger
 * amount; fixed-fee services simply leave it null.
 */
class Service extends Model
{
    protected $fillable = ['name', 'description', 'hourly_rate'];

    protected $casts = [
        'hourly_rate' => 'decimal:4',
    ];

    /**
     * Rate formatted for display (money precision, trailing zeros trimmed).
     */
    public function formattedRate(): string
    {
        return $this->hourly_rate === null
            ? '—'
            : '$'.rtrim(rtrim(number_format((float) $this->hourly_rate, 2), '0'), '.');
    }
}
