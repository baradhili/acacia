<?php

/**
 * Prepaid Subscriptions, Licences & Domain Names
 *
 * Account codes and settings for the prepaid/amortisation engine that
 * backs AASB/IFRS treatment of service contracts: payments for prepaid
 * bill lines debit the asset account (460) and are amortised monthly to
 * the expense account by the prepayments:amortise runner; initial
 * domain purchases capitalise to the intangible (170) while renewals
 * are expensed (7510). Codes refer to the IFRSSeeder chart of accounts.
 */

return [

    // Asset accounts
    'prepaid_account_codes' => [460],        // "Prepaid assets" optgroup in bills
    'prepaid_subscription_code' => 460,      // Prepaid Subscriptions (CURRENT_ASSET)
    'domain_intangible_code' => 170,         // Domain Names (NON_CURRENT_ASSET)

    // Expense accounts
    'subscription_expense_code' => 7500,     // Subscriptions & Licenses
    'domain_renewal_expense_code' => 7510,   // Domain Renewal Expense
    'amortisation_expense_code' => 7910,     // finite-life intangibles

    // Purchase-side GST Vat (input tax credit → account 430). Bill
    // payments fall back to 'G' when this code is not seeded.
    'purchase_gst_vat_code' => 'I',
];
