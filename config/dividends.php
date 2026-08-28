<?php

/**
 * Shares & dividends module configuration (see .zcode/plans/tax_spec.md).
 *
 * Account codes reference the seeded chart of accounts (IFRSSeeder):
 * 3400 Dividends Paid (equity contra), 2260 Dividends Payable, 320 the
 * operating bank account — the same accounts the ATO Company Tax Report
 * config maps for labels 8-J/8-K.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Company tax rates
    |--------------------------------------------------------------------------
    |
    | The corporate tax rates that drive the franking credit gross-up:
    | base rate entities ("small" — aggregated turnover under $50m and
    | ≤80% passive income) pay 25%, other companies 30%. The company
    | profile's tax_rate_type classification selects which applies
    | (CompanyProfile::effectiveTaxRate()); each declaration snapshots
    | the rate in force and can still be overridden.
    |
    */
    'tax_rates' => [
        'small' => (float) env('COMPANY_TAX_RATE_SMALL', 25),
        'company' => (float) env('COMPANY_TAX_RATE_COMPANY', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default franking percentage for new declarations (0-100)
    |--------------------------------------------------------------------------
    */
    'default_franking_percentage' => (float) env('DEFAULT_FRANKING_PERCENTAGE', 100),

    /*
    |--------------------------------------------------------------------------
    | Show a warning banner when a franking year closes in deficit
    |--------------------------------------------------------------------------
    |
    | FDT (franking deficit tax) itself is handled manually per the spec's
    | scope exclusions — record the payment as an FT entry once assessed.
    |
    */
    'enable_fdt_warning' => (bool) env('ENABLE_FDT_WARNING', true),

    /*
    |--------------------------------------------------------------------------
    | GL account codes (seeded chart of accounts)
    |--------------------------------------------------------------------------
    */
    'accounts' => [
        'dividends_paid' => 3400,
        'dividends_payable' => 2260,
        'bank' => 320,
    ],

];
