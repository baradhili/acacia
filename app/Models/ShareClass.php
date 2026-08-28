<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class of shares issued by the company (e.g. ORD ordinary, PREF
 * preference). Dividend declarations are made against a class; only
 * holdings in that class (and, if dividend_rights is set, only the class
 * itself) participate in distributions.
 */
class ShareClass extends Model
{
    public const STATUS_ACTIVE = 'A';
    public const STATUS_INACTIVE = 'I';

    protected $fillable = [
        'company_profile_id',
        'code',
        'description',
        'voting_rights',
        'dividend_rights',
        'ranking',
        'franking_entitlement',
        'status',
    ];

    protected $casts = [
        'voting_rights' => 'boolean',
        'dividend_rights' => 'boolean',
        'ranking' => 'integer',
        'franking_entitlement' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CompanyProfile::class, 'company_profile_id');
    }

    public function shareholdings(): HasMany
    {
        return $this->hasMany(Shareholding::class);
    }

    public function declarations(): HasMany
    {
        return $this->hasMany(DividendDeclaration::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
