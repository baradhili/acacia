<?php

/**
 * Reconciliation module configuration: the accounts bank interest
 * and fees post to from the match screen. Both resolve per entity at
 * posting time; the fee account is lazily created on existing charts
 * (the Funds Introduced precedent).
 */

return [

    'accounts' => [
        // Interest the bank pays on the account — income, not a
        // client receipt. 4510 is the seeded Interest Income account.
        'interest_income' => env('RECONCILIATION_INTEREST_INCOME_ACCOUNT_CODE', 4510),

        // Account-keeping and transaction fees the bank charges.
        'bank_fees' => env('RECONCILIATION_BANK_FEES_ACCOUNT_CODE', 5950),
    ],

];
