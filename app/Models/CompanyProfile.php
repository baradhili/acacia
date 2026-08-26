<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use IFRS\Models\Entity;

/**
 * Company identity for the reporting entity — ABN/TFN/ACN, registered
 * address and contact details, plus the directors and shareholders
 * registries. Backs the ATO Company Tax Return identification section
 * and the shareholder registry (Phase 1 of the franking/dividend spec).
 */
class CompanyProfile extends Model
{
    protected $fillable = [
        'entity_id',
        'abn',
        'tfn',
        'acn',
        'address_line1',
        'address_line2',
        'suburb',
        'state',
        'postcode',
        'country',
        'email',
        'phone',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function directors(): HasMany
    {
        return $this->hasMany(CompanyDirector::class)->orderBy('appointment_date');
    }

    public function shareholders(): HasMany
    {
        return $this->hasMany(CompanyShareholder::class)->where('status', 'A')->orderBy('name');
    }

    /**
     * All shareholders including inactive ones (registry maintenance).
     */
    public function allShareholders(): HasMany
    {
        return $this->hasMany(CompanyShareholder::class)->orderBy('name');
    }

    /**
     * The profile for an entity, or a null-object stand-in when none has
     * been maintained yet, so callers can read ABN/TFN unconditionally.
     */
    public static function forEntity(?int $entityId): self
    {
        return static::where('entity_id', $entityId)->first() ?? new static();
    }

    /**
     * Effective ABN: profile first, then the legacy env-config value.
     */
    public static function effectiveAbn(?int $entityId): string
    {
        return (string) (static::forEntity($entityId)->abn ?: config('australian.abn'));
    }

    /**
     * Effective TFN: profile first, then the legacy env-config value.
     */
    public static function effectiveTfn(?int $entityId): string
    {
        return (string) (static::forEntity($entityId)->tfn ?: config('australian.tfn'));
    }

    public function getFormattedAddressAttribute(): string
    {
        return collect([
            $this->address_line1,
            $this->address_line2,
            $this->suburb,
            $this->state,
            $this->postcode,
            $this->country,
        ])->filter()->implode(', ');
    }
}
