<?php

// Dashboard widget strings: the layout-management UI (edit-mode
// toolbar, removed-widgets catalog), labels shared across widget
// cards, and each widget's own strings — including the registry
// labels referenced from widget registration
// (`App\Support\Widgets::add`'s label argument — core via CoreNav,
// modules via their providers). en is the complete base; en_AU
// overrides only the keys that differ in Australian English.
return [
    // Layout management
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

    // Shared card labels (table headers, links, name fallbacks)
    'amount' => 'Amount',
    'client' => 'Client',
    'date' => 'Date',
    'project' => 'Project',
    'total' => 'Total',
    'view_all' => 'View All',
    'reconcile' => 'Reconcile',
    'no_project' => 'No Project',
    'unknown' => 'Unknown',

    // Registry labels: shown in the edit-mode catalog and as each
    // card's header, so the two never drift.
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

    'ar_aging' => [
        'bucket' => 'Aging Bucket',
        'buckets' => [
            'current' => 'Current',
            'days_30' => '1-30 Days',
            'days_60' => '31-60 Days',
            'days_90' => '61-90 Days',
            'over_90' => '90+ Days',
        ],
    ],

    'bank_balance' => [
        'total_balance' => 'Total Balance',
        'credits' => 'Credits',
        'debits' => 'Debits',
        'status' => 'Reconciliation Status',
        'unreconciled' => ':count unreconciled',
        'matched' => ':count matched',
        'ignored' => ':count ignored',
    ],

    'cash_flow' => [
        'inflows' => 'Inflows',
        'outflows' => 'Outflows',
        'net_flow' => 'Net Flow',
        'vs_previous' => 'vs previous period:',
    ],

    'po_budgets' => [
        'empty' => 'No outstanding PO budgets',
        'po_number' => 'PO #',
        'remaining' => 'Remaining',
        'used' => '% Used',
    ],

    'pnl_trend' => [
        'full_report' => 'Full Report',
        'total_revenue' => 'Total Revenue',
        'total_expenses' => 'Total Expenses',
        'net_income' => 'Net Income',
        'avg_revenue' => 'Avg Revenue/Month',
        'avg_expenses' => 'Avg Expenses/Month',
        'avg_net_income' => 'Avg Net Income',
    ],

    'quick_actions' => [
        'title' => 'Quick Actions',
        'add_client' => 'Add Client',
        'log_time' => 'Log Time',
        'new_project' => 'New Project',
    ],

    'recent_invoices' => [
        'empty' => 'No recent invoices',
        'invoice' => 'Invoice',
        'due' => 'Due',
    ],

    'recent_payments' => [
        'empty' => 'No recent payments',
        'payment' => 'Payment',
    ],

    'welcome' => [
        'title' => 'Welcome to Laravel ERP',
    ],

    'unbilled_time' => [
        'empty' => 'No unbilled time entries',
        'hours_label' => 'Hours',
        'hours' => ':count hrs',
        'total_line' => ':hours hrs = $:amount',
    ],

    'gst_payable' => [
        'to_pay' => 'to pay',
        'refund' => 'refund',
        'payable' => 'Payable',
        'receivable' => 'Receivable',
    ],

    'pipeline' => [
        'leads' => 'Leads',
        'open' => 'open',
        'forecast' => 'forecast',
        'overdue' => 'overdue',
        'empty' => 'No open leads.',
    ],
];
