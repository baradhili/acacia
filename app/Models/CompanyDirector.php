<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One director on a CompanyProfile's registry (cascade-deleted with
 * it); appointment/resignation dates back the ATO Company Tax Return
 * identification section. Listed ordered by appointment_date.
 */
class CompanyDirector extends Model
{
    protected $fillable = [
        'name',
        'appointment_date',
        'resignation_date',
        'email',
        'phone',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'resignation_date' => 'date',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CompanyProfile::class, 'company_profile_id');
    }
}
