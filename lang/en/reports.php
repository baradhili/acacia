<?php

// Report screen strings added with the cash flow statement's labelled
// columns (Oct 2026). en is the complete base; en_AU overrides only
// keys that differ in Australian English — none here.
return [

    'cash_flow' => [
        'activity' => 'Activity',
        'amount' => 'Amount',
    ],

    // Transaction Ledger Register screen (Oct 2026) — one row per
    // posted ledger leg with the transaction reference first.
    'transaction_register' => [
        'title' => 'Transaction Ledger Register',
        'nav' => 'Transaction Register',
        'reference' => 'Reference',
        'date' => 'Date',
        'type' => 'Type',
        'account' => 'Ledger Account',
        'debit' => 'Debit',
        'credit' => 'Credit',
        'narration' => 'Narration',
        'documents' => 'Documents',
        'start_date' => 'Start Date',
        'end_date' => 'End Date',
        'filter' => 'Apply Filters',
        'export_csv' => 'Export CSV',
        'total_debit' => 'Total Debits',
        'total_credit' => 'Total Credits',
        'leg_count' => 'Ledger Lines',
        'unknown_account' => 'Unknown',
        'period_all' => 'All dates',
        'period_range' => ':start to :end',
        'no_rows' => 'No ledger entries found for the selected period.',
    ],

];
