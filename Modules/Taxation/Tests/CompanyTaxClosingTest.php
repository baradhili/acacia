<?php

namespace Modules\Taxation\Tests;

use App\Models\User;
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

/**
 * The company tax statement survives a fiscal-year close unchanged —
 * moved from Tests\Feature\Accounting\ClosingAwareReportsTest when the
 * reports split into core and the Taxation module (the income-statement
 * and balance-sheet halves of the closing-aware coverage stayed core).
 */
class CompanyTaxClosingTest extends TestCase
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
        $this->actingAs(User::where('email', 'admin@example.com')->first());

        $this->entity = Entity::first();
        $this->service = new FiscalYearService;
        $this->bank = Account::where('code', 320)->where('entity_id', $this->entity->id)->first();
        $this->revenue = Account::where('code', 4100)->where('entity_id', $this->entity->id)->first();
        $this->expense = Account::where('code', 5100)->where('entity_id', $this->entity->id)->first();
    }

    protected function postJournal(string $date, Account $main, bool $credited, array $lines, ?string $reference = null): JournalEntry
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

    public function test_company_tax_statement_unchanged_by_the_close(): void
    {
        $year = $this->closableYear();

        $this->postJournal($year.'-09-15', $this->bank, false, [[$this->revenue, 1000]]);
        $this->postJournal(($year + 1).'-02-01', $this->bank, true, [[$this->expense, 400]]);

        $fy = $year + 1; // company-tax "fy" param is the FY's ending calendar year

        $before = $this->get('/reports/company-tax?fy='.$fy);
        $before->assertOk()->assertSee('$1,000')->assertSee('$400');

        $this->service->close($this->entity, $year, force: true);

        $after = $this->get('/reports/company-tax?fy='.$fy);
        $after->assertOk()->assertSee('$1,000')->assertSee('$400');

        // The closing entries are non-bank P&L journals — without the
        // reference exclusion V07 would count them as excluded activity.
        $after->assertSee('0 non-bank P&L ledger rows excluded');
    }
}
