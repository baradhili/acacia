<?php

namespace App\Models;

use App\Support\AuNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone the entity pays through payroll. Employment type drives the
 * tax treatment: employees and directors get PAYG withholding (their
 * scale — NAT 1004 Schedule 1 — from the tax-free-threshold flag) and
 * super on their ordinary earnings — the single-director Pty Ltd
 * paying themselves director fees is the canonical case and cannot
 * skip either. Contractors withhold nothing; a labour-only individual
 * contractor (a sole trader whose contract is wholly or principally
 * for their labour) still draws super, a company contractor does not.
 *
 * is_closely_linked marks payees related to directors/shareholders
 * (family members and the like) — relevant to FBT and to the PSI
 * rules, which block paying associates for non-principal work out of
 * personal services income; is_personal_services marks workers whose
 * payment is for the personal exertion of an individual (their
 * payslips are flagged through to the pay run for the entity's PSI
 * assessment).
 */
class Employee extends Model
{
    public const TYPE_EMPLOYEE = 'employee';

    public const TYPE_DIRECTOR = 'director';

    public const TYPE_CONTRACTOR = 'contractor';

    public const BASIS_HOURLY = 'hourly';

    public const BASIS_SALARY = 'salary';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'entity_id',
        'user_id',
        'name',
        'email',
        'tfn',
        'employment_type',
        'labour_only',
        'payment_basis',
        'hourly_rate',
        'annual_salary',
        'tax_free_threshold',
        'super_rate',
        'super_fund',
        'super_member_id',
        'is_closely_linked',
        'is_personal_services',
        'notes',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:4',
        'annual_salary' => 'decimal:2',
        'tax_free_threshold' => 'boolean',
        'labour_only' => 'boolean',
        'super_rate' => 'decimal:6',
        'is_closely_linked' => 'boolean',
        'is_personal_services' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_EMPLOYEE => 'Employee',
            self::TYPE_DIRECTOR => 'Director',
            self::TYPE_CONTRACTOR => 'Contractor',
        ];
    }

    public static function bases(): array
    {
        return [self::BASIS_HOURLY => 'Hourly', self::BASIS_SALARY => 'Salary'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * PAYG withholding applies: employees and directors (director
     * fees are OTE and withhold like salary); contractors do not.
     */
    public function withholdsPayg(): bool
    {
        return in_array($this->employment_type, [self::TYPE_EMPLOYEE, self::TYPE_DIRECTOR], true);
    }

    /**
     * Super applies: employees and directors always; an individual
     * (non-company) contractor only when the contract is wholly or
     * principally for their labour.
     */
    public function earnsSuper(): bool
    {
        return match ($this->employment_type) {
            self::TYPE_EMPLOYEE, self::TYPE_DIRECTOR => true,
            self::TYPE_CONTRACTOR => $this->labour_only,
            default => false,
        };
    }

    /**
     * The ATO scale this payee withholds under (NAT 1004): 2 with
     * the tax-free threshold, 1 without. Contractors never withhold.
     */
    public function taxScale(): int
    {
        return (($this->tax_free_threshold ?? true)) ? 2 : 1;
    }

    /**
     * TFN is stored as bare digits; spaced entry is normalised.
     */
    public function setTfnAttribute($value): void
    {
        $this->attributes['tfn'] = AuNumbers::digits($value);
    }

    public function getFormattedTfnAttribute(): ?string
    {
        return AuNumbers::tfn($this->tfn);
    }
}
