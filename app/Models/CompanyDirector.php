<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDirector extends Model
{
    protected $fillable = [
        'company_profile_id',
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
