<?php

namespace Tests\Unit;

use App\Models\FiscalPeriod;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodLockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PeriodLockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PeriodLockService();
    }

    public function test_creates_monthly_periods_for_fiscal_year(): void
    {
        $periods = FiscalPeriod::createMonthlyPeriodsForYear(2025);

        $this->assertCount(12, $periods);
        $this->assertEquals(2025, $periods[0]->year);
        $this->assertEquals('July 2025', $periods[0]->name);
        $this->assertEquals(FiscalPeriod::TYPE_MONTHLY, $periods[0]->period_type);
        // FY 2025 with a July start runs Jul 2025 – Jun 2026.
        $this->assertEquals('2025-07-01', $periods[0]->start_date->toDateString());
        $this->assertEquals('June 2026', $periods[11]->name);
        $this->assertEquals('2026-06-30', $periods[11]->end_date->toDateString());
    }

    public function test_creates_monthly_periods_for_non_july_start(): void
    {
        $periods = FiscalPeriod::createMonthlyPeriodsForYear(2025, 1);

        $this->assertCount(12, $periods);
        $this->assertEquals('January 2025', $periods[0]->name);
        $this->assertEquals('December 2025', $periods[11]->name);
    }

    public function test_creates_quarterly_periods_for_fiscal_year(): void
    {
        $periods = FiscalPeriod::createQuarterlyPeriodsForYear(2025);

        $this->assertCount(4, $periods);
        $this->assertEquals(2025, $periods[0]->year);
        $this->assertEquals('Q1 FY 2025', $periods[0]->name);
        $this->assertEquals('2025-07-01', $periods[0]->start_date->toDateString());
        $this->assertEquals('2025-09-30', $periods[0]->end_date->toDateString());
        $this->assertEquals(FiscalPeriod::TYPE_QUARTERLY, $periods[0]->period_type);
    }

    public function test_creates_annual_period_for_fiscal_year(): void
    {
        $period = FiscalPeriod::createAnnualPeriodForYear(2025);

        $this->assertEquals(2025, $period->year);
        $this->assertEquals('FY 2025', $period->name);
        $this->assertEquals(FiscalPeriod::TYPE_ANNUAL, $period->period_type);
        $this->assertEquals('2025-07-01', $period->start_date->toDateString());
        $this->assertEquals('2026-06-30', $period->end_date->toDateString());
    }

    public function test_period_is_not_locked_by_default(): void
    {
        $period = FiscalPeriod::createAnnualPeriodForYear(2025);

        $this->assertFalse($period->isLocked());
    }

    public function test_can_lock_period(): void
    {
        $period = FiscalPeriod::createAnnualPeriodForYear(2025);

        $period->lock('Test lock');

        $this->assertTrue($period->isLocked());
        $this->assertNotNull($period->locked_at);
        $this->assertEquals('Test lock', $period->lock_reason);
    }

    public function test_can_unlock_period(): void
    {
        $period = FiscalPeriod::createAnnualPeriodForYear(2025);
        $period->lock('Test lock');

        $period->unlock();

        $this->assertFalse($period->isLocked());
        $this->assertNull($period->locked_at);
    }

    public function test_period_contains_date(): void
    {
        $period = FiscalPeriod::createAnnualPeriodForYear(2025);

        // FY 2025 spans Jul 2025 – Jun 2026.
        $this->assertTrue($period->containsDate(Carbon::parse('2025-12-15')));
        $this->assertFalse($period->containsDate(Carbon::parse('2025-06-15')));
    }

    public function test_is_date_locked_returns_false_for_unlocked_period(): void
    {
        FiscalPeriod::createAnnualPeriodForYear(2025);

        $isLocked = $this->service->isDateLocked(Carbon::parse('2025-12-15'));

        $this->assertFalse($isLocked);
    }

    public function test_is_date_locked_returns_true_for_locked_period(): void
    {
        $period = FiscalPeriod::createAnnualPeriodForYear(2025);
        $period->lock();

        $isLocked = $this->service->isDateLocked(Carbon::parse('2025-12-15'));

        $this->assertTrue($isLocked);
    }

    public function test_validate_transaction_date_for_unlocked_period(): void
    {
        FiscalPeriod::createAnnualPeriodForYear(2025);

        $result = $this->service->validateTransactionDate(Carbon::parse('2025-12-15'));

        $this->assertTrue($result['valid']);
    }

    public function test_validate_transaction_date_for_locked_period(): void
    {
        $period = FiscalPeriod::createAnnualPeriodForYear(2025);
        $period->lock('Locked for audit');

        $result = $this->service->validateTransactionDate(Carbon::parse('2025-12-15'));

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('lock_reason', $result);
    }

    public function test_validate_transaction_date_with_no_period(): void
    {
        $result = $this->service->validateTransactionDate(Carbon::parse('2025-12-15'));

        $this->assertTrue($result['valid']);
    }

    public function test_get_locked_periods(): void
    {
        $period1 = FiscalPeriod::createAnnualPeriodForYear(2024);
        $period2 = FiscalPeriod::createAnnualPeriodForYear(2025);

        $period1->lock('Locked');

        $lockedPeriods = $this->service->getLockedPeriods();

        $this->assertCount(1, $lockedPeriods);
        $this->assertEquals($period1->id, $lockedPeriods->first()->id);
    }

    public function test_lock_periods_before_date(): void
    {
        FiscalPeriod::createAnnualPeriodForYear(2024);
        FiscalPeriod::createAnnualPeriodForYear(2025);
        FiscalPeriod::createAnnualPeriodForYear(2026);

        // Only FY 2024 (ending Jun 2025) is fully before 1 Jan 2026.
        $result = $this->service->lockPeriodsBeforeDate(Carbon::parse('2026-01-01'), 'Year-end close');

        $this->assertEquals(1, $result['locked']);
    }

    public function test_get_period_status(): void
    {
        $period1 = FiscalPeriod::createAnnualPeriodForYear(2024);
        $period2 = FiscalPeriod::createAnnualPeriodForYear(2025);

        $period1->lock();

        $status = $this->service->getPeriodStatus();

        $this->assertEquals(2, $status['total']);
        $this->assertEquals(1, $status['locked']);
        $this->assertEquals(1, $status['unlocked']);
    }

    public function test_locked_period_scope(): void
    {
        FiscalPeriod::createAnnualPeriodForYear(2024)->lock();
        FiscalPeriod::createAnnualPeriodForYear(2025);

        $lockedCount = FiscalPeriod::locked()->count();
        $unlockedCount = FiscalPeriod::unlocked()->count();

        $this->assertEquals(1, $lockedCount);
        $this->assertEquals(1, $unlockedCount);
    }

    public function test_containing_date_scope(): void
    {
        FiscalPeriod::createAnnualPeriodForYear(2025);

        $periods = FiscalPeriod::containingDate(Carbon::parse('2025-12-15'))->get();

        $this->assertCount(1, $periods);
    }
}
