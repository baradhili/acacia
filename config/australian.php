<?php

/**
 * Australian Accounting Configuration
 * 
 * Configuration specific to Australian professional services companies
 */

return [

    /*
    |--------------------------------------------------------------------------
    | GST Settings
    |--------------------------------------------------------------------------
    |
    | Australian Goods and Services Tax configuration.
    | Standard rate is 10% for most goods and services.
    |
    */

    'gst' => [
        'rate' => env('GST_RATE', 10),
        'rate_name' => env('GST_RATE_NAME', 'GST 10%'),
        'applicable_from' => env('GST_APPLICABLE_FROM', '2000-07-01'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Financial Year Settings
    |--------------------------------------------------------------------------
    |
    | Australian financial year runs from 1 July to 30 June.
    |
    */

    'financial_year' => [
        'start_month' => env('FINANCIAL_YEAR_START_MONTH', 7),  // July
        'start_day' => env('FINANCIAL_YEAR_START_DAY', 1),
        'end_month' => env('FINANCIAL_YEAR_END_MONTH', 6),     // June
        'end_day' => env('FINANCIAL_YEAR_END_DAY', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounting Method
    |--------------------------------------------------------------------------
    |
    | This system uses cash-based accounting (not accrual).
    | Revenue is recognised when cash is received.
    | Expenses are recognised when cash is paid.
    |
    */

    'accounting_method' => 'cash',

    /*
    |--------------------------------------------------------------------------
    | Currency Settings
    |--------------------------------------------------------------------------
    |
    | Default currency for the Australian company.
    |
    */

    'currency' => [
        'code' => env('DEFAULT_CURRENCY', 'AUD'),
        'symbol' => 'A$',
        'precision' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax File Number (TFN)
    |--------------------------------------------------------------------------
    |
    | Company TFN for reporting purposes.
    |
    */

    'tfn' => env('COMPANY_TFN', ''),

    /*
    |--------------------------------------------------------------------------
    | ABN (Australian Business Number)
    |--------------------------------------------------------------------------
    |
    | Company ABN for reporting and invoicing purposes.
    |
    */

    'abn' => env('COMPANY_ABN', ''),

    /*
    |--------------------------------------------------------------------------
    | BAS Settings
    |--------------------------------------------------------------------------
    |
    | Business Activity Statement settings for GST reporting.
    |
    */

    'bas' => [
        'reporting_frequency' => env('BAS_REPORTING_FREQUENCY', 'quarterly'),
        'installment_rate' => env('BAS_INSTALLMENT_RATE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Superannuation
    |--------------------------------------------------------------------------
    |
    | Superannuation guarantee contribution rate.
    |
    */

    'superannuation' => [
        'rate' => env('SUPER_RATE', 11.5),  // Current SG rate
        'max_earnings_base' => env('SUPER_MAX_EARNINGS_BASE', 62590),  // Quarterly
        'payment_due_day' => env('SUPER_PAYMENT_DUE_DAY', 28),  // 28th of each month
    ],

    /*
    |--------------------------------------------------------------------------
    | Payroll Tax
    |--------------------------------------------------------------------------
    |
    | Australian payroll tax thresholds (NSW example - varies by state).
    |
    */

    'payroll_tax' => [
        'threshold' => env('PAYROLL_TAX_THRESHOLD', 1200000),  // Annual NSW threshold
        'rate' => env('PAYROLL_TAX_RATE', 5.45),  // NSW rate
    ],

];
