<?php

namespace Tests\Feature;

use App\Models\BillPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\IfrsPosting;
use App\Widgets\PnLTrendWidget;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The P&L trend's expense side must carry the cash the supplier
 * subledger never sees: the money that actually left the bank for
 * payroll — net-pay journals and super/PAYG-withholding settlements,
 * in the month it left. Accrual journals (which post against
 * liabilities, never the bank) and reversed settlement rounds do
 * not count; employee reimbursements count in their payment month.
 */
class PnLTrendWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected Account $bank;

    protected Account $wagesPayable;

    protected Account $paygPayable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-06 09:00'));

        $this->entity = Entity::create([
            'name' => 'Test Entity',
            'locale' => 'en_AU',
            'multi_currency' => false,
            'year_start' => 7,
        ]);

        $currency = Currency::create([
            'name' => 'Australian Dollar',
            'currency_code' => 'AUD',
            'entity_id' => $this->entity->id,
        ]);
        $this->entity->update(['currency_id' => $currency->id]);
        $this->entity->refresh();

        ReportingPeriod::create([
            'period_count' => 1,
            'calendar_year' => 2026,
            'status' => ReportingPeriod::OPEN,
            'entity_id' => $this->entity->id,
        ]);

        foreach ([
            ['Operating Account', Account::BANK, 320],
            ['PAYG Withholding Payable', Account::CURRENT_LIABILITY, 2210],
            ['Superannuation Payable', Account::CURRENT_LIABILITY, 2220],
            ['Wages Payable', Account::CURRENT_LIABILITY, 2235],
            ['Salaries & Wages', Account::OPERATING_EXPENSE, 5100],
            ['Superannuation Expense', Account::OPERATING_EXPENSE, 5150],
            ['Consulting Revenue', Account::OPERATING_REVENUE, 4100],
            ['Funds Introduced', Account::EQUITY, 3500],
        ] as [$name, $type, $code]) {
            $account = Account::create([
                'name' => $name,
                'account_type' => $type,
                'code' => $code,
                'currency_id' => $currency->id,
                'entity_id' => $this->entity->id,
            ]);

            match ($code) {
                320 => $this->bank = $account,
                2210 => $this->paygPayable = $account,
                2235 => $this->wagesPayable = $account,
                default => null,
            };
        }

        // The widget resolves the entity from the auth context.
        $this->actingAs(User::factory()->create(['entity_id' => $this->entity->id]));
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /** The PayrollService posting shape: main account one side, legs the other. */
    protected function postJournal(Account $main, bool $credited, array $legs, string $reference, string $date): void
    {
        IfrsPosting::ensureReportingPeriod(Carbon::parse($date), $this->entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate(Carbon::parse($date), $this->entity),
            'account_id' => $main->id,
            'credited' => $credited,
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'narration' => 'Payroll fixture '.$reference,
            'reference' => $reference,
        ]);

        foreach ($legs as [$account, $amount]) {
            $line = LineItem::create([
                'account_id' => $account->id,
                'amount' => $amount,
                'quantity' => 1,
                'entity_id' => $this->entity->id,
            ]);
            $journal->addLineItem($line);
        }

        $journal->post();
    }

    public function test_only_the_cash_paid_on_payroll_counts(): void
    {
        // The run's journals: accruals never touch the bank…
        $this->postJournal($this->account(5100), false, [[$this->paygPayable, 854], [$this->wagesPayable, 2946]], 'PAYROLL-9-ACC', '2026-10-01');
        $this->postJournal($this->account(5150), false, [[$this->account(2220), 456]], 'PAYROLL-9-SUP', '2026-10-01');

        // …the net-pay journal does.
        $this->postJournal($this->bank, true, [[$this->wagesPayable, 2946]], 'PAYROLL-9-PAY', '2026-10-01');

        // The super settlement (match-screen PayrollLiabilityService shape).
        $this->postJournal($this->account(2220), false, [[$this->bank, 456]], 'PAYSET-77', '2026-10-01');

        // The PAYG-withholding BAS settlement — and an abandoned round
        // reversed straight back, which must not count.
        $this->postJournal($this->paygPayable, false, [[$this->bank, 854]], 'BAS-SETT-PAYG-20260930', '2026-10-02');
        $this->postJournal($this->paygPayable, false, [[$this->bank, 854]], 'BAS-SETT-PAYG-20260930-OLD', '2026-10-02');
        $this->postJournal($this->bank, false, [[$this->paygPayable, 854]], 'BAS-SETT-PAYG-20260930-OLD-REV', '2026-10-02');

        // A settled payment OUTSIDE the trailing window never appears.
        $this->postJournal($this->bank, true, [[$this->wagesPayable, 1000]], 'PAYROLL-3-PAY', '2025-06-15');

        $data = app(PnLTrendWidget::class)->run()->getData();
        $october = collect($data['months'])->firstWhere('month', '2026-10');

        // Cash paid: net 2,946 + super 456 + PAYG 854. The accruals
        // (gross expense, super accrual) and the reversed round are
        // not cash.
        $this->assertEqualsWithDelta(4256.0, (float) $october['expenses'], 0.001);

        // Every payroll bank leg was credited once — the reversal is a
        // debit leg and dropped by the query, not netted here.
        $this->assertSame(4, Ledger::where('post_account', $this->bank->id)
            ->where('entry_type', 'C')
            ->whereBetween('posting_date', ['2026-10-01', '2026-10-31'])
            ->count());
    }

    protected function account(int $code): Account
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
    }

    public function test_a_reversed_run_nets_to_no_payroll_cash(): void
    {
        // The net-pay journal and its REV mirror share the
        // PAYROLL-{id} family — an undone run leaves no payroll cash
        // in the trend.
        $this->postJournal($this->bank, true, [[$this->wagesPayable, 2946]], 'PAYROLL-9-PAY', '2026-10-01');
        $this->postJournal($this->bank, false, [[$this->wagesPayable, 2946]], 'PAYROLL-9-REV', '2026-10-01');

        $data = app(PnLTrendWidget::class)->run()->getData();
        $october = collect($data['months'])->firstWhere('month', '2026-10');

        $this->assertEqualsWithDelta(0.0, (float) $october['expenses'], 0.001);
    }

    public function test_employee_captures_count_once_at_their_reimbursement(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create();

        // A completed employee capture: the company's cash has not
        // left — it must not count as an expense here.
        BillPayment::createWithUniqueNumber([
            'supplier_id' => $supplier->id,
            'paid_by' => $user->id,
            'amount' => 100.00,
            'payment_date' => '2026-10-01',
            'payment_method' => BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT,
            'status' => BillPayment::STATUS_COMPLETED,
        ]);

        $data = app(PnLTrendWidget::class)->run()->getData();
        $october = collect($data['months'])->firstWhere('month', '2026-10');

        $this->assertEqualsWithDelta(0.0, (float) $october['expenses'], 0.001);
    }

    public function test_revenue_is_receipts_not_funds_or_settlements(): void
    {
        // A client receipt: revenue.
        $this->postJournal($this->bank, false, [[$this->account(4100), 1500]], 'PAY-2026-0099', '2026-10-02');

        // Funds introduced (external transfer): cash in, never income.
        $this->postJournal($this->bank, false, [[$this->account(3500), 10000]], 'XFER-901', '2026-10-02');

        // The BAS GST settlement: cash out, never an expense.
        $this->postJournal($this->paygPayable, false, [[$this->bank, 3606]], 'BAS-SETT-GST-20260930', '2026-10-02');

        $data = app(PnLTrendWidget::class)->run()->getData();
        $october = collect($data['months'])->firstWhere('month', '2026-10');

        $this->assertEqualsWithDelta(1500.0, (float) $october['revenue'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $october['expenses'], 0.001);
    }
}
