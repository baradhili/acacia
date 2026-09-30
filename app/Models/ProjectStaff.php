<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assignment of a user to a project with an optional per-assignment
 * hourly rate overriding the project default (effective_rate falls
 * back to project.hourly_rate). Unique on (project_id, user_id);
 * TimeEntry's staff cost uses the active assignment's rate.
 */
class ProjectStaff extends Model
{
    use HasFactory;

    protected $table = 'project_staff';

    protected $fillable = [
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
