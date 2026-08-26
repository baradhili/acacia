<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shareholder registry entry (Phase 1 of the franking/dividend spec in
 * .zcode/plans/tax_spec.md): master data plus current holding by share
 * class. Transaction-level shareholding history is deferred with the
 * franking module.
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
        'suburb',
        'state',
        'postcode',
        'country',
        'email',
        'phone',
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
}
