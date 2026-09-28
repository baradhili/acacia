<?php

namespace App\Nav;

use App\Support\Nav;
use App\Support\Widgets;
use App\Widgets\ARAgingWidget;
use App\Widgets\BankBalanceWidget;
use App\Widgets\CashFlowWidget;
use App\Widgets\OutstandingInvoicesWidget;
use App\Widgets\OutstandingPOBudgetsWidget;
use App\Widgets\PnLTrendWidget;
use App\Widgets\RecentInvoicesWidget;
use App\Widgets\RecentPaymentsWidget;
use App\Widgets\TotalClientsWidget;

/**
 * The core's own navigation and dashboard registrations — every link
 * the shell shipped with before the registry existed, transcribed
 * verbatim (labels, role gates, active-route patterns, icons, + add
 * shortcuts). Modules register alongside these from their service
 * providers; positions leave gaps (10, 20, 30…) for insertion.
 */
class CoreNav
{
    public static function register(Nav $nav): void
    {
        $nav->addSidebar(static::sidebar());
        $nav->addTopbar(static::topbar());
    }

    public static function registerWidgets(Widgets $widgets): void
    {
        foreach (static::widgets() as [$class, $span, $position, $label]) {
            $widgets->add($class, $span, $position, $label);
        }
    }

    public static function sidebar(): array
    {
        $icon = fn (string $d) => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="'.$d.'"></path>';

        return [
            ['type' => 'link', 'label' => 'Dashboard', 'route' => 'dashboard', 'active' => ['dashboard'],
                'icon' => $icon('M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'), 'position' => 0],

            ['type' => 'divider', 'position' => 5],
            ['type' => 'heading', 'label' => 'Contacts', 'position' => 10],
            ['type' => 'link', 'label' => 'Clients', 'route' => 'clients.index', 'active' => ['clients.*'],
                'icon' => $icon('M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0M7 10a2 2 0 11-4 0 2 2 0 014 0z'),
                'add' => 'clients.create', 'addTitle' => 'Add Client', 'position' => 11],
            ['type' => 'link', 'label' => 'Suppliers', 'route' => 'suppliers.index', 'active' => ['suppliers.*'],
                'icon' => $icon('M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'),
                'add' => 'suppliers.create', 'addTitle' => 'Add Supplier', 'position' => 12],

            ['type' => 'divider', 'position' => 15],
            ['type' => 'heading', 'label' => 'Time & Projects', 'position' => 20],
            ['type' => 'link', 'label' => 'Projects', 'route' => 'projects.index', 'active' => ['projects.*'],
                'icon' => $icon('M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'),
                'add' => 'projects.create', 'addTitle' => 'Add Project', 'position' => 21],
            ['type' => 'link', 'label' => 'Time Entries', 'route' => 'time-entries.index', 'active' => ['time-entries.*'],
                'icon' => $icon('M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'),
                'add' => 'time-entries.create', 'addTitle' => 'Add Time Entry', 'position' => 22],
            ['type' => 'link', 'label' => 'My Timesheet', 'route' => 'timesheets.weekly', 'active' => ['timesheets.weekly'],
                'icon' => $icon('M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'), 'position' => 23],
            ['type' => 'link', 'label' => 'Purchase Orders', 'route' => 'purchase-orders.index', 'active' => ['purchase-orders.*'],
                'icon' => $icon('M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'),
                'add' => 'purchase-orders.create', 'addTitle' => 'Add Purchase Order', 'position' => 24],

            ['type' => 'divider', 'position' => 30],
            ['type' => 'heading', 'label' => 'Invoicing', 'position' => 35],
            ['type' => 'link', 'label' => 'Invoices', 'route' => 'invoices.index', 'active' => ['invoices.*'],
                'icon' => $icon('M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'),
                'add' => 'invoices.create', 'addTitle' => 'New Invoice', 'position' => 36],
            ['type' => 'link', 'label' => 'Payments', 'route' => 'payments.index', 'active' => ['payments.*'],
                'icon' => $icon('M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'),
                'add' => 'payments.create', 'addTitle' => 'Record Payment', 'position' => 37],
            ['type' => 'link', 'label' => 'Estimates', 'route' => 'estimates.index', 'active' => ['estimates.*'],
                'icon' => $icon('M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'),
                'add' => 'estimates.create', 'addTitle' => 'New Estimate', 'position' => 38],
            ['type' => 'link', 'label' => 'Bills', 'route' => 'bills.index', 'active' => ['bills.*'],
                'icon' => $icon('M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'),
                'add' => 'bills.create', 'addTitle' => 'New Bill', 'position' => 39],
            ['type' => 'link', 'label' => 'Supplier Payments', 'route' => 'bill-payments.index', 'active' => ['bill-payments.*'],
                'icon' => $icon('M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'),
                'add' => 'bill-payments.create', 'addTitle' => 'Record Supplier Payment', 'position' => 40],
            ['type' => 'link', 'label' => 'Reimbursements', 'route' => 'reimbursement-payments.index', 'active' => ['reimbursement-payments.*'],
                'icon' => $icon('M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'),
                'add' => 'reimbursement-payments.create', 'addTitle' => 'Pay Employee Reimbursement', 'position' => 41],

        ];
    }

