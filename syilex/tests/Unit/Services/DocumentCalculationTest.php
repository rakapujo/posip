<?php

namespace Tests\Unit\Services;

use App\Services\DocumentCalculation;
use PHPUnit\Framework\TestCase;

class DocumentCalculationTest extends TestCase
{
    public function test_discount_level_percent(): void
    {
        $this->assertEquals(10000, DocumentCalculation::discountLevel('percent', 10, 100000));
    }

    public function test_discount_level_nominal_caps_to_base(): void
    {
        $this->assertEquals(5000, DocumentCalculation::discountLevel('nominal', 5000, 100000));
        $this->assertEquals(100000, DocumentCalculation::discountLevel('nominal', 200000, 100000));
    }

    public function test_discount_level_unknown_returns_zero(): void
    {
        $this->assertEquals(0, DocumentCalculation::discountLevel('none', 10, 100000));
    }

    public function test_fee_level_percent_and_nominal(): void
    {
        $this->assertEquals(5000, DocumentCalculation::feeLevel('percent', 5, 100000));
        $this->assertEquals(200000, DocumentCalculation::feeLevel('nominal', 200000, 100000));
    }

    public function test_apply_discount_line_requires_positive_nilai(): void
    {
        $this->assertEquals(0, DocumentCalculation::applyDiscountLine('percent', 0, 100000));
        $this->assertEquals(0, DocumentCalculation::applyDiscountLine('none', 10, 100000));
    }

    public function test_apply_discount_line_recursive_nominal_caps(): void
    {
        $this->assertEquals(3000, DocumentCalculation::applyDiscountLine('nominal', 5000, 3000));
    }

    public function test_apply_discount_line_sum_mode_nominal_no_cap(): void
    {
        $this->assertEquals(5000, DocumentCalculation::applyDiscountLine('nominal', 5000, 100000, capNominalToBase: false));
    }

    public function test_apply_fee_line(): void
    {
        $this->assertEquals(2500, DocumentCalculation::applyFeeLine('percent', 2.5, 100000));
        $this->assertEquals(1500, DocumentCalculation::applyFeeLine('nominal', 1500, 100000));
        $this->assertEquals(0, DocumentCalculation::applyFeeLine('percent', 0, 100000));
    }
}
