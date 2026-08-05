<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use IFRS\Models\Entity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'salary', 'charge_out_rate', 'position', 'phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'salary' => 'decimal:2',
            'charge_out_rate' => 'decimal:2',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function projectAssignments(): HasMany
    {
        return $this->hasMany(ProjectStaff::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get effective hourly rate for time tracking
     * Uses charge_out_rate if set, otherwise falls back to project rate
     */
    public function getEffectiveHourlyRateAttribute(): float
    {
        return (float) ($this->charge_out_rate ?? 0);
    }

    /**
     * Check if user has a charge out rate set
     */
    public function hasChargeOutRate(): bool
    {
        return $this->charge_out_rate !== null && $this->charge_out_rate > 0;
    }
}