    public static function topbar(): array
    {
        $gated = ['admin', 'accountant'];
        // Positions leave slots for module children: Practice owns the
        // Time & Projects group (10-15) and Taxation slots BAS (28) and
        // Company Tax Return (29) between Account Schedule and Prepayment
        // Schedule. Children sort by position (stable for equals), so
        // dropdown order stays as shipped.
        $link = fn (string $label, string $route, array $active, int $position = 0) => ['type' => 'link', 'label' => $label, 'route' => $route, 'active' => $active, 'position' => $position];

        return [
            ['type' => 'dropdown', 'label' => 'Reports', 'position' => 10, 'active' => ['reports.*'], 'children' => [
                ['type' => 'divider', 'position' => 20],
                ['type' => 'heading', 'label' => 'IFRS Reports', 'position' => 21],
                $link('Balance Sheet', 'reports.balance-sheet', ['reports.balance-sheet'], 22),
                $link('Trial Balance', 'reports.trial-balance', ['reports.trial-balance'], 23),
                $link('Income Statement', 'reports.income-statement', ['reports.income-statement'], 24),
                $link('Cash Flow', 'reports.cash-flow', ['reports.cash-flow'], 25),
                $link('Account Statement', 'reports.account-statement', ['reports.account-statement'], 26),
                $link('Account Schedule', 'reports.account-schedule', ['reports.account-schedule'], 27),
                $link('Prepayment Schedule', 'reports.prepayment-schedule', ['reports.prepayment-schedule'], 30),
            ]],

            ['type' => 'dropdown', 'label' => 'Accounting', 'position' => 20, 'roles' => $gated,
                'active' => ['prepayments.*', 'domains.*'], 'children' => [
                    $link('Prepayments', 'prepayments.index', ['prepayments.*']),
                    $link('Domain Names', 'domains.index', ['domains.*']),
                ]],

            ['type' => 'dropdown', 'label' => 'Setup', 'position' => 40, 'roles' => $gated,
                'active' => ['company-profile.*', 'chart-of-accounts.*', 'opening-balances.*', 'financial-years.*', 'services.*', 'share-classes.*'], 'children' => [
                    $link('Company Details', 'company-profile.index', ['company-profile.*']),
                    $link('Chart of Accounts', 'chart-of-accounts.index', ['chart-of-accounts.*']),
                    $link('Opening Balances', 'opening-balances.index', ['opening-balances.*']),
                    $link('Financial Years', 'financial-years.index', ['financial-years.*']),
                    $link('Services', 'services.index', ['services.*']),
                    $link('Share Classes', 'share-classes.index', ['share-classes.*']),
                ]],
        ];
    }

    /**
     * The dashboard grid as shipped: class, span class ('' = one
     * cell), registry position and the label translation key the
     * edit-mode catalog lists the widget under. Positions match the
     * previous hardcoded grid so stored layout preferences keep
     * resolving AND the default order stays the shipped one — module
     * widgets slot into the gaps (Practice's HoursThisMonthWidget 30
     * and UnbilledTimeWidget 110, Taxation's GstPayableWidget 40,
     * Crm's PipelineWidget 130), which matters because module
     * providers register before the core does.
     *
     * @return list<array{0: class-string, 1: string, 2: int, 3: string}>
     */
    public static function widgets(): array
    {
        return [
            [TotalClientsWidget::class, '', 10, 'widgets.labels.total_clients'],
            [OutstandingInvoicesWidget::class, '', 20, 'widgets.labels.outstanding_invoices'],
            [CashFlowWidget::class, 'md:col-span-2 lg:col-span-4', 50, 'widgets.labels.cash_flow'],
            [ARAgingWidget::class, 'md:col-span-1 lg:col-span-2', 60, 'widgets.labels.ar_aging'],
            [BankBalanceWidget::class, 'md:col-span-1 lg:col-span-2', 70, 'widgets.labels.bank_balance'],
            [RecentInvoicesWidget::class, 'md:col-span-1 lg:col-span-1', 80, 'widgets.labels.recent_invoices'],
            [RecentPaymentsWidget::class, 'md:col-span-1 lg:col-span-1', 90, 'widgets.labels.recent_payments'],
            [OutstandingPOBudgetsWidget::class, 'md:col-span-1 lg:col-span-2', 100, 'widgets.labels.outstanding_po_budgets'],
            [PnLTrendWidget::class, 'md:col-span-1 lg:col-span-2', 120, 'widgets.labels.pnl_trend'],
        ];
    }
}
