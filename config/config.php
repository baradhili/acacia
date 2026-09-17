<?php

/**
 * Australian payroll configuration: PAYG withholding tax scales
 * (ATO NAT 1004 Schedule 1 statement of formulas), the super
 * guarantee rate, and the ledger accounts payroll posts to.
 *
 * The coefficients below are the weekly values published by the ATO
 * for 2026-27 (Schedule 1, statement of formulas, effective 1 July
 * 2026). Each band is [x_less_than, a, b]: for weekly earnings x the
 * band applies when x < x_less_than (null = no upper bound), and
 * withholding before rounding is y = ax - b. The ATO revises the
 * tables for each financial year — update them here when it does.
 *
 * Medicare levy adjustment variants (family thresholds, senior
 * offsets) and the working-holiday-maker scale 15 are not modelled:
 * withholding uses the standard Scale 1/Scale 2 formulas.
 */

return [

    /*
    |----------------------------------------------------------------------
    | Ledger accounts (codes) — resolved per entity at posting time
    |----------------------------------------------------------------------
    */

    'accounts' => [
        'wages_expense' => env('PAYROLL_WAGES_ACCOUNT_CODE', 5100),
        'super_expense' => env('PAYROLL_SUPER_EXPENSE_ACCOUNT_CODE', 5150),
        // The PAYG liability the BAS settlement screen already nets.
        'payg_withholding' => env('PAYROLL_PAYG_ACCOUNT_CODE', 2210),
        'super_payable' => env('PAYROLL_SUPER_PAYABLE_ACCOUNT_CODE', 2220),
        // Net pay owed to employees between accrual and payment.
        'wages_payable' => env('PAYROLL_WAGES_PAYABLE_ACCOUNT_CODE', 2235),
        'bank' => env('PAYROLL_BANK_ACCOUNT_CODE', 320),
    ],

    /*
    |----------------------------------------------------------------------
    | Super guarantee rate by financial-year start (OTE percentage)
    |----------------------------------------------------------------------
    */

    'sg_rates' => [
        '2026-07-01' => 0.12,
        '2025-07-01' => 0.115,
        '2024-07-01' => 0.115,
        '2023-07-01' => 0.11,
    ],

    /*
    |----------------------------------------------------------------------
    | PAYG withholding scales — weekly coefficients (ATO NAT 1004 S1)
    |----------------------------------------------------------------------
    */

    // Scale 1: employee is NOT claiming the tax-free threshold.
    'scale_1' => [
        [188.00, 0.1500, 0.1500],
        [379.00, 0.2100, 12.1077],
        [538.00, 0.2190, 15.5185],
        [673.00, 0.3467, 83.9355],
        [721.00, 0.3450, 82.7892],
        [865.00, 0.3500, 86.9040],
        [1282.00, 0.3600, 95.5254],
        [3415.00, 0.3700, 108.3477],
        [null, 0.4500, 381.4746],
    ],

    // Scale 2: employee IS claiming the tax-free threshold.
    'scale_2' => [
        [362.00, 0.0000, 0.0000],
        [538.00, 0.1500, 54.3462],
        [673.00, 0.2500, 108.2135],
        [721.00, 0.1700, 54.3473],
        [865.00, 0.1790, 60.8377],
        [1282.00, 0.3227, 185.1935],
        [2596.00, 0.3200, 181.7319],
        [3653.00, 0.3900, 363.4627],
        [null, 0.4700, 655.7704],
    ],

    // Withholding rate applied when an employee has not provided a TFN.
    'no_tfn_rate' => 0.47,

    // Salary divided by this many periods for each pay frequency.
    'periods_per_year' => [
        'weekly' => 52,
        'fortnightly' => 26,
        'monthly' => 12,
    ],
];
