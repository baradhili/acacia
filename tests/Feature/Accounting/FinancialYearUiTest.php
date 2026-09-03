<?php

namespace Tests\Feature\Accounting;

use App\Models\FiscalYearClose;
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

class FinancialYearUiTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected FiscalYearService $service;

    protected User $admin;

    protected User $accountant;

    protected int $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(IFRSSeeder::class);

        $this->entity = Entity::first();
        $this->service = new FiscalYearService;
        $this->year = $this->service->currentYear($this->entity) - 1;

        $this->admin = User::where('email', 'admin@example.com')->first();
        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('accountant');
    }

    protected function postRevenue(float $amount): void
    {
        $this->actingAs($this->admin);
        IfrsPosting::ensureReportingPeriod($this->year.'-09-15', $this->entity);

        $bank = Account::where('code', 320)->where('entity_id', $this->entity->id)->first();
        $revenue = Account::where('code', 4100)->where('entity_id', $this->entity->id)->first();

        $je = new JournalEntry([
            'transaction_date' => Carbon::parse($this->year.'-09-15'),
            'account_id' => $bank->id,
            'credited' => false,
            'entity_id' => $this->entity->id,
            'narration' => 'Test entry',
        ]);
        $je->addLineItem(LineItem::create([
            'account_id' => $revenue->id,
            'amount' => $amount,
            'quantity' => 1,
            'entity_id' => $this->entity->id,
        ]));
        $je->post();
    }

    public function test_page_is_gated_to_admin_and_accountant(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/financial-years')->assertForbidden();
        $this->actingAs($this->accountant)->get('/financial-years')->assertOk();
        $this->actingAs($this->admin)->get('/financial-years')->assertOk();
    }

    public function test_index_lists_years_with_status_and_banner(): void
    {
        $this->postRevenue(1000);

        $response = $this->actingAs($this->admin)->get('/financial-years');
        $response->assertOk()
            ->assertSee('FY '.$this->year)
            ->assertSee('Ended — open')
            ->assertSee("FY {$this->year} has ended but hasn't been closed", false);
    }

    public function test_trial_page_shows_checklist_and_proposed_entries(): void
    {
        $this->postRevenue(1000);

        $response = $this->actingAs($this->admin)->get("/financial-years/{$this->year}/trial");
        $response->assertOk()
            ->assertSee('Pre-close checklist')
            ->assertSee('Proposed closing entries')
            ->assertSee('$1,000.00')
            ->assertSee('Submit for approval');

        $this->assertDatabaseHas('fiscal_year_closes', [
            'entity_id' => $this->entity->id,
            'year' => $this->year,
            'status' => FiscalYearClose::STATUS_TRIAL,
            'requested_by' => $this->admin->id,
        ]);
    }

    public function test_full_workflow_through_the_ui(): void
    {
        $this->postRevenue(1000);

        // Submit by the admin.
        $this->actingAs($this->admin)->post("/financial-years/{$this->year}/submit")
            ->assertRedirect();
        $this->assertDatabaseHas('fiscal_year_closes', [
            'year' => $this->year,
            'status' => FiscalYearClose::STATUS_PENDING_APPROVAL,
        ]);

        // The requester cannot approve — the trial page tells them so.
        $this->actingAs($this->admin)->post("/financial-years/{$this->year}/approve")
            ->assertSessionHas('error');
        $this->actingAs($this->admin)->get("/financial-years/{$this->year}/trial")
            ->assertSee('Waiting for another accountant/admin to approve');
        $this->assertDatabaseHas('fiscal_year_closes', [
            'year' => $this->year,
            'status' => FiscalYearClose::STATUS_PENDING_APPROVAL,
        ]);

        // A second accountant approves and the close executes.
        $this->actingAs($this->accountant)->post("/financial-years/{$this->year}/approve")
            ->assertSessionHas('success');
        $this->actingAs($this->accountant)->post("/financial-years/{$this->year}/close")
            ->assertSessionHas('success');

        $this->assertTrue($this->service->isClosed($this->entity, $this->year));
        $this->assertDatabaseHas('fiscal_year_closes', [
            'year' => $this->year,
            'status' => FiscalYearClose::STATUS_CLOSED,
            'approved_by' => $this->accountant->id,
        ]);

        // Banner is gone once the year is closed.
        $this->actingAs($this->admin)->get('/financial-years')
            ->assertDontSee("hasn't been closed", false);

        // Reopen from the index.
        $this->actingAs($this->admin)->post("/financial-years/{$this->year}/reopen")
            ->assertSessionHas('success');
        $this->assertFalse($this->service->isClosed($this->entity, $this->year));
    }

    public function test_sole_admin_approves_own_request(): void
    {
        $this->postRevenue(1000);

        // No other accountant/admin exists — the approval is routed back
        // to the requester instead of the waiting note.
        $this->accountant->syncRoles([]);

        $this->actingAs($this->admin)->post("/financial-years/{$this->year}/submit")
            ->assertRedirect()
            ->assertSessionHas('success', "FY {$this->year} submitted — you are the only accountant/admin, so the approval is routed to you.");

        $this->actingAs($this->admin)->get("/financial-years/{$this->year}/trial")
            ->assertOk()
            ->assertSee('You are the only accountant/admin — this approval is routed to you')
            ->assertDontSee('Waiting for another accountant/admin to approve');

        // Self-approval goes through and the close executes.
        $this->actingAs($this->admin)->post("/financial-years/{$this->year}/approve")
            ->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/financial-years/{$this->year}/close")
            ->assertSessionHas('success');

        $this->assertTrue($this->service->isClosed($this->entity, $this->year));
        $this->assertDatabaseHas('fiscal_year_closes', [
            'year' => $this->year,
            'status' => FiscalYearClose::STATUS_CLOSED,
            'requested_by' => $this->admin->id,
            'approved_by' => $this->admin->id,
        ]);
    }

    public function test_dashboard_warns_about_unclosed_prior_year(): void
    {
        $this->postRevenue(1000);

        $this->actingAs($this->admin)->get('/dashboard')
            ->assertOk()
            ->assertSee("financial year {$this->year} has ended but hasn't been", false);

        $this->service->close($this->entity, $this->year, force: true);

        $this->actingAs($this->admin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee("financial year {$this->year} has ended but hasn't been", false);
    }

    public function test_dashboard_banner_hidden_from_staff(): void
    {
        $this->postRevenue(1000);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/dashboard')
            ->assertOk()
            ->assertDontSee("financial year {$this->year} has ended but hasn't been", false);
    }
}
