<?php

namespace App\Models;

use App\Support\AuNumbers;
use App\Traits\HasCustomFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client of the firm — parent of its projects, invoices, payments
 * and credit notes, with accessors aggregating AR aging, overdue and
 * available credit. Soft-deleted; ABN stored as bare digits and
 * formatted 2-3-3-3; three postal addresses collapse via
 * same_as_billing. Estimates belong to the Proposals module's own
 * model — the core keeps no client→estimate relation.
 */
class Client extends Model
{
    use HasCustomFields, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        // Primary address
        'address',
        'city',
        'state',
        'postcode',
        'country',
        // Billing address
        'billing_address',
        'billing_city',
        'billing_state',
        'billing_postcode',
        'billing_country',
        // Shipping address
        'shipping_address',
        'shipping_city',
        'shipping_state',
        'shipping_postcode',
        'shipping_country',
        // Flags
        'same_as_billing',
        // Additional
        'abn',
        'notes',
        'logo',
    ];

    protected $casts = [
        'same_as_billing' => 'boolean',
        'custom_fields' => 'array',
    ];

    /**
     * ABN is stored as bare digits; spaced entry ("12 345 678 901")
     * is normalised on the way in.
     */
    public function setAbnAttribute($value): void
    {
        $this->attributes['abn'] = AuNumbers::digits($value);
    }

    /**
     * ABN formatted the way the ATO writes it (2-3-3-3); anything
     * that isn't an 11-digit ABN shows as entered.
     */
    public function getFormattedAbnAttribute(): ?string
    {
        return AuNumbers::abn($this->abn);
    }

    /**
     * Get the effective billing address (uses primary if same_as_billing)
     */
    public function getBillingAddressLineAttribute(): ?string
    {
        if ($this->same_as_billing) {
            return $this->address;
        }

        return $this->billing_address;
    }

    /**
     * Get the effective shipping address (uses primary if same_as_billing)
     */
    public function getShippingAddressLineAttribute(): ?string
    {
        if ($this->same_as_billing) {
            return $this->address;
        }

        return $this->shipping_address;
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    // Estimates live in the Proposals module (Modules\Proposals) —
    // its Estimate model owns the client() side of the pair; the
    // core keeps no core-to-module relation.

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Get total outstanding amount (AR aging)
     */
    public function getOutstandingAmountAttribute(): float
    {
        return $this->invoices()
            ->whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_OVERDUE,
            ])
            ->sum('total') - $this->invoices()
            ->whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_OVERDUE,
            ])
            ->with('allocations')
            ->get()
            ->sum('amount_paid');
    }

    /**
     * Get overdue amount
     */
    public function getOverdueAmountAttribute(): float
    {
        return $this->invoices()
            ->overdue()
            ->sum('total') - $this->invoices()
            ->overdue()
            ->with('allocations')
            ->get()
            ->sum('amount_paid');
    }

    /**
     * Get available credit from credit notes
     */
    public function getAvailableCreditAttribute(): float
    {
        return $this->creditNotes()
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount');
    }

    /**
     * Get the logo URL
     */
    public function getLogoUrlAttribute(): ?string
    {
        if ($this->logo && file_exists(public_path('storage/'.$this->logo))) {
            return asset('storage/'.$this->logo);
        }

        return null;
    }
}
