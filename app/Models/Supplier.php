<?php

namespace App\Models;

use App\Traits\HasCustomFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Supplier extends Model
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
        'category',
        'notes',
        'logo',
    ];

    protected $casts = [
        'same_as_billing' => 'boolean',
        'custom_fields' => 'array',
    ];

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
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
