<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Duration;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public function test_formats_seconds_minutes_hours_and_days(): void
    {
        $this->assertSame('0 dtk', Duration::human(0));
        $this->assertSame('59 dtk', Duration::human(59));
        $this->assertSame('1 m', Duration::human(60));
        $this->assertSame('1 j 1 m', Duration::human(3660));
        $this->assertSame('1 h 1 j', Duration::human(90000));
    }

    public function test_keeps_only_the_two_largest_units(): void
    {
        $this->assertSame('2 h 5 j', Duration::human(2 * 86400 + 5 * 3600 + 7 * 60));
    }

    public function test_negative_values_are_treated_as_zero(): void
    {
        $this->assertSame('0 dtk', Duration::human(-5));
    }
}
