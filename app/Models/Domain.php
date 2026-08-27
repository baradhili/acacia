<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered domain name. Initial purchases are capitalised to the
 * intangible account via a capital bill line (AASB/IFRS: controlled,
 * indefinite life by default → no amortisation); renewals are expensed
 * immediately and never increase the carrying amount.
 */
class Domain extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'entity_id',
        'name',
        'registrar',
        'purchased_at',
        'expiry_date',
        'cost',
        'account_id',
        'indefinite_life',
        'useful_life_months',
        'notes',
        'status',
    ];

    protected $casts = [
        'purchased_at' => 'date',
        'expiry_date' => 'date',
        'cost' => 'decimal:2',
        'indefinite_life' => 'boolean',
        'useful_life_months' => 'integer',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(\IFRS\Models\Entity::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(\IFRS\Models\Account::class);
    }

    /**
     * Finite-life amortisation schedules created from this registry
     * entry (Cr 170 intangible / Dr 7910 Amortisation Expense).
     */
    public function prepayments(): HasMany
    {
        return $this->hasMany(Prepayment::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Days until the next renewal falls due (null when no expiry is set).
     */
    public function daysUntilExpiry(): ?int
    {
        return $this->expiry_date
            ? (int) ceil(now()->startOfDay()->diffInDays($this->expiry_date, false))
            : null;
    }

    public function isExpiringSoon(int $withinDays = 60): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days >= 0 && $days <= $withinDays;
    }

    public function isExpired(): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days < 0;
    }
}
