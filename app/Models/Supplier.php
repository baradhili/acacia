<?php

namespace App\Models;

use App\Traits\HasCustomFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory, HasCustomFields;

    // Type constants
    const TYPE_SUPPLIER = 'supplier';
    const TYPE_VENDOR = 'vendor';

    protected $fillable = [
        'name',
        'type',
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
        'category',
        'notes',
    ];

    protected $casts = [
        'same_as_billing' => 'boolean',
        'custom_fields' => 'array',
    ];

    /**
     * Scope for suppliers only
     */
    public function scopeSuppliers($query)
    {
        return $query->where('type', self::TYPE_SUPPLIER);
    }

    /**
     * Scope for vendors only
     */
    public function scopeVendors($query)
    {
        return $query->where('type', self::TYPE_VENDOR);
    }

    /**
     * Check if this is a vendor
     */
    public function isVendor(): bool
    {
        return $this->type === self::TYPE_VENDOR;
    }

    /**
     * Check if this is a supplier
     */
    public function isSupplier(): bool
    {
        return $this->type === self::TYPE_SUPPLIER;
    }

    /**
     * Convert to vendor
     */
    public function convertToVendor(): void
    {
        $this->update(['type' => self::TYPE_VENDOR]);
    }

    /**
     * Convert to supplier
     */
    public function convertToSupplier(): void
    {
        $this->update(['type' => self::TYPE_SUPPLIER]);
    }
}
