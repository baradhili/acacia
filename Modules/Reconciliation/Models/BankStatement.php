<?php

namespace Modules\Reconciliation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One imported bank statement that carries balances — the MT940 and
 * camt.053 downloads (the Wise CSV layouts carry none). The closing
 * balance anchors the bank-vs-books cash check's actual side:
 * closing balance plus every imported line dated after it, which is
 * exact regardless of how far back the imported line history reaches.
 * Opening balance is stored for feed-history diagnosis — a statement
 * whose opening disagrees with the lines before it means the import
 * is missing movements.
 *
 * Rows are keyed on (source, statement_id) — the statement's own id
 * (:20: in MT940, Stmt Id in camt.053) — so re-importing updates the
 * balances instead of duplicating the anchor.
 */
class BankStatement extends Model
{
    protected $fillable = [
        'source',
        'format',
        'statement_id',
        'external_account',
        'currency',
        'opening_date',
        'closing_date',
        'opening_balance',
        'closing_balance',
    ];

    protected $casts = [
        'opening_date' => 'date',
        'closing_date' => 'date',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
    ];
}
