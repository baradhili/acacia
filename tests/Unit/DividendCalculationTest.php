<?php

namespace Tests\Unit;

use App\Services\DividendService;
use PHPUnit\Framework\TestCase;

/**
 * Franking credit gross-up maths per the spec: credit = cash x franking%
 * x (rate / (100 - rate)). Pure functions — no database.
 */
class DividendCalculationTest extends TestCase
{
    public function test_fully_franked_dividend_at_30_percent(): void
    {
        // $70 cash, 100% franked, 30% rate → $30 credit (the spec's example).
        $this->assertSame(30.0, DividendService::calculateFrankingCredit(70.0, 100.0, 30.0));
    }

    public function test_half_franked_dividend_at_30_percent(): void
    {
        // Only the franked half of $70 grosses up: 35 x 3/7 = 15.
        $this->assertSame(15.0, DividendService::calculateFrankingCredit(70.0, 50.0, 30.0));
    }

    public function test_base_rate_entity_at_25_percent(): void
    {
        // 25% rate: gross-up factor 25/75 = 1/3.
        $this->assertSame(33.33, DividendService::calculateFrankingCredit(100.0, 100.0, 25.0));
    }

    public function test_unfranked_dividend_attaches_nothing(): void
    {
        $this->assertSame(0.0, DividendService::calculateFrankingCredit(100.0, 0.0, 30.0));
        $this->assertSame(0.0, DividendService::calculateFrankingCredit(0.0, 100.0, 30.0));
    }

    public function test_result_is_rounded_to_cents(): void
    {
        $this->assertSame(4.29, DividendService::calculateFrankingCredit(10.0, 100.0, 30.0));
    }

    public function test_negative_cash_dividend_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DividendService::calculateFrankingCredit(-1.0, 100.0, 30.0);
    }

    public function test_franking_percentage_out_of_range_is_rejected(): void
    {
        try {
            DividendService::calculateFrankingCredit(10.0, 101.0, 30.0);
            $this->fail('Franking percentage above 100 was accepted.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->expectException(\InvalidArgumentException::class);
        DividendService::calculateFrankingCredit(10.0, -1.0, 30.0);
    }

    public function test_invalid_tax_rate_is_rejected(): void
    {
        try {
            DividendService::calculateFrankingCredit(10.0, 100.0, 0.0);
            $this->fail('A 0% tax rate was accepted.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->expectException(\InvalidArgumentException::class);
        DividendService::calculateFrankingCredit(10.0, 100.0, 100.0);
    }
}
