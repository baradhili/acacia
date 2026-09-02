<?php

namespace App\Models;

use IFRS\Models\Entity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Company identity for the reporting entity — the legal name lives on
 * ifrs_entities (authoritative for statutory outputs) with an optional
 * trading name here; ABN/TFN/ACN, registered address and contact details,
 * plus the directors and shareholders registries. Backs the ATO Company
 * Tax Return identification section and the shareholder registry
 * (Phase 1 of the franking/dividend spec).
 */
class CompanyProfile extends Model
{
    /** Base rate entity (small company): aggregated turnover < $50m and ≤80% passive income. */
    public const TAX_RATE_SMALL = 'small';

    /** Any other company (30%). */
    public const TAX_RATE_COMPANY = 'company';

    protected $fillable = [
        'entity_id',
        'trading_name',
        'logo',
        'abn',
        'tfn',
        'acn',
        'tax_rate_type',
        'address_line1',
        'address_line2',
        'suburb',
        'state',
        'postcode',
        'country',
        'email',
        'phone',
    ];

    /**
     * Servable URL for the stored logo (SVG or PNG), checked on the
     * public disk where the file lives rather than through the
     * public/storage link. Null when absent.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo && Storage::disk('public')->exists($this->logo)) {
            return asset('storage/'.$this->logo);
        }

        return null;
    }

    public static function taxRateTypes(): array
    {
        return [
            self::TAX_RATE_SMALL => 'Base rate entity (small company)',
            self::TAX_RATE_COMPANY => 'Other company',
        ];
    }

    /**
     * The corporate tax rate this company pays, from its tax_rate_type
     * classification — the rate the franking credit gross-up must use.
     */
    public static function effectiveTaxRate(?int $entityId = null): float
    {
        $type = static::forEntity($entityId)->tax_rate_type ?: self::TAX_RATE_SMALL;
        $rates = config('dividends.tax_rates');

        return (float) ($rates[$type] ?? $rates[self::TAX_RATE_SMALL] ?? 25);
    }

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
     * Classes of shares the company has issued (franking/dividend module).
     */
    public function shareClasses(): HasMany
    {
        return $this->hasMany(ShareClass::class)->orderBy('code');
    }

    /**
     * The profile for an entity, or a null-object stand-in when none has
     * been maintained yet, so callers can read ABN/TFN unconditionally.
     */
    public static function forEntity(?int $entityId): self
    {
        return static::where('entity_id', $entityId)->first() ?? new static;
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
