<?php

/**
 * ATO Company Tax Return — Label Mapping
 *
 * Maps the seeded chart of accounts (IFRSSeeder) to the Company tax return
 * 2026 (NAT 0656) item 6/7/8 labels. Label letters and names follow the
 * published 2026 instructions; amounts are GST-exclusive cash-basis ledger
 * totals (see ATO_tax_report_spec.md §4–5).
 *
 * Unmapped revenue/expense accounts fall back to the labels in 'fallback'
 * and are surfaced as warnings on the report so nothing is silently lost.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Company Tax Rate
    |--------------------------------------------------------------------------
    |
    | Used only for the informational Calculation statement estimate.
    | Base rate entities pay 25%; other companies 30%.
    |
    */

    'company_tax_rate' => env('COMPANY_TAX_RATE', 25),

    /*
    |--------------------------------------------------------------------------
    | Item 6 — Income labels (form order)
    |--------------------------------------------------------------------------
    */

    'income_labels' => [
        'A' => ['name' => 'Gross payments where ABN not quoted', 'accounts' => []],
        'B' => ['name' => 'Gross payments subject to foreign resident withholding', 'accounts' => [], 'note' => 'Not applicable — resident company'],
        'C' => ['name' => 'Other sales of goods and services', 'accounts' => [4100, 4110, 4120, 4130]],
        'D' => ['name' => 'Gross distribution from partnerships', 'accounts' => [], 'note' => 'Not applicable'],
        'E' => ['name' => 'Gross distribution from trusts', 'accounts' => [], 'note' => 'Not applicable'],
        'F' => ['name' => 'Gross interest', 'accounts' => [4510]],
        'G' => ['name' => 'Gross rent and other leasing and hiring income', 'accounts' => [], 'note' => 'Not applicable'],
        'H' => ['name' => 'Total dividends', 'accounts' => [], 'note' => 'Dividend/franking module not implemented'],
        'I' => ['name' => 'Fringe benefit employee contributions', 'accounts' => [], 'note' => 'Not applicable'],
        'J' => ['name' => 'Unrealised gains on revaluation of assets to fair value', 'accounts' => [], 'note' => 'Non-cash — excluded'],
        'Q' => ['name' => 'Assessable government industry payments', 'accounts' => [], 'note' => 'Not applicable'],
        'R' => ['name' => 'Other gross income', 'accounts' => [4520]],
        'S' => ['name' => 'Total income', 'total' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Item 6 — Expense labels (form order)
    |--------------------------------------------------------------------------
    */

    'expense_labels' => [
        'B' => ['name' => 'Foreign resident withholding expenses', 'accounts' => [], 'note' => 'Not applicable — resident company'],
        'A' => ['name' => 'Cost of sales', 'accounts' => [], 'note' => 'No trading stock accounts — services entity'],
        'C' => ['name' => 'Contractor, sub-contractor and commission expenses', 'accounts' => [5110]],
        'D' => ['name' => 'Superannuation expenses', 'accounts' => [], 'note' => 'No payroll/superannuation ledger in this system'],
        'E' => ['name' => 'Bad debts', 'accounts' => [8100]],
        'F' => ['name' => 'Lease expenses within Australia', 'accounts' => []],
        'I' => ['name' => 'Lease expenses overseas', 'accounts' => [], 'note' => 'Not applicable'],
        'H' => ['name' => 'Rent expenses', 'accounts' => [7100]],
        'V' => ['name' => 'Interest expenses within Australia', 'accounts' => [8200]],
        'J' => ['name' => 'Interest expenses overseas', 'accounts' => [], 'note' => 'Not applicable'],
        'U' => ['name' => 'Royalty expenses overseas', 'accounts' => [], 'note' => 'Not applicable'],
        'W' => ['name' => 'Royalty expenses within Australia', 'accounts' => []],
        'X' => ['name' => 'Depreciation expenses', 'accounts' => [], 'note' => 'Non-cash depreciation excluded — SBE capital deductions claimed via Item 10'],
        'Y' => ['name' => 'Motor vehicle expenses', 'accounts' => [5400], 'note' => 'Running expenses only — reduce for private use manually'],
        'Z' => ['name' => 'Repairs and maintenance', 'accounts' => []],
        'G' => ['name' => 'Unrealised losses on revaluation of assets to fair value', 'accounts' => [], 'note' => 'Non-cash — excluded'],
        'S' => ['name' => 'All other expenses', 'accounts' => [5100, 5120, 5200, 5300, 7250, 7300, 7400, 7500, 7600, 7700, 7800, 8300, 8900]],
        'Q' => ['name' => 'Total expenses', 'total' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback labels for unmapped accounts
    |--------------------------------------------------------------------------
    |
    | Unmapped operating revenue accounts report at 6-C, other revenue at
    | 6-R and any expense account at 6-S, with a warning on the report.
    |
    */

    'fallback' => [
        \IFRS\Models\Account::OPERATING_REVENUE => 'C',
        \IFRS\Models\Account::NON_OPERATING_REVENUE => 'R',
        'expense' => 'S',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account flags
    |--------------------------------------------------------------------------
    |
    | non_deductible: included at Item 6 per the accounts, then added back
    |                 at Item 7 label W (entertainment, income tax, FDT).
    | non_assessable: excluded from Item 6 income and shown at Item 7
    |                 label V/Q instead (none seeded by default).
    | excluded:       left out of the report entirely (non-cash).
    |
    */

    'account_flags' => [
        5500 => 'non_deductible', // Meals & Entertainment — entertainment is not deductible
        8400 => 'non_deductible', // Income Tax Expense — income tax itself is not deductible
        8410 => 'non_deductible', // Franking Deficit Tax Expense — FDT is not deductible
        7900 => 'excluded',       // Depreciation Expense — non-cash (SBE uses Item 10)
    ],

    /*
    |--------------------------------------------------------------------------
    | Item 8 sources
    |--------------------------------------------------------------------------
    */

    // Information label D — Total salary and wage expenses (gross cash paid).
    'salary_expense_accounts' => [5100, 5120],

    // Labels J/K — Franked/unfranked dividends paid (equity contra account).
    'dividends_paid_account' => 3400,
];
