<?php

// Dashboard layout-management strings: the edit-mode toolbar, the
// removed-widgets catalog, and the registry widget labels referenced
// from widget registration (`App\Support\Widgets::add`'s label
// argument — core via CoreNav, modules via their providers).
return [
    'title' => 'Dashboard',
    'customize' => 'Customize Dashboard',
    'editing_title' => 'Edit mode',
    'edit_instructions' => 'drag widgets by their header to reorder, change width with the arrows, remove with the cross — removed widgets come back from the list below the grid. Click Done to save.',
    'done' => 'Done',
    'reset' => 'Reset to default',
    'reset_confirm' => 'Reset your dashboard layout to the default?',
    'save_failed' => 'The layout could not be saved — click Done to retry.',
    'saved' => 'Dashboard layout saved',
    'reset_done' => 'Dashboard layout reset to defaults',
    'available' => 'Removed widgets',
    'all_shown' => 'Every widget is on your dashboard.',
    'add' => 'Add',
    'remove' => 'Remove widget',
    'resize' => 'Change width',

    'labels' => [
        'total_clients' => 'Total Clients',
        'outstanding_invoices' => 'Outstanding Invoices',
        'hours_this_month' => 'Hours This Month',
        'unbilled_time' => 'Unbilled Time',
        'gst_payable' => 'Unlodged GST',
        'cash_flow' => 'Cash Flow (30 Days)',
        'ar_aging' => 'AR Aging Summary',
        'bank_balance' => 'Bank Balance',
        'recent_invoices' => 'Recent Invoices',
        'recent_payments' => 'Recent Payments',
        'outstanding_po_budgets' => 'Outstanding PO Budgets',
        'pnl_trend' => 'P&L Trend (12 Months)',
        'pipeline' => 'Sales Pipeline',
    ],
];
