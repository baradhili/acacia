<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use IFRS\Models\Entity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'salary', 'charge_out_rate', 'position', 'phone', 'profile_photo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'salary' => 'decimal:2',
            'charge_out_rate' => 'decimal:2',
        ];
    }

    /**
     * Get the profile photo URL
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        if ($this->profile_photo && file_exists(public_path('storage/' . $this->profile_photo))) {
            return asset('storage/' . $this->profile_photo);
        }
        return null;
    }

    /**
     * Get initials for avatar fallback
     */
    public function getInitialsAttribute(): string
    {
        $names = explode(' ', $this->name);
        $initials = '';
        foreach (array_slice($names, 0, 2) as $name) {
            $initials .= strtoupper(substr($name, 0, 1));
        }
        return $initials ?: strtoupper(substr($this->name, 0, 2));
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
