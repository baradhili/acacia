<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shareholder's line on a dividend declaration: the shares they held
 * of the class at the books-close date, the cash dividend, the attached
 * franking credit and the grossed-up amount. Amounts are rounded per line
 * so the declaration totals (and the GL journals) equal the sum of the
 * lines exactly. Payments are made manually outside the ERP from the
 * payment schedule; this row records the outcome and statement-sending
 * state.
 */
class DividendDistribution extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'dividend_declaration_id',
        'company_shareholder_id',
        'shareholder_name',
        'share_class_id',
        'shares_eligible',
        'cash_dividend',
        'franking_credit',
        'grossed_up_dividend',
        'withholding_tax',
        'net_payment',
        'payment_reference',
        'status',
        'paid_at',
        'statement_sent',
        'statement_sent_at',
        'created_by',
    ];

    protected $casts = [
        'shares_eligible' => 'integer',
        'cash_dividend' => 'decimal:2',
        'franking_credit' => 'decimal:2',
        'grossed_up_dividend' => 'decimal:2',
        'withholding_tax' => 'decimal:2',
        'net_payment' => 'decimal:2',
        'paid_at' => 'datetime',
        'statement_sent' => 'boolean',
        'statement_sent_at' => 'datetime',
    ];

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(DividendDeclaration::class, 'dividend_declaration_id');
    }

    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(CompanyShareholder::class, 'company_shareholder_id');
    }

    public function shareClass(): BelongsTo
    {
        return $this->belongsTo(ShareClass::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
