<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStaff extends Model
{
    use HasFactory;

    protected $table = 'project_staff';

    protected $fillable = [
        'project_id',
        'user_id',
        'hourly_rate',
        'is_active',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get effective hourly rate (staff rate or project default)
     */
    public function getEffectiveRateAttribute(): float
    {
        return $this->hourly_rate 
            ? (float) $this->hourly_rate 
            : (float) $this->project->hourly_rate;
    }
}
