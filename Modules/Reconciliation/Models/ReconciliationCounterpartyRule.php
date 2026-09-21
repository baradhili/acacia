<?php

namespace Modules\Reconciliation\Models;

use App\Models\Client;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payroll\Models\Employee;

/**
 * One learned reconciliation rule: bank lines from a normalised
 * counterparty (match_key — payer for credits, payee/merchant for
 * debits) resolve to this client, supplier, or employee. Written
 * whenever a match resolves an identity (manual match, auto-match,
 * auto-created receipt/bill); read by the learned auto-match pass and
 * the auto-create client/supplier resolution.
 */
class ReconciliationCounterpartyRule extends Model
{
    protected $fillable = [
        'match_key',
        'direction',
        'client_id',
        'supplier_id',
        'employee_id',
        'times_matched',
        'last_matched_at',
    ];

    protected $casts = [
        'times_matched' => 'integer',
        'last_matched_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
