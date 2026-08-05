<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use HasFactory;

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
    ];

    protected $casts = [
        'same_as_billing' => 'boolean',
    ];
}
