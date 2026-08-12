<?php

namespace App\Models;

use App\Traits\HasCustomFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, HasCustomFields, SoftDeletes;

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

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

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
        if ($this->logo && file_exists(public_path('storage/' . $this->logo))) {
            return asset('storage/' . $this->logo);
        }
        return null;
    }
}
