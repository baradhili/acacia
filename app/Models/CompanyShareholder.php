<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shareholder registry entry (Phase 1 of the franking/dividend spec in
 * .zcode/plans/tax_spec.md): master data plus bank details for the manual
 * dividend payment run. Holdings come from the shareholdings transaction
 * ledger (ShareholdingService); share_class/shares_held remain as a
 * display cache kept in sync by the service. Master data is edited on the
 * company profile screen; the shareholders screens show holdings history
 * and dividend history.
 */
class CompanyShareholder extends Model
{
    public const STATUS_ACTIVE = 'A';

    public const STATUS_INACTIVE = 'I';

    protected $fillable = [
        'company_profile_id',
        'name',
        'abn',
        'tfn',
        'address_line1',
        'address_line2',
        'suburb',
        'state',
        'postcode',
        'country',
        'contact_name',
        'email',
        'phone',
        'bank_bsb',
        'bank_account_number',
        'bank_account_name',
        'resident_for_tax',
        'share_class',
        'shares_held',
        'status',
    ];

    protected $casts = [
        'resident_for_tax' => 'boolean',
        'shares_held' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CompanyProfile::class, 'company_profile_id');
    }

    public function shareholdings(): HasMany
    {
        return $this->hasMany(Shareholding::class, 'company_shareholder_id')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');
    }

    public function dividendDistributions(): HasMany
    {
        return $this->hasMany(DividendDistribution::class, 'company_shareholder_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function statusLabel(): string
    {
        return $this->status === self::STATUS_ACTIVE ? 'Active' : 'Inactive';
    }

    public function bankDetailsComplete(): bool
    {
        return (bool) ($this->bank_bsb && $this->bank_account_number && $this->bank_account_name);
    }

    /**
     * Full postal address as a single line, skipping empty parts.
     */
    public function addressLine(): string
    {
        return collect([$this->address_line1, $this->address_line2, $this->suburb, $this->state, $this->postcode, $this->country])
            ->filter()
            ->implode(', ');
    }
}
