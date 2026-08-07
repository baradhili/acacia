<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardService();
    }

    protected function createClient(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Test Client',
            'email' => 'test@example.com',
        ], $attributes));
    }

    protected function createInvoice(array $attributes = []): Invoice
    {
        $client = $attributes['client'] ?? $this->createClient();
        unset($attributes['client']);

        return Invoice::create(array_merge([
            'client_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => Carbon::now(),
            'due_date' => Carbon::now()->addDays(30),
            'total' => 1000.00,
            'subtotal' => 1000.00,
            'tax_amount' => 0,
        ], $attributes));
    }

    protected function createPayment(array $attributes = []): Payment
    {
        $client = $attributes['client'] ?? $this->createClient();
        unset($attributes['client']);

        return Payment::create(array_merge([
            'client_id' => $client->id,
            'amount' => 500.00,
            'payment_date' => Carbon::now(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ], $attributes));
    }

    // ========================
    // Cash Flow Widget Tests
    // ========================

    public function test_cash_flow_widget_returns_correct_structure(): void
    {
        $widget = $this->service->getCashFlowWidget();

        $this->assertArrayHasKey('inflows', $widget);
        $this->assertArrayHasKey('outflows', $widget);
        $this->assertArrayHasKey('net_flow', $widget);
        $this->assertArrayHasKey('daily_data', $widget);
    }

    public function test_cash_flow_calculates_inflows(): void
    {
        $client = $this->createClient();
        
        Payment::create([
            'client_id' => $client->id,
            'amount' => 1000.00,
            'payment_date' => Carbon::now(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $widget = $this->service->getCashFlowWidget();

        $this->assertEquals(1000.00, $widget['inflows']);
    }

    public function test_cash_flow_excludes_pending_payments(): void
    {
        $client = $this->createClient();
        
        Payment::create([
            'client_id' => $client->id,
            'amount' => 1000.00,
            'payment_date' => Carbon::now(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_PENDING,
        ]);

        $widget = $this->service->getCashFlowWidget();

        $this->assertEquals(0, $widget['inflows']);
    }

    public function test_cash_flow_daily_data_format(): void
    {
        $widget = $this->service->getCashFlowWidget();

        $this->assertIsArray($widget['daily_data']);
        if (!empty($widget['daily_data'])) {
            $first = $widget['daily_data'][0];
            $this->assertArrayHasKey('date', $first);
            $this->assertArrayHasKey('inflow', $first);
            $this->assertArrayHasKey('outflow', $first);
            $this->assertArrayHasKey('net', $first);
        }
    }

    // ========================
    // AR Aging Widget Tests
    // ========================

    public function test_ar_aging_returns_correct_buckets(): void
    {
        $widget = $this->service->getARAgingWidget();

        $this->assertArrayHasKey('current', $widget);
        $this->assertArrayHasKey('days_30', $widget);
        $this->assertArrayHasKey('days_60', $widget);
        $this->assertArrayHasKey('days_90', $widget);
        $this->assertArrayHasKey('over_90', $widget);
        $this->assertArrayHasKey('total', $widget);
        $this->assertArrayHasKey('aging_buckets', $widget);
    }

    public function test_ar_aging_buckets_sum_to_total(): void
    {
        $widget = $this->service->getARAgingWidget();

        $sum = $widget['current'] + $widget['days_30'] + $widget['days_60'] + 
               $widget['days_90'] + $widget['over_90'];
        $this->assertEquals($widget['total'], $sum);
    }

    public function test_ar_aging_current_invoice(): void
    {
        Invoice::create([
            'client_id' => $this->createClient()->id,
            'status' => Invoice::STATUS_SENT,
            'issue_date' => Carbon::now(),
            'due_date' => Carbon::now()->addDays(30), // Not yet due
            'total' => 500.00,
            'subtotal' => 500.00,
            'tax_amount' => 0,
        ]);

        $widget = $this->service->getARAgingWidget();

        $this->assertEquals(500.00, $widget['current']);
    }

    // ========================
    // Recent Invoices Widget Tests
    // ========================

    public function test_recent_invoices_returns_correct_structure(): void
    {
        $widget = $this->service->getRecentInvoicesWidget();

        $this->assertArrayHasKey('invoices', $widget);
        $this->assertArrayHasKey('count', $widget);
        $this->assertArrayHasKey('total_amount', $widget);
    }

    public function test_recent_invoices_includes_created_invoices(): void
    {
        $invoice = $this->createInvoice(['total' => 1500.00]);

        $widget = $this->service->getRecentInvoicesWidget();

        $this->assertEquals(1, $widget['count']);
        $this->assertEquals(1500.00, $widget['total_amount']);
    }

    public function test_recent_invoices_limits_results(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->createInvoice(['total' => 100.00]);
        }

        $widget = $this->service->getRecentInvoicesWidget(10);

        $this->assertEquals(10, $widget['count']);
    }

    // ========================
    // Recent Payments Widget Tests
    // ========================

    public function test_recent_payments_returns_correct_structure(): void
    {
        $widget = $this->service->getRecentPaymentsWidget();

        $this->assertArrayHasKey('payments', $widget);
        $this->assertArrayHasKey('count', $widget);
        $this->assertArrayHasKey('total_amount', $widget);
    }

    public function test_recent_payments_includes_completed_payments(): void
    {
        $payment = $this->createPayment(['amount' => 750.00]);

        $widget = $this->service->getRecentPaymentsWidget();

        $this->assertEquals(1, $widget['count']);
        $this->assertEquals(750.00, $widget['total_amount']);
    }

    // ========================
    // Bank Balance Widget Tests
    // ========================

    public function test_bank_balance_returns_correct_structure(): void
    {
        $widget = $this->service->getBankBalanceWidget();

        $this->assertArrayHasKey('balance', $widget);
        $this->assertArrayHasKey('total_credits', $widget);
        $this->assertArrayHasKey('total_debits', $widget);
        $this->assertArrayHasKey('unreconciled_count', $widget);
    }

    public function test_bank_balance_calculates_correctly(): void
    {
        \App\Models\BankTransaction::create([
            'source' => 'WISE',
            'source_id' => 'TST-001',
            'reference' => 'REF-001',
            'description' => 'Test credit',
            'amount' => 1000.00,
            'currency' => 'AUD',
            'type' => \App\Models\BankTransaction::TYPE_CREDIT,
            'transaction_date' => Carbon::now(),
            'status' => \App\Models\BankTransaction::STATUS_PENDING,
        ]);

        \App\Models\BankTransaction::create([
            'source' => 'WISE',
            'source_id' => 'TST-002',
            'reference' => 'REF-002',
            'description' => 'Test debit',
            'amount' => 300.00,
            'currency' => 'AUD',
            'type' => \App\Models\BankTransaction::TYPE_DEBIT,
            'transaction_date' => Carbon::now(),
            'status' => \App\Models\BankTransaction::STATUS_PENDING,
        ]);

        $widget = $this->service->getBankBalanceWidget();

        $this->assertEquals(1000.00, $widget['total_credits']);
        $this->assertEquals(300.00, $widget['total_debits']);
        $this->assertEquals(700.00, $widget['balance']);
    }

    // ========================
    // P&L Trend Widget Tests
    // ========================

    public function test_pnl_trend_returns_correct_structure(): void
    {
        $widget = $this->service->getPnLTrendWidget();

        $this->assertArrayHasKey('months', $widget);
        $this->assertArrayHasKey('avg_revenue', $widget);
        $this->assertArrayHasKey('avg_expenses', $widget);
        $this->assertArrayHasKey('total_revenue', $widget);
    }

    public function test_pnl_trend_months_count(): void
    {
        $widget = $this->service->getPnLTrendWidget(6);

        $this->assertCount(6, $widget['months']);
    }

    public function test_pnl_trend_month_data_structure(): void
    {
        $widget = $this->service->getPnLTrendWidget();

        $firstMonth = $widget['months'][0];
        $this->assertArrayHasKey('month', $firstMonth);
        $this->assertArrayHasKey('revenue', $firstMonth);
        $this->assertArrayHasKey('expenses', $firstMonth);
        $this->assertArrayHasKey('net_income', $firstMonth);
    }

    // ========================
    // All Widgets Test
    // ========================

    public function test_get_all_widgets_returns_all_widgets(): void
    {
        $widgets = $this->service->getAllWidgets();

        $this->assertArrayHasKey('cash_flow', $widgets);
        $this->assertArrayHasKey('ar_aging', $widgets);
        $this->assertArrayHasKey('recent_invoices', $widgets);
        $this->assertArrayHasKey('recent_payments', $widgets);
        $this->assertArrayHasKey('outstanding_po_budgets', $widgets);
        $this->assertArrayHasKey('unbilled_time', $widgets);
        $this->assertArrayHasKey('bank_balance', $widgets);
        $this->assertArrayHasKey('pnl_trend', $widgets);
    }
}
