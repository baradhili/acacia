<?php

namespace Tests\Feature\Accounting;

use App\Models\Client;
use App\Models\FiscalYearClose;
use App\Models\Payment;
use App\Services\FiscalYearService;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use Database\Seeders\IFRSSeeder;
use Database\Seeders\RoleSeeder;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalYearServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Entity $entity;
    protected FiscalYearService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(IFRSSeeder::class);
        $this->entity = Entity::first();
        $this->service = new FiscalYearService();
    }

    public function test_bounds_follow_entity_year_start(): void
    {
        $bounds = $this->service->bounds($this->entity, 2025);

        $this->assertEquals(7, $this->entity->year_start);
        $this->assertEquals('2025-07-01', $bounds['start']->toDateString());
        $this->assertEquals('2026-06-30', $bounds['end']->toDateString());
    }

    public function test_current_year_matches_package_derivation(): void
    {
        $this->assertEquals(
            ReportingPeriod::year(now(), $this->entity),
            $this->service->currentYear($this->entity)
        );
    }

    public function test_assert_closable_rejects_current_and_future_years(): void
    {
        $current = $this->service->currentYear($this->entity);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertClosable($this->entity, $current);
    }

    public function test_assert_closable_rejects_already_closed_year(): void
    {
        $this->service->reportingPeriod($this->entity, 2025)->update(['status' => ReportingPeriod::CLOSED]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertClosable($this->entity, 2025);
    }

    public function test_assert_closable_accepts_ended_open_year(): void
    {
        $this->service->assertClosable($this->entity, $this->service->currentYear($this->entity) - 1);

        $this->assertTrue(true);
    }

    public function test_checklist_passes_for_clean_year(): void
    {
        $year = $this->service->currentYear($this->entity) - 1;
        $checklist = $this->service->checklist($this->entity, $year);

        $this->assertTrue($this->service->checklistPasses($checklist));
        $this->assertNotEmpty($checklist);
    }

    public function test_checklist_fails_on_unposted_payment_dated_in_year(): void
    {
        $year = $this->service->currentYear($this->entity) - 1;
        $bounds = $this->service->bounds($this->entity, $year);

        Payment::create([
            'client_id' => Client::factory()->create()->id,
            'amount' => 1000,
            'payment_date' => $bounds['start']->copy()->addMonths(2)->toDateString(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_COMPLETED,
            // ifrs_receipt_id stays null — never posted.
        ]);

        $checklist = $this->service->checklist($this->entity, $year);

        $this->assertFalse($this->service->checklistPasses($checklist));
        $item = collect($checklist)->firstWhere('key', 'unposted_payments');
        $this->assertFalse($item['pass']);
        $this->assertTrue($item['blocking']);
        $this->assertStringContainsString('1 unposted', $item['detail']);
    }

    public function test_void_payments_do_not_block_checklist(): void
    {
        $year = $this->service->currentYear($this->entity) - 1;
        $bounds = $this->service->bounds($this->entity, $year);

        Payment::create([
            'client_id' => Client::factory()->create()->id,
            'amount' => 1000,
            'payment_date' => $bounds['start']->copy()->addMonths(2)->toDateString(),
            'payment_method' => Payment::METHOD_BANK_TRANSFER,
            'status' => Payment::STATUS_VOID,
        ]);

        $item = collect($this->service->checklist($this->entity, $year))->firstWhere('key', 'unposted_payments');

        $this->assertTrue($item['pass']);
    }

    public function test_is_closed_reflects_reporting_period_status(): void
    {
        $year = $this->service->currentYear($this->entity) - 1;

        $this->assertFalse($this->service->isClosed($this->entity, $year));

        $this->service->reportingPeriod($this->entity, $year)->update(['status' => ReportingPeriod::CLOSED]);

        $this->assertTrue($this->service->isClosed($this->entity, $year));
    }

    public function test_ensure_close_record_creates_trial_row_once(): void
    {
        $year = $this->service->currentYear($this->entity) - 1;

        $record = $this->service->ensureCloseRecord($this->entity, $year);
        $again = $this->service->ensureCloseRecord($this->entity, $year);

        $this->assertEquals(FiscalYearClose::STATUS_TRIAL, $record->status);
        $this->assertEquals($record->id, $again->id);
        $this->assertEquals($year, $record->year);
        $this->assertEquals('FY-CLOSE-' . $year, $record->closingReference());
    }

    public function test_unclosed_prior_year_ignores_years_without_periods(): void
    {
        // Only the current FY period exists (seeder) — nothing to warn about.
        $this->assertNull($this->service->unclosedPriorYear($this->entity));
    }

    public function test_unclosed_prior_year_flags_ended_unclosed_year(): void
    {
        $year = $this->service->currentYear($this->entity) - 1;
        $this->service->reportingPeriod($this->entity, $year);

        $this->assertEquals($year, $this->service->unclosedPriorYear($this->entity));

        // Once closed, the warning clears.
        $this->service->reportingPeriod($this->entity, $year)->update(['status' => ReportingPeriod::CLOSED]);
        $this->assertNull($this->service->unclosedPriorYear($this->entity));
    }

    public function test_is_date_blocked_by_closed_reporting_period(): void
    {
        $locks = new PeriodLockService();
        $year = $this->service->currentYear($this->entity) - 1;
        $date = Carbon::create($year, 10, 15);

        $this->assertFalse($locks->isDateBlocked($date, $this->entity));

        $this->service->reportingPeriod($this->entity, $year)->update(['status' => ReportingPeriod::CLOSED]);

        $this->assertTrue($locks->isDateBlocked($date, $this->entity));
        $message = $locks->dateBlockedMessage($date, $this->entity);
        $this->assertNotNull($message);
        $this->assertStringContainsString("Financial year {$year} is closed", $message);
    }

    public function test_is_date_blocked_by_locked_app_period(): void
    {
        $locks = new PeriodLockService();
        // 15 Nov 2030 falls in FY 2030 (Jul 2030 – Jun 2031).
        $date = Carbon::parse('2030-11-15');

        collect(\App\Models\FiscalPeriod::createMonthlyPeriodsForYear(2030))->each(
            fn ($p) => $p->lock('Test lock')
        );

        $this->assertTrue($locks->isDateBlocked($date, $this->entity));
        $this->assertStringContainsString('locked', $locks->dateBlockedMessage($date, $this->entity));
    }
}
