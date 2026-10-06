<?php

// Reconciliation screen strings: the bank-vs-books cash check and the
// statement import screen (Oct 2026). en is the complete base; en_AU
// overrides only keys that differ in Australian English — none here.
return [

    'index' => [
        'intro' => 'Import a bank statement — CSV, MT940 or camt.053 XML, the format is detected automatically — then match each movement against invoices, payments and bills.',
        'empty_pending' => 'Nothing pending — import a statement to bring in new bank movements.',
    ],

    'cash_check' => [
        'title' => 'Cash basis check — bank vs books',
        'intro' => 'This is a cash-basis system: the bank accounts are the books\' source of truth for cash. The gap below compares what the imported feed says the bank actually holds against what the ledger says it should hold.',

        'books_heading' => 'Expected cash (the books)',
        'books_total' => 'Total expected cash',
        'bank_heading' => 'Actual bank balance (the feed)',
        'bank_lines' => ':count lines, latest :date',
        'bank_basis_statement' => ':format statement balance, closed :date, + :count later line(s)',
        'bank_basis_running' => 'running sum of imported lines — no statement balances imported',
        'not_compared' => 'not compared — books are :currency',
        'no_feed' => 'No statement imported yet — import a statement (CSV, MT940 or camt.053 XML) to compare the actual balance.',
        'no_books' => 'No bank accounts in the ledger — nothing to compare the feed against.',

        'gap_label' => 'Gap (bank minus books)',
        'gap_bank_ahead' => 'The bank holds more than the books record.',
        'gap_books_ahead' => 'The books claim more than the bank holds.',
        'gap_zero' => 'The books and the bank agree.',

        'component_bank' => 'Bank lines not matched yet (pending + ignored)',
        'component_books' => 'Book movements not on the statement',
        'component_residual' => 'Other timing differences',

        'caveat' => 'The actual balance comes from the latest imported statement balance (its closing balance plus any later lines) when the feed carries one — MT940 and camt.053 do — and otherwise from the running sum of every imported line, which is only as complete as the import: a feed that starts after the account opened understates it, and a feed that covers only some of the bank accounts (the Wise feed against a multi-account chart, say) leaves the rest inside the gap\'s residual. Lines in other currencies are listed but never netted against the :currency books.',
    ],

    'import' => [
        'title' => 'Import bank statement',
        'back' => 'Back to reconciliation',
        'heading' => 'Upload statement file',
        'upload_label' => 'Upload a file',
        'drop_hint' => 'or drag and drop',
        'file_hint' => 'Wise CSV export, MT940 or camt.053 XML — files up to 10MB, format detected automatically',
        'formats_title' => 'Accepted formats',
        'formats_body' => 'Any Wise statement download of this account: the transactions CSV export (transaction-history.csv or the older statement export), the MT940 statement, or the camt.053 XML report. Already-imported rows and non-completed movements (e.g. refunds) are skipped automatically, so re-uploading the same file — or the same period in a different format — is safe.',
        'cancel' => 'Cancel',
        'submit' => 'Import statement',

        'imported' => 'Imported :count statement lines (:format)',
        'skipped' => ', skipped :count (already imported or not completed movements)',
        'first_issue' => '. First issue: :issue',
        'error_unreadable' => 'Cannot open file',
        'error_unrecognised' => 'Unrecognised statement format — export a statement from Wise (CSV, MT940 or camt.053 XML) and try again.',
    ],

    'transfer' => [
        'title' => 'Record as a transfer or funds movement',
        'intro' => 'For your own money moving: between two bank accounts in the books (a bank-to-bank journal), or in from / out to an account the books don\'t track (funds introduced or withdrawn — an equity movement, never income, so nothing touches revenue, expenses or GST). Payment-limit splits each record their own line. The journal is dated the bank line\'s date and this line matches to it.',

        'account_in' => 'Into bank account',
        'account_out' => 'From bank account',
        'source' => 'Source',
        'destination' => 'Destination',
        'external_option' => 'External account (not in the books)',
        'notes' => 'Notes (optional)',
        'notes_placeholder' => 'e.g. transfer to top up the account, split by payment limits',
        'submit' => 'Post transfer and match',
    ],

    'settlement' => [
        'title' => 'Record as a payroll liability payment',
        'intro' => 'For the money that leaves the bank after a pay run: the super or PAYG-withholding payment that settles what the run\'s accrual journals credited but never paid. A combined BAS payment nets the whole quarter on the BAS settlement screen instead. The journal (Dr liability / Cr bank) is dated the bank line\'s date and this line matches to it.',

        'bank_account' => 'From bank account',
        'payable_account' => 'Liability settled',
        'notes' => 'Notes (optional)',
        'notes_placeholder' => 'e.g. September quarter super for run 1',
        'submit' => 'Post settlement and match',
        'posted' => 'Settlement posted and matched — the books now hold the movement.',
    ],

];
