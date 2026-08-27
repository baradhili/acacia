<?php

namespace Tests\Feature\Accounting;

use App\Models\FiscalYearClose;
use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalYearTrialCloseTest extends TestCase
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

    /**
     * Post Dr/Cr legs: main account takes the side of $credited (false =
     * debit), line items always take the opposite side — the same shape
     * Payment::postToIFRS() uses.
     */
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

    public function test_trial_close_computes_lines_and_totals(): void
    {
        $year = $this->closableYear();

        // FY activity: 10,000 revenue in, 4,000 expenses out.
        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 10000]]);
        $this->postJournal(($year + 1) . '-01-10', $this->bank, true, [[$this->expense, 4000]]);

        $trial = $this->service->trialClose($this->entity, $year);

        $this->assertTrue($trial['checklist_passes']);

        $revenueLine = collect($trial['lines'])->firstWhere('code', 4100);
        $this->assertNotNull($revenueLine);
        $this->assertEquals(-10000.0, $revenueLine['balance']);
        $this->assertEquals(-10000.0, $revenueLine['fy_movement']);
        $this->assertEquals(0.0, $revenueLine['prior_years']);
        $this->assertEquals('debit', $revenueLine['close_side']);
        $this->assertEquals(10000.0, $revenueLine['amount']);

        $expenseLine = collect($trial['lines'])->firstWhere('code', 5100);
        $this->assertNotNull($expenseLine);
        $this->assertEquals(4000.0, $expenseLine['balance']);
        $this->assertEquals('credit', $expenseLine['close_side']);

        $this->assertEquals(6000.0, $trial['fy_net_profit']);
        $this->assertEquals(0.0, $trial['prior_years_catch_up']);
        $this->assertEquals(6000.0, $trial['net_to_retained_earnings']);
        $this->assertEquals(3200, $trial['retained_earnings']['code']);
    }

    public function test_first_close_carries_prior_years_profit_as_catch_up(): void
    {
        $year = $this->closableYear();

        // Activity dated two FYs back, never closed.
        $this->postJournal(($year - 1) . '-08-15', $this->bank, false, [[$this->revenue, 7000]]);
        // Activity in the year being closed.
        $this->postJournal($year . '-10-20', $this->bank, false, [[$this->revenue, 3000]]);

        $trial = $this->service->trialClose($this->entity, $year);

        $revenueLine = collect($trial['lines'])->firstWhere('code', 4100);
        $this->assertEquals(-10000.0, $revenueLine['balance']);
        $this->assertEquals(-3000.0, $revenueLine['fy_movement']);
        $this->assertEquals(-7000.0, $revenueLine['prior_years']);

        $this->assertEquals(3000.0, $trial['fy_net_profit']);
        $this->assertEquals(7000.0, $trial['prior_years_catch_up']);
        $this->assertEquals(10000.0, $trial['net_to_retained_earnings']);
    }

    public function test_closed_prior_year_leaves_no_carry_in(): void
    {
        $year = $this->closableYear();

        // Old activity, already closed out by a hand-posted FY-CLOSE entry
        // dated the prior FY's year end.
        $this->postJournal(($year - 1) . '-08-15', $this->bank, false, [[$this->revenue, 7000]]);
        $re = Account::where('code', 3200)->where('entity_id', $this->entity->id)->first();
        $priorEnd = ReportingPeriod::periodEnd(Carbon::create($year - 1, 7, 1), $this->entity);
        $this->postJournal(
            $priorEnd->toDateString(),
            $re,
            true, // Cr RE; line items Dr revenue
            [[$this->revenue, 7000]],
            'FY-CLOSE-' . ($year - 1)
        );

        // This year's activity.
        $this->postJournal($year . '-10-20', $this->bank, false, [[$this->revenue, 2500]]);

        $trial = $this->service->trialClose($this->entity, $year);

        $revenueLine = collect($trial['lines'])->firstWhere('code', 4100);
        $this->assertEquals(-2500.0, $revenueLine['balance']);
        $this->assertEquals(0.0, $revenueLine['prior_years']);
        $this->assertEquals(0.0, $trial['prior_years_catch_up']);
        $this->assertEquals(2500.0, $trial['net_to_retained_earnings']);
    }

    public function test_zero_balance_accounts_are_skipped(): void
    {
        $trial = $this->service->trialClose($this->entity, $this->closableYear());

        $this->assertEmpty($trial['lines']);
        $this->assertEquals(0.0, $trial['net_to_retained_earnings']);
    }

    public function test_store_trial_saves_workflow_row(): void
    {
        $year = $this->closableYear();
        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 1000]]);

        $trial = $this->service->storeTrial($this->entity, $year);
        $again = $this->service->storeTrial($this->entity, $year);

        $this->assertEquals($trial['record']->id, $again['record']->id);

        $record = FiscalYearClose::where('entity_id', $this->entity->id)->where('year', $year)->first();
        $this->assertNotNull($record);
        $this->assertEquals(FiscalYearClose::STATUS_TRIAL, $record->status);
        $this->assertNotEmpty($record->checklist);
        $this->assertEquals(1000.0, $record->trial_totals['net_to_retained_earnings']);
        $this->assertEquals(1, $record->trial_totals['line_count']);
    }

    public function test_trial_command_defaults_to_last_ended_year(): void
    {
        $year = $this->closableYear();
        $this->postJournal($year . '-09-15', $this->bank, false, [[$this->revenue, 5000]]);

        $this->artisan('fiscal-year:trial')
            ->expectsOutputToContain("Trial close — FY {$year}")
            ->assertSuccessful();

        $this->assertDatabaseHas('fiscal_year_closes', [
            'entity_id' => $this->entity->id,
            'year' => $year,
            'status' => FiscalYearClose::STATUS_TRIAL,
        ]);
    }

    public function test_trial_command_rejects_current_year(): void
    {
        $this->artisan('fiscal-year:trial', ['year' => $this->service->currentYear($this->entity)])
            ->assertFailed();
    }
}
