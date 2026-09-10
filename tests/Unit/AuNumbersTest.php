<?php

namespace Tests\Unit;

use App\Support\AuNumbers;
use PHPUnit\Framework\TestCase;

/**
 * ABN/ACN/TFN formatting and normalisation: the ATO/ASIC groupings,
 * spaced entry collapsing to digits, and passthrough for values that
 * don't match the expected shape (never mangle partial data).
 */
class AuNumbersTest extends TestCase
{
    public function test_abn_groups_2_3_3_3(): void
    {
        $this->assertSame('51 824 753 556', AuNumbers::abn('51824753556'));
        $this->assertSame('51 824 753 556', AuNumbers::abn('51 824 753 556')); // already spaced
    }

    public function test_acn_groups_3_3_3(): void
    {
        $this->assertSame('123 456 789', AuNumbers::acn('123456789'));
        $this->assertSame('123 456 789', AuNumbers::acn('123 456 789'));
    }

    public function test_tfn_groups_by_length(): void
    {
        $this->assertSame('123 456 789', AuNumbers::tfn('123456789'));
        $this->assertSame('123 45678', AuNumbers::tfn('12345678'));
        $this->assertSame('123 456 789', AuNumbers::tfn('123 456 789'));
    }

    public function test_unexpected_shapes_pass_through_untouched(): void
    {
        $this->assertSame('12345', AuNumbers::abn('12345'));
        $this->assertSame('12345678901', AuNumbers::acn('12345678901')); // ABN-length is not an ACN
        $this->assertNull(AuNumbers::abn(null));
        $this->assertNull(AuNumbers::tfn('   '));
    }

    public function test_digits_strips_everything_non_numeric(): void
    {
        $this->assertSame('51824753556', AuNumbers::digits('51 824-753.556'));
        $this->assertNull(AuNumbers::digits('ABC'));
    }
}
