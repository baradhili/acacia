<?php

// Reconciliation screen strings added with the bank-vs-books cash
// check (Oct 2026). en is the complete base; en_AU overrides only
// keys that differ in Australian English — none here.
return [

    'cash_check' => [
        'title' => 'Cash basis check — bank vs books',
        'intro' => 'This is a cash-basis system: the bank accounts are the books\' source of truth for cash. The gap below compares what the imported feed says the bank actually holds against what the ledger says it should hold.',

        'books_heading' => 'Expected cash (the books)',
        'books_total' => 'Total expected cash',
        'bank_heading' => 'Actual bank balance (the feed)',
        'bank_lines' => ':count lines, latest :date',
        'not_compared' => 'not compared — books are :currency',
        'no_feed' => 'No statement imported yet — import the bank\'s CSV export to compare the actual balance.',
        'no_books' => 'No bank accounts in the ledger — nothing to compare the feed against.',

        'gap_label' => 'Gap (bank minus books)',
        'gap_bank_ahead' => 'The bank holds more than the books record.',
        'gap_books_ahead' => 'The books claim more than the bank holds.',
        'gap_zero' => 'The books and the bank agree.',

        'component_bank' => 'Bank lines not matched yet (pending + ignored)',
        'component_books' => 'Book movements not on the statement',
        'component_residual' => 'Other timing differences',

        'caveat' => 'The actual balance is the running sum of every imported line, so it is only as complete as the import — a feed that starts after the account opened understates it. Lines in other currencies are listed but never netted against the :currency books.',
    ],

];
