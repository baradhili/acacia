<?php

namespace Tests\Feature\Bas;

use App\Models\User;
use App\Services\BasSettlementService;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The BAS report's prior-year line: when the previous financial
 * year's net BAS total was never settled with the ATO, it leads the
 * quarterly table so the unpaid year cannot quietly disappear — and
 * clears once a GST settlement has covered through that year's end
 * (the balance-side action the settlement screen records).
 */
class BasPriorYearLineTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected BasSettlementService $settlements;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand inside FY2027 (Jul 2026 – Jun 2027) so FY2026 is a
        // complete prior year.
        $this->travelTo(Carbon::parse('2026-10-15 09:00'));

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);

        $this->entity = IfrsPosting::resolveEntity();
        $this->settlements = app(BasSettlementService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    protected function admin(): User
    {
        return tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('admin');
    }

    protected function account(int $code): Account
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
    }

    /** Cr GST Payable (a GST-collect leg) dated inside FY2026. */
    protected function collectPriorYear(float $amount): void
    {
        $date = Carbon::parse('2025-08-15');
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate($date, $this->entity),
            'account_id' => $this->account(320)->id,
            'credited' => false,
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'narration' => 'Prior-year GST fixture',
            'reference' => 'PRIOR-YEAR-FIXTURE',
        ]);

        $line = LineItem::create([
            'account_id' => $this->account(2200)->id,
            'amount' => $amount,
            'quantity' => 1,
            'entity_id' => $this->entity->id,
        ]);
        $journal->addLineItem($line);
        $journal->post();
    }

    public function test_an_unsettled_prior_year_leads_the_quarterly_table(): void
    {
        $this->collectPriorYear(1000.00);

        $this->actingAs($this->admin())
            ->get('/reports/bas?fy=2027')
            ->assertOk()
            ->assertSee('FY2026 total', false)
            ->assertSee('Prior year net BAS not settled with the ATO', false)
            ->assertSee('payable, unsettled', false)
            ->assertSee('1,000.00', false)
            ->assertViewHas('priorYear', fn ($prior) => $prior !== null
                && $prior['fy_end'] === 2026
                && abs($prior['net'] - 1000.0) < 0.005);
    }

    public function test_the_line_clears_once_a_settlement_covers_the_prior_year(): void
    {
        $this->collectPriorYear(1000.00);

        // A catch-up settlement covering through 30 June 2026 (the
        // bank movement lands early the next FY, as lodgement lag).
        $this->settlements->settle([
            'as_at' => '2026-06-30',
            'settled_at' => '2026-07-05',
            'type' => 'gst',
        ]);

        $this->actingAs($this->admin())
            ->get('/reports/bas?fy=2027')
            ->assertOk()
            ->assertDontSee('not settled with the ATO', false)
            ->assertViewHas('priorYear', null);
    }

    public function test_a_settled_prior_year_never_shows_the_line(): void
    {
        // Nothing owing from FY2026 — no line even though the current
        // year is generating GST.
        $this->assertDatabaseCount('bas_settlements', 0);

        $this->actingAs($this->admin())
            ->get('/reports/bas?fy=2027')
            ->assertOk()
            ->assertDontSee('not settled with the ATO', false)
            ->assertViewHas('priorYear', null);
    }
}
