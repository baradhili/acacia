<?php

namespace Tests\Feature\Bas;

use App\Models\BasStatement;
use App\Models\User;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BAS quarters frozen at lodgement: freezing captures the report's
 * live figures so backdated postings can never rewrite a lodged BAS,
 * refreezing recaptures the live figures, and unfreezing returns the
 * quarter to recomputation. Gating mirrors the settlements screen.
 */
class BasStatementFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected int $fyEnd;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand after Q1's end (Jul–Sep for the July start) so there is
        // a completed quarter to lodge; journals inside it are dated
        // explicitly below.
        $this->travelTo(Carbon::parse('2026-10-15 09:00'));

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->seed(IFRSSeeder::class);

        $this->entity = IfrsPosting::resolveEntity();
        // The report's FY key: the June year-end year (FY2027 = Jul
        // 2026 – Jun 2027 for the default July start).
        $this->fyEnd = ReportingPeriod::year(now(), $this->entity) + 1;
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * Entity-bound, like real users — ReportController::ifrsEntity()
     * reads Auth::user()->entity directly.
     */
    protected function admin(): User
    {
        return tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('admin');
    }

    protected function staff(): User
    {
        return tap(User::factory()->create(['entity_id' => $this->entity->id]))->assignRole('staff');
    }

    /** Cr GST Payable (a GST-collect leg) inside FY Q1 (Jul–Sep). */
    protected function collect(float $amount): void
    {
        // Dated inside the completed quarter, not at travelled "now"
        // (October already belongs to Q2).
        $date = Carbon::parse('2026-08-15');
        IfrsPosting::ensureReportingPeriod($date, $this->entity);

        $journal = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate($date, $this->entity),
            'account_id' => $this->account(320)->id,
            'credited' => false,
            'entity_id' => $this->entity->id,
            'currency_id' => $this->entity->currency_id,
            'narration' => 'GST fixture',
            'reference' => 'FREEZE-FIXTURE',
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

    protected function account(int $code)
    {
        return Account::where('entity_id', $this->entity->id)->where('code', $code)->firstOrFail();
    }

    public function test_freezing_captures_live_figures_and_stops_recomputation(): void
    {
        $this->collect(1000);

        $this->actingAs($this->admin())
            ->post('/bas-statements/freeze', ['fy' => $this->fyEnd, 'quarter' => 1])
            ->assertRedirect(route('reports.bas', ['fy' => $this->fyEnd]))
            ->assertSessionHas('success');

        $statement = BasStatement::query()->firstOrFail();
        $this->assertEqualsWithDelta(1000.0, $statement->gst_sales, 0.001);

        // A backdated posting into the lodged quarter must not rewrite it.
        $this->collect(500);

        $this->actingAs($this->admin())
            ->get('/reports/bas?fy='.$this->fyEnd)
            ->assertOk()
            ->assertSee('Lodged')
            ->assertSee('1,000.00')
            ->assertDontSee('1,500.00');

        // Exactly one frozen row — freezing is an upsert per quarter.
        $this->assertDatabaseCount('bas_statements', 1);
    }

    public function test_refreezing_recaptures_the_live_figures(): void
    {
        $this->collect(1000);
        $this->actingAs($this->admin())
            ->post('/bas-statements/freeze', ['fy' => $this->fyEnd, 'quarter' => 1]);

        // Ledger moved on after lodgement; refreezing takes the CURRENT
        // figures, not the previously frozen ones.
        $this->collect(500);
        $this->actingAs($this->admin())
            ->post('/bas-statements/freeze', ['fy' => $this->fyEnd, 'quarter' => 1])
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(1500.0, BasStatement::query()->firstOrFail()->gst_sales, 0.001);
        $this->assertDatabaseCount('bas_statements', 1);
    }

    public function test_unfreezing_returns_to_live_recomputation(): void
    {
        $this->collect(1000);
        $this->actingAs($this->admin())
            ->post('/bas-statements/freeze', ['fy' => $this->fyEnd, 'quarter' => 1]);
        $frozen = BasStatement::query()->firstOrFail();

        $this->collect(500);

        $this->actingAs($this->admin())
            ->delete("/bas-statements/{$frozen->id}/unfreeze")
            ->assertRedirect(route('reports.bas', ['fy' => $this->fyEnd]))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('bas_statements', 0);
        $this->actingAs($this->admin())
            ->get('/reports/bas?fy='.$this->fyEnd)
            ->assertOk()
            ->assertDontSee('Lodged')
            ->assertSee('1,500.00');
    }

    public function test_a_quarter_that_has_not_ended_cannot_be_frozen(): void
    {
        $this->actingAs($this->admin())
            ->post('/bas-statements/freeze', ['fy' => $this->fyEnd, 'quarter' => 4])
            ->assertRedirect(route('reports.bas', ['fy' => $this->fyEnd]))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('bas_statements', 0);
    }

    public function test_freezing_is_gated_to_admin_or_accountant(): void
    {
        $this->actingAs($this->staff())
            ->post('/bas-statements/freeze', ['fy' => $this->fyEnd, 'quarter' => 1])
            ->assertForbidden();

        $this->assertDatabaseCount('bas_statements', 0);
    }
}
