<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SeriesPoints;
use PHPUnit\Framework\TestCase;

final class SeriesPointsTest extends TestCase
{
    public function test_empty_series_gives_empty_string(): void
    {
        $this->assertSame('', SeriesPoints::points([], 200, 40));
    }

    public function test_points_span_width_and_scale_to_max(): void
    {
        $this->assertSame('0,40 100,20 200,0', SeriesPoints::points([0, 5, 10], 200, 40));
    }

    public function test_shared_max_and_flat_zero_series_are_safe(): void
    {
        $this->assertSame('0,30 200,20', SeriesPoints::points([10, 20], 200, 40, 40.0));
        $this->assertSame('0,40 200,40', SeriesPoints::points([0, 0], 200, 40));
        $this->assertSame('100,0', SeriesPoints::points([7], 200, 40));
    }

    public function test_negative_values_are_clamped_to_baseline(): void
    {
        $this->assertSame('0,40 200,0', SeriesPoints::points([-5, 10], 200, 40));
    }
}
