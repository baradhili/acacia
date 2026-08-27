<?php

namespace Tests\Feature\Accounting;

use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosingAwareReportsTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;
    protected FiscalYearService $service;
    protected Account $bank;
    protected Account $revenue;
    protected Account $expense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(IFRSSeeder::class);
        $this->actingAs(\App\Models\User::where('email', 'admin@example.com')->first());

        $this->entity = Entity::first();
        $this->service = new FiscalYearService();
        $this->bank = Account::where('code', 320)->where('entity_id', $this->entity->id)->first();
        $this->revenue = Account::where('code', 4100)->where('entity_id', $this->entity->id)->first();
        $this->expense = Account::where('code', 5100)->where('entity_id', $this->entity->id)->first();
    }

    protected function postJournal(string $date, Account $main, bool $credited, array $lines, string $reference = null): JournalEntry
    {
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $je = new JournalEntry([
            'transaction_date' => Carbon::parse($date),
            'account_id' => $main->id,
            'credited' => $credited,
            'entity_id' => $this->entity->id,
            'narration' => 'Test entry',
            'reference' => $reference,
        ]);

        foreach ($lines as [$account, $amount]) {
            $je->addLineItem(LineItem::create([
                'account_id' => $account->id,
                'amount' => $amount,
                'quantity' => 1,
                'entity_id' => $this->entity->id,
            ]));
        }

        $je->post();

        return $je;
    }

    protected function closableYear(): int
    {
        return $this->service->currentYear($this->entity) - 1;
    }

    public function test_income_statement_for_a_closed_year_keeps_its_figures(): void
    {
        $year = $this->closableYear();

        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 10000]]);
        $this->postJournal(($year + 1) . '-01-10', $this->bank, true, [[$this->expense, 4000]]);

        $query = ['start_date' => $year . '-07-01', 'end_date' => ($year + 1) . '-06-30'];

        $before = $this->get('/reports/income-statement?' . http_build_query($query));
        $before->assertOk();
        $statement = $before->viewData('lines')['statement'];
        $this->assertEquals(10000.0, $statement['revenueTotal']);
        $this->assertEquals(4000.0, $statement['expenseTotal']);
        $this->assertEquals(6000.0, $statement['netProfit']);

        $this->service->close($this->entity, $year, force: true);

        $after = $this->get('/reports/income-statement?' . http_build_query($query));
        $after->assertOk();
        $statement = $after->viewData('lines')['statement'];

        // The closing entries zeroed the P&L accounts in the ledger —
        // the report must still show the year's real trading results.
        $this->assertEquals(10000.0, $statement['revenueTotal']);
        $this->assertEquals(4000.0, $statement['expenseTotal']);
        $this->assertEquals(6000.0, $statement['netProfit']);
        $this->assertEquals(10000.0, $statement['grossProfit']);

        $after->assertSee('$10,000.00');
        $after->assertSee('($4,000.00)');
    }

    public function test_income_statement_totals_are_sums_of_their_rows(): void
    {
        $year = $this->closableYear();
        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 10000]]);

        $response = $this->get('/reports/income-statement?' . http_build_query([
            'start_date' => $year . '-07-01',
            'end_date' => ($year + 1) . '-06-30',
        ]));

        $statement = $response->viewData('lines')['statement'];
        $this->assertEquals(
            round(array_sum(array_column($statement['revenue'], 'balance')), 2),
            $statement['revenueTotal']
        );
    }

    public function test_balance_sheet_does_not_double_count_profit_after_close(): void
    {
        $year = $this->closableYear();

        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 10000]]);

        $asAt = 'end_date=' . ($year + 1) . '-06-30';

        $before = $this->get('/reports/balance-sheet?' . $asAt);
        $before->assertOk();
        $statement = $before->viewData('lines')['statement'];

        // Pre-close: profit only exists as the on-the-fly equity figure.
        $this->assertEquals(10000.0, $statement['assetsTotal']);
        $this->assertEquals(10000.0, $statement['equityTotal']);

        $this->service->close($this->entity, $year, force: true);

        $after = $this->get('/reports/balance-sheet?' . $asAt);
        $after->assertOk();
        $statement = $after->viewData('lines')['statement'];

        // Post-close: the profit sits in Retained Earnings via the
        // closing entries and the on-the-fly figure is switched off —
        // equity totals exactly once and the sheet still balances.
        $this->assertEquals(10000.0, $statement['assetsTotal']);
        $this->assertEquals(10000.0, $statement['equityTotal']);
        $this->assertEquals($statement['assetsTotal'], round($statement['equityTotal'] + $statement['liabilitiesTotal'], 2));
    }

    public function test_balance_sheet_open_year_still_adds_profit_on_the_fly(): void
    {
        $year = $this->service->currentYear($this->entity);

        // Early in the current FY, before "now", so the default as-at
        // window (FY start → today) includes it.
        $this->postJournal($year . '-07-15', $this->bank, false, [[$this->revenue, 2500]]);

        $response = $this->get('/reports/balance-sheet');
        $response->assertOk();

        $statement = $response->viewData('lines')['statement'];
        $this->assertEquals(2500.0, $statement['assetsTotal']);
        $this->assertEquals(2500.0, $statement['equityTotal']);
    }

    public function test_company_tax_statement_unchanged_by_the_close(): void
    {
        $year = $this->closableYear();

        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 1000]]);
        $this->postJournal(($year + 1) . '-02-01', $this->bank, true, [[$this->expense, 400]]);

        $fy = $year + 1; // company-tax "fy" param is the FY's ending calendar year

        $before = $this->get('/reports/company-tax?fy=' . $fy);
        $before->assertOk()->assertSee('$1,000')->assertSee('$400');

        $this->service->close($this->entity, $year, force: true);

        $after = $this->get('/reports/company-tax?fy=' . $fy);
        $after->assertOk()->assertSee('$1,000')->assertSee('$400');

        // The closing entries are non-bank P&L journals — without the
        // reference exclusion V07 would count them as excluded activity.
        $after->assertSee('0 non-bank P&L ledger rows excluded');
    }
}
