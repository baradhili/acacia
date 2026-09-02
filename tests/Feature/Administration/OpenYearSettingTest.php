<?php

namespace Tests\Feature\Administration;

use App\Models\EntitySetting;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Scopes\EntityScope;
use IFRS\Transactions\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin's open-year pin: access control, the 7-years-before-now
 * window, and the backfill unlocks — a pinned past year gets its OPEN
 * reporting period, so opening balances can be entered for it and
 * transactions dated in it can post.
 */
class OpenYearSettingTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;

    protected FiscalYearService $service;

    protected User $admin;

    protected int $clockYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(IFRSSeeder::class);

        $this->entity = Entity::first();
        $this->service = new FiscalYearService;
        $this->clockYear = $this->service->clockYear($this->entity);

        $this->admin = User::where('email', 'admin@example.com')->first();
    }

    public function test_page_is_gated_to_admin(): void
    {
        // NB: no guest assertion here — IFRSSeeder ends with Auth::login(),
        // so the app instance already carries an authenticated admin (same
        // reason FinancialYearUiTest skips guest checks).
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->actingAs($staff)->get('/administration')->assertForbidden();
        $this->actingAs($accountant)->get('/administration')->assertForbidden();
        $this->actingAs($this->admin)->get('/administration')->assertOk();
    }

    public function test_dropdown_links_administration_for_admin_only(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($this->admin)->get('/dashboard')
            ->assertSee('Administration')
            ->assertSee('Currently Open Year');
        $this->actingAs($staff)->get('/dashboard')
            ->assertDontSee('Currently Open Year');
    }

    public function test_page_shows_the_effective_year_and_window(): void
    {
        $response = $this->actingAs($this->admin)->get('/administration');

        $response->assertOk()
            ->assertSee('FY '.$this->clockYear)
            ->assertSee('FY '.($this->clockYear - 7))
            ->assertDontSee('FY '.($this->clockYear - 8));
    }

    public function test_pinning_oldest_allowed_year_creates_period_and_anchors_current_year(): void
    {
        $year = $this->clockYear - 7;

        $response = $this->actingAs($this->admin)
            ->put('/administration/open-year', ['open_year' => (string) $year]);

        $response->assertRedirect(route('administration.index'));
        $response->assertSessionHas('success');

        $this->assertSame($year, EntitySetting::storedOpenYear($this->entity));
        $this->assertSame($year, $this->service->currentYear($this->entity));

        $period = ReportingPeriod::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $this->entity->id)
            ->where('calendar_year', $year)
            ->first();
        $this->assertNotNull($period);
        $this->assertSame(ReportingPeriod::OPEN, $period->status);

        // The Financial Years page anchors on the pinned year.
        $this->actingAs($this->admin)->get('/financial-years')
            ->assertOk()
            ->assertSee('FY '.$year);
    }

    public function test_pinned_year_is_default_for_opening_balances_and_saves(): void
    {
        $year = $this->clockYear - 5;
        $this->service->setOpenYear($this->entity, $year);

        $bank = Account::where('code', 320)->where('entity_id', $this->entity->id)->first();
        $payable = Account::where('code', 2100)->where('entity_id', $this->entity->id)->first();

        // No ?year= given: the selector defaults to the pinned year.
        $this->actingAs($this->admin)->get('/opening-balances')
            ->assertOk()
            ->assertSee('value="'.$year.'" selected', false);

        $response = $this->actingAs($this->admin)->post('/opening-balances', [
            'year' => $year,
            'balances' => [
                $bank->id => ['debit' => '15000.50'],
                $payable->id => ['credit' => '15000.50'],
            ],
        ]);

        $response->assertRedirect(route('opening-balances.index', ['year' => $year]));

        $period = ReportingPeriod::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $this->entity->id)
            ->where('calendar_year', $year)
            ->first();
        $openingDate = Carbon::create($year, $this->entity->year_start, 1)->subDay()->startOfDay();

        $this->assertSame(2, Balance::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $this->entity->id)
            ->where('reporting_period_id', $period->id)
            ->count());
        $this->assertDatabaseHas('ifrs_balances', [
            'entity_id' => $this->entity->id,
            'reporting_period_id' => $period->id,
            'account_id' => $bank->id,
            'transaction_date' => $openingDate->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_journal_entry_posts_in_pinned_year(): void
    {
        $year = $this->clockYear - 7;
        $this->service->setOpenYear($this->entity, $year);

        $this->actingAs($this->admin);
        IfrsPosting::ensureReportingPeriod($year.'-09-15', $this->entity);

        $bank = Account::where('code', 320)->where('entity_id', $this->entity->id)->first();
        $revenue = Account::where('code', 4100)->where('entity_id', $this->entity->id)->first();

        $je = new JournalEntry([
            'transaction_date' => Carbon::parse($year.'-09-15'),
            'account_id' => $bank->id,
            'credited' => false,
            'entity_id' => $this->entity->id,
            'narration' => 'Backfill entry in the pinned year',
        ]);
        $je->addLineItem(LineItem::create([
            'account_id' => $revenue->id,
            'amount' => 500,
            'quantity' => 1,
            'entity_id' => $this->entity->id,
        ]));
        $je->post();

        $this->assertDatabaseHas('ifrs_transactions', [
            'entity_id' => $this->entity->id,
            'narration' => 'Backfill entry in the pinned year',
            'transaction_date' => $year.'-09-15 00:00:00',
        ]);
    }

    public function test_years_outside_window_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put('/administration/open-year', ['open_year' => (string) ($this->clockYear - 8)])
            ->assertSessionHasErrors('open_year');

        $this->actingAs($this->admin)
            ->put('/administration/open-year', ['open_year' => (string) ($this->clockYear + 1)])
            ->assertSessionHasErrors('open_year');

        $this->assertNull(EntitySetting::storedOpenYear($this->entity));
        $this->assertSame($this->clockYear, $this->service->currentYear($this->entity));
    }

    public function test_closed_year_cannot_be_pinned(): void
    {
        $year = $this->clockYear - 1;

        ReportingPeriod::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $this->entity->id)
            ->where('calendar_year', $year)
            ->firstOrCreate(
                ['entity_id' => $this->entity->id, 'calendar_year' => $year],
                ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
            )
            ->update(['status' => ReportingPeriod::CLOSED]);

        $this->actingAs($this->admin)
            ->put('/administration/open-year', ['open_year' => (string) $year])
            ->assertRedirect(route('administration.index'))
            ->assertSessionHas('error');

        $this->assertNull(EntitySetting::storedOpenYear($this->entity));
    }

    public function test_automatic_clears_the_pin_but_keeps_created_periods(): void
    {
        $year = $this->clockYear - 7;
        $this->service->setOpenYear($this->entity, $year);

        $this->actingAs($this->admin)
            ->put('/administration/open-year', ['open_year' => 'auto'])
            ->assertRedirect(route('administration.index'))
            ->assertSessionHas('success');

        $this->assertNull(EntitySetting::storedOpenYear($this->entity));
        $this->assertSame($this->clockYear, $this->service->currentYear($this->entity));

        $this->assertDatabaseHas('ifrs_reporting_periods', [
            'entity_id' => $this->entity->id,
            'calendar_year' => $year,
            'status' => ReportingPeriod::OPEN,
        ]);
    }

    public function test_stale_pin_outside_window_is_ignored_on_read(): void
    {
        EntitySetting::setOpenYear($this->entity, $this->clockYear - 8);

        $this->assertSame($this->clockYear, $this->service->currentYear($this->entity));

        // And the settings page explains the expiry instead of hiding it.
        $this->actingAs($this->admin)->get('/administration')
            ->assertOk()
            ->assertSee('is being ignored');
    }
}
