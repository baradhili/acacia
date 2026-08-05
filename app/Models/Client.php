<?php

namespace App\Models;

use App\Traits\HasCustomFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory, HasCustomFields;

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
}
